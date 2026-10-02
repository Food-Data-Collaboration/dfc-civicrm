<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Http;

use Civi\Dfc\V2\Controller\Negotiation\MediaType;

/**
 * What one specific response is: which representation was selected, which entity
 * tag covers its bytes, and whatever per-response links and directives apply.
 *
 * Separate from {@see ResourceCapabilities} because the two answer different
 * questions and have different lifetimes. Capabilities are a property of the
 * resource and can be cached per route; a decision is produced per request, after
 * negotiation, from a representation that may not even have been fetched yet (an
 * `OPTIONS` response, a 304).
 *
 * Holding them apart also keeps {@see HeaderPolicy} honest: it can emit
 * `Content-Type` and `ETag` for a body it has not seen, which is safe, whereas it
 * could never safely invent the resource's methods.
 *
 * @package Civi\Dfc
 */
final class ResponseDecision
{
    /** Absolute URI with a scheme and no whitespace. See {@see validateLocation()}. */
    private const ABSOLUTE_URI_PATTERN = '#^[A-Za-z][A-Za-z0-9.\-]*://\S+$#';

    private readonly ?MediaType $contentType;

    private readonly ?ETag $etag;

    /** @var list<LinkRelation> */
    private readonly array $links;

    private readonly ?string $location;

    private readonly ?string $cacheControl;

    /**
     * @param list<LinkRelation> $links  Per-response links, e.g. a container's
     *                                   `next` / `prev` page. Appended after the
     *                                   capability links by {@see HeaderPolicy}.
     * @param string|null       $location An absolute URI for a redirect or a 303.
     *                                   Required by the Identity Service, whose
     *                                   contract is "303 to the user WebID subject"
     *                                   (sa-006 owns the endpoint; this is where
     *                                   its `Location` becomes a header).
     * @param string|null       $cacheControl A `Cache-Control` value. Null means
     *                                   the route has no opinion — the error
     *                                   responder sets `no-store` itself.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        ?MediaType $contentType = null,
        ?ETag $etag = null,
        array $links = [],
        ?string $location = null,
        ?string $cacheControl = null
    ) {
        foreach ($links as $position => $link) {
            if (!$link instanceof LinkRelation) {
                throw new \InvalidArgumentException(sprintf(
                    'Response links must be LinkRelation instances. Element #%d is %s.',
                    $position + 1,
                    get_debug_type($link)
                ));
            }
        }

        $this->contentType = $contentType;
        $this->etag = $etag;
        $this->links = array_values($links);
        $this->location = self::validateLocation($location);
        $this->cacheControl = self::validateCacheControl($cacheControl);
    }

    public function contentType(): ?MediaType
    {
        return $this->contentType;
    }

    public function etag(): ?ETag
    {
        return $this->etag;
    }

    /** @return list<LinkRelation> */
    public function links(): array
    {
        return $this->links;
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function cacheControl(): ?string
    {
        return $this->cacheControl;
    }

    public function withLinks(LinkRelation ...$links): self
    {
        return new self($this->contentType, $this->etag, [...$this->links, ...$links], $this->location, $this->cacheControl);
    }

    public function withEtag(ETag $etag): self
    {
        return new self($this->contentType, $etag, $this->links, $this->location, $this->cacheControl);
    }

    private static function validateLocation(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $candidate = trim($location);

        // A `Location` that is not an absolute URI is a request to make the client
        // resolve it against a base it does not have. The DFC contract's Identity
        // Service 303 points at a user WebID SUBJECT, which carries a "#me"
        // fragment — so fragments are required here, not merely tolerated.
        if (preg_match(self::ABSOLUTE_URI_PATTERN, $candidate) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A response Location must be an absolute http(s) URI. Got "%s".',
                $location
            ));
        }

        return $candidate;
    }

    private static function validateCacheControl(?string $cacheControl): ?string
    {
        if ($cacheControl === null) {
            return null;
        }

        $candidate = trim($cacheControl);

        // Deliberately narrow: only the directives this layer sets, and no
        // control characters (the usual header-injection route).
        if (preg_match('#^(?:no-store|no-cache|(?:public|private)(?:, ?max-age=\d+|, ?must-revalidate)*)$#', $candidate) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A Cache-Control value from this layer must be no-store, no-cache, or public/private with an '
                . 'optional max-age. Got "%s".',
                $cacheControl
            ));
        }

        return $candidate;
    }
}
