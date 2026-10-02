<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Http;

use Civi\Dfc\V2\Controller\Negotiation\MediaType;

/**
 * What one DFC resource supports: its methods, its representations, its
 * discoverable links, and whether it is an LDP container.
 *
 * ============================================================================
 * WHY CAPABILITIES ARE DECLARED RATHER THAN INFERRED
 * ============================================================================
 * `Allow`, `Accept-Post`, `Accept-Patch` and `Link` are four different views of
 * one fact set. Computing them independently at four call sites is how they
 * disagree — a container that advertises `POST` in `Allow` but no `Accept-Post`,
 * or a resource whose `Link` mentions an `index` it does not serve. Declaring
 * the facts once and deriving all four in {@see HeaderPolicy} makes disagreement
 * structurally impossible.
 *
 * It also makes authorisation decisions answerable before any data is touched:
 * a route that has the capabilities knows whether a write is even offered, which
 * is the question a 403 has to answer.
 *
 * ============================================================================
 * `Accept-Post` / `Accept-Patch` ARE CONTAINER-ONLY, AND ENFORCED
 * ============================================================================
 * The LDP specification introduces these on containers. Declaring them on a
 * non-container would advertise a capability the resource cannot honour, and a
 * client that trusted it would send a body that gets a 415 for no stated reason.
 * The constructor therefore REJECTS the combination rather than silently
 * dropping it: the alternative is a capability list that lies about itself, and a
 * list that lies about itself cannot be used to drive client behaviour.
 *
 * ============================================================================
 * WHAT IS DELIBERATELY NOT HERE
 * ============================================================================
 * No authorisation. A capability says what a resource *offers*, not who may use
 * it; "who" is sa-011's scope-to-permission mapping and depends on the caller's
 * token, so folding it in would make these objects per-request and untestable.
 * The route intersects capabilities with permissions and reports 403 through the
 * error model.
 *
 * @package Civi\Dfc
 */
final class ResourceCapabilities
{
    /**
     * Canonical `Allow` ordering, following RFC 9110 §9.1's own listing.
     *
     * RFC 9110 does not mandate an order, but a deterministic one is worth
     * having: it makes header assertions in tests exact rather than
     * set-comparisons, and it means a capability difference shows up as a diff
     * instead of as a reordering.
     */
    private const METHOD_ORDER = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private const METHOD_PATTERN = '/^[A-Z][A-Z0-9!#$%&\'*+.^_`|~\-]{0,31}$/';

    /** @var list<string> */
    private readonly array $methods;

    /** @var list<MediaType> */
    private readonly array $responseMediaTypes;

    /** @var list<MediaType> */
    private readonly array $acceptPost;

    /** @var list<MediaType> */
    private readonly array $acceptPatch;

    /** @var list<LinkRelation> */
    private readonly array $links;

    private readonly bool $container;

    /**
     * @param list<string>       $methods            HTTP methods, any order.
     * @param list<MediaType>    $responseMediaTypes On offer, most preferred
     *                                                FIRST. MUST start with
     *                                                `application/ld+json` for a
     *                                                DFC resource: it is the one
     *                                                serialisation the contract
     *                                                requires.
     * @param list<MediaType>    $acceptPost         Request-body types accepted by
     *                                                POST. Containers only.
     * @param list<MediaType>    $acceptPatch        Request-body types accepted by
     *                                                PATCH. Containers only.
     * @param list<LinkRelation> $links              Discoverable relations.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array $methods,
        array $responseMediaTypes,
        bool $container = false,
        array $acceptPost = [],
        array $acceptPatch = [],
        array $links = []
    ) {
        if ($responseMediaTypes === []) {
            throw new \InvalidArgumentException(
                'A resource must offer at least one response media type. A capability set that promises no '
                . 'representation describes a route that cannot work.'
            );
        }

        if (!$container && ($acceptPost !== [] || $acceptPatch !== [])) {
            throw new \InvalidArgumentException(sprintf(
                'Accept-Post/Accept-Patch are LDP CONTAINER features. This resource is not a container but '
                . 'declares %d POST and %d PATCH media type(s). Remove them, or mark the resource as a '
                . 'container if it really is one.',
                count($acceptPost),
                count($acceptPatch)
            ));
        }

        $this->methods = self::canonicaliseMethods($methods);
        $this->responseMediaTypes = self::validateMediaTypes($responseMediaTypes, 'response');
        $this->container = $container;
        $this->acceptPost = self::validateMediaTypes($acceptPost, 'Accept-Post');
        $this->acceptPatch = self::validateMediaTypes($acceptPatch, 'Accept-Patch');
        $this->links = self::validateLinks($links);
    }

    /**
     * A read-only LDP Basic Container: `GET`, `HEAD`, `OPTIONS`.
     *
     * The common case — the organisation container a WebID's TypeIndex points at.
     */
    public static function readOnlyContainer(array $links = [], ?array $responseMediaTypes = null): self
    {
        return new self(
            ['GET', 'HEAD', 'OPTIONS'],
            $responseMediaTypes ?? [MediaType::jsonLd(), MediaType::turtle()],
            true,
            [],
            [],
            $links
        );
    }

