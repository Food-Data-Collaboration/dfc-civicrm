<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Negotiation;

/**
 * One media type, or one media RANGE, as parsed from a header.
 *
 * ============================================================================
 * WHY RANGES AND CONCRETE TYPES SHARE A CLASS
 * ============================================================================
 * RFC 9110 distinguishes them (`media-type` vs `media-range`) but every operation
 * this layer performs — parse, compare parameters, rank by specificity — is the
 * same operation on the same grammar. Two classes would mean two parsers, two
 * sets of validation rules, and a conversion between them at the exact point
 * where a bug would be hardest to see. So one class, with the distinction
 * carried by two predicates: {@see isWildcard()} tells you whether a type can be
 * *offered*, and {@see matches()} only ever succeeds with a concrete type on its
 * right-hand side.
 *
 * ============================================================================
 * THE TYPE VOCABULARY IS A CONSTANT, NOT CONFIGURATION
 * ============================================================================
 * {@see JSON_LD} is REQUIRED by the DFC v2 contract and {@see TURTLE} is
 * supported. They are class constants rather than configuration because making
 * them configurable would only let a site advertise a media type no DFC consumer
 * can read — a strictly worse outcome than a code change a human reviewed. The
 * contrast with the JSON-LD `@context` URL is deliberate and load-bearing: the
 * context URL IS release configuration (it changes with a DFC version) and is read
 * from {@see \Civi\Dfc\V2\Identity\DfcReleaseConfig}, never from a constant.
 *
 * ============================================================================
 * PARAMETER HANDLING
 * ============================================================================
 * Parameters after `q` are `accept-ext` per RFC 9110 §12.5.1: they do not affect
 * matching and are kept separately so a caller can inspect them without them
 * leaking into {@see matches()}.
 *
 * `charset` is matched leniently in exactly one direction: a range asking for
 * `charset=utf-8` matches a concrete type that declares no charset, because
 * both required types are UTF-8-only by specification (RFC 8259 for JSON-LD) and
 * so there is no ambiguity to resolve. A range asking for any OTHER charset
 * (`iso-8859-1`) does NOT match, because we would then be serving UTF-8 under a
 * lie.
 *
 * `profile` is matched leniently too, and for a different reason: RFC 6906 makes
 * it an advisory hint, this layer emits exactly one document, and refusing every
 * conformant JSON-LD client that attaches a default profile would be strictness
 * with no benefit. Every other parameter must match exactly.
 *
 * @package Civi\Dfc
 */
final class MediaType
{
    /** REQUIRED by the DFC v2 HTTP contract. */
    public const JSON_LD = 'application/ld+json';

    /** Supported: the RDF serialisation every Linked Data client can read. */
    public const TURTLE = 'text/turtle';

    /** Supported on request; not on offer for responses by default. */
    public const RDF_XML = 'application/rdf+xml';

    /**
     * Plain JSON.
     *
     * NOT offered and NOT accepted for DFC representations. Accepting it would
     * mean serving a JSON-LD document under a media type that promises no JSON-LD
     * semantics — which is the "silently fall back to a format the client did
     * not ask for" failure, in its most tempting form. It is declared here so a
     * deployment can add it to a capabilities list explicitly, and so the 415 body
     * can name it as unsupported.
     */
    public const JSON = 'application/json';

    /** PATCH body type for LDP partial updates. */
    public const MERGE_PATCH_JSON = 'application/merge-patch+json';

    /** RFC 9110's "we do not know" type, used when no `Content-Type` is sent. */
    public const OCTET_STREAM = 'application/octet-stream';

    /** RFC 9110 `token`. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9!#$%&\'*+.^_`|~\-]+$/';

    private const TCHAR = "!#$%&'*+-.^_`|~";

    private readonly string $type;

    private readonly string $subtype;

    /** @var array<string, string> Parameters before `q`, in header order. */
    private readonly array $parameters;

    /** @var array<string, string> `accept-ext` parameters, after `q`. */
    private readonly array $extensions;

    private readonly ?float $quality;

    /**
     * @param array<string, string> $parameters Media-type parameters (not `q`).
     * @param array<string, string> $extensions Accept extensions (after `q`).
     * @param float|null           $quality    `q` value, or null when the header
     *                                          carried none (which means 1.0).
     */
    private function __construct(
        string $type,
        string $subtype,
        array $parameters = [],
        array $extensions = [],
        ?float $quality = null
    ) {
        $this->type = $type;
        $this->subtype = $subtype;
        $this->parameters = $parameters;
        $this->extensions = $extensions;
        $this->quality = $quality;
    }

    public static function jsonLd(): self
    {
        return self::of(self::JSON_LD);
    }

