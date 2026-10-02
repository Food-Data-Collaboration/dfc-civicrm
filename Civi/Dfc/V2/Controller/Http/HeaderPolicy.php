<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Http;

use Civi\Dfc\V2\Controller\Negotiation\ContentNegotiator;
use Civi\Dfc\V2\Controller\Negotiation\MediaType;

/**
 * Turns a {@see ResourceCapabilities} — plus whatever the route actually decided
 * — into the header set for a response. The single producer of `Allow`,
 * `Accept-Post`, `Accept-Patch`, `Link` and `Vary`.
 *
 * ============================================================================
 * WHY THE ROUTE SUPPLIES ETAG / CONTENT-TYPE / LINKS AND NOT THIS CLASS
 * ============================================================================
 * The division is "what the RESOURCE IS" versus "what THIS RESPONSE IS":
 *
 *   - this class owns the resource's declared shape: methods, acceptable body
 *     types, discoverable relations, the fact that the response varies by
 *     negotiation;
 *   - the route owns the per-response facts: which media type was actually
 *     selected, which entity tag covers the bytes being sent, and the container's
 *     prev/next page links, which only exist once a page has been fetched.
 *
 * So {@see describe()} takes the capabilities and an optional
 * {@see ResponseDecision} carrying the per-response facts. Everything the route
 * does not supply is simply absent — an OPTIONS response with no representation
 * gets no `Content-Type` and no `ETag`, which is correct.
 *
 * ============================================================================
 * `Vary` IS NOT OPTIONAL
 * ============================================================================
 * `Vary: Accept, Authorization` is emitted on every response this policy builds,
 * including error responses built from the same capabilities. Two responses with
 * the same URI but different `Accept` are different representations, and a shared
 * cache that is not told so will serve Turtle to a JSON-LD-only client, or a
 * fuller projection to a caller with fewer scopes. Both are information
 * disclosures caused by caching, which is exactly the class of bug PRD-002 §9
 * flags for visibility.
 *
 * ============================================================================
 * `Allow` ALWAYS APPEARS, EVEN ON A 405-ish PATH
 * ============================================================================
 * Emitted whenever capabilities are known, including for `OPTIONS` and for
 * responses to methods the resource does not support. A client that gets an
 * error for `DELETE` can then discover what *is* supported without a second
 * round trip — which is why the LDP-style `Allow` is preferred here over a bare
 * 405.
 *
 * @package Civi\Dfc
 */
final class HeaderPolicy
{
    private readonly ContentNegotiator $negotiator;

    public function __construct(?ContentNegotiator $negotiator = null)
    {
        $this->negotiator = $negotiator ?? new ContentNegotiator();
    }

    /**
     * Build the header set.
     *
     * @param ResourceCapabilities  $capabilities What the resource supports.
     * @param ResponseDecision|null $decision     What THIS response is. Null for
     *                                            a capability-only response.
     */
    public function describe(ResourceCapabilities $capabilities, ?ResponseDecision $decision = null): ResponseHeaders
    {
        $headers = ResponseHeaders::create()
            ->with('Allow', implode(', ', $capabilities->methods()))
            ->with('Vary', implode(', ', ContentNegotiator::VARY));

        // Container-only by construction: ResourceCapabilities rejects a
        // non-container that declares these, so this cannot advertise a
        // capability the resource cannot honour.
        if ($capabilities->acceptPost() !== []) {
            $headers = $headers->with(
                'Accept-Post',
                $this->negotiator->renderList($capabilities->acceptPost())
            );
        }

        if ($capabilities->acceptPatch() !== []) {
            $headers = $headers->with(
                'Accept-Patch',
                $this->negotiator->renderList($capabilities->acceptPatch())
            );
        }

        $links = $this->collectLinks($capabilities, $decision);

        if ($links !== []) {
            $headers = $headers->with(
                'Link',
                ...array_map(static fn (LinkRelation $link): string => $link->toString(), $links)
            );
        }

        if ($decision !== null) {
            if ($decision->contentType() !== null) {
                $headers = $headers->with('Content-Type', $decision->contentType()->toString());
            }

            if ($decision->etag() !== null) {
                $headers = $headers->with('ETag', $decision->etag()->value());
            }

            if ($decision->location() !== null) {
                $headers = $headers->with('Location', $decision->location());
            }

            if ($decision->cacheControl() !== null) {
                $headers = $headers->with('Cache-Control', $decision->cacheControl());
            }
        }

        return $headers;
    }

    /**
     * Capability links first, then response links, each group in declaration
     * order and de-duplicated by rendered value.
     *
     * Stable ordering is the point: it makes `Link` assertable in tests and makes
     * a capability change visible as a diff rather than as a reshuffle.
     *
     * @return list<LinkRelation>
     */
    private function collectLinks(ResourceCapabilities $capabilities, ?ResponseDecision $decision): array
    {
        $links = $capabilities->links();

        if ($decision !== null) {
            $links = [...$links, ...$decision->links()];
        }

        $seen = [];
        $unique = [];

        foreach ($links as $link) {
            $key = $link->toString();
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $link;
        }

        return $unique;
    }

    /**
     * Build the decision for a response that carries a selected representation.
     */
    public function respond(
        ResourceCapabilities $capabilities,
        MediaType $contentType,
        ?ETag $etag = null,
        array $links = []
    ): ResponseDecision {
        return new ResponseDecision($contentType, $etag, $links);
    }
}
