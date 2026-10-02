<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Http;

/**
 * An HTTP entity tag, and the only thing in this layer allowed to compute one.
 *
 * ============================================================================
 * WHY ETAGS ARE GENERATED FROM BYTES AND NOT FROM A RECORD VERSION
 * ============================================================================
 * The obvious design is "ETag = the contact's `last_modified` timestamp" or "=
 * `version`". Both are wrong for a JSON-LD surface, and for the same reason: an
 * ETag is a statement about a *representation*, and the representation is a
 * projection. Visibility policy (PRD-002 §9: "Export visibility is an explicit
 * allow-list per field/predicate"), field ordering and the negotiated media type
 * all change the bytes without the underlying record changing. A version-based
 * tag would either change when nothing changed, or — worse, if someone "fixes"
 * that by folding the policy into it — require recomputing it on every field
 * change, which is the projection cost this design exists to avoid.
 *
 * Hashing the exact bytes is the only construction that cannot lie: if the tag
 * is the same, the bytes are the same.
 *
 * ============================================================================
 * STRONG VS WEAK
 * ============================================================================
 * A **strong** tag (no `W/`) promises byte-for-byte equality, and is what
 * `If-Match` compares with. {@see Ldp\ContainerPage} and
 * {@see Ldp\LdpBasicContainer} can produce it, because their serialisation is
 * canonical. A **weak** tag (`W/`) promises only semantic equivalence and is what
 * a resource whose projection is not byte-stable must use — sa-006's WebID
 * profiles and TypeIndexes are the likely cases. {@see weak()} exists for those
 * and, deliberately, `If-Match` will never accept one (RFC 9110 §8.8.3.2 strong
 * comparison).
 *
 * @see \Civi\Dfc\V2\Controller\Ldp\LdpBasicContainer — one strong-tag producer
 * @see \Civi\Dfc\V2\Controller\Http\IfMatchEvaluator — the only consumer
 *
 * @package Civi\Dfc
 */
final class ETag
{
    private const PREFIX_STRONG = '"';
    private const PREFIX_WEAK = 'W/"';

    /**
     * 32 hex characters of SHA-256, i.e. 128 bits.
     *
     * Full 256 bits would be indistinguishable in a header and cost 32 more
     * bytes per response; 128 bits makes an accidental collision in a cache-key
     * space of realistic DFC size impossible (birthday bound ~2^64 entries).
     */
    private const DIGEST_LENGTH = 32;

    /** RFC 9110 §8.8.3: `etagc = %x21 / %x23-7E`, i.e. no DQUOTE inside. */
    private const OPAQUE_PATTERN = '/^[\x21\x23-\x7E]+$/';

    private readonly string $opaque;

    private readonly bool $weak;

    private function __construct(string $opaque, bool $weak)
    {
        $this->opaque = $opaque;
        $this->weak = $weak;
    }

    /**
     * A strong tag over the exact representation bytes.
     *
     * @param string $representation The bytes that will actually be sent. Send
     *                               exactly this string; recomputing the ETag
     *                               from a re-encoded array is how a proxy ends
     *                               up caching a tag that does not describe the
     *                               body it stored.
     */
    public static function strong(string $representation): self
    {
        return new self(self::digest($representation), false);
    }

    /**
     * A weak tag: same resource, not necessarily same bytes.
     *
     * @param string $stableIdentity Something that changes iff the resource's
     *                               MEANING changes — a projection version, not a
     *                               timestamp of the last write.
     */
    public static function weak(string $stableIdentity): self
    {
        return new self(self::digest($stableIdentity), true);
    }

