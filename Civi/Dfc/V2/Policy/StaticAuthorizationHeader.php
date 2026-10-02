<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Policy;

/**
 * A fixed `Authorization` header, or its absence.
 *
 * ============================================================================
 * WHAT THIS IS FOR
 * ============================================================================
 * Two audiences, both legitimate:
 *
 *   - **Tests**, which need to say "this request carries `Bearer x`" without building
 *     an HTTP request. That is the majority of the uses in
 *     {@see \Civi\Dfc\V2\Security\BearerAuthenticatorTest}.
 *   - **Deployment wiring**, for a route that knows its own credential — which is
 *     unusual, and {@see required()} says so plainly.
 *
 * A CMS-backed implementation belongs in lane-2, next to the dispatcher that has a
 * request object to read. It implements this interface and nothing else changes.
 *
 * ============================================================================
 * WHY IT VALIDATES NOTHING
 * ============================================================================
 * Because it is not the place validation happens: {@see \Civi\Dfc\V2\Security\BearerTokenExtractor}
 * owns the RFC 6750 grammar, and a header that this class rejects and the extractor
 * would have rejected differently would create two answers to one question. This
 * class holds the string.
 *
 * @package Civi\Dfc
 */
final class StaticAuthorizationHeader implements AuthorizationHeaderSource
{
    private readonly ?string $header;

    private function __construct(?string $header)
    {
        $this->header = $header;
    }

    /**
     * No `Authorization` header at all. The anonymous case.
     */
    public static function absent(): self
    {
        return new self(null);
    }

    public static function of(string $header): self
    {
        return new self($header);
    }

    /**
     * A `Bearer` header for the given compact JWS.
     *
     * Convenience for tests and for documentation examples; the grammar is still
     * checked by the extractor at the point of use.
     */
    public static function bearer(string $token): self
    {
        return new self('Bearer ' . $token);
    }

    public function headerFor(string $method, string $requestUri): ?string
    {
        return $this->header;
    }

    public function isPresent(): bool
    {
        return $this->header !== null && trim($this->header) !== '';
    }
}