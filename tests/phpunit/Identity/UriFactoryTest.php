<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Identity;

use Civi\Dfc\V2\Identity\DfcReleaseConfig;
use Civi\Dfc\V2\Identity\Exception\InvalidUriException;
use Civi\Dfc\V2\Identity\UriFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exact expected strings for every URI kind, plus encoding and validation.
 *
 * The stability-specific cases live in UriFactoryStabilityTest so a reviewer can
 * read the policy assertions on their own.
 */
#[CoversClass(UriFactory::class)]
final class UriFactoryTest extends TestCase
{
    use IdentityFixtureTrait;

    /** A realistic opaque identifier: Crockford base32, as the real generator emits. */


    private function factory(?DfcReleaseConfig $config = null): UriFactory
    {
        return new UriFactory($config ?? self::fixtureConfig());
    }

    // -- Platform -------------------------------------------------------------

    public function testPlatformWebId(): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/webid',
            $this->factory()->platformWebId()
        );
    }

    public function testPlatformSubject(): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/webid#me',
            $this->factory()->platformSubject()
        );
    }

    public function testIdentityService(): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/identity-service',
            $this->factory()->identityService()
        );
    }

    // -- User and organization ------------------------------------------------

    public function testUserWebId(): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/users/01HZY8QK3M7X4V2N6T9B0C5D8E/webid',
            $this->factory()->userWebId(IdentityFixtures::USER_KEY)
        );
    }

    public function testUserSubjectIsTheMeFragmentOfTheUserWebId(): void
    {
        $factory = $this->factory();

        self::assertSame(
            $factory->userWebId(IdentityFixtures::USER_KEY) . '#me',
            $factory->userSubject(IdentityFixtures::USER_KEY)
        );
    }

    public function testOrganizationWebId(): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V/webid',
            $this->factory()->organizationWebId(IdentityFixtures::ORGANIZATION_KEY)
        );
    }

    public function testOrganizationSubject(): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V/webid#me',
            $this->factory()->organizationSubject(IdentityFixtures::ORGANIZATION_KEY)
        );
    }

    public function testOrganizationContainerKeepsItsTrailingSlash(): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V/',
            $this->factory()->organizationContainer(IdentityFixtures::ORGANIZATION_KEY)
        );
    }

    public function testOrganizationIndexIsAContainedResource(): void
    {
        $factory = $this->factory();

        self::assertSame(
            'https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V/index',
            $factory->organizationIndex(IdentityFixtures::ORGANIZATION_KEY)
        );
    }

    /**
     * LDP containment is a MUST in the DFC standard, not a convenience: the URI
     * of a contained resource must be container URI + "/" + name. If this ever
     * stops holding, `ldp:contains` triples stop corresponding to the path
     * hierarchy and the containment invariant the standard relies on is gone.
     */
    public function testOrganizationIndexEqualsContainerPlusName(): void
    {
        $factory = $this->factory();

        self::assertSame(
            $factory->organizationContainer(IdentityFixtures::ORGANIZATION_KEY) . UriFactory::RESOURCE_INDEX,
            $factory->organizationIndex(IdentityFixtures::ORGANIZATION_KEY)
        );
    }

    // -- Generic containers ---------------------------------------------------

    public function testLdpContainerUriIsHierarchicalAndSlashTerminated(): void
    {
        $factory = $this->factory();

        self::assertSame(
            'https://platform.example/dfc/v2/organizations/' . IdentityFixtures::ORGANIZATION_KEY . '/',
            $factory->ldpContainerUri(UriFactory::COLLECTION_ORGANIZATIONS, IdentityFixtures::ORGANIZATION_KEY)
        );
    }

    public function testNestedLdpContainer(): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/organizations/' . IdentityFixtures::ORGANIZATION_KEY . '/catalogs/',
            $this->factory()->ldpContainerUri(UriFactory::COLLECTION_ORGANIZATIONS, IdentityFixtures::ORGANIZATION_KEY, 'catalogs')
        );
    }

    public function testLdpContainerNeedsAtLeastOneSegment(): void
    {
        $this->expectException(InvalidUriException::class);
        $this->expectExceptionMessage('at least one path segment');

        $this->factory()->ldpContainerUri();
    }

    public function testContainedResourceUriJoinsWithoutDoublingTheSlash(): void
    {
        $factory = $this->factory();

        self::assertSame(
            'https://platform.example/dfc/v2/organizations/' . IdentityFixtures::ORGANIZATION_KEY . '/index',
            $factory->containedResourceUri(
                $factory->organizationContainer(IdentityFixtures::ORGANIZATION_KEY),
                UriFactory::RESOURCE_INDEX
            )
        );

        // Tolerated: a container URI without the trailing slash still works.
        self::assertSame(
            'https://platform.example/dfc/v2/catalogue/index',
            $factory->containedResourceUri('https://platform.example/dfc/v2/catalogue', 'index')
        );
    }

    // -- Semantic resources ---------------------------------------------------

    public function testSemanticResourceUri(): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/semantic/address/' . IdentityFixtures::USER_KEY,
            $this->factory()->semanticResourceUri('address', IdentityFixtures::USER_KEY)
        );
    }

    public function testSemanticResourceUriIsScopedByDfcType(): void
    {
        $factory = $this->factory();

        // Same identifier, different class: different resources. Without the type
        // segment an Address and a PhoneNumber minted from the same key would
        // collide in a public, dereferenceable graph.
        self::assertNotSame(
            $factory->semanticResourceUri('address', IdentityFixtures::USER_KEY),
            $factory->semanticResourceUri('phone-number', IdentityFixtures::USER_KEY)
        );
    }

    public function testSemanticResourceUriNeverEmbedsTheDfcVersion(): void
    {
        $descriptor = self::fixtureDescriptor();
        $descriptor['dfc_ontology_version'] = '2.1.0';
        $descriptor['ontology']['business_url'] = 'https://w3id.org/dfc/ontology/v2.1.0/src/DFC_BusinessOntology.rdf';
        $descriptor['ontology']['technical_url'] = 'https://w3id.org/dfc/ontology/v2.1.0/src/DFC_TechnicalOntology.rdf';

        $before = $this->factory();
        $after = $this->factory(DfcReleaseConfig::fromDescriptorArray(
            $descriptor,
            ['platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI]
        ));

        self::assertSame(
            $before->semanticResourceUri('address', IdentityFixtures::USER_KEY),
            $after->semanticResourceUri('address', IdentityFixtures::USER_KEY)
        );
        self::assertStringNotContainsString('2.0.0', $before->semanticResourceUri('address', IdentityFixtures::USER_KEY));
    }

    #[DataProvider('rejectedDfcTypeProvider')]
    public function testRejectedDfcTypeSegment(string $dfcType): void
    {
        $this->expectException(InvalidUriException::class);

        $this->factory()->semanticResourceUri($dfcType, IdentityFixtures::USER_KEY);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedDfcTypeProvider(): array
    {
        return [
            'empty' => [''],
            'upper case' => ['Address'],
            'slash' => ['dfc/b/address'],
            'dot dot' => ['..'],
            'space' => ['physical place'],
            'leading dash' => ['-address'],
            'percent sign' => ['add%20ress'],
            'hash' => ['address#1'],
        ];
    }

    // -- Subjects -------------------------------------------------------------

    public function testSubjectOfAppendsMe(): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/webid#me',
            $this->factory()->subjectOf('https://platform.example/dfc/v2/webid')
        );
    }

    public function testSubjectOfRefusesAUriThatAlreadyHasAFragment(): void
    {
        $this->expectException(InvalidUriException::class);
        $this->expectExceptionMessage('must not already carry a fragment');

        $this->factory()->subjectOf('https://platform.example/dfc/v2/users/x/webid#extended');
    }

    public function testSubjectOfRejectsWhitespace(): void
    {
        $this->expectException(InvalidUriException::class);

        $this->factory()->subjectOf("https://platform.example/dfc/v2/web id");
    }

    // -- Percent-encoding of hostile identifiers ------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function hostileIdentifierProvider(): array
    {
        return [
            'path traversal stays one segment' => [
                '../../etc/passwd',
                '..%2F..%2Fetc%2Fpasswd',
                'the slashes are encoded, so the segment cannot climb the hierarchy',
            ],
            'bare dot dot' => [
                '../',
                '..%2F',
                'a trailing slash cannot escape the collection',
            ],
            'hash cannot start a fragment' => [
                'abc#def',
                'abc%23def',
                'otherwise the fragment would be silently dropped and two identifiers would collide',
            ],
            'question mark cannot start a query' => [
                'abc?x=1',
                'abc%3Fx%3D1',
                'otherwise the query would be silently dropped',
            ],
            'space becomes percent-20 not plus' => [
                'john smith',
                'john%20smith',
                'rawurlencode, not urlencode: "+" is not a space in a path',
            ],
            'percent sign is not double decoded' => [
                'a%2Fb',
                'a%252Fb',
                'the identifier contains a literal "%", which is encoded',
            ],
            'non ascii utf8' => [
                'Ünïcødé',
                '%C3%9Cn%C3%AFc%C3%B8d%C3%A9',
                'RFC 3986 percent-encodes UTF-8 bytes, not code points',
            ],
            'cjk' => [
                '農家',
                '%E8%BE%B2%E5%AE%B6',
                'same rule, different alphabet',
            ],
            'emoji' => [
                '🍎',
                '%F0%9F%8D%8E',
                'four UTF-8 bytes, four triplets',
            ],
            'ampersand and equals' => [
                'a&b=c',
                'a%26b%3Dc',
                'these are legal in a query but not in a pchar',
            ],
            'backtick and braces' => [
                'x`{y}',
                'x%60%7By%7D',
                'sub-delims and gen-delims are excluded by the unreserved-set rule',
            ],
            'plus sign is a literal plus' => [
                'a+b',
                'a%2Bb',
                'in a path "+" is not a space, so it must not be decoded as one',
            ],
            'surrounding whitespace is encoded not trimmed' => [
                '  john  ',
                '%20%20john%20%20',
                'the stored identifier is authoritative, so its exact bytes must round-trip',
            ],
            'semicolon and colon' => [
                'urn:x;y',
                'urn%3Ax%3By',
                'a colon would look like a scheme if the segment ever became absolute',
            ],
            'windows drive letter' => [
                'C:\\Windows',
                'C%3A%5CWindows',
                'backslashes and colons are encoded',
            ],
        ];
    }

    #[DataProvider('hostileIdentifierProvider')]
    public function testHostileIdentifierIsPercentEncodedInUserWebId(string $identifier, string $expectedSegment): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/users/' . $expectedSegment . '/webid',
            $this->factory()->userWebId($identifier)
        );
    }

    #[DataProvider('hostileIdentifierProvider')]
    public function testHostileIdentifierIsPercentEncodedInSemanticUri(string $identifier, string $expectedSegment): void
    {
        self::assertSame(
            'https://platform.example/dfc/v2/semantic/address/' . $expectedSegment,
            $this->factory()->semanticResourceUri('address', $identifier)
        );
    }

    /**
     * The encoding is not lossy: what comes back out is what went in. This is why
     * the factory validates identifiers instead of sanitising them.
     */
    #[DataProvider('hostileIdentifierProvider')]
    public function testEncodedIdentifierRoundTrips(string $identifier, string $expectedSegment): void
    {
        $factory = $this->factory();

        $uri = $factory->semanticResourceUri('address', $identifier);

        self::assertStringContainsString($expectedSegment, $uri);
        self::assertSame($identifier, $factory->matchSemanticIdentifier($uri));
    }

    public function testTraversalCannotEscapeTheCollection(): void
    {
        $factory = $this->factory();

        $uri = $factory->userWebId('../../../etc/passwd');

        self::assertStringNotContainsString('/../', $uri);
        // Still exactly one segment below /users/.
        self::assertSame('../../../etc/passwd', $factory->matchUserKey($uri));
        self::assertSame($uri, $factory->userWebId($factory->matchUserKey($uri) ?? ''));
    }

    // -- Rejected identifiers -------------------------------------------------

    #[DataProvider('rejectedIdentifierProvider')]
    public function testRejectedIdentifierThrows(string $identifier, string $messageFragment): void
    {
        $this->expectException(InvalidUriException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messageFragment, '/') . '/');

        $this->factory()->userWebId($identifier);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedIdentifierProvider(): array
    {
        return [
            'empty string' => ['', 'is empty'],
            'single space' => [' ', 'whitespace only'],
            'tabs and newlines only' => ["\t\n", 'whitespace only'],
            'dot' => ['.', 'relative-path reference'],
            'dot dot' => ['..', 'relative-path reference'],
            'nul byte' => ["a\0b", 'control character'],
            'bell character' => ["a\x07b", 'control character'],
            'delete character' => ["a\x7Fb", 'control character'],
            'over 255 bytes' => [str_repeat('a', 256), 'over the 255-byte limit'],
        ];
    }

    public function testIdentifierOfExactlyTheLimitIsAccepted(): void
    {
        $identifier = str_repeat('a', UriFactory::MAX_IDENTIFIER_BYTES);

        self::assertSame(
            'https://platform.example/dfc/v2/users/' . $identifier . '/webid',
            $this->factory()->userWebId($identifier)
        );
    }

    public function testOverLongIdentifierIsProbablyAMistakeNotAnAttackButStillRejected(): void
    {
        $this->expectException(InvalidUriException::class);
        $this->expectExceptionMessage('usually means a display name, a JSON blob or an HTML fragment');

        $this->factory()->semanticResourceUri(
            'organization',
            'Acme Corporation, Incorporated, 41 Example Street, Suite 900, Springfield, Illinois 62704, '
            . 'United States of America, incorporated 1974, VAT identification number US123456789, '
            . 'registered with the Illinois Secretary of State, trading as Acme Global Holdings, '
            . 'a wholly owned subsidiary of Example Industries International.'
        );
    }

    #[DataProvider('rejectedLabelProvider')]
    public function testRejectedResourceLabelThrows(string $name): void
    {
        $this->expectException(InvalidUriException::class);

        $this->factory()->containedResourceUri('https://platform.example/dfc/v2/catalogue/', $name);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedLabelProvider(): array
    {
        return [
            'empty' => [''],
            'slash' => ['sub/index'],
            'dot dot' => ['..'],
            'leading dash' => ['-index'],
            'space' => ['my index'],
            'percent' => ['index%20'],
            'hash' => ['index#x'],
            'question mark' => ['index?x=1'],
            'unicode' => ['indéx'],
        ];
    }

    public function testContainedResourceRejectsAHostileContainerUri(): void
    {
        $this->expectException(InvalidUriException::class);
        $this->expectExceptionMessage('cannot take a contained resource');

        $this->factory()->containedResourceUri('https://platform.example/dfc/v2/cat alogue', 'index');
    }

    // -- Identifier minting ---------------------------------------------------

    public function testIdentifiersComeFromTheInjectedGenerator(): void
    {
        $generator = new ScriptedIdentifierGenerator([IdentityFixtures::USER_KEY, IdentityFixtures::ORGANIZATION_KEY]);
        $factory = new UriFactory(self::fixtureConfig(), $generator);

        self::assertSame(IdentityFixtures::USER_KEY, $factory->generateIdentifier('contact'));
        self::assertSame(IdentityFixtures::ORGANIZATION_KEY, $factory->generateIdentifier('organization'));
        self::assertSame(['contact', 'organization'], $generator->requestedKinds());
    }

    public function testNewSemanticResourceUriUsesTheInjectedGenerator(): void
    {
        $factory = new UriFactory(
            self::fixtureConfig(),
            new ScriptedIdentifierGenerator(['FIXEDKEY000000000000000000'])
        );

        self::assertSame(
            'https://platform.example/dfc/v2/semantic/address/FIXEDKEY000000000000000000',
            $factory->newSemanticResourceUri('address', 'contact')
        );
    }

    public function testAnUnusableGeneratedIdentifierFailsAtMintTimeNotAtReadTime(): void
    {
        // A generator that returns nothing is a defect in the generator. Failing
        // here means the record is never created with an empty identity; failing
        // later means a public URI that addresses its parent collection.
        $factory = new UriFactory(
            self::fixtureConfig(),
            ScriptedIdentifierGenerator::returningUnusable('')
        );

        $this->expectException(InvalidUriException::class);
        $this->expectExceptionMessage('is empty');

        $factory->generateIdentifier('contact');
    }

    public function testAGeneratedIdentifierIsValidatedEvenWhenItLooksHarmlessButIsOverlong(): void
    {
        $factory = new UriFactory(
            self::fixtureConfig(),
            ScriptedIdentifierGenerator::returningUnusable(str_repeat('z', 300))
        );

        $this->expectException(InvalidUriException::class);
        $this->expectExceptionMessage('over the 255-byte limit');

        $factory->generateIdentifier('contact');
    }

    public function testAFailingGeneratorIsNotSwallowed(): void
    {
        $factory = new UriFactory(self::fixtureConfig(), ScriptedIdentifierGenerator::throwing());

        $this->expectException(InvalidUriException::class);
        $this->expectExceptionMessage('scripted failure');

        $factory->generateIdentifier('contact');
    }

    // -- Base-URI prefix and trailing-slash handling --------------------------

    /**
     * A base with a multi-segment path prefix must keep every segment: losing one
     * would mint URIs that resolve outside the advertised base entirely.
     */
    public function testMultiSegmentBasePathPrefixIsPreserved(): void
    {
        $factory = $this->factory(self::fixtureConfig('https://site.example/crm/sites/12/dfc/v2'));

        self::assertSame(
            'https://site.example/crm/sites/12/dfc/v2/users/' . IdentityFixtures::USER_KEY . '/webid',
            $factory->userWebId(IdentityFixtures::USER_KEY)
        );
        self::assertSame(
            'https://site.example/crm/sites/12/dfc/v2/organizations/' . IdentityFixtures::ORGANIZATION_KEY . '/',
            $factory->organizationContainer(IdentityFixtures::ORGANIZATION_KEY)
        );
        self::assertSame(
            'https://site.example/crm/sites/12/dfc/v2/webid',
            $factory->platformWebId()
        );
    }

    public function testSiteRootBaseEmitsNoDoubledSlash(): void
    {
        $factory = $this->factory(self::fixtureConfig('https://site.example'));

        self::assertSame('https://site.example/webid', $factory->platformWebId());
        self::assertSame('https://site.example/identity-service', $factory->identityService());
        self::assertSame('https://site.example/users/' . IdentityFixtures::USER_KEY . '/webid', $factory->userWebId(IdentityFixtures::USER_KEY));
        self::assertSame('https://site.example/semantic/address/' . IdentityFixtures::USER_KEY, $factory->semanticResourceUri('address', IdentityFixtures::USER_KEY));
    }

    public function testBaseUriGivenWithSloppySlashesStillEmitsCleanUris(): void
    {
        $factory = $this->factory(self::fixtureConfig('https://site.example//dfc//v2///'));

        $uris = [
            $factory->platformWebId(),
            $factory->identityService(),
            $factory->userWebId(IdentityFixtures::USER_KEY),
            $factory->organizationContainer(IdentityFixtures::ORGANIZATION_KEY),
            $factory->organizationIndex(IdentityFixtures::ORGANIZATION_KEY),
            $factory->semanticResourceUri('address', IdentityFixtures::USER_KEY),
            $factory->ldpContainerUri(UriFactory::COLLECTION_ORGANIZATIONS, IdentityFixtures::ORGANIZATION_KEY, 'catalogs'),
        ];

        foreach ($uris as $uri) {
            self::assertStringStartsWith('https://site.example/dfc/v2/', $uri);
            self::assertStringNotContainsString('//', substr($uri, strpos($uri, '://') + 3), $uri);
        }
    }

    public function testEveryEmittedUriHasNoWhitespaceAndNoEmptySegment(): void
    {
        $factory = $this->factory();

        $uris = [
            $factory->platformWebId(),
            $factory->platformSubject(),
            $factory->identityService(),
            $factory->userWebId(IdentityFixtures::USER_KEY),
            $factory->userSubject(IdentityFixtures::USER_KEY),
            $factory->organizationWebId(IdentityFixtures::ORGANIZATION_KEY),
            $factory->organizationSubject(IdentityFixtures::ORGANIZATION_KEY),
            $factory->organizationContainer(IdentityFixtures::ORGANIZATION_KEY),
            $factory->organizationIndex(IdentityFixtures::ORGANIZATION_KEY),
            $factory->semanticResourceUri('address', IdentityFixtures::USER_KEY),
            $factory->ldpContainerUri(UriFactory::COLLECTION_ORGANIZATIONS, IdentityFixtures::ORGANIZATION_KEY),
        ];

        foreach ($uris as $uri) {
            self::assertDoesNotMatchRegularExpression('/\s/', $uri, $uri);
            self::assertStringNotContainsString('//', substr($uri, strpos($uri, '://') + 3), $uri);
            self::assertStringStartsWith('https://platform.example/dfc/v2/', $uri);
        }
    }
}