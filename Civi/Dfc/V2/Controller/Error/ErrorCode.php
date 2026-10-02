<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

/**
 * The single, closed enumeration of machine-readable protocol error codes.
 *
 * ============================================================================
 * WHY AN ENUM AND NOT A `throw new HttpException(422, '...')`
 * ============================================================================
 * This enum is the ONLY status-code policy in the DFC HTTP surface. A route
 * cannot pick a status: it picks a code, and the status comes from
 * {@see status()}. That is what makes "single error model" enforceable rather
 * than aspirational — there is no code path from a controller to an HTTP status
 * that does not pass through this file.
 *
 * ============================================================================
 * THE CODE IS NOT THE STATUS. CLIENTS BRANCH ON THE CODE.
 * ============================================================================
 * Every value below is a stable, lowercase, snake_case token, deliberately
 * distinct from its numeric status:
 *
 *   - the status says what happened at the HTTP layer ("this request could not
 *     be processed as sent");
 *   - the code says what a DFC client should DO about it, and is the thing that
 *     stays stable if a status is ever re-mapped (e.g. merging 409 and 412 in a
 *     future revision of the DFC contract).
 *
 * Two codes sharing one status is therefore expected, not a smell:
 * `authentication_required` and `authentication_unusable` are both 401;
 * `semantic_id_conflict` and `relationship_conflict` are both 409;
 * `internal_error` and `membership_inconsistent` are both 500.
 *
 * ============================================================================
 * WHY `title()` AND `detail()` ARE FIXED STRINGS WITH NO INTERPOLATION
 * ============================================================================
 * They are the only human-readable text this layer can ever emit, so they are
 * literals with no format placeholders. A client that wants the specifics gets
 * them from {@see ProtocolError::diagnostics()} — structured, per-path data
 * with a closed vocabulary — never from an interpolated sentence. See
 * {@see ProtocolError} for why there is no free-text field at all.
 *
 * ============================================================================
 * STATUSES DELIBERATELY ABSENT
 * ============================================================================
 *  405 — the LDP answer to an unsupported method is `Allow` on a 200/404-style
 *        response, not a 405 with a problem body; the DFC standard expects
 *        `Allow` to be discoverable. Not modelled. (sa-005 note, see report.)
 *  406 — a response `Accept` failure is reported as **415** on this surface.
 *        PRD-002 §3 CP-1 fixes the code set and 406 is not in it. Rationale for
 *        choosing 415 over 406 is in sa-005's report; it is a deviation from
 *        strict RFC 9110 and is flagged as such.
 *  429 / 413 / 503 — real, but owned by sa-014 (request/graph limits, audit).
 *        Inventing them here would create untested paths; the code enum is
 *        designed to be extended at that point without changing anything else.
 *
 * @package Civi\Dfc
 */
enum ErrorCode: string
{
    // -- 400 Bad Request ------------------------------------------------------

    /** The request is not well-formed HTTP: bad target, bad syntax, bad header. */
    case INVALID_REQUEST = 'invalid_request';

    /** The body was supposed to be JSON-LD and could not be parsed as such. */
    case MALFORMED_JSON_LD = 'malformed_json_ld';

    /**
     * A header was syntactically invalid in a way that changes what the request
     * means — a bad `Accept` q-value, an unparseable `If-Match`.
     *
     * Distinct from {@see UNSUPPORTED_MEDIA_TYPE}: "I could not read your
     * preference" is a 400, "your preference excludes everything I have" is a
     * 415. Silently ignoring the former is how a client ends up debugging a
     * content-type bug for an afternoon.
     */
    case INVALID_HEADER = 'invalid_header';

    // -- 401 Unauthorized -----------------------------------------------------

    /** No usable credential was presented. Carries `WWW-Authenticate`. */
    case AUTHENTICATION_REQUIRED = 'authentication_required';

    /**
     * A credential WAS presented and could not be accepted: expired, wrong
     * issuer, wrong audience, unknown `kid` after refresh, an ID token where an
     * access token is required.
     *
     * A separate code from {@see AUTHENTICATION_REQUIRED} because the client's
     * remedy differs: refresh the token versus supply one. PRD-002 CP-3 forbids
     * accepting ID tokens, and that rejection lands here.
     */
    case AUTHENTICATION_UNUSABLE = 'authentication_unusable';

    // -- 403 Forbidden --------------------------------------------------------

    /**
     * Authenticated, but the identity lacks the scope or the CiviCRM permission.
     *
     * Deliberately NOT 404: this layer is an authorisation filter, and reporting
     * "exists but you may not see it" as 404 makes permission diagnostics
     * impossible. If a deployment needs existence-hiding, that is a policy on
     * top of this code, not a different code.
     */
    case PERMISSION_DENIED = 'permission_denied';

