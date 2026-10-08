<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Http;

use Civi\Dfc\Http\SubPath\MalformedSubPathException;
use Civi\Dfc\Http\SubPath\SubPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SubPath is the seam that makes path-style WebIDs possible.
 *
 * It parses whatever CiviCRM routed to us after the registered prefix. That
 * value is user input, and it is about to become part of a *published identity
 * URI*. So the design rule throughout is: refuse, never sanitise.
 *
 * A sanitised path mints a WebID that nobody - including us - predicted, and a
 * WebID we cannot predict is an identity we cannot resolve. A 400 is strictly
 * better than a wrong identity.
 */
final class SubPathTest extends TestCase
{
    private const ROUTE = 'civicrm/dfc/v2/users';

    // -- the shapes the DFC contract actually uses ----------------------------

    public function testItReadsAUserWebIdSubPath(): void
    {
        $sub = SubPath::fromRequestPath('/civicrm/dfc/v2/users/01HZY8QK/webid', self::ROUTE);

        self::assertSame(['01HZY8QK', 'webid'], $sub->segments());
        self::assertFalse($sub->isEmpty());
        self::assertSame(2, $sub->count());
    }

    public function testItReadsTheContractUserShapes(): void
    {
        foreach (['webid', 'prefs', 'privateTypeIndex'] as $leaf) {
            $sub = SubPath::fromRequestPath('/civicrm/dfc/v2/users/john/' . $leaf, self::ROUTE);

            self::assertTrue(
                $sub->matches(['{userId}', $leaf], $bound),
                'contract shape /users/{userId}/' . $leaf . ' must match'
            );
            self::assertSame(['userId' => 'john'], $bound);
        }
    }

    public function testItReadsAnOrganizationResourceSubPath(): void
    {
        $sub = SubPath::fromRequestPath(
            '/civicrm/dfc/v2/organizations/awesome-farm',
            'civicrm/dfc/v2/organizations'
        );

        self::assertTrue($sub->matches(['{organizationId}'], $bound));
        self::assertSame(['organizationId' => 'awesome-farm'], $bound);
    }

    public function testTheContainerItselfIsAnEmptySubPath(): void
    {
        $sub = SubPath::fromRequestPath('/civicrm/dfc/v2/users', self::ROUTE);

        self::assertTrue($sub->isEmpty());
        self::assertSame([], $sub->segments());
        self::assertNull($sub->first());
    }

    // -- CiviCRM hands over different shapes depending on CMS -----------------

    #[DataProvider('requestPathShapes')]
    public function testItAcceptsEveryShapeCiviCrmMayHandOver(string $pathInfo): void
    {
        $sub = SubPath::fromRequestPath($pathInfo, self::ROUTE);

        self::assertSame(['john', 'webid'], $sub->segments(), 'from ' . $pathInfo);
    }

    /** @return array<string, array{0: string}> */
    public static function requestPathShapes(): array
    {
        return [
            'PATH_INFO' => ['/civicrm/dfc/v2/users/john/webid'],
            'REQUEST_URI with query string' => ['/civicrm/dfc/v2/users/john/webid?limit=10'],
            'REQUEST_URI with fragment' => ['/civicrm/dfc/v2/users/john/webid#me'],
            'absolute URI' => ['https://crm.example.org/civicrm/dfc/v2/users/john/webid'],
            'trailing slash' => ['/civicrm/dfc/v2/users/john/webid/'],
            'leading double slash' => ['//civicrm/dfc/v2/users/john/webid'],
        ];
    }

    // -- refusals -------------------------------------------------------------

    public function testItRefusesADoubledSlash(): void
    {
        // Collapsing would mean /users//webid and /users/webid mint one identity.
        $this->expectException(MalformedSubPathException::class);
        SubPath::fromRequestPath('/civicrm/dfc/v2/users//webid', self::ROUTE);
    }

    public function testItRefusesARelativeSegment(): void
    {
        $this->expectException(MalformedSubPathException::class);
        SubPath::fromRequestPath('/civicrm/dfc/v2/users/../webid', self::ROUTE);
    }

    public function testItRefusesAnEncodedPathSeparator(): void
    {
        // %2F decodes to '/', which would smuggle an extra segment past a naive
        // check and let /users/a%2Fb resolve as if it were /users/a/b.
        $this->expectException(MalformedSubPathException::class);
        SubPath::fromRequestPath('/civicrm/dfc/v2/users/a%2Fb/webid', self::ROUTE);
    }

