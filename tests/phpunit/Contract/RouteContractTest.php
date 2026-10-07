<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Contract;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Pins our declared routes against the published DFC contract.
 *
 * WHY THIS IS A TEST RATHER THAN A COMMENT.
 *
 * Three routes this extension originally declared did not exist in
 * dfc-ldp.yaml: /platform/webid, /persons and /places. All three were
 * invented. They would have installed cleanly, appeared in the CiviCRM menu,
 * and 404'd for every real DFC client — because no client written against the
 * standard would ever call them. Nothing in a unit suite would have caught it.
 *
 * The contract is a file we fetch, not something we ship, so the assertions
 * below run against a recorded copy of the path table rather than a live fetch:
 * a network dependency in a unit test is a flaky test, and this suite has to be
 * able to say "the contract moved and we did not" even offline.
 *
 * Update the fixture by re-fetching:
 *   curl -sS https://raw.githubusercontent.com/Food-Data-Collaboration/dfc-api-doc/main/dfc-ldp.yaml \
 *     -o tests/fixtures/contract/dfc-ldp.yaml
 * then copy the `paths:` keys into tests/fixtures/contract/dfc-ldp-paths.json.
 *
 * @see BLK-013, and https://github.com/Food-Data-Collaboration/dfc-api-doc/issues/15
 */
final class RouteContractTest extends TestCase
{
    /**
     * Our route paths, mapped to the DFC contract path each one implements.
     *
     * Left column: <path> in xml/Menu/dfc_civicrm.xml, minus the civicrm/dfc/v2
     * prefix. Right column: the OpenAPI path template it serves.
     *
     * The mapping is deliberately not 1:1 on the wire. CiviCRM's route table is
     * flat and cannot express a "{id}" path segment, so path templates become
     * query arguments. This table is the authoritative statement of which
     * contract path each route stands in for.
     */
    private const ROUTE_TO_CONTRACT = [
        // Discovery
        'webid' => '/webid',
        'identity-service' => '/identity-service',
        'users/webid' => '/users/{userId}/webid',
        'users/prefs' => '/users/{userId}/prefs',
        'users/private-type-index' => '/users/{userId}/privateTypeIndex',
        // Organizations
        'organizations' => '/organizations',
        'organizations/resource' => '/organizations/{organizationId}',
        'organizations/addresses' => '/organizations/{organizationId}/addresses',
        'organizations/physical-places' => '/organizations/{organizationId}/physical-places',
        'organizations/affiliated-to' => '/organizations/{organizationId}/affiliated-to',
        'organizations/social-medias' => '/organizations/{organizationId}/social-medias',
        'organizations/customer-categories' => '/organizations/{organizationId}/customer-categories',
        // Individuals and flat resources
        'persons/resource' => '/persons/{personId}/index',
        'phone-numbers' => '/phone-numbers/{phoneNumberId}/index',
    ];

    /**
     * Classes PRD-002 §1 puts permanently out of scope. dfc-ldp.yaml declares
     * them; we must not route them. A route here is not a gap, it is a scope
     * violation, and it would return partial product data over HTTP.
     */
    private const OUT_OF_SCOPE_SEGMENTS = [
        'supplied-products',
        'orders',
        'orderlines',
        'catalogs',
        'certifications',
        'template-sale-sessions',
        'routes',
    ];