    // -- 404 Not Found --------------------------------------------------------

    /** No resource at this URI under the platform base. */
    case RESOURCE_NOT_FOUND = 'resource_not_found';

    /**
     * A well-formed request for a DFC class this implementation deliberately does
     * not serve — `Order`, `OrderLine`, and every product class.
     *
     * PRD-002 §1 requires a "deterministic spec-defined unsupported-resource
     * response, never a partial persist". 404 + this code says exactly that:
     * this server has no such resource, and the code tells a client whether it
     * is looking at a gap in the DFC v2 surface or a typo. See sa-005's report —
     * the PRD does not actually specify the status, and this is a documented
     * choice, not a derived one.
     */
    case RESOURCE_OUT_OF_SCOPE = 'resource_out_of_scope';

    // -- 409 Conflict ---------------------------------------------------------

    /**
     * The semantic identifier is already bound to a different record, or the
     * binding would create a second identity for an existing one.
     *
     * This is the anti-"silent merge" guard from PRD-002 §9 and lane rule 5. It
     * is 409, not 422: the request is semantically valid, the *world state* is
     * what conflicts.
     */
    case SEMANTIC_ID_CONFLICT = 'semantic_id_conflict';

    /** A relationship cannot be created or re-pointed without contradicting an
     *  existing one (e.g. inverse already exists pointing elsewhere). */
    case RELATIONSHIP_CONFLICT = 'relationship_conflict';

    // -- 412 Precondition Failed ----------------------------------------------

    /**
     * `If-Match` did not match the current representation.
     *
     * Note the asymmetry with {@see SEMANTIC_ID_CONFLICT}: both 412 and 409 can
     * mean "your view of the world is out of date". The split is client
     * contract, not convenience — a conditional-write failure is always
     * recoverable by re-reading, whereas a 409 needs a merge decision.
     */
    case PRECONDITION_FAILED = 'precondition_failed';

    // -- 415 Unsupported Media Type -------------------------------------------

    /**
     * The request body is not in a media type this endpoint accepts, OR the
     * `Accept` header excludes every representation on offer.
     *
     * Both directions are one code because both are "this is not a media type I
     * can speak". The `supportedMediaTypes` field on {@see ProtocolError} lists
     * the acceptable set in either case, so a client never has to guess.
     */
    case UNSUPPORTED_MEDIA_TYPE = 'unsupported_media_type';

    // -- 422 Unprocessable Content --------------------------------------------

    /**
     * Syntactically valid JSON-LD that does not describe a valid DFC resource.
     *
     * The distinction from {@see MALFORMED_JSON_LD} is the whole point of 400 vs
     * 422 and it must not be blurred: 400 means the bytes are not JSON-LD, 422
     * means the JSON-LD is not a DFC resource. Retrying a 400 unchanged always
     * fails; retrying a 422 after a schema fix can succeed.
     */
    case VALIDATION_FAILED = 'validation_failed';

    // -- 500 Internal Server Error --------------------------------------------

    /**
     * An unexpected server-side failure.
     *
     * Carries a correlation id and NOTHING else about what went wrong. The
     * detail of the failure belongs in the server-side log keyed by that id —
     * see {@see ErrorMapper}, which is where the exception stops being readable.
     */
    case INTERNAL_ERROR = 'internal_error';

    /**
     * A container's membership page contradicted itself: a duplicate member URI,
     * a member that is not contained by the container, or a member that is not a
     * well-formed absolute URI.
     *
     * A dedicated code because this is the one internal error that is
     * *provable from the outside*: a client that sees it knows the response is
     * incomplete and must not cache or trust the page. sa-014 owns the
     * diagnostics endpoint that explains it.
     */
    case MEMBERSHIP_INCONSISTENT = 'membership_inconsistent';

    // -- Policy ---------------------------------------------------------------

    /**
     * The HTTP status for this code.
     *
     * The one place in the repository where a code becomes a number.
     */
    public function status(): int
    {
        return match ($this) {
            self::INVALID_REQUEST,
            self::MALFORMED_JSON_LD,
            self::INVALID_HEADER => 400,

            self::AUTHENTICATION_REQUIRED,
            self::AUTHENTICATION_UNUSABLE => 401,

            self::PERMISSION_DENIED => 403,

            self::RESOURCE_NOT_FOUND,
            self::RESOURCE_OUT_OF_SCOPE => 404,

            self::SEMANTIC_ID_CONFLICT,
            self::RELATIONSHIP_CONFLICT => 409,

            self::PRECONDITION_FAILED => 412,

            self::UNSUPPORTED_MEDIA_TYPE => 415,

            self::VALIDATION_FAILED => 422,

            self::INTERNAL_ERROR,
            self::MEMBERSHIP_INCONSISTENT => 500,
        };
    }

