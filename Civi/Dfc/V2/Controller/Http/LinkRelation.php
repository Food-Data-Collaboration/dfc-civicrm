<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Http;

/**
 * One `Link` header field value, as a value object.
 *
 * ============================================================================
 * WHY A `Link` VALUE OBJECT AND NOT A STRING IN THE CAPABILITIES
 * ============================================================================
 * A capability that says "there is a type index over here" is only useful if it
 * is machine-readable. `Link: <https://.../index>; rel="type"` typed by hand in
 * two places drifts, and the drift is invisible until a WebID client cannot find
 * the index. So a link is built here, validated here, and rendered by
 * {@see HeaderPolicy} in one place.
 *
 * ============================================================================
 * WHY THERE IS NO `title` PARAMETER
 * ============================================================================
 * RFC 8288 offers `title` as free human-readable text, and it is the obvious
 * place for a description like "John's Wholesales". It is also the one field
 * that could carry a display name — i.e. personal data — into a header that gets
 * logged by every proxy in the path, on a link that is followed by machines.
 *
 * So it is omitted, not sanitised. Nothing in the DFC standard requires it, and a
 * consumer that wants a human label dereferences the resource. This is a
 * consequence of PRD-002 §9's "secrets or personal data in logs" risk applied at
 * the point where the choice is cheap.
 *
 * Note also what is NOT here: display names, and any internal identifier. A
 * `Link` target is a public URI minted by {@see \Civi\Dfc\V2\Identity\UriFactory},
 * and a `rel` is a protocol constant or a registered relation IRI. There is
 * nothing else a link may carry.
 *
 * @package Civi\Dfc
 */
final class LinkRelation
{
    /** Registered relation token, or an absolute IRI for an extension relation. */
    private const RELATION_PATTERN = '/^[A-Za-z][A-Za-z0-9.\-]{0,63}$/';

    /** A media type, no parameters. The `#` is escaped: it is this pattern's delimiter. */
    private const TYPE_PATTERN = '#^[A-Za-z0-9][A-Za-z0-9!\#$&^_.+\-]{0,126}/'
        . '[A-Za-z0-9][A-Za-z0-9!\#$&^_.+\-]{0,126}$#';

    /**
     * Absolute URI, no whitespace. A fragment is allowed (a relation IRI such as
     * `http://www.w3.org/ns/ldp#type` has one) and so is a query string (a paging
     * link has one). What is refused is anything a client would have to resolve
     * against a base it does not have.
     */
    private const ABSOLUTE_URI_PATTERN = '#^[A-Za-z][A-Za-z0-9.\-]*://\S+$#';

    /** @var list<string> */
    private readonly array $relations;

    private readonly string $target;

    private readonly ?string $type;

    /**
     * @param string|list<string> $relations One or more relation tokens. Several
     *        relations on one field value is legal (RFC 8288 §3.3) and is how a
     *        TypeIndex target is advertised without a second `Link` line.
     * @param string|null        $type      Media type of the target.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string|array $relations, string $target, ?string $type = null)
    {
        $list = is_array($relations) ? array_values($relations) : [$relations];

        if ($list === []) {
            throw new \InvalidArgumentException('A Link must carry at least one relation.');
        }

        $validated = [];
        foreach ($list as $position => $relation) {
            $candidate = trim((string) $relation);

            if (preg_match(self::RELATION_PATTERN, $candidate) === 1) {
                $validated[] = $candidate;

                continue;
            }

            if (preg_match(self::ABSOLUTE_URI_PATTERN, $candidate) === 1) {
                $validated[] = $candidate;

                continue;
            }

            throw new \InvalidArgumentException(sprintf(
                'Link relation #%d ("%s") is neither a registered relation token nor an absolute relation IRI. '
                . 'A relation must be a protocol constant; free text is not permitted here.',
                $position + 1,
                $candidate
            ));
        }

        $this->relations = $validated;
        $this->target = self::validateTarget($target);
        $this->type = self::validateType($type);
    }

    /** `rel="type"` — the DFC standard's organisation-index relation. */
    public static function typeRelation(string $target, ?string $type = null): self
    {
        return new self('type', $target, $type);
    }

    /** `rel="next"` / `rel="prev"` — container paging. */
    public static function paging(string $relation, string $target): self
    {
        return new self($relation, $target);
    }

    /** `rel="describedby"`, `rel="identity-service"`, and similar. */
    public static function named(string $relation, string $target, ?string $type = null): self
    {
        return new self($relation, $target, $type);
    }

    /** @return list<string> */
    public function relations(): array
    {
        return $this->relations;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function hasRelation(string $relation): bool
    {
        foreach ($this->relations as $candidate) {
            if (strcasecmp($candidate, $relation) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The field value: `<target>; rel="a b"; type="application/ld+json"`.
     *
     * Parameter order is fixed so the same link always renders to the same bytes.
     */
    public function toString(): string
    {
        $value = '<' . $this->target . '>; rel="' . implode(' ', $this->relations) . '"';

        if ($this->type !== null) {
            $value .= '; type="' . $this->type . '"';
        }

        return $value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private static function validateTarget(string $target): string
    {
        $candidate = trim($target);

        if (preg_match(self::ABSOLUTE_URI_PATTERN, $candidate) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A Link target must be an absolute http(s) URI. Got "%s". A relative or opaque target is not '
                . 'resolvable by a third-party client, which is the only consumer that matters for a link.',
                $target
            ));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            throw new \InvalidArgumentException('A Link target must not contain control characters.');
        }

        return $candidate;
    }

    private static function validateType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $candidate = trim($type);

        if (preg_match(self::TYPE_PATTERN, $candidate) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A Link `type` must be a bare media type such as "application/ld+json". Got "%s".',
                $type
            ));
        }

        return strtolower($candidate);
    }
}
