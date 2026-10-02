<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * Everything about this deployment's OIDC relationship that is not the signature.
 *
 * ============================================================================
 * PROVENANCE — AND WHY `audience` IS THE ONLY REQUIRED FIELD
 * ============================================================================
 * The endpoint locations are NOT configuration here; they come from the discovery
 * document at runtime ({@see OidcEndpoints}), because PRD-002 "Upstream refresh"
 * item 1 records that the URL in all four published specs
 * (`.../auth/realm/dev`) returns 404 and a hardcoded base "would pass review and
 * 401 on every request".
 *
 * What IS configuration:
 *
 *  - `audience`        the client id this resource server answers to. There is no
 *                      default: a resource server that accepts "any audience" is
 *                      not validating anything. RFC 8414 / RFC 8707 resource
 *                      indicators are not used by this issuer's realm, so the
 *                      client id is the audience.
 *  - `allowedAlgorithms` an allow-list, never a denylist. See
 *                      {@see LocalJwtDecoder} for why reading `alg` from the
 *                      token, or from the realm's discovery document, is wrong.
 *  - `clockSkewSeconds` bounded at both ends. Zero is not honest across a network;
 *                      600 is an hour of replay.
 *
 * ============================================================================
 * WHY SKEW IS APPLIED TO `exp`, `nbf` AND `iat` ALIKE
 * ============================================================================
 * A token minted at 12:00:00.400 that arrives at a server whose clock reads
 * 12:00:00.100 is not "not yet valid", it is a clock difference of 300 ms. `iat`
 * is included because an `iat` in the future is otherwise unexploited: it is the
 * input to several token-replay constructions.
 *
 * ============================================================================
 * WHY `azp` IS ENFORCED WHEN THE AUDIENCE IS AMBIGUOUS
 * ============================================================================
 * RFC 8725 §3.1: a token with several audiences must carry an `azp`, and a
 * resource server must verify it. OIDC Core §3.1.3.7 §6 requires `azp` when the
 * token may be used at more than one resource server. Without this check, a token
 * minted for the *userinfo* endpoint is accepted here, which is a confused
 * deputy: the token was legitimately issued, just not to us.
 *
 * @package Civi\Dfc
 */
final class OidcClientConfig
{
    /** The target realm's signing key is `alg: RS256` (live JWKS, 2026-10-02). */
    public const DEFAULT_ALGORITHMS = ['RS256'];

    public const MIN_SKEW_SECONDS = 0;

    /** Ten minutes. Beyond this, refusing to accept a token is safer than it. */
    public const MAX_SKEW_SECONDS = 600;

    private readonly string $audience;

    /** @var list<string> */
    private readonly array $allowedAlgorithms;

    private readonly int $clockSkewSeconds;

    private readonly TokenProfile $tokenProfile;

    private readonly bool $enforceAuthorizedParty;

    /**
     * @param string           $audience                  This resource server's client id.
     * @param list<string>|null $allowedAlgorithms         Null for
     *                                                     {@see DEFAULT_ALGORITHMS}.
     * @param int|null         $clockSkewSeconds           Null for 30.
     * @param TokenProfile|null $tokenProfile              Null for
     *                                                     {@see TokenProfile::standard()}.
     * @param bool|null        $enforceAuthorizedParty     Null for true.
     */
    public function __construct(
        string $audience,
        ?array $allowedAlgorithms = null,
        ?int $clockSkewSeconds = null,
        ?TokenProfile $tokenProfile = null,
        ?bool $enforceAuthorizedParty = null
    ) {
        $this->audience = self::assertAudience($audience);
        $this->allowedAlgorithms = self::normaliseAlgorithms(
            $allowedAlgorithms ?? self::DEFAULT_ALGORITHMS
        );
        $this->clockSkewSeconds = self::assertSkew($clockSkewSeconds ?? 30);
        $this->tokenProfile = $tokenProfile ?? TokenProfile::standard();
        $this->enforceAuthorizedParty = $enforceAuthorizedParty ?? true;
    }

    public function audience(): string
    {
        return $this->audience;
    }

    /** @return list<string> */
    public function allowedAlgorithms(): array
    {
        return $this->allowedAlgorithms;
    }

    public function allowsAlgorithm(string $algorithm): bool
    {
        return in_array($algorithm, $this->allowedAlgorithms, true);
    }

    public function clockSkewSeconds(): int
    {
        return $this->clockSkewSeconds;
    }

    public function tokenProfile(): TokenProfile
    {
        return $this->tokenProfile;
    }

    public function enforcesAuthorizedParty(): bool
    {
        return $this->enforceAuthorizedParty;
    }

    private static function assertAudience(string $audience): string
    {
        // An audience is an opaque string (a client id, an origin, a URI), so it is
        // only checked for being usable: non-empty, no whitespace, no control
        // characters. Anything more specific would reject legitimate client ids.
        if ($audience === '' || trim($audience) !== $audience) {
            throw new \InvalidArgumentException(
                'The OIDC audience must be a non-empty string with no surrounding whitespace.'
            );
        }

        if (preg_match('/[\x00-\x20\x7F]/', $audience) === 1) {
            throw new \InvalidArgumentException(
                'The OIDC audience must not contain whitespace or control characters. It is compared against '
                . 'a claim and, when echoed in an RFC 6750 challenge, written into a response header.'
            );
        }

        return $audience;
    }

    /**
     * @param list<string> $algorithms
     *
     * @return list<string>
     */
    private static function normaliseAlgorithms(array $algorithms): array
    {
        if ($algorithms === []) {
            throw new \InvalidArgumentException(
                'The algorithm allow-list may not be empty. An empty allow-list that silently permits '
                . 'everything is the configuration mistake this field exists to make impossible.'
            );
        }

        $normalised = [];
        foreach ($algorithms as $algorithm) {
            if (!is_string($algorithm) || preg_match('/^[A-Za-z0-9+\-]{1,32}$/', $algorithm) !== 1) {
                throw new \InvalidArgumentException(
                    'A JOSE algorithm name must match [A-Za-z0-9+-].'
                );
            }

            if ($algorithm === 'none') {
                throw new \InvalidArgumentException(
                    '"none" can never be in an algorithm allow-list: it means "no signature".'
                );
            }

            if (str_starts_with(strtoupper($algorithm), 'HS')) {
                throw new \InvalidArgumentException(
                    'A symmetric (HS*) algorithm cannot be allowed here: its key material would have to come '
                    . 'from the public JWKS endpoint, which is the algorithm-confusion attack.'
                );
            }

            $normalised[] = $algorithm;
        }

        return array_values(array_unique($normalised));
    }

    private static function assertSkew(int $seconds): int
    {
        if ($seconds < self::MIN_SKEW_SECONDS || $seconds > self::MAX_SKEW_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'The OIDC clock skew must be between %d and %d seconds, got %d.',
                self::MIN_SKEW_SECONDS,
                self::MAX_SKEW_SECONDS,
                $seconds
            ));
        }

        return $seconds;
    }
}