    /**
     * Segments that LOOK out of scope but are not, and why.
     *
     * An earlier version of this list included 'customer-categories' and the
     * test caught it. Checked against dfc-ldp.yaml: that path is "Get
     * Organization Customer Categories Container", a container of
     * dfc-b:CustomerCategory - a controlled-vocabulary enumeration. It carries
     * no Product, Order or Offer semantics, it is GET-only, and it is not in the
     * PRD-002 §1 exclusion list. Routing it is correct.
     *
     * Recorded because the name reads like commerce, and the next person to
     * touch this list will wonder.
     */
    private const IN_SCOPE_AFTER_ALL = [
        'customer-categories' => 'container of dfc-b:CustomerCategory, a vocabulary enumeration; GET-only, no product or order semantics',
        'physical-places' => 'dfc-b:PhysicalPlace, in scope per PRD-002 §1 despite being org-scoped',
        'phone-numbers' => 'dfc-b:PhoneNumber, in scope per PRD-002 §1',
        'social-medias' => 'dfc-b:SocialMedia, in scope per PRD-002 §1',
        'addresses' => 'dfc-b:Address, in scope per PRD-002 §1',
        'affiliated-to' => 'dfc-b:affiliates relationship target, in scope per PRD-002 §1',
    ];

    /** In-scope contract paths that carry product or order semantics. */
    private const OUT_OF_SCOPE_CONTRACT_PATHS = [
        '/organizations/{organizationId}/supplied-products',
        '/organizations/{organizationId}/orders',
        '/organizations/{organizationId}/orders/{orderId}/index',
        '/organizations/{organizationId}/orders/{orderId}/orderlines',
        '/organizations/{organizationId}/orders/{orderId}/orderlines/{lineId}/index',
        '/organizations/{organizationId}/catalogs',
        '/organizations/{organizationId}/catalogs/{catalogId}/index',
        '/organizations/{organizationId}/certifications',
        '/organizations/{organizationId}/certifications/{certificationId}/index',
        '/organizations/{organizationId}/template-sale-sessions',
        '/organizations/{organizationId}/template-sale-sessions/{sessionId}/index',
        '/organizations/{organizationId}/routes',
        '/organizations/{organizationId}/routes/{routeId}/index',
    ];

    private SimpleXMLElement $menu;

