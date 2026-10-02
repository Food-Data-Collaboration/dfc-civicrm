<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

use Civi\Dfc\V2\Controller\Error\AuthenticateChallenge;
use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ProtocolError;

/**
 * The entry point: `Authorization` header in, {@see AuthenticatedPrincipal} out or
 * a {@see DfcApiException}.
 *
 * ============================================================================
 * THIS IS THE "CENTRAL AUTHENTICATION SERVICE" PRD-002 §9 ASKS FOR
 * ============================================================================
 * §9's medium/high risk is "OIDC validation done per-controller instead of
 * centrally". A controller holds no algorithm allow-list, no JWKS and no clock, so
 * it cannot validate a token; it can only call this. The three collaborators it is
 * given are exactly the three things a controller must not own:
 * {@see BearerTokenExtractor} (the grammar), {@see AccessTokenValidator} (the
 * policy) and {@see ScopePermissionRegistry} (the meaning).
 *
 * ============================================================================
 * THE 401 / 500 SPLIT IS DONE HERE, NOT DEFERRED
 * ============================================================================
 *  - a rejected token -> 401 `authentication_unusable` with `error="invalid_token"`;
 *  - signing keys unobtainable -> 500 `internal_error`, with NO challenge.
 *
 * The second is the one that is easy to get wrong and expensive to get wrong: an
 * IdP outage presented as "your token is invalid" produces a support queue full of
 * users refreshing tokens against a server that was never the problem. See
 * {@see JwksUnavailableException}.
 *
 * ============================================================================
 * `optional()` STILL REJECTS A BAD CREDENTIAL
 * ============================================================================
 * The anonymous-capable surfaces — a public WebID read, a public platform profile —
 * must not 401 a request that carries no header. But a request that DOES carry a
 * header has made a claim, and silently ignoring a claim that cannot be verified is
 * how "this resource is public" turns into "this resource is public unless you hold
 * a token, in which case your token is not checked". {@see optional()} therefore
 * returns null only for an absent header and rejects everything else exactly as
 * {@see authenticate()} does.
 *
 * @package Civi\Dfc
 */
final class BearerAuthenticator
{
    private readonly BearerTokenExtractor $extractor;

    private readonly AccessTokenValidator $validator;

    private readonly ScopePermissionRegistry $registry;

    public function __construct(
        BearerTokenExtractor $extractor,
        AccessTokenValidator $validator,
        ScopePermissionRegistry $registry
    ) {
        $this->extractor = $extractor;
        $this->validator = $validator;
        $this->registry = $registry;
    }

    /**
     * Authenticate, or throw 401 / 500.
     *
     * @param string|null $authorizationHeader Raw `Authorization` field value, or
     *                                         null when absent.
     *
     * @throws DfcApiException
     */
    public function authenticate(?string $authorizationHeader): AuthenticatedPrincipal
    {
        $token = $this->extractor->extract($authorizationHeader);

        try {
            $claims = $this->validator->validate($token->value());
        } catch (JwtDecodeException $rejected) {
            throw DfcApiException::of(ProtocolError::authenticationUnusable(
                AuthenticateChallenge::bearer($this->extractor->realm(), 'invalid_token')
            ));
        } catch (JwksUnavailableException $keysUnavailable) {
            // NOT a 401. This server cannot say the token is bad; it can only say it
            // cannot check it. `invalid_token` here would be a lie, and a useful one
            // for an attacker timing IdP outages.
            throw DfcApiException::of(ProtocolError::internalError());
        }

        return new AuthenticatedPrincipal($claims, $this->registry->grantsFor($claims));
    }

    /**
     * Authenticate if a credential was presented; null if none was.
     *
     * @throws DfcApiException when a credential WAS presented and could not be used.
     */
    public function optional(?string $authorizationHeader): ?AuthenticatedPrincipal
    {
        if ($this->extractor->isAbsent($authorizationHeader)) {
            return null;
        }

        return $this->authenticate($authorizationHeader);
    }

    /**
     * The RFC 6750 challenge for a 403 on this interface.
     *
     * Provided so a route that wants to describe what was missing can build the same
     * challenge the 401 path uses — but see {@see ScopePermissionRegistry::scopeHintsFor()}
     * for why it cannot be ATTACHED to a 403:
     * {@see \Civi\Dfc\V2\Controller\Error\ProtocolError} only permits a
     * `WWW-Authenticate` on a 401.
     */
    public function insufficientScopeChallenge(): AuthenticateChallenge
    {
        return AuthenticateChallenge::bearer($this->extractor->realm(), 'insufficient_scope');
    }
}