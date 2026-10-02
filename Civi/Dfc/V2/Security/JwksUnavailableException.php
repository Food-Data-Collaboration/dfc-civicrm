<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The signing keys could not be obtained or refreshed.
 *
 * ============================================================================
 * WHY THIS IS NOT A {@see JwtDecodeException}
 * ============================================================================
 * Because the two produce different answers for the client, and answering them
 * the same way is how an IdP outage becomes a mass credential rejection. A token
 * presented while the JWKS endpoint is unreachable is not *invalid*: this server
 * has no way to know, so it must not claim to.
 *
 *  - {@see JwtDecodeException} -> 401 `invalid_token`. Retry with a fresh token.
 *  - this                          -> 500 `internal_error`. Retry the same token
 *                                     later; it may well be fine.
 *
 * `ErrorCode` has no 503 — sa-014 owns it (see {@see ErrorCode}'s docblock on
 * statuses deliberately absent) — so 500 is what this surfaces as. That is a
 * deliberate limitation, recorded here rather than hidden.
 *
 * The distinction is also what makes the JWKS cache worth having: with a bounded
 * TTL and a cooldown on forced refreshes, a short IdP blip is served from cache
 * and produces no failures at all.
 *
 * @package Civi\Dfc
 */
final class JwksUnavailableException extends \RuntimeException implements JwtVerificationException
{
}