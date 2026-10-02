<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * A validated access token's claims, as this layer uses them.
 *
 * ============================================================================
 * WHY THE CLAIM SET CAN ONLY BE BUILT BY THE VALIDATOR
 * ============================================================================
 * The constructor is private and the only factory is
 * {@see fromVerifiedClaims()}, which is called exactly once — from
 * {@see AccessTokenValidator} after every check has passed. That is the mechanism
 * behind "a validated token": there is no public path that produces one of these
 * from a decoded-but-unverified payload, so a future caller cannot skip
 * validation by calling a different constructor.
 *
 * ============================================================================
 * SCOPES AND ROLES ARE SEPARATED ON PURPOSE
 * ============================================================================
 * `scopes()` comes from the OAuth 2.0 `scope` claim: what the client asked the
 * issuer for. `roles()` comes from Keycloak's realm and client role containers:
 * what the issuer says this identity IS. They are different authorities —
 * delegation versus identity — and conflating them is the failure PRD-002 §10
 * names as "scope-to-permission coherence".
 *
 * {@see ScopePermissionRegistry} consults BOTH, and reports the names it did not
 * recognise, so an identity whose roles are named differently from the
 * deployment's configuration fails loudly instead of silently losing access.
 *
 * The realm's role names are a property of the deployment, so this class extracts
 * them generically rather than naming any: `realm_access.roles` and, for every
 * entry in `resource_access`, that client's `roles`. Those are Keycloak's two
 * documented shapes and the shape a JOSE `roles` claim would take in another IdP.
 *
 * ============================================================================
 * NO TOKEN MATERIAL IS RETAINED
 * ============================================================================
 * The compact JWS is not stored, and {@see __debugInfo()} returns the claim
 * *names* rather than their values. A `var_dump($claims)` in a stack trace then
 * shows `subject` and `roles` keys and no identity data — which matters because
 * `sub` on this interface is an OIDC subject that lane-3 resolves to a CiviCRM
 * contact, and a stack trace is the one place that mapping leaks.
 *
 * @package Civi\Dfc
 */
final class AccessTokenClaims
{
    private readonly string $issuer;

    private readonly string $subject;

    /** @var list<string> */
    private readonly array $audiences;

    private readonly \DateTimeImmutable $expiresAt;

    private readonly ?\DateTimeImmutable $notBefore;

    private readonly ?\DateTimeImmutable $issuedAt;

    /** @var list<string> */
    private readonly array $scopes;

    /** @var list<string> */
    private readonly array $roles;

    private readonly ?string $authorizedParty;

    private readonly ?string $tokenId;

    /** @var array<string, mixed> */
    private readonly array $claims;

    /**
     * @param list<string>          $audiences
     * @param list<string>          $scopes
     * @param list<string>          $roles
     * @param array<string, mixed>  $claims
     */
    private function __construct(
        string $issuer,
        string $subject,
        array $audiences,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $notBefore,
        ?\DateTimeImmutable $issuedAt,
        array $scopes,
        array $roles,
        ?string $authorizedParty,
        ?string $tokenId,
        array $claims
    ) {
        $this->issuer = $issuer;
        $this->subject = $subject;
        $this->audiences = $audiences;
        $this->expiresAt = $expiresAt;
        $this->notBefore = $notBefore;
        $this->issuedAt = $issuedAt;
        $this->scopes = $scopes;
        $this->roles = $roles;
        $this->authorizedParty = $authorizedParty;
        $this->tokenId = $tokenId;
        $this->claims = $claims;
    }

    /**
     * Build from a claim set that has ALREADY passed every check.
     *
     * Public because it is called from {@see AccessTokenValidator}, which is a
     * different class; the naming is the guard, and the class docblock says the
     * alternative — a public constructor — was not chosen so that this stays the
     * only entry point.
     *
     * @param list<string>         $audiences
     * @param list<string>         $scopes
     * @param list<string>         $roles
     * @param array<string, mixed> $claims
     */
    public static function fromVerifiedClaims(
        string $issuer,
        string $subject,
        array $audiences,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $notBefore,
        ?\DateTimeImmutable $issuedAt,
        array $scopes,
        array $roles,
        ?string $authorizedParty,
        ?string $tokenId,
        array $claims
    ): self {
        return new self(
            $issuer,
            $subject,
            $audiences,
            $expiresAt,
            $notBefore,
            $issuedAt,
            $scopes,
            $roles,
            $authorizedParty,
            $tokenId,
            $claims
        );
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    /** The OIDC `sub`. Resolving this to a WebID or contact is lane-3's work. */
    public function subject(): string
    {
        return $this->subject;
    }

    /** @return list<string> */
    public function audiences(): array
    {
        return $this->audiences;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function notBefore(): ?\DateTimeImmutable
    {
        return $this->notBefore;
    }

    public function issuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    /**
     * The OAuth 2.0 `scope` claim, split on whitespace.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Keycloak realm and client roles, merged and de-duplicated.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function authorizedParty(): ?string
    {
        return $this->authorizedParty;
    }

    /** `jti`. Recorded for audit correlation; never used as an authorisation input. */
    public function tokenId(): ?string
    {
        return $this->tokenId;
    }

    /**
     * One verified claim by name, for the parts of this layer that have not
     * promoted them to a property (`preferred_username`, `email`, a client-specific
     * claim lane-3 needs).
     *
     * Returns whatever the issuer sent. The value has been signature-verified but
     * NOT schema-validated, so a caller must treat it as untrusted input — including
     * before writing it to a log.
     */
    public function claim(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    /**
     * Audit context. Public identifiers and policy decisions only — no token, no
     * personal claims, nothing that has not already been through the no-leak
     * guarantees in the error layer.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'subject' => $this->subject,
            'audiences' => $this->audiences,
            'scopes' => $this->scopes,
            'roles' => $this->roles,
            'authorizedParty' => $this->authorizedParty,
            'tokenId' => $this->tokenId,
            'expiresAt' => $this->expiresAt->format(\DATE_ATOM),
            'issuedAt' => $this->issuedAt?->format(\DATE_ATOM),
            'notBefore' => $this->notBefore?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        // Claim NAMES only. A `var_dump()` of this object in a stack trace or a log
        // must not print an OIDC subject, an e-mail address or a role list.
        return ['claimNames' => implode(',', array_keys($this->claims))];
    }
}