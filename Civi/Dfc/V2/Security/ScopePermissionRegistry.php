<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The one place realm scopes and realm roles become CiviCRM permissions.
 *
 * ============================================================================
 * PROVENANCE OF THE TABLE — AND WHY IT IS COMPLETE
 * ============================================================================
 * PRD-002 "Upstream refresh" item 2 is the requirement:
 *
 *     The live realm advertises: openid profile ReadEnterprise ReadProduct webid
 *     organization roles email phone address microprofile-jwt web-origins acr
 *     offline_access basic service_account.
 *     "The plan's invented `dfc:read:organization` style names do not exist."
 *     "Use the realm's names; map them to the four CiviCRM permissions in one registry."
 *
 * Verified live against the realm's discovery document on 2026-10-02; the
 * `scopes_supported` array is exactly the 16 names in
 * {@see ADVERTISED_REALM_SCOPES}. Every one of the 16 has an explicit row here —
 * including the ten that grant NOTHING — because "complete" means the table
 * accounts for every name the issuer advertises, not that every name is
 * productive. A name with no row is an unmapped name, and
 * {@see PermissionGrant::unmappedScopes()} reports it, which is the honest
 * behaviour but a worse one than an explicit "this scope is not an access grant"
 * row with a note explaining which OIDC standard scope it actually is.
 *
 * THE PLAN'S INVENTED NAMES APPEAR NOWHERE IN THIS FILE. `dfc:read:organization`,
 * `dfc:write:organization`, `dfc:read:person`, `dfc:admin` are not registered, not
 * recognised, and not treated as aliases. A client sending one gets an unmapped
 * scope and no access — which is correct, because the realm never issued it and a
 * request for a scope the issuer does not advertise cannot have been granted.
 *
 * ============================================================================
 * WHY TEN OF THE SIXTEEN GRANT NOTHING
 * ============================================================================
 * Eleven of the sixteen are OpenID Connect standard scopes or Keycloak client
 * features. None of them is a statement about DFC data:
 *
 *  - `openid` says the token is an OIDC token at all. It establishes WHO, never
 *    WHAT DATA. Mapping it to a read grant would make every OIDC login a data
 *    credential.
 *  - `profile`, `email`, `phone`, `address` are claims about the SUBJECT: this is
 *    how the userinfo endpoint learns the user's name. They say nothing about
 *    whether the client may read an organization's catalog.
 *  - `offline_access` is a REFRESH grant. On this interface — which never calls a
 *    token endpoint — it has no effect whatsoever.
 *  - `basic` is an HTTP Basic client-authentication method, not a scope.
 *  - `service_account` marks a client-type. A machine identity still needs
 *    `ReadEnterprise` for the Organization surface like anyone else; that is the
 *    whole point of not inventing a blanket machine grant.
 *  - `roles` is the interesting one: it says the token CARRIES roles. The roles
 *    themselves are what map to permissions ({@see readRoles()} in
 *    {@see AccessTokenValidator} extracts them), so granting on the scope name
 *    would grant on nothing.
 *  - `acr`, `microprofile-jwt`, `web-origins` are claim-format and browser-origin
 *    signals. `microprofile-jwt` in particular is another profile's token format
 *    compatibility flag.
 *
 * `ReadProduct` is the eleventh, and it is the one that needs the most explaining:
 * it genuinely is an enterprise-read scope, and this server refuses to serve
 * Product at all (PRD-002 §1 excludes `DefinedProduct`, `SuppliedProduct` and every
 * other product class). Granting a read permission on a class this server returns
 * 404 for would produce an authorisation model that claims to protect something it
 * does not have. So it grants nothing, and the note says so, so the decision is
 * reviewable rather than looking like an oversight.
 *
 * ============================================================================
 * THE TWO SCOPES THAT DO GRANT, AND WHY
 * ============================================================================
 *  - `ReadEnterprise` -> `read dfc data` on the ORGANIZATION surface. This is the
 *    DFC interoperability contract's own scope name for reading enterprise data,
 *    and DFC's `dfc-b:Enterprise` is what this extension maps to
 *    `dfc-b:Organization` (PRD-002 "Upstream refresh" item 6: "`dfc-b:Enterprise`
 *    maps to `Organization`"), so the surface is ORGANIZATION and not a
 *    hypothetical Enterprise one.
 *  - `organization` -> the same grant. A general "organization data" scope, which
 *    is the same authorisation statement in a different dialect. Both appear
 *    because a deployment may issue either and neither is a superset of the other.
 *
 * `webid` -> `read dfc data` on the WEBID surface: reading a public WebID profile
 * or a TypeIndex is exactly what the `webid` scope is for, and it is deliberately
 * NOT extended to CONTAINER or ORGANIZATION.
 *
 * ============================================================================
 * WHY WRITE AND ADMINISTER COME FROM ROLES AND NOT FROM SCOPES
 * ============================================================================
 * Because this realm advertises no write scope. Sixteen names, none of which says
 * "write". So the only honest source of `write dfc data` and `administer dfc` is the
 * `roles` claim, which is why {@see ROLES} exists as an open, deployment-populated
 * map rather than a guessed one:
 *
 *     $registry = ScopePermissionRegistry::standard()
 *         ->withRoleGrant('<the realm role name>', DfcPermission::WRITE_DATA);
 *
 * The realm's role names are a property of this deployment's Keycloak configuration
 * and cannot be derived from a discovery document. Inventing a plausible name would
 * be the same mistake the plan made with scopes, so {@see ROLES} ships EMPTY and
 * says so. A deployment that grants nothing gains nothing — fail-closed, and
 * correct.
 *
 * ============================================================================
 * `access dfc api` IS NOT GRANTED BY ANY SCOPE OR ROLE HERE
 * ============================================================================
 * It is a CiviCRM permission and the deployment's permission backend owns it
 * (lane-3). It appears in {@see requiredPermissionsFor()} as part of what every
 * operation needs, and {@see PermissionGrant} has no way to contain it, so there
 * is no path by which a token confers the API gate. That separation is the point:
 * "may this client use the API" and "may this client see this organization" are two
 * questions with two different authorities behind them.
 *
 * @package Civi\Dfc
 */
final class ScopePermissionRegistry
{
    /**
     * Every scope the target realm advertises. Verified against the live discovery
     * document on 2026-10-02.
     *
     * @var list<string>
     */
    public const ADVERTISED_REALM_SCOPES = [
        'openid',
        'profile',
        'ReadEnterprise',
        'ReadProduct',
        'webid',
        'organization',
        'roles',
        'email',
        'phone',
        'address',
        'microprofile-jwt',
        'web-origins',
        'acr',
        'offline_access',
        'basic',
        'service_account',
    ];

    /**
     * The scope → grants table. Every advertised scope appears exactly once.
     *
     * @var array<string, list<ScopeGrant>>
     */
    private const REALM_SCOPE_GRANTS = [
        // -- Grants. Two scopes, two surfaces. ---------------------------------
        'ReadEnterprise' => [
            'dfc-b:Enterprise is mapped to dfc-b:Organization by PRD-002, so this is the Organization surface.',
        ],
        'organization' => [
            'The general organization-data scope: the same authorisation statement in a different dialect.',
        ],
        'webid' => [
            'WebID profiles and TypeIndexes only. Deliberately not extended to Organization or Container '
            . 'membership: reading who someone is is not reading what they sell.',
        ],

        // -- No grant. OIDC standard scopes about the SUBJECT. -------------------
        'openid' => [],
        'profile' => [],
        'email' => [],
        'phone' => [],
        'address' => [],

        // -- No grant. Client features, not access grants. -----------------------
        'offline_access' => [],
        'basic' => [],
        'service_account' => [],
        'acr' => [],
        'microprofile-jwt' => [],
        'web-origins' => [],

        // -- No grant, and it carries the roles. -------------------------------
        'roles' => [],

        // -- No grant, and the reason is this server's own scope. ----------------
        'ReadProduct' => [],
    ];

    /**
     * The explicit "this advertised scope grants nothing" statements, with the
     * reason. Kept apart from {@see REALM_SCOPE_GRANTS} so the table above stays
     * scannable, and kept from the docblock so an operator reading the audit output
     * learns *why* rather than only *that*.
     *
     * @var array<string, string>
     */
    private const REALM_SCOPE_NOTES = [
        'ReadEnterprise' => 'Reads the Organization surface. No write: this realm has no write scope.',
        'organization' => 'Reads the Organization surface. No write: this realm has no write scope.',
        'webid' => 'Reads WebID profiles and TypeIndexes only.',

        'openid' => 'Establishes WHO the token is about. Says nothing about WHAT DATA the client may read.',
        'profile' => 'An OIDC claims scope about the subject, for the userinfo endpoint. Not a data grant.',
        'email' => 'An OIDC claims scope about the subject. Not a data grant.',
        'phone' => 'An OIDC claims scope about the subject. Not a data grant.',
        'address' => 'An OIDC claims scope about the subject. Not a data grant.',

        'offline_access' => 'A refresh-token grant. This interface is a resource server and calls no token '
            . 'endpoint, so it has no effect here.',
        'basic' => 'An HTTP Basic client-authentication method, not a scope. Grants nothing.',
        'service_account' => 'Marks a client type. A machine identity still needs ReadEnterprise like any other.',
        'acr' => 'An authentication-context reference: how strongly the user authenticated. Not an access grant.',
        'microprofile-jwt' => 'A MicroProfile token-format compatibility flag. Not an access grant.',
        'web-origins' => 'A browser-origin claim for cross-origin requests. This is not a browser API.',

        'roles' => 'The token CARRIES realm and client roles; the roles themselves are what map to permissions. '
            . 'Granting on the scope name would grant on nothing.',

        'ReadProduct' => 'An enterprise-read scope for Product data, which PRD-002 §1 excludes from this '
            . 'extension entirely. Granting a read permission on a class this server answers 404 for would '
            . 'advertise protection this server does not have.',
    ];

    /**
     * Role name -> grants. EMPTY, on purpose.
     *
     * See the class docblock: the realm's role names are a deployment fact that no
     * discovery document states, and guessing one is the same failure as guessing a
     * scope name. Populate with {@see withRoleGrant()}.
     *
     * @var array<string, list<ScopeGrant>>
     */
    private const ROLES = [];

    /** @var array<string, list<ScopeGrant>> */
    private readonly array $scopeGrants;

    /** @var array<string, list<ScopeGrant>> */
    private readonly array $roleGrants;

    /**
     * @param array<string, list<ScopeGrant>> $scopeGrants
     * @param array<string, list<ScopeGrant>> $roleGrants
     */
    private function __construct(array $scopeGrants, array $roleGrants)
    {
        $this->scopeGrants = $scopeGrants;
        $this->roleGrants = $roleGrants;
    }

    /**
     * The registry this extension ships with.
     *
     * Every advertised realm scope mapped, no roles, and therefore no write and no
     * administer permission reachable from a token alone. Fail-closed.
     */
    public static function standard(): self
    {
        $scopeGrants = [];
        foreach (self::REALM_SCOPE_GRANTS as $scope => $notes) {
            $grants = [];

            foreach ($notes as $note) {
                $grants[] = self::grantForScope($scope, $note);
            }

            $scopeGrants[$scope] = $grants;
        }

        // Any advertised scope with no row above would be unmapped, which is safe
        // but not complete. Fail loudly in a test rather than silently: see
        // ScopePermissionRegistryTest::testEveryAdvertisedRealmScopeHasARow().
        return new self($scopeGrants, self::ROLES);
    }

    /**
     * A registry that maps nothing at all. For a deployment that supplies its own.
     */
    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * Add or replace one scope's grants. Returns a new registry.
     *
     * @param DfcSurface|null $surface Null means "every surface"; see {@see ScopeGrant}.
     */
    public function withScopeGrant(
        string $scope,
        DfcPermission $permission,
        ?DfcSurface $surface = null,
        string $note = 'Configured by the deployment.'
    ): self {
        $scope = self::assertScopeName($scope, 'scope');

        $scopeGrants = $this->scopeGrants;
        $scopeGrants[$scope] = [ScopeGrant::of($permission, $surface, $note)];

        return new self($scopeGrants, $this->roleGrants);
    }

    /**
     * Add or replace one role's grants. Returns a new registry.
     *
     * @param DfcSurface|null $surface Null means "every surface".
     */
    public function withRoleGrant(
        string $role,
        DfcPermission $permission,
        ?DfcSurface $surface = null,
        string $note = 'Configured by the deployment.'
    ): self {
        $role = self::assertScopeName($role, 'role');

        $roleGrants = $this->roleGrants;
        $roleGrants[$role] = [ScopeGrant::of($permission, $surface, $note)];

        return new self($this->scopeGrants, $roleGrants);
    }

    /**
     * Resolve a validated token into what it is allowed to do.
     *
     * The ONLY way a {@see PermissionGrant} is produced, which is what makes the
     * class docblock's claim about unmapped names checkable.
     */
    public function grantsFor(AccessTokenClaims $claims): PermissionGrant
    {
        $grants = [];
        $unmappedScopes = [];

        foreach ($claims->scopes() as $scope) {
            if (array_key_exists($scope, $this->scopeGrants)) {
                foreach ($this->scopeGrants[$scope] as $grant) {
                    $grants[] = $grant;
                }

                continue;
            }

            $unmappedScopes[] = $scope;
        }

        $unmappedRoles = [];
        foreach ($claims->roles() as $role) {
            if (array_key_exists($role, $this->roleGrants)) {
                foreach ($this->roleGrants[$role] as $grant) {
                    $grants[] = $grant;
                }

                continue;
            }

            $unmappedRoles[] = $role;
        }

        return new PermissionGrant($grants, $unmappedScopes, $unmappedRoles);
    }

    /**
     * The permissions an operation needs, before any token is considered.
     *
     * Always includes `access dfc api`, which no token can supply — see the class
     * docblock.
     *
     * @return list<DfcPermission>
     */
    public static function requiredPermissionsFor(DfcAction $action): array
    {
        return [DfcPermission::ACCESS_API, $action->requiredPermission()];
    }

    /**
     * The realm scope names that would supply $action's permission on $surface.
     *
     * FOR DIAGNOSTICS AND AUDIT ONLY. It is tempting to put this in a
     * `WWW-Authenticate: error="insufficient_scope", scope="..."` header on a 403,
     * and RFC 6750 §3.1 does describe exactly that — but
     * {@see \Civi\Dfc\V2\Controller\Error\ProtocolError} structurally forbids a
     * challenge on a 403 (a challenge is only permitted on a 401), so there is
     * nowhere in this surface for the value to go. See sa-006's report on that
     * asymmetry.
     *
     * @return list<string>
     */
    public function scopeHintsFor(DfcSurface $surface, DfcAction $action): array
    {
        $required = $action->requiredPermission();
        $hints = [];

        foreach ($this->scopeGrants as $scope => $grants) {
            foreach ($grants as $grant) {
                if (!$grant->appliesTo($surface)) {
                    continue;
                }

                if ($grant->permission()->implies($required)) {
                    $hints[] = $scope;
                }
            }
        }

        sort($hints, \SORT_STRING);

        return array_values(array_unique($hints));
    }

    /**
     * The scope names this registry has a row for, sorted.
     *
     * @return list<string>
     */
    public function mappedScopes(): array
    {
        $names = array_keys($this->scopeGrants);
        sort($names, \SORT_STRING);

        /** @var list<string> $names */
        return array_values($names);
    }

    /**
     * @return list<string>
     */
    public function mappedRoles(): array
    {
        $names = array_keys($this->roleGrants);
        sort($names, \SORT_STRING);

        /** @var list<string> $names */
        return array_values($names);
    }

    /**
     * Advertised realm scopes with no row in this registry.
     *
     * For {@see ScopePermissionRegistry::standard()} this is empty. It exists so a
     * deployment that starts from {@see empty()} can see how much of the realm's
     * advertised surface it has declined to map.
     *
     * @return list<string>
     */
    public function advertisedRealmScopesWithoutRow(): array
    {
        return array_values(array_diff(self::ADVERTISED_REALM_SCOPES, $this->mappedScopes()));
    }

    /**
     * The full table for the audit record and the diagnostics endpoint, including
     * the notes explaining each non-granting row.
     *
     * @return array<string, array{grants: list<array{permission: string, surface: string, note: string}>, note: string}>
     */
    public function toArray(): array
    {
        $table = [];

        $names = $this->mappedScopes();
        sort($names, \SORT_STRING);

        foreach ($names as $name) {
            $table[$name] = [
                'grants' => array_map(
                    static fn (ScopeGrant $grant): array => $grant->toArray(),
                    $this->scopeGrants[$name]
                ),
                'note' => self::REALM_SCOPE_NOTES[$name] ?? 'Configured by the deployment.',
            ];
        }

        return $table;
    }

    /**
     * The one place a scope name and a note become a {@see ScopeGrant}.
     *
     * Split out so {@see REALM_SCOPE_GRANTS} can be a table of NOTES (which is what
     * an auditor reads) rather than a table of object-construction calls (which is
     * not).
     */
    private static function grantForScope(string $scope, string $note): ScopeGrant
    {
        return match ($scope) {
            'ReadEnterprise', 'organization' => ScopeGrant::of(
                DfcPermission::READ_DATA,
                DfcSurface::ORGANIZATION,
                $note
            ),
            'webid' => ScopeGrant::of(
                DfcPermission::READ_DATA,
                DfcSurface::WEBID,
                $note
            ),
            default => throw new \LogicException(sprintf(
                'The scope "%s" is listed in the registry table with a note but no grant is defined for it. '
                . 'Every row with a note must state what it grants.',
                $scope
            )),
        };
    }

    private static function assertScopeName(string $name, string $what): string
    {
        // OAuth 2.0 scope-token grammar (RFC 6749 §3.3), which is also what
        // AuthenticateChallenge validates when echoing a scope back.
        if (preg_match('#^[A-Za-z0-9._~:/+\-]+$#', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A %s name must be an OAuth 2.0 scope token ([A-Za-z0-9._~:/+-]). Got "%s".',
                $what,
                $name
            ));
        }

        return $name;
    }
}