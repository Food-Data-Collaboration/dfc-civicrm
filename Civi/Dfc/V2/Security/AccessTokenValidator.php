<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The one place a bearer token becomes an identity.
 *
 * ============================================================================
 * WHY THIS EXISTS AS A SINGLE CLASS
 * ============================================================================
 * PRD-002 §9 lists "OIDC validation done per-controller instead of centrally" as a
 * MEDIUM likelihood / HIGH impact risk, and this is the answer to it: a controller
 * cannot decide whether a token is acceptable, because it does not hold the
 * algorithm allow-list, the issuer, the audience, the JWKS or the clock. It gets
 * either an {@see AccessTokenClaims} or a {@see JwtDecodeException}.
 *
 * ============================================================================
 * THE ORDER OF THE CHECKS, AND WHY IT IS THIS ORDER
 * ============================================================================
 * Each step is placed so that the cheapest and most decisive check comes first and
 * no expensive or externally-visible operation happens before the request has been
 * shown to deserve it:
 *
 *   1. PARSE THE HEADER. Needed for `kid` and `alg`; no crypto.
 *   2. ALGORITHM ALLOW-LIST. Before any key lookup, so an `alg: none` or `HS256`
 *      token never causes a JWKS fetch. This ordering is a DoS control as much as a
 *      security one, and it is also why the allow-list is checked here and not only
 *      inside the decoder: the decoder's check happens *after* a key is selected.
 *   3. KEY SELECTION, refreshing once for an unknown `kid`. The only step that may
 *      touch the network.
 *   4. SIGNATURE, via the decoder with the PEM for the selected key.
 *   5. TOKEN PROFILE. Only now can the claims be trusted enough to look for the
 *      ID-token markers.
 *   6. REQUIRED CLAIMS, then ISSUER, then AUDIENCE (+ `azp`), then the TIME claims.
 *
 * Time is last because an `exp` comparison is meaningless until the issuer, the
 * audience and the signature are settled: an unsigned token with a long `exp` must
 * not be reported to the client as "expired", because that would tell an attacker
 * which part of their forgery to fix.
 *
 * ============================================================================
 * EXACT MATCHING, NOT NORMALISED MATCHING
 * ============================================================================
 * `iss` is compared with `!==` against {@see OidcEndpoints::issuer()}. Not
 * case-folded, not trailing-slash-tolerant, not prefix-matched. RFC 8414 §3.3
 * defines the issuer identifier as an exact string, and every relaxation of that
 * comparison is a mix-up attack waiting to happen — including the one where two
 * realms differ only by a trailing slash.
 *
 * `aud` is a CONTAINMENT test, because an access token legitimately names several
 * audiences; but with {@see OidcClientConfig::enforcesAuthorizedParty()} on, an
 * `azp` is then required whenever more than one audience is present, which is what
 * RFC 8725 §3.1 asks for.
 *
 * ============================================================================
 * CLOCK SKEW IS A DURATION, NOT A DISABLE
 * ============================================================================
 * `exp` is accepted while `now - skew < exp`; `nbf` and `iat` are accepted while
 * `nbf <= now + skew`. A token whose `nbf` is far in the future is therefore
 * rejected even though the skew allowance exists — the allowance is 30 seconds by
 * default and is bounded at 600 by {@see OidcClientConfig}.
 *
 * @package Civi\Dfc
 *
 * @see TokenProfile — why an ID token is refused, and by which three signals
 * @see JwksCache — refresh-on-unknown-kid and the one-retry rule
 */
final class AccessTokenValidator
{
    private readonly JwtDecoderInterface $decoder;

    private readonly JwksCache $keys;

    private readonly OidcEndpoints $endpoints;

    private readonly OidcClientConfig $config;

    private readonly ClockInterface $clock;

    public function __construct(
        JwtDecoderInterface $decoder,
        JwksCache $keys,
        OidcEndpoints $endpoints,
        OidcClientConfig $config,
        ClockInterface $clock
    ) {
        $this->decoder = $decoder;
        $this->keys = $keys;
        $this->endpoints = $endpoints;
        $this->config = $config;
        $this->clock = $clock;
    }

