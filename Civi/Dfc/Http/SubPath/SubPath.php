<?php

declare(strict_types=1);

namespace Civi\Dfc\Http\SubPath;

/**
 * The sub-path that CiviCRM routed to us, with the prefix removed.
 *
 * WHY THIS EXISTS
 *
 * The DFC contract is written with OpenAPI path templates:
 *
 *     GET /organizations/{organizationId}
 *     GET /users/{userId}/webid
 *
 * and the data-storage-and-discovery specification makes PATH-HIERARCHY
 * CONTAINMENT normative:
 *
 *     "if the resource `resource` is contained in the container
 *      `https://platform.ex/container/`, this resource URI MUST be
 *      `https://platform.ex/container/resource`"
 *
 * A query string is not in the path hierarchy. Serving `/organizations?organizationId=x`
 * and listing `?organizationId=x` as an ldp:contains member therefore breaks a
 * MUST, not merely an aesthetic. So we serve real path-based URLs.
 *
 * CiviCRM makes that possible because its route table matches sub-paths. The
 * routing documentation states:
 *
 *     "One path can match all sub-paths. For example, <path>civicrm/admin</path>
 *      can match http://example.org/civicrm/admin/f/o/o/b/a/r. However, one
 *      should avoid designs which rely on this because it's imprecise and it
 *      can be difficult to integrate with some frontends."
 *
 * The imprecision is accepted here deliberately: this extension's consumers are
 * other DFC platforms, not CMS front ends, so "difficult to integrate with some
 * frontends" is not a cost this project bears. WHAT MUST BE VERIFIED on a real
 * CiviCRM box is that the sub-path reaches PHP intact and identically across
 * Drupal, WordPress and Joomla. See BLK-005.
 *
 * WHAT THIS CLASS DOES *NOT* DO
 *
 * It does not trust the sub-path. It parses it against a declared shape and
 * refuses anything that does not match exactly, because the sub-path arrives
 * from user input and is about to become part of a minted identity URI.
 */
final class SubPath
{
    private const MAX_LENGTH = 512;

    /** @var list<string> */
    private array $segments;

    private string $raw;

    /**
     * @param string $raw the sub-path, still percent-encoded as it arrived
     */
    private function __construct(string $raw, array $segments)
    {
        $this->raw = $raw;
        $this->segments = $segments;
    }

    /**
     * Build from the request path minus the route prefix.
     *
     * Rejects rather than sanitises. A path that cannot be split into clean
     * segments is refused, because this value ends up in a URI we publish as an
     * identity - a silently normalised path would mint a WebID nobody can
     * predict, and a WebID that does not match what we serve is worse than a 400.
     */
    public static function fromRequestPath(string $pathInfo, string $routePrefix): self
    {
        $raw = $pathInfo;

        // CiviCRM hands over REQUEST_URI in some CMS configurations and PATH_INFO
        // in others, so accept both shapes rather than assuming one.
        $queryAt = strpos($raw, '?');
        if ($queryAt !== false) {
            $raw = substr($raw, 0, $queryAt);
        }
        $fragmentAt = strpos($raw, '#');
        if ($fragmentAt !== false) {
            $raw = substr($raw, 0, $fragmentAt);
        }

        // Strip the scheme+host if an absolute URI was passed.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw) === 1) {
            $afterAuthority = strpos($raw, '/', strpos($raw, '://') + 3);
            $raw = $afterAuthority === false ? '/' : substr($raw, $afterAuthority);
        }

        if (strlen($raw) > self::MAX_LENGTH) {
            throw new MalformedSubPathException(
                sprintf('sub-path is %d bytes, over the %d byte ceiling', strlen($raw), self::MAX_LENGTH)
            );
        }

        // Collapse only the leading run of slashes: some CMS front ends emit
        // '//path'. Interior doubled slashes are a DIFFERENT problem and must
        // survive to parseSegments(), which refuses them - collapsing those
        // would make /users//webid and /users/webid one identity.
        $raw = '/' . ltrim($raw, '/');

        $prefix = '/' . trim($routePrefix, '/');
        $normalised = '/' . trim($raw, '/');
        if ($normalised === '/') {
            $normalised = '/';
        }

