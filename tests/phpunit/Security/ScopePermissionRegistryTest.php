<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Security\AccessTokenClaims;
use Civi\Dfc\V2\Security\DfcAction;
use Civi\Dfc\V2\Security\DfcPermission;
use Civi\Dfc\V2\Security\DfcSurface;
use Civi\Dfc\V2\Security\PermissionGrant;
use Civi\Dfc\V2\Security\ScopeGrant;
use Civi\Dfc\V2\Security\ScopePermissionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one scope → permission mapping, and the rules about what it must not do.
 *
 * PRD-002 "Upstream refresh" item 2: "Map these to the four CiviCRM permissions in a
 * single registry." This suite is what makes "single" and "complete" checkable rather
 * than aspirational.
 *
 * @see BearerAuthenticatorTest for the end-to-end 403 behaviour
 */
#[CoversClass(ScopePermissionRegistry::class)]
#[CoversClass(PermissionGrant::class)]
#[CoversClass(ScopeGrant::class)]
#[CoversClass(DfcPermission::class)]
#[CoversClass(DfcSurface::class)]
#[CoversClass(DfcAction::class)]
final class ScopePermissionRegistryTest extends TestCase
{
    private ScopePermissionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = ScopePermissionRegistry::standard();
    }

    // -- Completeness ---------------------------------------------------------

    public function testEveryAdvertisedRealmScopeHasARow(): void
    {
        // "Complete" means the table accounts for every name the issuer advertises,
        // including the ten that grant nothing. An unmapped name would be reported at
        // runtime instead of at review time.
        self::assertSame([], $this->registry->advertisedRealmScopesWithoutRow());
    }

    public function testTheAdvertisedListIsTheSixteenTheRealmPublishes(): void
    {
        // Verified live 2026-10-02 against the realm's `scopes_supported`.
        self::assertCount(16, ScopePermissionRegistry::ADVERTISED_REALM_SCOPES);

        foreach (['openid', 'profile', 'ReadEnterprise', 'ReadProduct', 'webid', 'organization', 'roles',
            'email', 'phone', 'address', 'microprofile-jwt', 'web-origins', 'acr', 'offline_access', 'basic',
            'service_account'] as $scope) {
            self::assertContains($scope, ScopePermissionRegistry::ADVERTISED_REALM_SCOPES);
        }
    }

    public function testThePlansInventedScopeNamesAreNotRegistered(): void
    {
        // The plan offered `dfc:read:organization`, `dfc:write:organization`,
        // `dfc:read:person` and `dfc:admin`. The realm never issued them.
        foreach (['dfc:read:organization', 'dfc:write:organization', 'dfc:read:person', 'dfc:admin'] as $invented) {
            self::assertNotContains($invented, $this->registry->mappedScopes());
            self::assertFalse(
                $this->registry->grantsFor($this->claims([$invented]))->allows(DfcPermission::READ_DATA),
                sprintf('"%s" does not exist in this realm and must grant nothing.', $invented)
            );
        }
    }

    public function testEveryRowCarriesANote(): void
    {
        // An authorisation row with no stated reason is one nobody can review.
        $table = $this->registry->toArray();

        self::assertCount(16, $table);

        foreach ($table as $scope => $entry) {
            self::assertNotSame('', trim($entry['note']), sprintf('Scope "%s" has no note.', $scope));
        }
    }

    // -- The three granting scopes --------------------------------------------

    public function testReadEnterpriseGrantsReadOnTheOrganizationSurface(): void
    {
        $grant = $this->registry->grantsFor($this->claims(['ReadEnterprise']));

        self::assertTrue($grant->allowsOn(DfcSurface::ORGANIZATION, DfcAction::READ));
        self::assertTrue($grant->allows(DfcPermission::READ_DATA));
    }

    public function testOrganizationGrantsTheSameRead(): void
    {
        $grant = $this->registry->grantsFor($this->claims(['organization']));

        self::assertTrue($grant->allowsOn(DfcSurface::ORGANIZATION, DfcAction::READ));
    }

    public function testWebidGrantsReadOnTheWebidSurfaceOnly(): void
    {
        $grant = $this->registry->grantsFor($this->claims(['webid']));

        self::assertTrue($grant->allowsOn(DfcSurface::WEBID, DfcAction::READ));

        // Reading who someone is is not reading what they sell.
        self::assertFalse($grant->allowsOn(DfcSurface::ORGANIZATION, DfcAction::READ));
        self::assertFalse($grant->allowsOn(DfcSurface::CONTAINER, DfcAction::READ));
        self::assertFalse($grant->allowsOn(DfcSurface::PERSON, DfcAction::READ));
    }

    public function testAReadScopeNeverGrantsWrite(): void
    {
        foreach (['ReadEnterprise', 'organization', 'webid'] as $scope) {
            $grant = $this->registry->grantsFor($this->claims([$scope]));

            self::assertFalse(
                $grant->allows(DfcPermission::WRITE_DATA),
                sprintf('"%s" is a read scope and must not grant write.', $scope)
            );
            self::assertFalse($grant->allows(DfcPermission::ADMINISTER));
        }
    }

    // -- The scopes that deliberately grant nothing ----------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonGrantingScopes(): iterable
    {
        foreach ([
            'openid', 'profile', 'email', 'phone', 'address',
            'microprofile-jwt', 'web-origins', 'acr', 'offline_access', 'basic',
            'service_account', 'roles', 'ReadProduct',
        ] as $scope) {
            yield $scope => [$scope];
        }
    }

    #[DataProvider('nonGrantingScopes')]
    public function testANonGrantingScopeGrantsNothingAtAll(string $scope): void
    {
        $grant = $this->registry->grantsFor($this->claims([$scope]));

        self::assertTrue($grant->isEmpty(), sprintf('"%s" must grant nothing.', $scope));
        self::assertFalse($grant->allows(DfcPermission::ACCESS_API));
        self::assertFalse($grant->allows(DfcPermission::READ_DATA));
        self::assertFalse($grant->allows(DfcPermission::WRITE_DATA));
        self::assertFalse($grant->allows(DfcPermission::ADMINISTER));
    }

    #[DataProvider('nonGrantingScopes')]
    public function testANonGrantingScopeIsStillMappedSoItIsNotReportedAsUnmapped(string $scope): void
    {
        // The distinction between "deliberately grants nothing" and "nobody knows what
        // this is" is the whole point of the note column.
        $grant = $this->registry->grantsFor($this->claims([$scope]));

        self::assertSame([], $grant->unmappedScopes());
    }

    public function testReadProductGrantsNothingBecauseProductIsOutOfScope(): void
    {
        // PRD-002 §1 excludes every product class. Granting a read permission on a
        // class this server answers 404 for would advertise protection it does not have.
        $grant = $this->registry->grantsFor($this->claims(['ReadProduct']));

        self::assertTrue($grant->isEmpty());
        self::assertFalse($grant->allowsOn(DfcSurface::ORGANIZATION, DfcAction::READ));
    }

    public function testOpenidAloneDoesNotGrantADataRead(): void
    {
        // The single most important row: every OIDC login carries `openid`, so
        // granting on it would make every authenticated user a data reader.
        $grant = $this->registry->grantsFor($this->claims(['openid', 'profile', 'email']));

        self::assertTrue($grant->isEmpty());
    }

    public function testRolesScopeGrantsNothingBecauseTheRolesDo(): void
    {
        $grant = $this->registry->grantsFor($this->claims(['roles']));

        self::assertTrue($grant->isEmpty());
        self::assertSame([], $grant->unmappedScopes(), 'The scope is mapped; the ROLES are what carry grants.');
    }

    // -- Unmapped names -------------------------------------------------------

    public function testAnUnmappedScopeIsReportedAndGrantsNothing(): void
    {
        $grant = $this->registry->grantsFor($this->claims(['someScopeNobodyMapped']));

        self::assertTrue($grant->isEmpty());
        self::assertSame(['someScopeNobodyMapped'], $grant->unmappedScopes());
        self::assertFalse($grant->allows(DfcPermission::READ_DATA));
    }

    public function testAnUnmappedScopeIsNotTreatedAsFullAccess(): void
    {
        // Stated as an explicit assertion because "unrecognised means full access" is the
        // failure this design exists to prevent.
        foreach (['dfc:admin', 'everything', 'admin', '*', 'openid'] as $name) {
            $grant = $this->registry->grantsFor($this->claims([$name]));

            self::assertFalse(
                $grant->allows(DfcPermission::ADMINISTER),
                sprintf('"%s" must not be treated as administrative access.', $name)
            );
            self::assertFalse($grant->allows(DfcPermission::WRITE_DATA));
        }
    }

    public function testAnUnmappedRoleIsReported(): void
    {
        $grant = $this->registry->grantsFor($this->claims(['openid'], ['a-role-nobody-mapped']));

        self::assertSame(['a-role-nobody-mapped'], $grant->unmappedRoles());
        self::assertTrue($grant->isEmpty());
    }

    public function testAMappedScopeAndAnUnmappedOneCoexist(): void
    {
        $grant = $this->registry->grantsFor($this->claims(['webid', 'a-brand-new-scope']));

        self::assertTrue($grant->allowsOn(DfcSurface::WEBID, DfcAction::READ));
        self::assertSame(['a-brand-new-scope'], $grant->unmappedScopes());
    }

    // -- Roles, which is where write and administer come from -----------------

    public function testNoRoleIsMappedByDefault(): void
    {
        // The realm's role names are a deployment fact no discovery document states, and
        // guessing one is the same failure the plan made with scopes.
        self::assertSame([], ScopePermissionRegistry::standard()->mappedRoles());
    }

    public function testAConfiguredRoleGrantsThePermissionItNames(): void
    {
        $registry = $this->registry
            ->withRoleGrant('dfc-writer', DfcPermission::WRITE_DATA)
            ->withRoleGrant('dfc-admin', DfcPermission::ADMINISTER);

        $writer = $registry->grantsFor($this->claims([], ['dfc-writer']));
        self::assertTrue($writer->allowsOn(DfcSurface::ORGANIZATION, DfcAction::WRITE));

        $admin = $registry->grantsFor($this->claims([], ['dfc-admin']));
        self::assertTrue($admin->allows(DfcPermission::ADMINISTER));
    }

    public function testWriteImpliesReadAndAdministerImpliesEverything(): void
    {
        // The implication graph, stated once in DfcPermission and asserted here so a
        // change to it has to be deliberate.
        self::assertTrue(DfcPermission::ADMINISTER->implies(DfcPermission::READ_DATA));
        self::assertTrue(DfcPermission::ADMINISTER->implies(DfcPermission::WRITE_DATA));

        self::assertTrue(DfcPermission::WRITE_DATA->implies(DfcPermission::READ_DATA));
        self::assertFalse(DfcPermission::READ_DATA->implies(DfcPermission::WRITE_DATA));
        self::assertFalse(DfcPermission::READ_DATA->implies(DfcPermission::ADMINISTER));
    }

    public function testNoPermissionImpliesTheApiGate(): void
    {
        // The gate is checked against CiviCRM. If the implication graph reached it, a
        // caller's `allows(ACCESS_API)` would answer for a question only the CMS can
        // answer, and a caller who forgot to check would open the interface to any
        // administrator's token.
        foreach (DfcPermission::all() as $permission) {
            if ($permission === DfcPermission::ACCESS_API) {
                self::assertTrue($permission->implies(DfcPermission::ACCESS_API));

                continue;
            }

            self::assertFalse(
                $permission->implies(DfcPermission::ACCESS_API),
                sprintf('%s must not imply "access dfc api".', $permission->value)
            );
        }
    }

    public function testASurfaceScopedGrantDoesNotLeakToAnotherSurface(): void
    {
        $registry = $this->registry->withScopeGrant(
            'an-internal-scope',
            DfcPermission::WRITE_DATA,
            DfcSurface::ADDRESS,
            'Configured by the deployment.'
        );

        $grant = $registry->grantsFor($this->claims(['an-internal-scope']));

        self::assertTrue($grant->allowsOn(DfcSurface::ADDRESS, DfcAction::WRITE));
        self::assertFalse($grant->allowsOn(DfcSurface::PERSON, DfcAction::WRITE));
    }

    public function testANullSurfaceIsAWildcard(): void
    {
        $registry = $this->registry->withScopeGrant(
            'a-read-everything-scope',
            DfcPermission::READ_DATA,
            null,
            'Configured by the deployment.'
        );

        $grant = $registry->grantsFor($this->claims(['a-read-everything-scope']));

        foreach (DfcSurface::all() as $surface) {
            self::assertTrue($grant->allowsOn($surface, DfcAction::READ), $surface->value);
        }

        self::assertSame('*', $grant->grants()[0]->toArray()['surface']);
    }

    public function testWithScopeGrantReturnsANewRegistry(): void
    {
        $original = ScopePermissionRegistry::standard();
        $extended = $original->withScopeGrant('extra', DfcPermission::READ_DATA);

        self::assertNotSame([], $original->mappedScopes());
        self::assertNotContains('extra', $original->mappedScopes());
        self::assertContains('extra', $extended->mappedScopes());
    }

    // -- The API gate ---------------------------------------------------------

    public function testAccessApiIsRequiredForEveryOperationAndNeverGrantedByAToken(): void
    {
        self::assertSame(
            [DfcPermission::ACCESS_API, DfcPermission::READ_DATA],
            ScopePermissionRegistry::requiredPermissionsFor(DfcAction::READ)
        );
        self::assertSame(
            [DfcPermission::ACCESS_API, DfcPermission::WRITE_DATA],
            ScopePermissionRegistry::requiredPermissionsFor(DfcAction::WRITE)
        );

        // Even an administrator role does not put `access dfc api` into a token's
        // grant: it is a CiviCRM permission the CMS checks, not a scope.
        $registry = $this->registry->withRoleGrant('dfc-admin', DfcPermission::ADMINISTER);

        self::assertFalse($registry->grantsFor($this->claims([], ['dfc-admin']))->allows(DfcPermission::ACCESS_API));
    }

    // -- Surfaces -------------------------------------------------------------

    public function testNoProductOrOrderSurfaceExists(): void
    {
        // PRD-002 §1 excludes them, and a surface nothing serves must not be
        // authorisable.
        foreach (DfcSurface::all() as $surface) {
            self::assertStringNotContainsStringIgnoringCase('product', $surface->value);
            self::assertStringNotContainsStringIgnoringCase('order', $surface->value);
        }
    }

    public function testTheIdentityServiceIsIndependentlyAuthorisable(): void
    {
        // The one operation whose output is a LOCATION. An identity may discover its own
        // WebID through it without being able to read an organization's catalog.
        $registry = $this->registry->withRoleGrant(
            'dfc-self',
            DfcPermission::READ_DATA,
            DfcSurface::IDENTITY_SERVICE,
            'Configured by the deployment.'
        );

        $grant = $registry->grantsFor($this->claims([], ['dfc-self']));

        self::assertTrue($grant->allowsOn(DfcSurface::IDENTITY_SERVICE, DfcAction::READ));
        self::assertFalse($grant->allowsOn(DfcSurface::ORGANIZATION, DfcAction::READ));
    }

    // -- Scope hints ----------------------------------------------------------

    public function testScopeHintsNameTheScopesThatWouldSupplyThePermission(): void
    {
        self::assertSame(
            ['ReadEnterprise', 'organization'],
            $this->registry->scopeHintsFor(DfcSurface::ORGANIZATION, DfcAction::READ)
        );
        self::assertSame(['webid'], $this->registry->scopeHintsFor(DfcSurface::WEBID, DfcAction::READ));
        self::assertSame([], $this->registry->scopeHintsFor(DfcSurface::PERSON, DfcAction::READ));
    }

    public function testAnEmptyRegistryDeclinesToMapAnything(): void
    {
        $empty = ScopePermissionRegistry::empty();

        self::assertSame([], $empty->mappedScopes());
        self::assertCount(16, $empty->advertisedRealmScopesWithoutRow());
        self::assertTrue($empty->grantsFor($this->claims(['ReadEnterprise', 'webid']))->isEmpty());
    }

    // -- Validation -----------------------------------------------------------

    public function testAMalformedScopeNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/OAuth 2.0 scope token/');

        $this->registry->withScopeGrant('has a space', DfcPermission::READ_DATA);
    }

    public function testAGrantWithNoNoteIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must carry a note/');

        ScopeGrant::of(DfcPermission::READ_DATA, null, '   ');
    }

    public function testTheToArrayOutputCarriesNoKeyMaterialAndNoToken(): void
    {
        $table = $this->registry->toArray();

        $rendered = print_r($table, true);

        self::assertStringContainsString('read dfc data', $rendered);
        self::assertStringNotContainsString('BEGIN', $rendered);
        self::assertStringNotContainsString(JwtTestSupport::SUBJECT, $rendered);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @param list<string> $scopes
     * @param list<string> $roles
     */
    private function claims(array $scopes, array $roles = []): AccessTokenClaims
    {
        $issued = (new \DateTimeImmutable(JwtTestSupport::NOON_UTC))->getTimestamp();

        return AccessTokenClaims::fromVerifiedClaims(
            JwtTestSupport::REALM,
            JwtTestSupport::SUBJECT,
            [JwtTestSupport::AUDIENCE],
            (new \DateTimeImmutable('@' . ($issued + 3600))),
            null,
            null,
            $scopes,
            $roles,
            JwtTestSupport::AUDIENCE,
            't1',
            []
        );
    }
}