    /**
     * Validate a compact JWS and return its claims.
     *
     * @param string $jwt The bearer token, already extracted from the
     *                    `Authorization` header by {@see BearerTokenExtractor}.
     *
     * @throws JwtDecodeException       when the token is rejected. 401.
     * @throws JwksUnavailableException when the signing keys could not be obtained.
     *                                  500 — the token's validity is unknown.
     */
    public function validate(string $jwt): AccessTokenClaims
    {
        $header = $this->decoder->header($jwt);

        $algorithm = $header['alg'] ?? null;
        if (!is_string($algorithm) || $algorithm === '') {
            throw new JwtDecodeException('The token header declares no "alg", so there is nothing to check.');
        }

        // Before any key lookup: see the class docblock. This is also the check the
        // decoder cannot make for us, because the decoder runs after a key is chosen.
        if (!$this->config->allowsAlgorithm($algorithm)) {
            throw new JwtDecodeException(sprintf(
                'The token is signed with "alg: %s", which this deployment does not accept. Accepted: %s.',
                $algorithm,
                implode(', ', $this->config->allowedAlgorithms())
            ));
        }

        $kid = $header['kid'] ?? null;
        if ($kid !== null && !is_string($kid)) {
            throw new JwtDecodeException('The token header carries a "kid" that is not a string.');
        }

        $key = $this->keys->signingKey($kid, $algorithm);

        $claims = $this->decoder->decode($jwt, $key->publicKeyPem(), $algorithm);

        $this->config->tokenProfile()->assertAccessToken($header, $claims);

        return $this->toClaims($claims);
    }

    /**
     * Every claim-level check, in the order the class docblock gives.
     *
     * @param array<string, mixed> $claims Signature-verified.
     *
     * @throws JwtDecodeException
     */
    private function toClaims(array $claims): AccessTokenClaims
    {
        foreach ($this->config->tokenProfile()->requiredClaims() as $required) {
            // A `null` value counts as absent, not as "present but null". Both are
            // unusable, and the client-facing remedy is identical, so they share one
            // rejection — and the check cannot be satisfied by a claim explicitly set
            // to null.
            if (!array_key_exists($required, $claims) || $claims[$required] === null) {
                throw new JwtDecodeException(sprintf(
                    'The token has no "%s" claim. A token missing a mandatory access-token claim cannot be '
                    . 'evaluated, and RFC 9068 requires it to be rejected rather than guessed at.',
                    $required
                ));
            }
        }

        $issuer = $claims['iss'];
        if (!is_string($issuer) || $issuer === '') {
            throw new JwtDecodeException('The token\'s "iss" claim is not a non-empty string.');
        }

        if ($issuer !== $this->endpoints->issuer()) {
            // No truncation, no "starts with". Exact, per RFC 8414 §3.3.
            throw new JwtDecodeException(
                'The token was issued by a different issuer than this server is configured for. The issuer is '
                . 'not echoed back: it is attacker-influenced text and this message reaches a log.'
            );
        }

        $subject = $claims['sub'];
        if (!is_string($subject) || $subject === '') {
            throw new JwtDecodeException('The token\'s "sub" claim is not a non-empty string.');
        }

        $audiences = $this->readAudiences($claims['aud']);
        $this->assertAudienceContainment($audiences, $claims);

        $expiresAt = $this->readInstant($claims['exp'], 'exp', required: true);
        $notBefore = $this->readOptionalInstant($claims, 'nbf');
        $issuedAt = $this->readOptionalInstant($claims, 'iat');

        $this->assertTimeClaims($expiresAt, $notBefore, $issuedAt);

        // A `null` azp is treated as ABSENT rather than as a malformed value. JSON has no
        // way to distinguish "the issuer did not send this" from "the issuer sent null",
        // and RFC 8725's question — "which resource server was this minted for?" —
        // has the same answer either way: unknown, so
        // {@see assertAudienceContainment()} decides.
        $authorizedParty = null;
        if (array_key_exists('azp', $claims) && $claims['azp'] !== null) {
            if (!is_string($claims['azp']) || $claims['azp'] === '') {
                throw new JwtDecodeException('The token\'s "azp" claim is not a non-empty string.');
            }

            $authorizedParty = $claims['azp'];
        }

        $tokenId = null;
        if (array_key_exists('jti', $claims)) {
            if (!is_string($claims['jti']) || $claims['jti'] === '') {
                throw new JwtDecodeException('The token\'s "jti" claim is not a non-empty string.');
            }

            $tokenId = $claims['jti'];
        }

        return AccessTokenClaims::fromVerifiedClaims(
            $issuer,
            $subject,
            $audiences,
            $expiresAt,
            $notBefore,
            $issuedAt,
            $this->readScopes($claims),
            self::readRoles($claims),
            $authorizedParty,
            $tokenId,
            $claims
        );
    }

    /**
     * `aud` is a string or an array of strings, and nothing else.
     *
     * @param mixed $aud
     *
     * @return list<string>
     */
    private function readAudiences(mixed $aud): array
    {
        if (is_string($aud)) {
            if ($aud === '') {
                throw new JwtDecodeException('The token\'s "aud" claim is an empty string.');
            }

            return [$aud];
        }

        if (!is_array($aud) || !array_is_list($aud) || $aud === []) {
            throw new JwtDecodeException('The token\'s "aud" claim is neither a string nor a non-empty array.');
        }

        $audiences = [];
        foreach ($aud as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new JwtDecodeException('An element of the token\'s "aud" claim is not a non-empty string.');
            }

            $audiences[] = $entry;
        }