    /**
     * Parse an entity tag from a header or from an `If-Match` list element.
     *
     * @throws \InvalidArgumentException when it is not an entity tag. Callers on
     *         the request side must decide what to do with that; they do not get
     *         a lenient "best effort" parse, because a lenient parse of an
     *         `If-Match` silently weakens the precondition it exists to enforce.
     */
    public static function fromHeaderValue(string $value): self
    {
        $candidate = trim($value);

        if (strncasecmp($candidate, 'W/', 2) === 0) {
            // `W/` is exactly two bytes in RFC 9110, but a client that lowercases it
            // has not changed its intent, so the indicator is matched case
            // insensitively. The tag body stays case-sensitive.
            $opaque = substr($candidate, 2);
            $weak = true;
        } elseif (str_starts_with($candidate, '"')) {
            $opaque = $candidate;
            $weak = false;
        } else {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not an entity tag. An entity tag is DQUOTE opaque DQUOTE, optionally prefixed with '
                . 'the weak indicator W/.',
                $value
            ));
        }

        if (!str_ends_with($opaque, '"') || strlen($opaque) < 3) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a complete entity tag.', $value));
        }

        $body = substr($opaque, 1, -1);

        if (preg_match(self::OPAQUE_PATTERN, $body) !== 1) {
            throw new \InvalidArgumentException(
                'An entity tag may not contain a double quote or a control character in its opaque part.'
            );
        }

        return new self($body, $weak);
    }

    public function isWeak(): bool
    {
        return $this->weak;
    }

    /** The tag without quotes or weak indicator: the part that is compared. */
    public function opaque(): string
    {
        return $this->opaque;
    }

    /** The complete header field value, e.g. `"sha256-…"`. */
    public function value(): string
    {
        return ($this->weak ? self::PREFIX_WEAK : self::PREFIX_STRONG) . $this->opaque . '"';
    }

    /**
     * RFC 9110 §8.8.3.2 strong comparison.
     *
     * Weak tags never match: they explicitly decline to promise byte equality,
     * and `If-Match` is asking exactly that question.
     */
    public function equalsStrong(self $other): bool
    {
        return !$this->weak && !$other->weak && hash_equals($this->opaque, $other->opaque);
    }

    public function equals(self $other): bool
    {
        return $this->weak === $other->weak && hash_equals($this->opaque, $other->opaque);
    }

    public function __toString(): string
    {
        return $this->value();
    }

    /**
     * Split an `If-Match` field value into entity tags, or `["*"]`.
     *
     * ============================================================================
     * A KNOWN IMPERFECTION, STATED RATHER THAN HIDDEN
     * ============================================================================
     * RFC 9110's `entity-tag` grammar admits `,` inside the opaque part
     * (`etagc` is `%x21 / %x23-7E`, and 0x2C is in that range). Splitting on
     * every comma therefore mis-splits a tag whose opaque part contains one —
     * which this class never produces, since its digests are `[0-9a-f]` prefixed
     * by `sha256-`. Splitting on commas *followed by an optional `W/` or `"`*
     * The split is at commas that are FOLLOWED by the start of another tag
     * (optional whitespace, optional `W/`, a quote), so the opening quote of each
     * tag survives. Splitting on every comma would mis-split a tag whose opaque part
     * contains one; splitting on the lookahead is exact for every tag this layer
     * mints and degrades gracefully for foreign ones. A fully general parser would
     * need a quote-aware scanner; it is not worth the complexity here, and the note
     * is in the file so nobody later mistakes this for exact.
     *
     * @return list<string> Raw field values; `*` on its own is the literal `"*"`.
     *
     * @throws \InvalidArgumentException when an element is not an entity tag.
     */
    public static function splitIfMatch(string $fieldValue): array
    {
        $trimmed = trim($fieldValue);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('An If-Match field value must not be empty when present.');
        }

        if ($trimmed === '*') {
            return ['*'];
        }

        $parts = preg_split('#,(?=\s*(?:W/)?")#', $trimmed);

        if ($parts === false || $parts === []) {
            throw new \InvalidArgumentException('An If-Match field value must be "*" or a list of entity tags.');
        }

        $tags = [];
        foreach ($parts as $part) {
            $candidate = trim($part);
            if ($candidate === '') {
                continue;
            }

            // Each element is validated here rather than by the caller. A split that
            // returned junk would let a caller mistake "the list had a comma in it"
            // for "the list was empty", and a lenient parse of `If-Match` silently
            // weakens the precondition it exists to enforce.
            self::fromHeaderValue($candidate);

            $tags[] = $candidate;
        }

        if ($tags === []) {
            throw new \InvalidArgumentException('An If-Match field value must contain at least one entity tag.');
        }

        return $tags;
    }

    private static function digest(string $material): string
    {
        // The `sha256-` label is inside the opaque part, so it is part of the tag.
        // A future change of digest function therefore produces a different tag
        // rather than silently colliding with tags minted under the old one.
        return 'sha256-' . substr(hash('sha256', $material), 0, self::DIGEST_LENGTH);
    }
}
