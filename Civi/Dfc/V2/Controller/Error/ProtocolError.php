<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

use Civi\Dfc\V2\Controller\Serialisation\CanonicalJson;

/**
 * The one and only error value in the DFC HTTP surface.
 *
 * ============================================================================
 * THE NO-LEAK GUARANTEE, AND HOW IT IS BUILT
 * ============================================================================
 * PRD-002 CP-1 requires "no raw SQL/PHP traces/internal IDs in protocol errors".
 * The usual approach — scrub the message with a regex — fails, because a
 * denylist is written against what you have already imagined. This type is built
 * so the leak has nowhere to go instead:
 *
 *   1. THERE IS NO FREE-TEXT FIELD.
 *      The constructor takes an {@see ErrorCode} (a closed enum), a
 *      {@see DfcPath} (tokens only, no string constructor), a list of
 *      {@see ValidationDiagnostic}s (validated predicate/constraint tokens), an
 *      optional {@see CorrelationId} (ULID or UUID only), an optional
 *      {@see AuthenticateChallenge} (a fixed scheme plus a fixed error set), and
 *      a list of media-type strings (validated against the media-type grammar).
 *      There is no `message`, no `reason`, no `debug`, no `context`, and no
 *      `Throwable` parameter. `title()` and `detail()` come from the enum and
 *      are literals.
 *   2. NO THROWABLE IS REACHABLE FROM HERE.
 *      {@see ProtocolError} is a value object, not an exception, and
 *      {@see DfcApiException} — the only client-facing throwable — accepts
 *      nothing but an already-constructed `ProtocolError`. A layer that wants to
 *      surface a `PDOException` must go through {@see ErrorMapper}, which is the
 *      single place where an exception stops being readable.
 *   3. THE SERIALISER VERIFIES ITS OWN OUTPUT.
 *      {@see toArray()} runs the rendered payload through {@see LeakGuard} and
 *      sets `redacted: true` if anything was removed. So the guarantee is
 *      assertable from the outside, not merely argued.
 *
 * WHAT THIS DOES NOT COVER, STATED PLAINLY
 *   A developer who deliberately encodes an internal value into an allowed token
 *   — a `DfcPath` whose tokens spell `var/www/.../Mapper.php`, or a numeric
 *   opaque identifier that genuinely is an identifier — gets it through. Detecting
 *   that would mean forbidding digit strings, which would forbid legitimate DFC
 *   semantic identifiers. The control is against *accidental* leakage, which is
 *   what actually happens in a PHP extension (a `sprintf` in an error path, a
 *   `$e->getMessage()` in a log-forwarding response). See sa-005's report.
 *
 * ============================================================================
 * THE WIRE SHAPE
 * ============================================================================
 * `application/problem+json`, RFC 9457 field names where they exist:
 *
 *     {
 *       "type": "urn:dfc-civicrm:error:validation_failed",
 *       "title": "DFC resource is not valid",
 *       "status": 422,
 *       "code": "validation_failed",
 *       "detail": "...",
 *       "path": "/organizations/0/name",
 *       "correlationId": "01HZY8QK3M7X4V2N6T9B0C5D8E",
 *       "supportedMediaTypes": ["application/ld+json"],
 *       "violations": [ ... ],
 *       "redacted": false
 *     }
 *
 * `code` is the field a client branches on; `status` is there because RFC 9457
 * clients expect it and because it makes a proxy log readable without parsing.
 *
 * ============================================================================
 * ERROR BODIES ARE NOT DFC DOCUMENTS
 * ============================================================================
 * The `@context` of an error body would be a DFC context describing a resource
 * that does not exist, so error bodies are plain `application/problem+json` and
 * are deliberately outside the content negotiation the DFC representations take
 * part in. See {@see ErrorResponder}.
 *
 * @package Civi\Dfc
 */