        if ($normalised !== $prefix && !str_starts_with($normalised, $prefix . '/')) {
            throw new MalformedSubPathException(sprintf(
                'sub-path "%s" does not sit under the registered route "%s"',
                $normalised,
                $prefix
            ));
        }

        $remainder = substr($normalised, strlen($prefix));

        return new self($raw, self::parseSegments($remainder));
    }

    /**
     * @return list<string> percent-decoded, non-empty segments
     */
    private static function parseSegments(string $remainder): array
    {
        $trimmed = trim($remainder, '/');
        if ($trimmed === '') {
            return [];
        }

        // A doubled slash is refused BEFORE trimming, because trim() would hide
        // it. '/users//webid' and '/users/webid' would otherwise resolve to the
        // same identity - two URLs, one WebID, which is the exact failure this
        // class exists to prevent. Only a single trailing slash is tolerated
        // (some front ends append one), and that is handled after this check.
        if (str_contains($remainder, '//')) {
            throw new MalformedSubPathException('empty path segment (doubled slash)');
        }

        $segments = [];
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '') {
                // A doubled slash: "/users//webid". Refuse rather than collapse,
                // because collapsing means two different URLs mint one identity.
                throw new MalformedSubPathException('empty path segment (doubled slash)');
            }
            $decoded = rawurldecode($segment);
            if ($decoded === '' || str_contains($decoded, '/') || str_contains($decoded, "\0")) {
                // Decoding to something containing a separator means the client
                // sent an encoded separator - a classic path-traversal attempt.
                throw new MalformedSubPathException(
                    'segment decodes to an empty value or contains a path separator'
                );
            }
            if ($decoded === '.' || $decoded === '..') {
                throw new MalformedSubPathException('relative path segment');
            }
            $segments[] = $decoded;
        }

        return $segments;
    }

    /** @return list<string> */
    public function segments(): array
    {
        return $this->segments;
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
    }

    public function raw(): string
    {
        return $this->raw;
    }

    /** The first segment, or null when the sub-path is empty. */
    public function first(): ?string
    {
        return $this->segments[0] ?? null;
    }

    public function count(): int
    {
        return count($this->segments);
    }

    /** The segment at $index, or null. */
    public function at(int $index): ?string
    {
        return $this->segments[$index] ?? null;
    }

    /**
     * Match a segment against a fixed set of known values.
     *
     * @param list<string> $allowed
     */
    public function isOneOf(int $index, array $allowed): bool
    {
        $value = $this->at($index);
        return $value !== null && in_array($value, $allowed, true);
    }

    /**
     * Match the whole sub-path against a fixed shape, e.g. ['webid'] or
     * ['organizations', '{organizationId}'].
     *
     * A '{name}' placeholder consumes exactly one segment and binds it to $out.
     *
     * @param list<string> $shape
     * @param array<string, string> $out
     */
    public function matches(array $shape, ?array &$out = null): bool
    {
        // Nullable by-ref, not `array &$out = []`: a caller that omits the
        // second argument would otherwise get "must be of type array, null
        // given", so matching a shape would require every call site to declare
        // a throwaway variable. Callers that want the bindings pass one.
        if ($out === null) {
            $out = [];
        }
        $out = [];
        if (count($shape) !== count($this->segments)) {
            return false;
        }
        foreach ($shape as $index => $expectation) {
            $actual = $this->segments[$index];
            if (str_starts_with($expectation, '{') && str_ends_with($expectation, '}')) {
                $name = substr($expectation, 1, -1);
                if ($actual === '') {
                    return false;
                }
                $out[$name] = $actual;
                continue;
            }
            if ($actual !== $expectation) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the sub-path is exactly the given literal segments.
     *
     * @param list<string> $expected
     */
    public function isExactly(array $expected): bool
    {
        return $this->segments === $expected;
    }

    /**
     * Reassemble the sub-path with each segment percent-encoded per RFC 3986.
     *
     * Used when re-emitting a URI we minted, so the bytes we publish are
     * canonical rather than whatever the client happened to send.
     *
     * @param list<string> $segments
     */
    public static function encode(array $segments): string
    {
        return implode('/', array_map(
            // rawurlencode encodes everything unreserved-safe, including '/',
            // which is exactly what we want inside one segment.
            static fn (string $s): string => rawurlencode($s),
            $segments
        ));
    }
}