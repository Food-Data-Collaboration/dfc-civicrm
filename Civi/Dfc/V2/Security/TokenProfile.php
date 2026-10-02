<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The token-type rules that keep an ID token out of this interface.
 *
 * ============================================================================
 * WHY AN ID TOKEN MUST BE REFUSED AT ALL
 * ============================================================================
 * PRD-002 §4.7 requires "validated OIDC **access** tokens (never an ID token)",
 * and {@see \Civi\Dfc\V2\Controller\Error\ErrorCode::AUTHENTICATION_UNUSABLE}
 * names "an ID token where an access token is required" as one of the conditions
 * that code exists for.
 *
 * The reason is not pedantry. An ID token is minted for a CLIENT to learn about a
 * USER at the authorisation endpoint: it carries the `nonce` that proves the token
 * came from this authorisation request, and it is frequently signed with a
 * symmetric key shared between client and issuer, or with a different key
 * lifetime and audience from the access token. Accepting one at a resource server
 * means:
 *
 *   - a token obtained by ANY client that saw the authorisation response is a
 *     credential for THIS server, whether or not that client was ever
 *     authorised to call it;
 *   - the `aud` check becomes ambiguous, because the ID token's audience is the
 *     client, not the resource server;
 *   - no scope semantics — an ID token has no `scope` claim, so the scope →
 *     permission registry would have nothing to map.
 *
 * ============================================================================
 * THREE INDEPENDENT SIGNALS, BECAUSE ONE IS NOT ENOUGH
 * ============================================================================
 * Keycloak sets `"typ": "ID"` in the JOSE header of an ID token and `"typ":
 * "JWT"` in that of an access token (RFC 9068 standardises `at+jwt` for the
 * latter, so both are accepted here). That header is the cheapest signal and it is
 * attacker-controlled, so it cannot be the only one. This class therefore also
 * refuses tokens carrying ID-token-only claims:
 *
 *   - `nonce`  — proves a token came from an authorisation request. The realm's own
 *     discovery document lists its `claims_supported` and `nonce` is not among
 *     them (verified live 2026-10-02), so an access token from this issuer will
 *     not carry it.
 *   - `at_hash` — defined by OIDC Core §3.1.3.6 as a hash of the access token, in
 *     the ID token. Never present in an access token.
 *   - `c_hash` / `s_hash` — the state and session hashes, same provenance.
 *
 * An `auth_time` claim is deliberately NOT in the forbidden set: Keycloak emits
 * it on access tokens too, so forbidding it would reject genuine credentials.
 * That is the honest answer to "which signal is reliable" — some are, and the ones
 * that are not belong in a comment, not in a denylist that breaks production.
 *
 * ============================================================================
 * A MISSING `typ` IS ACCEPTED
 * ============================================================================
 * RFC 7519 §5.1 makes `typ` optional. A conforming producer may omit it, and
 * refusing every token without one would be an interoperability bug dressed as
 * security. The forbidden-claim checks still apply, so omitting `typ` is not a
 * bypass.
 *
 * @package Civi\Dfc
 */
final class TokenProfile
{
    /**
     * `typ` values this interface accepts in the JOSE header, lower-cased.
     *
     * `jwt` is Keycloak's actual value for access tokens and `at+jwt` /
     * `application/at+jwt` are RFC 9068's. Anything else present in the header is
     * refused — which is what rejects `id` (RFC 9068 §2.1's abbreviation) and
     * Keycloak's `ID` without naming either as a special case.
     *
     * @var list<string>
     */
    private const DEFAULT_ACCEPTED_TYPES = ['jwt', 'at+jwt', 'application/at+jwt'];

    /**
     * Claims whose presence means "this is not an access token".
     *
     * @var list<string>
     */
    private const DEFAULT_FORBIDDEN_CLAIMS = ['nonce', 'at_hash', 'c_hash', 's_hash'];

    /**
     * Claims an access token must carry.
     *
     * RFC 9068 §2.2 makes `iss`, `exp`, `aud` and `sub` mandatory for an access
     * token and says a token missing one MUST be rejected. Listed here so the
     * set is one declaration rather than four `isset()` calls spread across the
     * validator.
     *
     * @var list<string>
     */
    private const REQUIRED_CLAIMS = ['iss', 'sub', 'aud', 'exp'];