    private const ROUTE_PREFIX = 'civicrm/dfc/v2';

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../../xml/Menu/dfc_civicrm.xml';
        self::assertFileExists($path, 'the route table under test must exist');
        $menu = @simplexml_load_file($path);
        self::assertNotFalse($menu, 'xml/Menu/dfc_civicrm.xml must be well-formed XML');
        $this->menu = $menu;
    }

    /** @return list<string> */
    private function declaredPaths(): array
    {
        $paths = [];
        foreach ($this->menu->item as $item) {
            $paths[] = (string) $item->path;
        }

        return $paths;
    }

    /** @return list<string> paths with our prefix stripped */
    private function declaredPathsRelative(): array
    {
        return array_map(
            static fn (string $p): string => str_replace(self::ROUTE_PREFIX . '/', '', $p),
            $this->declaredPaths()
        );
    }

    /** @return list<string> the recorded contract path table */
    private function contractPaths(): array
    {
        $fixture = __DIR__ . '/../../fixtures/contract/dfc-ldp-paths.json';
        self::assertFileExists($fixture, 'recorded contract path table missing; re-fetch dfc-ldp.yaml');
        $paths = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($paths);

        /** @var list<string> $paths */
        return $paths;
    }

    public function testEveryDeclaredRouteIsMappedToAContractPath(): void
    {
        $declared = $this->declaredPathsRelative();
        $unmapped = array_diff($declared, array_keys(self::ROUTE_TO_CONTRACT));

        self::assertSame(
            [],
            array_values($unmapped),
            'every declared route must state which DFC contract path it implements. '
            . 'The three routes we previously invented (/platform/webid, /persons, /places) '
            . 'were exactly the unmapped ones.'
        );
    }

    public function testEveryMappedContractPathActuallyExistsInTheContract(): void
    {
        $contract = $this->contractPaths();
        $missing = array_values(array_diff(
            array_values(self::ROUTE_TO_CONTRACT),
            $contract
        ));

        self::assertSame(
            [],
            $missing,
            'these routes claim to implement contract paths that dfc-ldp.yaml does not declare'
        );
    }

    /**
     * The regression this whole file exists for.
     */
    public function testTheRoutesWePreviouslyInventedAreNotReintroduced(): void
    {
        $declared = $this->declaredPathsRelative();

        self::assertNotContains(
            'platform/webid',
            $declared,
            'dfc-ldp.yaml has no /platform/webid - the platform WebID is served at /webid'
        );
        self::assertNotContains(
            'persons',
            $declared,
            'dfc-ldp.yaml has no /persons container - persons sit outside any organization'
        );
        self::assertNotContains(
            'places',
            $declared,
            'dfc-ldp.yaml has no top-level /places - places are nested as physical-places'
        );
        self::assertNotContains(
            'agents',
            $declared,
            'dfc-ldp.yaml declares no /agents container - Agent is a schema superclass'
        );
    }

    public function testNoRouteServesAnOutOfScopeClass(): void
    {
        $offenders = [];
        foreach ($this->declaredPathsRelative() as $path) {
            foreach (self::OUT_OF_SCOPE_SEGMENTS as $segment) {
                if (str_contains($path, $segment)) {
                    $offenders[] = $path . ' (matches ' . $segment . ')';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'PRD-002 §1 puts these permanently out of scope. Serving them over HTTP would '
            . 'return partial product/order data, which the plan explicitly forbids.'
        );
    }

    public function testNoRouteIsDeclaredForAnOutOfScopeContractPath(): void
    {
        $mappedTargets = array_values(self::ROUTE_TO_CONTRACT);
        $offenders = array_values(array_intersect($mappedTargets, self::OUT_OF_SCOPE_CONTRACT_PATHS));

        self::assertSame([], $offenders);
    }

    public function testEveryRouteIsPermissionGated(): void
    {
        foreach ($this->menu->item as $item) {
            self::assertSame(
                'access dfc api',
                (string) $item->access_arguments,
                'route ' . (string) $item->path . ' must be gated on the "access dfc api" permission'
            );
            self::assertNotSame('', (string) $item->page_callback);
            self::assertNotSame('', (string) $item->title);
        }
    }

    public function testEveryContainerRouteDeclaresWhichContainerItServes(): void
    {
        foreach ($this->menu->item as $item) {
            $callback = (string) $item->page_callback;
            if (!str_ends_with($callback, 'LdpContainer')) {
                continue;
            }
            $args = (string) $item->page_arguments;
            self::assertMatchesRegularExpression(
                '/^dfc_container=[a-z-]+$/',
                $args,
                'container route ' . (string) $item->path . ' must declare dfc_container=<name>'
            );
        }
    }

    /**
     * Every route needs a page class. None of them exist yet — lane-3 owns them —
     * so this asserts the mismatch is *known and bounded*, not silently growing.
     */
    public function testReferencedPageClassesDoNotExistYetAndThatIsKnown(): void
    {
        $missing = [];
        foreach ($this->menu->item as $item) {
            $class = (string) $item->page_callback;
            if (!class_exists($class)) {
                $missing[] = $class;
            }
        }

        if ($missing !== []) {
            self::assertContains(
                'Civi\Dfc\Http\Page\LdpContainer',
                array_values(array_unique($missing)),
                'sanity: the shared container handler is expected among the not-yet-written classes'
            );
            self::markTestIncomplete(
                sprintf(
                    '%d page class(es) belong to lane-3 and are not written yet (%s). '
                    . 'Routes are registered but return class-not-found until they land. '
                    . 'Delete this test once lane-3 completes.',
                    count($missing),
                    implode(', ', array_unique($missing))
                )
            );
        }

        self::assertSame([], $missing, 'all page classes exist');
    }

    public function testTheMenuDeclaresNoDuplicatePaths(): void
    {
        $paths = $this->declaredPaths();
        self::assertSame(
            count($paths),
            count(array_unique($paths)),
            'a duplicate <path> produces two civicrm_menu rows and an ambiguous route table'
        );
    }
}