    public function testItRefusesAPathOutsideTheRegisteredRoute(): void
    {
        $this->expectException(MalformedSubPathException::class);
        SubPath::fromRequestPath('/civicrm/admin/evil', self::ROUTE);
    }

    public function testItRefusesAPrefixThatOnlyLooksLikeTheRoute(): void
    {
        // "users-evil" starts with "users" as a string but is not under it.
        $this->expectException(MalformedSubPathException::class);
        SubPath::fromRequestPath('/civicrm/dfc/v2/users-evil/webid', self::ROUTE);
    }

    public function testItRefusesAnAbsurdlyLongSubPath(): void
    {
        $this->expectException(MalformedSubPathException::class);
        SubPath::fromRequestPath('/civicrm/dfc/v2/users/' . str_repeat('a', 600), self::ROUTE);
    }

    public function testItRefusesASubjectMarkerSmuggledAsAPathSegment(): void
    {
        // #me belongs in the URI fragment, never as a path segment. If a client
        // sends /users/john/webid%23me, # is data, not a fragment.
        $sub = SubPath::fromRequestPath('/civicrm/dfc/v2/users/john/webid%23me', self::ROUTE);

        self::assertSame(['john', 'webid#me'], $sub->segments(),
            'decoded faithfully - the handler must not treat this as the #me subject');
    }

    // -- shape matching -------------------------------------------------------

    public function testShapeMatchingBindsPlaceholders(): void
    {
        $sub = SubPath::fromRequestPath(
            '/civicrm/dfc/v2/organizations/awesome-farm/physical-places/farm-gate',
            'civicrm/dfc/v2/organizations'
        );

        self::assertTrue($sub->matches(
            ['{organizationId}', 'physical-places', '{placeId}'],
            $bound
        ));
        self::assertSame(
            ['organizationId' => 'awesome-farm', 'placeId' => 'farm-gate'],
            $bound
        );
    }

    public function testShapeMatchingFailsOnTheWrongNumberOfSegments(): void
    {
        $sub = SubPath::fromRequestPath('/civicrm/dfc/v2/users/john', self::ROUTE);

        self::assertFalse($sub->matches(['{userId}', 'webid'], $bound));
        self::assertSame([], $bound, 'a failed match must not leave partial bindings behind');
    }

    public function testShapeMatchingFailsOnALiteralMismatch(): void
    {
        $sub = SubPath::fromRequestPath('/civicrm/dfc/v2/users/john/prefs', self::ROUTE);

        self::assertFalse($sub->matches(['{userId}', 'webid'], $bound));
    }

    public function testIsExactlyRejectsPrefixMatches(): void
    {
        $sub = SubPath::fromRequestPath('/civicrm/dfc/v2/users/john/webid', self::ROUTE);

        self::assertTrue($sub->isExactly(['john', 'webid']));
        self::assertFalse($sub->isExactly(['john']));
        self::assertFalse($sub->isExactly(['john', 'webid', 'extra']));
    }

    // -- round trip: what we mint is what we can read back --------------------

    #[DataProvider('identityShapes')]
    public function testAMintedSubPathReadsBackIdentically(string $path): void
    {
        $sub = SubPath::fromRequestPath($path, 'civicrm/dfc/v2');

        self::assertSame(
            trim($path, '/'),
            'civicrm/dfc/v2/' . SubPath::encode($sub->segments()),
            'the URI we publish must parse back to exactly the same segments'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function identityShapes(): array
    {
        return [
            'platform webid' => ['/civicrm/dfc/v2/webid'],
            'user webid' => ['/civicrm/dfc/v2/users/01HZY8QK3M7X4V2N6T9B0C5D8E/webid'],
            'organization' => ['/civicrm/dfc/v2/organizations/awesome-farm'],
            'nested place' => ['/civicrm/dfc/v2/organizations/awesome-farm/physical-places/farm-gate'],
            'address index' => ['/civicrm/dfc/v2/organizations/awesome-farm/addresses/farm-address/index'],
        ];
    }

    public function testEncodingIsCanonical(): void
    {
        self::assertSame('a%20b', SubPath::encode(['a b']));
        self::assertSame('a%2Fb', SubPath::encode(['a/b']),
            'a slash inside one segment must be encoded, or it becomes two segments');
        self::assertSame('caf%C3%A9', SubPath::encode(['café']));
        self::assertSame('plain', SubPath::encode(['plain']));
    }
}