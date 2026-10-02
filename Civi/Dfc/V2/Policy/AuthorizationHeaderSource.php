<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Policy;

/**
 * Where the `Authorization` header value comes from.
 *
 * ============================================================================
 * WHY THIS IS AN INTERFACE RATHER THAN A SUPERGLOBAL READ
 * ============================================================================
 * Because the protocol layer must not know what a request object is. PRD-002 §2 Step 2
 * asks for "protocol abstractions testable without a CMS bootstrap", and
 * {@see \Civi\Dfc\V2\Validation\ValidationPipeline} is a set of stages that receive a
 * {@see \Civi\Dfc\V2\Validation\ValidationContext} and nothing else. A stage that
 * reached for `$_SERVER['HTTP_AUTHORIZATION']` would be untestable without an HTTP
 * request and would put the auth decision somewhere no unit test can reach.
 *
 * So the header arrives through this one-method seam, and lane-2 supplies an
 * implementation that reads it off whatever dispatcher it uses.
 *
 * ============================================================================
 * WHY IT TAKES THE REQUEST, RATHER THAN BEING CALLED ONCE AT CONSTRUCTION
 * ============================================================================
 * Because the header is per-request and this object is per-deployment. Passing the
 * method and URI also means an implementation CAN vary behaviour by request, which it
 * needs to: some surfaces are anonymous-capable, and a future implementation might
 * deliberately ignore the header on a route that must never authenticate.
 *
 * @package Civi\Dfc
 */
interface AuthorizationHeaderSource
{
    /**
     * @param string $method     The upper-cased HTTP method.
     * @param string $requestUri The absolute https request URI.
     *
     * @return string|null The raw `Authorization` field value, or null when absent.
     */
    public function headerFor(string $method, string $requestUri): ?string;
}