final class ProtocolError implements \JsonSerializable
{
    /**
     * Cap on reported violations.
     *
     * A malformed submission can produce thousands. Emitting all of them turns a
     * 422 into an amplification vector and buries the first, most informative
     * violation under the last. The route layer truncates and says so; see
     * {@see truncated()}.
     */
    public const MAX_DIAGNOSTICS = 32;

    /** Cap on the media-type list echoed by a 415. */
    private const MAX_SUPPORTED_MEDIA_TYPES = 8;

    /** The `#` in the tchar set is escaped: it is this pattern's own delimiter. */
    private const MEDIA_TYPE_PATTERN = '#^[A-Za-z0-9][A-Za-z0-9!\#$&^_.+\-]{0,126}/'
        . '[A-Za-z0-9][A-Za-z0-9!\#$&^_.+\-]{0,126}$#';

    private readonly ErrorCode $code;

    private readonly ?DfcPath $path;

    /** @var list<ValidationDiagnostic> */
    private readonly array $diagnostics;

    /**
     * Not `readonly`, and not a constructor parameter: it records HOW the error
     * was built, never what a caller asked for. {@see truncated()} is the only
     * writer, and class scope is what lets it write without exposing a setter.
     */
    private bool $truncated = false;

    private readonly ?CorrelationId $correlationId;

    private readonly ?AuthenticateChallenge $challenge;

    /** @var list<string> */
    private readonly array $supportedMediaTypes;

    /**
     * @param list<ValidationDiagnostic> $diagnostics
     * @param list<string>               $supportedMediaTypes
     *
     * @throws \InvalidArgumentException on a violated invariant. These are
     *         programming errors: the constructor refuses rather than emitting a
     *         malformed error body, because an error body that cannot be trusted
     *         is worse than a thrown exception in the request path.
     */
    public function __construct(
        ErrorCode $code,
        ?DfcPath $path = null,
        array $diagnostics = [],
        ?CorrelationId $correlationId = null,
        ?AuthenticateChallenge $challenge = null,
        array $supportedMediaTypes = []
    ) {
        foreach ($diagnostics as $position => $diagnostic) {
            if (!$diagnostic instanceof ValidationDiagnostic) {
                throw new \InvalidArgumentException(sprintf(
                    'Protocol error diagnostics must be ValidationDiagnostic instances. Element #%d is %s.',
                    $position + 1,
                    get_debug_type($diagnostic)
                ));
            }
        }

        if (count($diagnostics) > self::MAX_DIAGNOSTICS) {
            throw new \InvalidArgumentException(sprintf(
                'A protocol error carries at most %d diagnostics, got %d. Truncate with truncated() instead '
                . 'of passing the whole list — a 422 that echoes an unbounded validation report is an '
                . 'amplification vector.',
                self::MAX_DIAGNOSTICS,
                count($diagnostics)
            ));
        }

        if ($code->requiresChallenge() && $challenge === null) {
            throw new \InvalidArgumentException(sprintf(
                'The error code "%s" is a 401 and MUST carry a WWW-Authenticate challenge. A 401 without one '
                . 'is unactionable: RFC 9110 gives the client no way to learn which scheme to use.',
                $code->value
            ));
        }

        if (!$code->requiresChallenge() && $challenge !== null) {
            throw new \InvalidArgumentException(sprintf(
                'The error code "%s" is not a 401 and MUST NOT carry a WWW-Authenticate challenge.',
                $code->value
            ));
        }

        if (count($supportedMediaTypes) > self::MAX_SUPPORTED_MEDIA_TYPES) {
            throw new \InvalidArgumentException(sprintf(
                'A protocol error lists at most %d supported media types, got %d.',
                self::MAX_SUPPORTED_MEDIA_TYPES,
                count($supportedMediaTypes)
            ));
        }

        $media = [];
        foreach ($supportedMediaTypes as $position => $mediaType) {
            if (!is_string($mediaType) || preg_match(self::MEDIA_TYPE_PATTERN, $mediaType) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Supported media type #%d is not a bare media type. Got %s. The field is echoed to clients '
                    . 'and must be a type/subtype pair with no parameters, no whitespace and no wildcard.',
                    $position + 1,
                    is_string($mediaType) ? '"' . $mediaType . '"' : get_debug_type($mediaType)
                ));
            }

