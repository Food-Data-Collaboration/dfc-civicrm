<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * An authenticated caller: a validated token plus what it bought.
 *
 * ============================================================================
 * WHY THESE TWO THINGS TRAVEL TOGETHER
 * ============================================================================
 * A {@see PermissionGrant} without its claims is unusable (there is no way to tell
 * which identity holds it, which is the first thing an audit record needs), and
 * claims without a grant invite every call site to re-derive the grant — which is
 * how two routes end up with two authorisation decisions.
 *
 * So they are one object, and {@see PermissionGrant} is the only authority on what
 * the identity may do. {@see subject()} is here for lane-3's subject → WebID →
 * contact resolution and is the OIDC `sub`, NOT a CiviCRM id: PRD-002 §5 states the
 * OIDC identity key is `(oidc_issuer, oidc_subject)`, never `sub` alone, so the
 * issuer is one field away on {@see claims()}.
 *
 * @package Civi\Dfc
 */
final class AuthenticatedPrincipal
{
    private readonly AccessTokenClaims $claims;

    private readonly PermissionGrant $permissions;

    public function __construct(AccessTokenClaims $claims, PermissionGrant $permissions)
    {
        $this->claims = $claims;
        $this->permissions = $permissions;
    }

    public function claims(): AccessTokenClaims
    {
        return $this->claims;
    }

    public function permissions(): PermissionGrant
    {
        return $this->permissions;
    }

    /**
     * The OIDC `sub`. Pair with {@see issuer()} — `sub` alone is not an identity.
     */
    public function subject(): string
    {
        return $this->claims->subject();
    }

    public function issuer(): string
    {
        return $this->claims->issuer();
    }

    /**
     * Is this identity allowed to perform $action on $surface?
     *
     * The token half of the question only. `access dfc api` is checked separately
     * against CiviCRM by the deployment's permission backend — see
     * {@see ScopePermissionRegistry} for why it cannot come from a token.
     */
    public function isAllowed(DfcSurface $surface, DfcAction $action): bool
    {
        return $this->permissions->allowsOn($surface, $action);
    }

    /**
     * Audit record. Identity, grants, and the names that were NOT recognised.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'claims' => $this->claims->toArray(),
            'permissions' => $this->permissions->toArray(),
        ];
    }
}