    /**
     * Whether this code must be accompanied by a `WWW-Authenticate` challenge.
     *
     * Enforced as an invariant of {@see ProtocolError} rather than a convention,
     * because a 401 without a challenge is a client bug that RFC 9110 makes
     * unrecoverable: the client has no way to know which scheme to use.
     */
    public function requiresChallenge(): bool
    {
        return $this === self::AUTHENTICATION_REQUIRED || $this === self::AUTHENTICATION_UNUSABLE;
    }

    /** A short, stable, human-readable summary. No interpolation, ever. */
    public function title(): string
    {
        return match ($this) {
            self::INVALID_REQUEST => 'Malformed request',
            self::MALFORMED_JSON_LD => 'Malformed JSON-LD document',
            self::INVALID_HEADER => 'Malformed request header',
            self::AUTHENTICATION_REQUIRED => 'Authentication required',
            self::AUTHENTICATION_UNUSABLE => 'Authentication credentials unusable',
            self::PERMISSION_DENIED => 'Permission denied',
            self::RESOURCE_NOT_FOUND => 'Resource not found',
            self::RESOURCE_OUT_OF_SCOPE => 'Resource out of scope',
            self::SEMANTIC_ID_CONFLICT => 'Semantic identifier conflict',
            self::RELATIONSHIP_CONFLICT => 'Relationship conflict',
            self::PRECONDITION_FAILED => 'Precondition failed',
            self::UNSUPPORTED_MEDIA_TYPE => 'Unsupported media type',
            self::VALIDATION_FAILED => 'DFC resource is not valid',
            self::INTERNAL_ERROR => 'Internal server error',
            self::MEMBERSHIP_INCONSISTENT => 'Container membership is inconsistent',
        };
    }

    /**
     * The client-facing explanation.
     *
     * Fixed text. It deliberately says what the server did NOT do and what the
     * client should try, rather than what went wrong internally — the internal
     * "what" is a log line keyed by the correlation id.
     */
    public function detail(): string
    {
        return match ($this) {
            self::INVALID_REQUEST => 'The request could not be interpreted. Fix the request and retry.',
            self::MALFORMED_JSON_LD => 'The request body is not a parseable JSON-LD document. '
                . 'Nothing was read and nothing was changed.',
            self::INVALID_HEADER => 'A request header could not be parsed, so its meaning is unknown. '
                . 'The header is ignored rather than guessed at; fix it and retry.',
            self::AUTHENTICATION_REQUIRED => 'This resource requires authentication. Present an access token '
                . 'in the Authorization header.',
            self::AUTHENTICATION_UNUSABLE => 'The presented credential was not accepted. Obtain a fresh access '
                . 'token and retry; an ID token is not accepted on this interface.',
            self::PERMISSION_DENIED => 'The authenticated identity does not hold the scope or permission this '
                . 'resource requires.',
            self::RESOURCE_NOT_FOUND => 'No resource exists at this URI under the advertised platform base.',
            self::RESOURCE_OUT_OF_SCOPE => 'This interface does not serve the requested DFC class. '
                . 'No partial resource was created.',
            self::SEMANTIC_ID_CONFLICT => 'The submitted semantic identifier is already bound to a different '
                . 'record. Nothing was merged; resolve the conflict explicitly.',
            self::RELATIONSHIP_CONFLICT => 'The requested relationship contradicts an existing one. '
                . 'Nothing was written.',
            self::PRECONDITION_FAILED => 'The current representation of this resource changed since it was read. '
                . 'Re-read the resource, rebase the change and retry.',
            self::UNSUPPORTED_MEDIA_TYPE => 'No acceptable representation is available in the requested media '
                . 'type. The acceptable media types are listed with this error.',
            self::VALIDATION_FAILED => 'The document is valid JSON-LD but does not describe a valid DFC '
                . 'resource. Nothing was written. Each violation names the affected path.',
            self::INTERNAL_ERROR => 'The request failed unexpectedly. Nothing was changed. Quote the '
                . 'correlation id when reporting this.',
            self::MEMBERSHIP_INCONSISTENT => 'This container page cannot be represented consistently and was not '
                . 'serialised. Do not cache it; re-read the container.',
        };
    }

    /**
     * A dereferenceable-shaped identifier for this code.
     *
     * A URN, not an https URL, on purpose: a `type` that looks dereferenceable
     * but 404s is worse than one that obviously cannot be fetched. Following a
     * future deployment that publishes real documentation, this becomes an https
     * URL and clients that only read `type` need no change.
     */
    public function problemType(): string
    {
        return 'urn:dfc-civicrm:error:' . $this->value;
    }
}