        return $audiences;
    }

    /**
     * @param list<string>         $audiences
     * @param array<string, mixed> $claims
     */
    private function assertAudienceContainment(array $audiences, array $claims): void
    {
        if (!in_array($this->config->audience(), $audiences, true)) {
            throw new JwtDecodeException(sprintf(
                'The token is not for this API. It names %d audience(s) and this resource server is not one of '
                . 'them. A token minted for another client — or for this client\'s userinfo endpoint — is not a '
                . 'credential for this interface.',
                count($audiences)
            ));
        }

        if (!$this->config->enforcesAuthorizedParty()) {
            return;
        }

        $authorizedParty = array_key_exists('azp', $claims) ? $claims['azp'] : null;

        if (count($audiences) > 1 && (!is_string($authorizedParty) || $authorizedParty === '')) {
            // RFC 8725 §3.1: several audiences, no azp, refuse.
            throw new JwtDecodeException(
                'The token names several audiences but carries no "azp", so it is ambiguous which resource '
                . 'server it was minted for. Refused rather than assumed to be this one.'
            );
        }

        if (is_string($authorizedParty) && !in_array($authorizedParty, $audiences, true)) {
            throw new JwtDecodeException(
                'The token\'s "azp" names a party that is not among its own audiences, which is internally '
                . 'inconsistent.'
            );
        }
    }

    private function assertTimeClaims(
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $notBefore,
        ?\DateTimeImmutable $issuedAt
    ): void {
        $now = $this->clock->now()->getTimestamp();
        $skew = $this->config->clockSkewSeconds();

        if ($now - $skew >= $expiresAt->getTimestamp()) {
            throw new JwtDecodeException('The token has expired.');
        }

        if ($notBefore !== null && $notBefore->getTimestamp() > $now + $skew) {
            throw new JwtDecodeException('The token is not valid yet: its "nbf" is in the future.');
        }

        if ($issuedAt !== null && $issuedAt->getTimestamp() > $now + $skew) {
            throw new JwtDecodeException('The token was issued in the future.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function readInstant(mixed $value, string $claim, bool $required = false): ?\DateTimeImmutable
    {
        if ($value === null && !$required) {
            return null;
        }

        // RFC 7519 §2: NumericDate is a NUMBER. A string is accepted because some
        // issuers send one and refusing it is an interoperability bug rather than a
        // security control — but it is cast explicitly, and a non-numeric string is
        // refused rather than coerced to 0 (which would read as 1970 and silently
        // mean "expired").
        if (is_int($value)) {
            $seconds = $value;
        } elseif (is_float($value)) {
            $seconds = (int) $value;
        } elseif (is_string($value) && preg_match('/^-?\d{1,19}$/', $value) === 1) {
            $seconds = (int) $value;
        } else {
            throw new JwtDecodeException(sprintf(
                'The token\'s "%s" claim is not a NumericDate. A time claim that cannot be read cannot be '
                . 'evaluated, and a value that is treated as "0" would silently mean 1970.',
                $claim
            ));
        }

        return (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function readOptionalInstant(array $claims, string $claim): ?\DateTimeImmutable
    {
        if (!array_key_exists($claim, $claims) || $claims[$claim] === null) {
            return null;
        }

        return $this->readInstant($claims[$claim], $claim);
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    private function readScopes(array $claims): array
    {
        $scope = $claims['scope'] ?? null;

        if ($scope === null) {
            return [];
        }

        if (!is_string($scope)) {
            throw new JwtDecodeException('The token\'s "scope" claim is not a string.');
        }

        $scopes = preg_split('/\s+/', trim($scope), -1, \PREG_SPLIT_NO_EMPTY);
        if ($scopes === false) {
            return [];
        }

        /** @var list<string> $scopes */
        return array_values($scopes);
    }

    /**
     * Keycloak's two documented role shapes, merged.
     *
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    private static function readRoles(array $claims): array
    {
        $roles = [];

        $realmAccess = $claims['realm_access'] ?? null;
        if (is_array($realmAccess) && is_array($realmAccess['roles'] ?? null)) {
            foreach ($realmAccess['roles'] as $role) {
                if (is_string($role) && $role !== '') {
                    $roles[] = $role;
                }
            }
        }

        $resourceAccess = $claims['resource_access'] ?? null;
        if (is_array($resourceAccess)) {
            foreach ($resourceAccess as $client) {
                if (!is_array($client) || !is_array($client['roles'] ?? null)) {
                    continue;
                }

                foreach ($client['roles'] as $role) {
                    if (is_string($role) && $role !== '') {
                        $roles[] = $role;
                    }
                }
            }
        }

        $roles = array_values(array_unique($roles));
        sort($roles, \SORT_STRING);

        /** @var list<string> $roles */
        return $roles;
    }
}