    /** @var list<string> */
    private readonly array $acceptedTypes;

    /** @var list<string> */
    private readonly array $forbiddenClaims;

    /**
     * @param list<string>|null $acceptedTypes  Lower-cased `typ` values; null for
     *                                           {@see DEFAULT_ACCEPTED_TYPES}.
     * @param list<string>|null $forbiddenClaims Claim names; null for
     *                                           {@see DEFAULT_FORBIDDEN_CLAIMS}.
     */
    public function __construct(?array $acceptedTypes = null, ?array $forbiddenClaims = null)
    {
        $this->acceptedTypes = self::normaliseTypes($acceptedTypes ?? self::DEFAULT_ACCEPTED_TYPES);
        $this->forbiddenClaims = self::normaliseClaims($forbiddenClaims ?? self::DEFAULT_FORBIDDEN_CLAIMS);
    }

    /** The profile this interface uses. Immutable and free of state. */
    public static function standard(): self
    {
        return new self();
    }

    /**
     * A profile that accepts any `typ` and forbids nothing.
     *
     * EXISTS TO BE CONFIGURED AWAY, NOT TO BE USED. An issuer that legitimately
     * puts a non-standard `typ` on access tokens can construct this and keep the
     * forbidden-claim checks — but the default is the strict profile, so
     * disabling a check is a visible, deliberate act in the wiring rather than a
     * consequence of a missing argument.
     */
    public static function permissive(): self
    {
        return new self([], []);
    }

    /**
     * Is this token profile an API credential rather than an ID token?
     *
     * @param array<string, mixed> $header Unverified JOSE header.
     * @param array<string, mixed> $claims Verified claim set.
     *
     * @throws JwtDecodeException when it is not.
     */
    public function assertAccessToken(array $header, array $claims): void
    {
        $type = $header['typ'] ?? null;
        if (is_string($type) && $type !== '' && $this->acceptedTypes !== []) {
            if (!in_array(strtolower($type), $this->acceptedTypes, true)) {
                throw new JwtDecodeException(sprintf(
                    'The token header declares "typ: %s", which is not an access-token type on this interface. '
                    . 'This interface accepts API credentials only; an ID token is refused. Obtain an access '
                    . 'token from the token endpoint and retry.',
                    $type
                ));
            }
        }

        foreach ($this->forbiddenClaims as $claim) {
            if (array_key_exists($claim, $claims)) {
                throw new JwtDecodeException(sprintf(
                    'The token carries the "%s" claim, which only an ID token has. This interface accepts API '
                    . 'credentials only; obtain an access token from the token endpoint and retry.',
                    $claim
                ));
            }
        }
    }

    /**
     * The claims a usable access token must carry.
     *
     * @return list<string>
     */
    public function requiredClaims(): array
    {
        return self::REQUIRED_CLAIMS;
    }

    /** @return list<string> */
    public function acceptedTypes(): array
    {
        return $this->acceptedTypes;
    }

    /** @return list<string> */
    public function forbiddenClaims(): array
    {
        return $this->forbiddenClaims;
    }

    /**
     * @param list<string> $types
     *
     * @return list<string>
     */
    private static function normaliseTypes(array $types): array
    {
        $normalised = [];
        foreach ($types as $type) {
            if (!is_string($type) || $type === '') {
                throw new \InvalidArgumentException(
                    'An accepted JOSE "typ" value must be a non-empty string.'
                );
            }

            $normalised[] = strtolower($type);
        }

        return array_values(array_unique($normalised));
    }

    /**
     * @param list<string> $claims
     *
     * @return list<string>
     */
    private static function normaliseClaims(array $claims): array
    {
        $normalised = [];
        foreach ($claims as $claim) {
            if (!is_string($claim) || preg_match('/^[A-Za-z_][A-Za-z0-9_.]{0,63}$/', $claim) !== 1) {
                throw new \InvalidArgumentException(
                    'A forbidden-claim name must be a JSON member name: a letter or underscore followed by '
                    . 'letters, digits, dots or underscores.'
                );
            }

            $normalised[] = $claim;
        }

        return array_values(array_unique($normalised));
    }
}