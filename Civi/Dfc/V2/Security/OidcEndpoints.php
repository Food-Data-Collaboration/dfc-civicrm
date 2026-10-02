<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * Endpoint locations, resolved from the issuer's discovery document at runtime.
 *
 * ============================================================================
 * WHY THERE IS NO SETTER AND NO HARD-CODED BASE
 * ============================================================================
 * PRD-002 "Upstream refresh" item 1 is the reason this class exists:
 *
 *     All four OpenAPI files use `https://login.fooddatacollaboration.org.uk/auth/realm/dev`,
 *     which returns 404 (verified). The live document is under `/realms/dev`.
 *     "Configure the realm from the discovery document at runtime; never
 *      hardcode a base. A hardcoded `/auth/realm/dev` would pass review and 401
 *      on every request."
 *
 * So the only factory takes a decoded discovery document. There is no
 * `fromIssuer(string $base)` that appends a path segment, because that is the
 * exact shape of the mistake: a helper that knows a realm's internal layout is a
 * helper that breaks when Keycloak's routing or a reverse proxy changes. Keycloak
 * supports a `KC_HOSTNAME` with path components and a realm *import* option that
 * moves a realm between `/auth` and no `/auth`; a URL template hardcodes a
 * deployment decision into a library.
 *
 * ============================================================================
 * ISSUER VERIFICATION IS NOT OPTIONAL
 * ============================================================================
 * OIDC Discovery §4.3 requires the client to validate that the `issuer` in the
 * document equals the issuer it expected. Without that check, an attacker who can
 * answer `/.well-known/openid-configuration` on any host this server trusts
 * redirects signature verification to keys they chose, and every token is
 * "valid". It is the same class of bug as trusting `alg` from the token.
 *
 * The check is therefore two-sided: pass $expectedIssuer and a mismatch is a
 * hard error; omit it only in a context that genuinely has no expectation, and
 * the docblock says the resulting value is not yet verified.
 *
 * ============================================================================
 * WHICH ENDPOINTS ARE RECORDED, AND WHY
 * ============================================================================
 *  - `issuer` and `jwks_uri` are REQUIRED. This is a resource server: it
 *    verifies signatures and nothing else, so those two are the whole of what it
 *    cannot work without.
 *  - `token_endpoint` and `userinfo_endpoint` are OPTIONAL and recorded when
 *    present. This server never calls them — token exchange is the client's job
 *    and userinfo is not used, because the access token's own claims are
 *    authoritative. They are carried because the Identity Service and lane-3's
 *    subject resolution will want to name them in a document or an error detail,
 *    and a value that has to be re-derived from a string template is a value that
 *    will eventually be wrong.
 *
 * @package Civi\Dfc
 */
final class OidcEndpoints
{
    private const MAX_SCOPES = 64;

    private readonly string $issuer;

    private readonly string $jwksUri;

    private readonly ?string $tokenEndpoint;

    private readonly ?string $userinfoEndpoint;

    /** @var list<string> */
    private readonly array $scopesSupported;

    /**
     * @param list<string> $scopesSupported
     */
    private function __construct(
        string $issuer,
        string $jwksUri,
        ?string $tokenEndpoint,
        ?string $userinfoEndpoint,
        array $scopesSupported
    ) {
        $this->issuer = $issuer;
        $this->jwksUri = $jwksUri;
        $this->tokenEndpoint = $tokenEndpoint;
        $this->userinfoEndpoint = $userinfoEndpoint;
        $this->scopesSupported = $scopesSupported;
    }

