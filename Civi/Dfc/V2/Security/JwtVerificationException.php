<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * Marker for every failure this namespace raises while establishing that a
 * presented credential is genuine.
 *
 * ============================================================================
 * WHY A MARKER INTERFACE RATHER THAN A BASE CLASS
 * ============================================================================
 * Callers need one question answered, not a taxonomy: "was this failure about the
 * token, or about something else?" {@see BearerAuthenticator} answers it by
 * catching this interface and mapping it to 401, and lets everything else fall
 * through to {@see \Civi\Dfc\V2\Controller\Error\ErrorMapper}'s 500. A base class
 * would force an inheritance hierarchy across a namespace that owns no other
 * exceptions, and would tempt a catch block into catching a specific subclass and
 * re-deriving a status — the per-controller authorisation decision PRD-002 §9
 * flags as a medium/high risk.
 *
 * The split that actually matters, and which is *not* expressed by this marker:
 *
 *   - {@see JwtDecodeException}        the token was rejected. 401, `invalid_token`.
 *   - {@see JwksUnavailableException}  the signing keys could not be obtained.
 *                                      500. The client's token may be perfectly
 *                                      fine; this server simply cannot check it.
 *
 * Conflating those two is the single most common way an OIDC resource server
 * turns an IdP outage into a wave of "your token is invalid" support tickets.
 *
 * @package Civi\Dfc
 *
 * @see \Civi\Dfc\V2\Controller\Error\ErrorCode::AUTHENTICATION_UNUSABLE
 */
interface JwtVerificationException extends \Throwable
{
}