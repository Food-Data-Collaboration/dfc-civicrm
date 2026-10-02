<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * How signing keys leave the issuer.
 *
 * ============================================================================
 * WHY THIS IS AN INTERFACE
 * ============================================================================
 * Because "how does this deployment make an HTTPS GET" is not a protocol question.
 * In the unit suite the fetcher is a scripted double, which is what makes the JWKS
 * tests hermetic: rotation, a key set that changes between two fetches, an endpoint
 * that 500s, a `Cache-Control: max-age=0` — all reproducible with no network and no
 * sleeping. In production it is {@see HttpJwksFetcher}.
 *
 * The alternative — calling `file_get_contents()` from {@see JwksCache} — would put
 * I/O inside the one class that must have a precise, testable freshness policy.
 *
 * ============================================================================
 * THE CONTRACT IS FAIL-LOUD
 * ============================================================================
 * A fetcher that cannot obtain the document throws {@see JwksUnavailableException}.
 * It must NOT return an empty {@see JwksDocument}: "I could not reach the issuer"
 * and "the issuer says it has no keys" are different facts, and only the first is
 * recoverable. {@see JwksCache} keeps the previous good set on a fetch failure
 * within its TTL — but an expired cache plus a failed fetch is a hard failure, and
 * the distinction is what decides whether the client sees 500 or 401.
 *
 * @package Civi\Dfc
 */
interface JwksFetcherInterface
{
    /**
     * Fetch and parse the key set at $uri.
     *
     * @param string $uri Absolute https URI, normally
     *                    {@see OidcEndpoints::jwksUri()}.
     *
     * @throws JwksUnavailableException when the document could not be obtained.
     * @throws \InvalidArgumentException when $uri is not an absolute https URI.
     */
    public function fetch(string $uri): JwksDocument;
}