    public static function turtle(): self
    {
        return self::of(self::TURTLE);
    }

    /**
     * Build a concrete, wildcard-free media type.
     *
     * @param array<string, string> $parameters
     *
     * @throws \InvalidArgumentException
     */
    public static function of(string $type, array $parameters = []): self
    {
        $slash = strpos($type, '/');

        if ($slash === false) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a media type: it needs a "/" between the type and the subtype.',
                $type
            ));
        }

        $rawType = substr($type, 0, $slash);
        $rawSubtype = substr($type, $slash + 1);

        self::assertToken($rawType, 'media type');
        self::assertToken($rawSubtype, 'media subtype');

        if ($rawType === '*' || $rawSubtype === '*') {
            throw new \InvalidArgumentException(
                'A media type that is OFFERED must be concrete. Wildcards exist only in a client\'s Accept '
                . 'header; offering "*/*" would make negotiation meaningless.'
            );
        }

        $parsed = [];
        foreach ($parameters as $name => $value) {
            $parsed[strtolower((string) $name)] = self::assertParameterValue((string) $value);
        }

        return new self(
            strtolower($rawType),
            strtolower($rawSubtype),
            $parsed,
            [],
            null
        );
    }

    /**
     * Parse one media range from an `Accept` or `Content-Type` header value.
     *
     * @throws \InvalidArgumentException on anything unparseable. Deliberately
     *         strict: the caller decides whether an unparseable entry is a 400 or
     *         an entry to skip, and it cannot make that decision well if the
     *         parser has already guessed.
     */
    public static function parse(string $headerValue): self
    {
        $parts = explode(';', trim($headerValue));

        $essence = trim((string) array_shift($parts));

        $slash = strpos($essence, '/');
        if ($slash === false) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a media range. A range is "type/subtype", "type/*" or "*/*".',
                $headerValue
            ));
        }

        $type = strtolower(trim(substr($essence, 0, $slash)));
        $subtype = strtolower(trim(substr($essence, $slash + 1)));

        self::assertRangeToken($type, $headerValue);
        self::assertRangeToken($subtype, $headerValue);

        if ($type === '*' && $subtype !== '*') {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a valid media range: "*" only ever appears as the whole "*/*" or as a subtype.',
                $headerValue
            ));
        }

        if ($type !== '*' && $type !== '' && $subtype !== '' && str_contains($type, '*')) {
            throw new \InvalidArgumentException(sprintf('"%s" contains a misplaced wildcard.', $headerValue));
        }

        if ($subtype !== '*' && str_contains($subtype, '*')) {
            throw new \InvalidArgumentException(sprintf('"%s" contains a misplaced wildcard.', $headerValue));
        }

        $parameters = [];
        $extensions = [];
        $quality = null;

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $equals = strpos($part, '=');
            if ($equals === false) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" contains the parameter "%s", which has no "=" and therefore no value.',
                    $headerValue,
                    $part
                ));
            }

            $name = strtolower(trim(substr($part, 0, $equals)));
            $value = self::unquote(trim(substr($part, $equals + 1)), $headerValue);

            if ($quality === null && $name === 'q') {
                $quality = self::parseQuality($value, $headerValue);

                continue;
            }

            if ($quality === null) {
                $parameters[$name] = self::assertParameterValue($value);
            } else {
                $extensions[$name] = self::assertParameterValue($value);
            }
        }

        return new self($type, $subtype, $parameters, $extensions, $quality);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function subtype(): string
    {
        return $this->subtype;
    }

    /** `type/subtype`, lower-cased, no parameters. */
    public function essence(): string
    {
        return $this->type . '/' . $this->subtype;
    }

    public function parameter(string $name): ?string
    {
        return $this->parameters[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /** @return array<string, string> */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /** The `q` weight, defaulting to 1.0 when the header carried none. */
    public function quality(): float
    {
        return $this->quality ?? 1.0;
    }

    public function hasExplicitQuality(): bool
    {
        return $this->quality !== null;
    }

    public function isWildcard(): bool
    {
        return $this->type === '*' || $this->subtype === '*';
    }

    /** Do two concrete media types denote the same type, parameters aside? */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->subtype === $other->subtype;
    }

    /**
     * Ranking weight: 2 for `type/subtype`, 1 for `type/*`, 0 for the
     * wildcard-only range `*&#47;*`.
     *
     * Higher wins. RFC 9110 §12.5.1 gives a more specific reference precedence
     * over a less specific one, independently of `q`.
     */
    public function specificity(): int
    {
        if ($this->type === '*') {
            return 0;
        }

        return $this->subtype === '*' ? 1 : 2;
    }

    /**
     * Does this RANGE accept the concrete media type $offer?
     *
     * Asymmetric on purpose: a concrete type never matches a wildcard range, so a
     * caller cannot accidentally negotiate an offer by offering the wildcard
     * range `*&#47;*`.
     *
     * @param MediaType $offer Must be concrete; see {@see isWildcard()}.
     */
    public function matches(MediaType $offer): bool
    {
        if ($offer->isWildcard()) {
            return false;
        }

        // Each half of the range is compared independently, and each half is skipped
        // only when THAT half is the wildcard. Comparing the halves as a unit
        // (`if (!isWildcard())`) makes `text/*` accept every type, which is the kind
        // of bug that only shows up as a wrong answer to a client, never as an error.
        if ($this->type !== '*' && $this->type !== $offer->type) {
            return false;
        }

        if ($this->subtype !== '*' && $this->subtype !== $offer->subtype) {
            return false;
        }

        foreach ($this->parameters as $name => $value) {
            if ($name === 'charset') {
                // Lenient only for the UTF-8 case: our required types are
                // UTF-8-only by specification, so honouring `charset=utf-8` needs
                // no declaration. Any other charset would be a lie, so it fails.
                if (!self::isUtf8($value)) {
                    return false;
                }

                continue;
            }

            if ($name === 'profile') {
                // RFC 6906 makes `profile` an ADVISORY hint naming an alternative
                // context. This layer emits exactly one document — the DFC context
                // from the release config — so there is no alternative it could be
                // asking for, and RFC 6906's guidance for an unrecognised profile
                // is to ignore it.
                //
                // Refusing instead would break every conformant JSON-LD client that
                // attaches `profile="...#compacted"` by default, in exchange for
                // strictness about a hint that cannot change the answer. The one
                // thing it could change — the compaction — is not parameterisable
                // here, and a client that needs a different compaction is asking for
                // a different endpoint.
                continue;
            }

            $offered = $offer->parameter($name);
            if ($offered === null || strcasecmp($offered, $value) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * `type/subtype` plus parameters, omitting `q` and accept extensions.
     *
     * This is what goes into `Content-Type` and `Accept-Post`, both of which must
     * not carry a weight.
     */
    public function toString(): string
    {
        $value = $this->essence();

        foreach ($this->parameters as $name => $parameterValue) {
            $value .= '; ' . $name . '=' . $parameterValue;
        }

        return $value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    // -- Parsing internals ----------------------------------------------------

    private static function isUtf8(string $value): bool
    {
        return in_array(strtolower(trim($value, " \t\"'")), ['utf-8', 'utf8'], true);
    }

    private static function assertToken(string $token, string $role): void
    {
        if ($token === '' || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a valid %s. The allowed characters are letters, digits and "%s".',
                $token,
                $role,
                self::TCHAR
            ));
        }
    }

    /** Like {@see assertToken()} but additionally accepts `*`. */
    private static function assertRangeToken(string $token, string $headerValue): void
    {
        if ($token === '*') {
            return;
        }

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a valid media range. The allowed characters are letters, digits, "*" and "%s".',
                $headerValue,
                self::TCHAR
            ));
        }
    }

    private static function parseQuality(string $value, string $headerValue): float
    {
        // RFC 9110 §12.4.2: 0..1 with up to three decimal places. Stricter than
        // float casting, because `q=2` and `q=abc` must not silently become 2.0
        // and 0.0 — the first would make an unacceptable range acceptable and the
        // second would make an unacceptable range merely unattractive.
        if (preg_match('/^(?:0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'The q-value in "%s" must be between 0 and 1 with at most three decimals.',
                $headerValue
            ));
        }

        return (float) $value;
    }

    private static function unquote(string $value, string $headerValue): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('"%s" contains a parameter with an empty value.', $headerValue));
        }

        if ($value[0] !== '"') {
            return $value;
        }

        if (strlen($value) < 2 || !str_ends_with($value, '"')) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" contains an unterminated quoted-string parameter value.',
                $headerValue
            ));
        }

        $inner = substr($value, 1, -1);

        // Only \" and \\ are legal escapes; anything else means the header is not
        // the thing the client thinks it sent.
        return (string) preg_replace_callback(
            '/\\\\(.)/',
            static function (array $match): string {
                if ($match[1] === '"' || $match[1] === '\\') {
                    return $match[1];
                }

                throw new \InvalidArgumentException(sprintf(
                    '"\\%s" is not a valid quoted-string escape.',
                    $match[1]
                ));
            },
            $inner
        );
    }

    private static function assertParameterValue(string $value): string
    {
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('A media-type parameter value must be non-empty and printable.');
        }

        return $value;
    }
}