    /**
     * A writable LDP Basic Container: adds `POST` and `PATCH`, which is what makes
     * `Accept-Post` meaningful.
     *
     * @param list<MediaType> $acceptPost Defaults to JSON-LD only. `application/json`
     *                                     is deliberately NOT included; see
     *                                     {@see MediaType::JSON}.
     */
    public static function writableContainer(
        array $links = [],
        ?array $acceptPost = null,
        array $acceptPatch = [],
        ?array $responseMediaTypes = null
    ): self {
        return new self(
            ['GET', 'HEAD', 'OPTIONS', 'POST', 'PATCH'],
            $responseMediaTypes ?? [MediaType::jsonLd(), MediaType::turtle()],
            true,
            $acceptPost ?? [MediaType::jsonLd()],
            $acceptPatch,
            $links
        );
    }

    /**
     * A non-container DFC resource — a WebID profile, a semantic resource, the
     * `index` document.
     */
    public static function document(array $methods, array $links = [], ?array $responseMediaTypes = null): self
    {
        return new self(
            $methods,
            $responseMediaTypes ?? [MediaType::jsonLd(), MediaType::turtle()],
            false,
            [],
            [],
            $links
        );
    }

    public function isContainer(): bool
    {
        return $this->container;
    }

    /** @return list<string> Upper-case, in canonical order. */
    public function methods(): array
    {
        return $this->methods;
    }

    public function allows(string $method): bool
    {
        return in_array(strtoupper(trim($method)), $this->methods, true);
    }

    /** @return list<MediaType> */
    public function responseMediaTypes(): array
    {
        return $this->responseMediaTypes;
    }

    /** @return list<MediaType> Always empty on a non-container. */
    public function acceptPost(): array
    {
        return $this->acceptPost;
    }

    /** @return list<MediaType> Always empty on a non-container. */
    public function acceptPatch(): array
    {
        return $this->acceptPatch;
    }

    /** @return list<LinkRelation> */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * A copy with one more discoverable relation.
     *
     * Link relations are additive, so this is the only mutator a route needs and
     * it returns a new object rather than changing this one.
     */
    public function withLink(LinkRelation $link): self
    {
        return new self(
            $this->methods,
            $this->responseMediaTypes,
            $this->container,
            $this->acceptPost,
            $this->acceptPatch,
            [...$this->links, $link]
        );
    }

    /**
     * @param list<string> $methods
     *
     * @return list<string>
     */
    private static function canonicaliseMethods(array $methods): array
    {
        if ($methods === []) {
            throw new \InvalidArgumentException(
                'A resource must support at least one HTTP method. A capability set with an empty Allow is not '
                . 'a description of anything.'
            );
        }

        $collected = [];

        foreach ($methods as $position => $method) {
            $candidate = strtoupper(trim((string) $method));

            if (preg_match(self::METHOD_PATTERN, $candidate) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Method #%d ("%s") is not an HTTP method token.',
                    $position + 1,
                    (string) $method
                ));
            }

            $collected[$candidate] = true;
        }

        $ordered = [];
        foreach (self::METHOD_ORDER as $method) {
            if (isset($collected[$method])) {
                $ordered[] = $method;
                unset($collected[$method]);
            }
        }

        // Anything outside the canonical order (an extension method, or a future
        // RFC) still gets through, sorted, so a capability set is never silently
        // truncated by a table that has not been updated.
        $remainder = array_keys($collected);
        sort($remainder, SORT_STRING);

        return [...$ordered, ...$remainder];
    }

    /**
     * @param list<MediaType> $mediaTypes
     *
     * @return list<MediaType>
     */
    private static function validateMediaTypes(array $mediaTypes, string $role): array
    {
        $validated = [];
        $seen = [];

        foreach ($mediaTypes as $position => $mediaType) {
            if (!$mediaType instanceof MediaType) {
                throw new \InvalidArgumentException(sprintf(
                    'The %s media type list must contain MediaType instances. Element #%d is %s.',
                    $role,
                    $position + 1,
                    get_debug_type($mediaType)
                ));
            }

            if ($mediaType->isWildcard()) {
                throw new \InvalidArgumentException(sprintf(
                    'The %s media type list must be concrete. Element #%d is the wildcard "%s".',
                    $role,
                    $position + 1,
                    $mediaType->essence()
                ));
            }

            $essence = $mediaType->essence();
            if (isset($seen[$essence])) {
                continue;
            }

            $seen[$essence] = true;
            $validated[] = $mediaType;
        }

        return $validated;
    }

    /**
     * @param list<LinkRelation> $links
     *
     * @return list<LinkRelation>
     */
    private static function validateLinks(array $links): array
    {
        $validated = [];
        $seen = [];

        foreach ($links as $position => $link) {
            if (!$link instanceof LinkRelation) {
                throw new \InvalidArgumentException(sprintf(
                    'The Link list must contain LinkRelation instances. Element #%d is %s.',
                    $position + 1,
                    get_debug_type($link)
                ));
            }

            // Exact-duplicate links are collapsed; two links to the same target
            // with DIFFERENT relations are kept, because that is a legitimate way
            // to advertise one resource under several relations.
            $key = $link->toString();
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $validated[] = $link;
        }

        return $validated;
    }
}