    /**
     * Resolve from a decoded `openid-configuration` document.
     *
     * @param array<string, mixed> $document      The decoded JSON object.
     * @param string|null         $expectedIssuer The issuer this deployment EXPECTS,
     *                                             when it knows one. Omit only when
     *                                             there is genuinely no expectation.
     *
     * @throws \InvalidArgumentException when a required member is absent, an
     *         endpoint is not an absolute https URI without a fragment, the
     *         issuer does not match $expectedIssuer, or the scope list is not a
     *         list of scope tokens.
     */
    public static function fromDiscoveryDocument(array $document, ?string $expectedIssuer = null): self
    {
        $issuer = self::requireHttpsUri($document, 'issuer', 'The discovery document');
        $jwksUri = self::requireHttpsUri($document, 'jwks_uri', 'The discovery document');

        if ($expectedIssuer !== null) {
            // Exact byte comparison, not a prefix or a trailing-slash-tolerant
            // match: RFC 8414 §3.3 defines the issuer identifier as an exact
            // string, and normalisation here would re-open the mix-up attack the
            // check exists to stop. Compare the resolved issuer against what the
            // deployment configured, so a typo in the expectation fails loudly.
            if ($issuer !== $expectedIssuer) {
                throw new \InvalidArgumentException(sprintf(
                    'The discovery document names issuer "%s" but this deployment expects "%s". Refusing to '
                    . 'continue: verifying signatures against keys served by an issuer you did not expect makes '
                    . 'every token forgeable by whoever controls that endpoint.',
                    $issuer,
                    $expectedIssuer
                ));
            }
        }

        return new self(
            $issuer,
            $jwksUri,
            self::optionalHttpsUri($document, 'token_endpoint'),
            self::optionalHttpsUri($document, 'userinfo_endpoint'),
            self::optionalScopes($document)
        );
    }

    /** The verified issuer identifier, exactly as the document states it. */
    public function issuer(): string
    {
        return $this->issuer;
    }

    /** Where signing keys are fetched from. Public; carries no secret. */
    public function jwksUri(): string
    {
        return $this->jwksUri;
    }

    public function tokenEndpoint(): ?string
    {
        return $this->tokenEndpoint;
    }

    public function userinfoEndpoint(): ?string
    {
        return $this->userinfoEndpoint;
    }

    /**
     * The scopes the issuer advertises, or an empty list if it advertises none.
     *
     * Recorded for the diagnostics surface and for cross-checking
     * {@see ScopePermissionRegistry}'s table against reality. NEVER used to
     * decide what a token may do — see that class's docblock.
     *
     * @return list<string>
     */
    public function scopesSupported(): array
    {
        return $this->scopesSupported;
    }

    /**
     * Flat view for the health endpoint and audit context. Public identifiers only.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'jwks_uri' => $this->jwksUri,
            'token_endpoint' => $this->tokenEndpoint ?? '',
            'userinfo_endpoint' => $this->userinfoEndpoint ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function requireHttpsUri(array $document, string $member, string $what): string
    {
        $value = $document[$member] ?? null;

        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf(
                '%s has no non-empty string member "%s". A resource server cannot function without the '
                . 'issuer and the JWKS endpoint, and will not guess either.',
                $what,
                $member
            ));
        }

        if (str_contains($value, '#')) {
            throw new \InvalidArgumentException(sprintf(
                'The "%s" in %s carries a fragment. OIDC endpoint URIs never do, and a fragment here means '
                . 'the document is not what it claims to be.',
                $member,
                $what
            ));
        }

        if (preg_match('#^https://[^\s/?\#]+(?::\d+)?(?:/[^\s?\#]*)?$#', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'The "%s" in %s must be an absolute https URI with no query string. Got something that is not '
                . 'one. Plaintext would let anyone on the path substitute the signing keys.',
                $member,
                $what
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function optionalHttpsUri(array $document, string $member): ?string
    {
        $value = $document[$member] ?? null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        return self::requireHttpsUri($document, $member, 'The discovery document');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private static function optionalScopes(array $document): array
    {
        $value = $document['scopes_supported'] ?? null;

        if ($value === null) {
            return [];
        }

        // A present-but-wrongly-typed member is an ERROR, not an absence. Silently
        // treating `scopes_supported: "openid"` as "no scopes advertised" would make the
        // cross-check against the registry pass vacuously, which is the one thing that
        // check exists to prevent.
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new \InvalidArgumentException(
                'The "scopes_supported" member of the discovery document must be a non-empty JSON array of '
                . 'scope names when present. It is used to cross-check the scope registry, so a value of any '
                . 'other shape would make that check pass vacuously.'
            );
        }

        if (count($value) > self::MAX_SCOPES) {
            throw new \InvalidArgumentException(sprintf(
                'The discovery document advertises %d scopes, over the %d this class will hold. That is not an '
                . 'OIDC realm.',
                count($value),
                self::MAX_SCOPES
            ));
        }

        $scopes = [];
        foreach ($value as $scope) {
            if (!is_string($scope) || $scope === '') {
                throw new \InvalidArgumentException(
                    'Every entry in "scopes_supported" must be a non-empty string.'
                );
            }

            $scopes[] = $scope;
        }

        return $scopes;
    }
}