            $media[] = strtolower($mediaType);
        }

        $this->code = $code;
        $this->path = $path;
        $this->diagnostics = array_values($diagnostics);
        $this->correlationId = $correlationId;
        $this->challenge = $challenge;
        $this->supportedMediaTypes = $media;
    }

    // -- Named constructors ---------------------------------------------------
    //
    // There is no public way to reach the status without going through one of
    // these, and each one documents the condition it represents. Boolean-blind
    // `new ProtocolError(ErrorCode::X, true)` calls do not exist.

    /** 400 — the request could not be interpreted at all. */
    public static function invalidRequest(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::INVALID_REQUEST, null, [], $correlationId);
    }

    /** 400 — the body was supposed to be JSON-LD and is not. */
    public static function malformedJsonLd(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::MALFORMED_JSON_LD, null, [], $correlationId);
    }

    /** 400 — a header's meaning is unknown, so it is not guessed at. */
    public static function invalidHeader(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::INVALID_HEADER, null, [], $correlationId);
    }

    /** 401 — nothing usable was presented. */
    public static function authenticationRequired(
        AuthenticateChallenge $challenge,
        ?CorrelationId $correlationId = null
    ): self {
        return new self(ErrorCode::AUTHENTICATION_REQUIRED, null, [], $correlationId, $challenge);
    }

    /** 401 — something was presented and rejected. */
    public static function authenticationUnusable(
        AuthenticateChallenge $challenge,
        ?CorrelationId $correlationId = null
    ): self {
        return new self(ErrorCode::AUTHENTICATION_UNUSABLE, null, [], $correlationId, $challenge);
    }

    /** 403 — authenticated, not authorised. */
    public static function permissionDenied(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::PERMISSION_DENIED, null, [], $correlationId);
    }

    /** 404 — no such resource under the advertised base. */
    public static function notFound(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::RESOURCE_NOT_FOUND, null, [], $correlationId);
    }

    /** 404 — a DFC class this implementation deliberately does not serve. */
    public static function outOfScope(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::RESOURCE_OUT_OF_SCOPE, null, [], $correlationId);
    }

    /** 409 — the semantic identifier is bound elsewhere. Nothing was merged. */
    public static function semanticIdConflict(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::SEMANTIC_ID_CONFLICT, null, [], $correlationId);
    }

    /** 409 — the relationship contradicts an existing one. Nothing was written. */
    public static function relationshipConflict(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::RELATIONSHIP_CONFLICT, null, [], $correlationId);
    }

    /** 412 — `If-Match` did not match. Re-read, rebase, retry. */
    public static function preconditionFailed(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::PRECONDITION_FAILED, null, [], $correlationId);
    }

    /**
     * 415 — nothing acceptable on offer, in either direction.
     *
     * @param list<string> $supportedMediaTypes Bare media types, no parameters.
     *                                          Echoed so the client does not have
     *                                          to probe.
     */
    public static function unsupportedMediaType(
        array $supportedMediaTypes = [],
        ?CorrelationId $correlationId = null
    ): self {
        return new self(
            ErrorCode::UNSUPPORTED_MEDIA_TYPE,
            null,
            [],
            $correlationId,
            null,
            $supportedMediaTypes
        );
    }

    /**
     * 422 — valid JSON-LD, invalid DFC.
     *
     * A list longer than {@see MAX_DIAGNOSTICS} is TRUNCATED here rather than
     * refused: the caller (a SHACL or LinkML validator) legitimately cannot
     * predict how many violations a malformed document produces, and failing the
     * whole response because there were too many ways for it to be wrong is not
     * useful. The first ones are kept, because a validator walks the document in
     * order and the earliest violation is usually the root cause, and
     * `violationsTruncated: true` is emitted so a client knows to look again.
     *
     * The CONSTRUCTOR still refuses an oversized list, so the bound cannot be
     * bypassed by calling it directly.
     *
     * @param list<ValidationDiagnostic> $diagnostics
     */
    public static function unprocessable(
        array $diagnostics = [],
        ?DfcPath $path = null,
        ?CorrelationId $correlationId = null
    ): self {
        $truncated = count($diagnostics) > self::MAX_DIAGNOSTICS;

        $error = new self(
            ErrorCode::VALIDATION_FAILED,
            $path,
            array_slice($diagnostics, 0, self::MAX_DIAGNOSTICS),
            $correlationId
        );

        $error->truncated = $truncated;

        return $error;
    }

    /** 500 — unexpected. Carries a correlation id and nothing else. */
    public static function internalError(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::INTERNAL_ERROR, null, [], $correlationId);
    }

    /**
     * 500 — a container page contradicted itself and was NOT serialised.
     *
     * A dedicated code rather than a generic 500 because a client that receives
     * it must know the page is incomplete and untrustworthy, not merely that
     * something went wrong somewhere.
     */
    public static function membershipInconsistent(?CorrelationId $correlationId = null): self
    {
        return new self(ErrorCode::MEMBERSHIP_INCONSISTENT, null, [], $correlationId);
    }

    // -- Accessors ------------------------------------------------------------

    public function code(): ErrorCode
    {
        return $this->code;
    }

    /** The machine-readable token clients branch on. */
    public function codeValue(): string
    {
        return $this->code->value;
    }

    public function status(): int
    {
        return $this->code->status();
    }

    public function title(): string
    {
        return $this->code->title();
    }

    public function detail(): string
    {
        return $this->code->detail();
    }

    public function path(): ?DfcPath
    {
        return $this->path;
    }

    /** @return list<ValidationDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** Were violations dropped to respect {@see MAX_DIAGNOSTICS}? */
    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    public function correlationId(): ?CorrelationId
    {
        return $this->correlationId;
    }

    public function challenge(): ?AuthenticateChallenge
    {
        return $this->challenge;
    }

    /** @return list<string> */
    public function supportedMediaTypes(): array
    {
        return $this->supportedMediaTypes;
    }

    /** The headers this error must be sent with. Currently only the challenge. */
    public function headers(): array
    {
        if ($this->challenge === null) {
            return [];
        }

        return ['WWW-Authenticate' => $this->challenge->headerValue()];
    }

    // -- Serialisation --------------------------------------------------------

    /**
     * The rendered problem document, scrubbed.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'type' => $this->code->problemType(),
            'code' => $this->code->value,
            'status' => $this->status(),
            'title' => $this->code->title(),
            'detail' => $this->code->detail(),
        ];

        if ($this->path !== null) {
            $payload['path'] = $this->path->render();
        }

        if ($this->correlationId !== null) {
            $payload['correlationId'] = $this->correlationId->value();
        }

        if ($this->supportedMediaTypes !== []) {
            $payload['supportedMediaTypes'] = $this->supportedMediaTypes;
        }

        if ($this->diagnostics !== []) {
            $payload['violations'] = array_map(
                static fn (ValidationDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics
            );
        }

        if ($this->truncated) {
            $payload['violationsTruncated'] = true;
        }

        // CanonicalJson sorts keys, so `redacted` lands between `path` and
        // `status` in the encoded bytes. Deterministic beats pretty.
        $payload['redacted'] = false;

        $encoded = CanonicalJson::encode($payload);

        if (!LeakGuard::containsLeak($encoded)) {
            return $payload;
        }

        $payload['redacted'] = true;

        return json_decode(LeakGuard::scrub(CanonicalJson::encode($payload)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The exact bytes to put in the response body.
     *
     * A single method so there is no way to send `toArray()` re-encoded with
     * different flags: the same string is what {@see \Civi\Dfc\V2\Controller\Http\ETag}
     * is computed over.
     */
    public function toJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
