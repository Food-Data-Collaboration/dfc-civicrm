<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Identity;

use Civi\Dfc\V2\Identity\DfcReleaseConfig;
use Civi\Dfc\V2\Identity\Exception\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The release/config value object: provenance, derivation and normalisation.
 */
#[CoversClass(DfcReleaseConfig::class)]
final class DfcReleaseConfigTest extends TestCase
{
    use IdentityFixtureTrait;

    // -- Provenance: the fixture yields the expected release contract ---------

    public function testFixtureYieldsTheExpectedDfcVersion(): void
    {
        self::assertSame('2.0.0', self::fixtureConfig()->dfcVersion());
    }

    public function testFixtureYieldsTheDerivedContextUrl(): void
    {
        self::assertSame(
            'https://w3id.org/dfc/ontology/v2.0.0/context/context_2.0.0.json',
            self::fixtureConfig()->contextUrl()
        );
    }

    /**
     * R1: the context URL is DERIVED from the version, never hand-coded, and the
     * derivation must agree with upstream's own constant.
     *
     * `Connector::DEFAULT_CONTEXT_URL` in the DFC-LinkML php-connector is
     * literally the string below. If this assertion ever needs editing, the
     * extension and the connector have diverged on the release contract.
     */
    public function testDerivedContextUrlMatchesTheUpstreamConnectorConstant(): void
    {
        $connectorDefault = 'https://w3id.org/dfc/ontology/v2.0.0/context/context_2.0.0.json';

        self::assertSame($connectorDefault, self::fixtureConfig()->contextUrl());
        self::assertSame(
            DfcReleaseConfig::ONTOLOGY_BASE_URL . '/v2.0.0/context/context_2.0.0.json',
            $connectorDefault
        );
    }

    public function testFixtureYieldsTheOntologyFileBase(): void
    {
        self::assertSame(
            'https://w3id.org/dfc/ontology/v2.0.0/',
            self::fixtureConfig()->ontologyFileBase()
        );
    }

    /**
     * (a) from the provenance note: the prefix IRIs are UNVERSIONED upstream.
     *
     * If a future upstream release versions them, this test fails and the class
     * docblock's claim (a) must be revisited — because that would change what a
     * DFC upgrade does to CURIEs.
     */
    public function testFixturePrefixesAreUnversionedExactlyAsUpstreamPinsThem(): void
    {
        $config = self::fixtureConfig();

        self::assertSame(
            'https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#',
            $config->businessPrefixIri()
        );
        self::assertSame(
            'https://w3id.org/dfc/ontology/src/DFC_TechnicalOntology.owl#',
            $config->technicalPrefixIri()
        );
        self::assertStringNotContainsString('/v2.0.0/', $config->businessPrefixIri());
        self::assertStringNotContainsString('/v2.0.0/', $config->technicalPrefixIri());
    }

    public function testFixturePrefixNames(): void
    {
        $config = self::fixtureConfig();

        self::assertSame('dfc-b', $config->businessPrefixName());
        self::assertSame('dfc-t', $config->technicalPrefixName());
    }

    public function testPrefixIriExpansionAndCurieRendering(): void
    {
        $config = self::fixtureConfig();

        self::assertSame(
            'https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#Organization',
            $config->businessOntologyIri('Organization')
        );
        self::assertSame(
            'https://w3id.org/dfc/ontology/src/DFC_TechnicalOntology.owl#Platform',
            $config->technicalOntologyIri('Platform')
        );
        self::assertSame('dfc-b:Organization', $config->curie('dfc-b', 'Organization'));
        self::assertSame('dfc-t:Platform', $config->curie('dfc-t', 'Platform'));
    }

    public function testUnknownPrefixIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown DFC prefix "dfc-x"');

        self::fixtureConfig()->curie('dfc-x', 'Organization');
    }

    public function testPlatformValuesComeFromTheDeploymentNotTheDescriptor(): void
    {
        $config = self::fixtureConfig();

        self::assertSame('https://platform.example/dfc/v2/', $config->platformBaseUri());
        self::assertSame('https://platform.example/dfc/v2/webid', $config->platformWebIdUri());
        self::assertSame('https://platform.example/dfc/v2/identity-service', $config->identityServiceUri());
        self::assertSame('https://platform.example/dfc/v2/semantic/', $config->semanticResourceBaseUri());
    }

    public function testToArrayExposesConfigurationAndNothingElse(): void
    {
        $asArray = self::fixtureConfig()->toArray();

        self::assertSame('2.0.0', $asArray['dfc_version']);
        self::assertSame('https://platform.example/dfc/v2/', $asArray['platform_base_uri']);
        self::assertCount(9, $asArray, 'toArray() is a fixed diagnostic view; adding keys is a deliberate change.');
    }

    // -- Base-URI normalisation ------------------------------------------------

    #[DataProvider('baseUriProvider')]
    public function testBaseUriIsNormalised(string $input, string $expected, string $why): void
    {
        self::assertSame($expected, DfcReleaseConfig::normaliseDirectoryUri($input), $why);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function baseUriProvider(): array
    {
        return [
            'no trailing slash' => [
                'https://site.example/dfc/v2',
                'https://site.example/dfc/v2/',
                'a path base gets exactly one trailing slash so joining cannot produce a doubled separator',
            ],
            'already has one trailing slash' => [
                'https://site.example/dfc/v2/',
                'https://site.example/dfc/v2/',
                'normalisation is idempotent',
            ],
            'several trailing slashes' => [
                'https://site.example/dfc/v2///',
                'https://site.example/dfc/v2/',
                'an empty trailing path segment is always a typo',
            ],
            'root only' => [
                'https://site.example',
                'https://site.example/',
                'the authority alone is the root directory',
            ],
            'root with slash' => [
                'https://site.example/',
                'https://site.example/',
                'already normalised',
            ],
            'repeated slashes inside the prefix' => [
                'https://site.example//dfc//v2/',
                'https://site.example/dfc/v2/',
                'the path prefix must survive without becoming double-slashed',
            ],
            'path prefix is preserved' => [
                'https://site.example/crm/sites/1/dfc/v2',
                'https://site.example/crm/sites/1/dfc/v2/',
                'a Civi multisite front-controller subdirectory must not be dropped',
            ],
            'scheme and host are lowercased' => [
                'HTTPS://Site.Example/DFC/V2/',
                'https://site.example/DFC/V2/',
                'hosts are case-insensitive; path case is NOT, so /DFC/V2 survives intact',
            ],
            'explicit port is kept' => [
                'https://site.example:8443/dfc/v2',
                'https://site.example:8443/dfc/v2/',
                'a non-default port is part of the authority and therefore part of every URI',
            ],
            'http loopback is allowed for tests' => [
                'http://127.0.0.1:8080/dfc/v2',
                'http://127.0.0.1:8080/dfc/v2/',
                'tests need http; production hosts do not',
            ],
            'ipv6 loopback is allowed' => [
                'http://[::1]/dfc/v2',
                'http://[::1]/dfc/v2/',
                'bracket form is preserved so the authority stays parseable',
            ],
        ];
    }

    #[DataProvider('normalisationIsIdempotentProvider')]
    public function testNormalisationIsIdempotent(string $input): void
    {
        $once = DfcReleaseConfig::normaliseDirectoryUri($input);

        self::assertSame($once, DfcReleaseConfig::normaliseDirectoryUri($once));
        self::assertStringEndsWith('/', $once);
        self::assertStringNotContainsString('//', substr($once, strpos($once, '://') + 3));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function normalisationIsIdempotentProvider(): array
    {
        return [
            'plain' => ['https://site.example/dfc/v2'],
            'sloppy' => ['https://site.example//dfc/v2//'],
            'root' => ['https://site.example'],
            'uppercase' => ['HTTPS://SITE.EXAMPLE/DFC'],
        ];
    }

    public function testDocumentUriKeepsNoTrailingSlash(): void
    {
        // A WebID document URI must NOT gain a trailing slash: ".../webid" and
        // ".../webid/" are different HTTP resources.
        self::assertSame(
            'https://site.example/dfc/v2/webid',
            DfcReleaseConfig::normaliseUri('https://site.example/dfc/v2/webid')
        );
        self::assertSame(
            'https://site.example/dfc/v2/webid',
            DfcReleaseConfig::normaliseUri('https://site.example/dfc/v2//webid')
        );
    }

    // -- Rejected configuration ------------------------------------------------

    #[DataProvider('rejectedBaseUriProvider')]
    public function testRejectedBaseUri(string $input, string $expectedMessageFragment): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessageFragment, '/') . '/');

        DfcReleaseConfig::normaliseDirectoryUri($input);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedBaseUriProvider(): array
    {
        return [
            'empty' => ['', 'non-empty'],
            'leading space' => [' https://site.example/', 'whitespace-free'],
            'trailing space' => ['https://site.example/ ', 'whitespace-free'],
            'relative path' => ['/dfc/v2', 'absolute http(s) URI'],
            'scheme relative' => ['//site.example/dfc/v2', 'absolute http(s) URI'],
            'urn scheme' => ['urn:dfc:v2', 'absolute http(s) URI'],
            'ftp scheme' => ['ftp://site.example/dfc', 'must use http or https'],
            'file scheme' => ['file:///dfc', 'must use http or https'],
            'did scheme' => ['did:example:123', 'absolute http(s) URI'],
            'query string' => ['https://site.example/dfc?v=2', 'no query string'],
            'fragment' => ['https://site.example/dfc#v2', 'no query string'],
            'userinfo' => ['https://user:pass@site.example/dfc', 'must not contain userinfo'],
            'http on a public host' => ['http://site.example/dfc', 'plaintext'],
            'bad port' => ['https://site.example:port/dfc', 'malformed host:port'],
            'empty host' => ['https:///dfc', 'empty host'],
        ];
    }

    public function testPlatformBaseUriIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The override "platform_base_uri" is required');

        DfcReleaseConfig::fromDescriptorArray(self::fixtureDescriptor());
    }

    public function testUnknownOverrideIsRejectedRatherThanIgnored(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown DFC release override "platform_base_uri_typo"');

        DfcReleaseConfig::fromDescriptorArray(
            self::fixtureDescriptor(),
            ['platform_base_uri_typo' => 'https://site.example/']
        );
    }

    public function testMissingPrefixesAreNotGuessed(): void
    {
        $descriptor = self::fixtureDescriptor();
        unset($descriptor['dfc_civicrm']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Refusing to guess');

        DfcReleaseConfig::fromDescriptorArray($descriptor, ['platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI]);
    }

    public function testMissingVersionIsRejected(): void
    {
        $descriptor = self::fixtureDescriptor();
        unset($descriptor['dfc_ontology_version']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('missing a non-empty string at "dfc_ontology_version"');

        DfcReleaseConfig::fromDescriptorArray($descriptor, ['platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI]);
    }

    #[DataProvider('rejectedVersionProvider')]
    public function testRejectedVersion(string $version): void
    {
        $this->expectException(InvalidConfigurationException::class);

        DfcReleaseConfig::fromDescriptorArray(
            ['dfc_ontology_version' => $version, 'dfc_civicrm' => ['prefixes' => [
                'dfc-b' => 'https://example.org/ns/b#',
                'dfc-t' => 'https://example.org/ns/t#',
            ]]],
            ['platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI]
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedVersionProvider(): array
    {
        return [
            'major only' => ['2'],
            'major.minor' => ['2.0'],
            'not a version at all' => ['latest'],
            'empty-ish' => [' '],
            'path traversal' => ['../../etc'],
        ];
    }

    public function testNonSemverVersionIsRejectedWithAClearMessage(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('semver-shaped triple');

        DfcReleaseConfig::fromDescriptorArray(
            ['dfc_ontology_version' => 'latest', 'dfc_civicrm' => ['prefixes' => [
                'dfc-b' => 'https://example.org/ns/b#',
                'dfc-t' => 'https://example.org/ns/t#',
            ]]],
            ['platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI]
        );
    }

    public function testHalfBumpedDescriptorIsRejected(): void
    {
        // Version moved, ontology URLs did not: the descriptor would make this
        // extension advertise a version its own ontology URLs contradict.
        $descriptor = self::fixtureDescriptor();
        $descriptor['dfc_ontology_version'] = '2.1.0';

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('internally inconsistent');

        DfcReleaseConfig::fromDescriptorArray($descriptor, ['platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI]);
    }

    public function testFullyBumpedDescriptorIsAccepted(): void
    {
        $descriptor = self::fixtureDescriptor();
        $descriptor['dfc_ontology_version'] = '2.1.0';
        $descriptor['ontology']['business_url'] = 'https://w3id.org/dfc/ontology/v2.1.0/src/DFC_BusinessOntology.rdf';
        $descriptor['ontology']['technical_url'] = 'https://w3id.org/dfc/ontology/v2.1.0/src/DFC_TechnicalOntology.rdf';

        $config = DfcReleaseConfig::fromDescriptorArray($descriptor, ['platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI]);

        self::assertSame('2.1.0', $config->dfcVersion());
        self::assertSame(
            'https://w3id.org/dfc/ontology/v2.1.0/context/context_2.1.0.json',
            $config->contextUrl()
        );
    }

    public function testPrefixIriWithoutDelimiterIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must end in "#" or "/"');

        DfcReleaseConfig::fromDescriptorArray(
            ['dfc_ontology_version' => '2.0.0', 'dfc_civicrm' => ['prefixes' => [
                'dfc-b' => 'https://example.org/ns/b',
                'dfc-t' => 'https://example.org/ns/t#',
            ]]],
            ['platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI]
        );
    }

    public function testPlatformWebIdMustBeOneSegmentBelowTheBase(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be exactly one path segment below the platform base URI');

        DfcReleaseConfig::fromDescriptorArray(
            self::fixtureDescriptor(),
            [
                'platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI,
                'platform_web_id' => 'agent/webid',
            ]
        );
    }

    /**
     * The constructor is public so a reviewer can build one by hand, so the
     * "must sit below the base" guard has to be reachable directly.
     */
    public function testIdentityServiceOutsideTheBaseIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must sit below the platform base URI');

        new DfcReleaseConfig(
            '2.0.0',
            'https://w3id.org/dfc/ontology/v2.0.0/context/context_2.0.0.json',
            'https://platform.example/dfc/v2/',
            'https://platform.example/dfc/v2/webid',
            'https://elsewhere.example/identity-service',
            'https://platform.example/dfc/v2/semantic/',
            'https://w3id.org/dfc/ontology/v2.0.0/',
            'https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#',
            'https://w3id.org/dfc/ontology/src/DFC_TechnicalOntology.owl#',
        );
    }

    public function testSemanticBaseOutsideTheBaseIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must sit below the platform base URI');

        new DfcReleaseConfig(
            '2.0.0',
            'https://w3id.org/dfc/ontology/v2.0.0/context/context_2.0.0.json',
            'https://platform.example/dfc/v2/',
            'https://platform.example/dfc/v2/webid',
            'https://platform.example/dfc/v2/identity-service',
            'https://elsewhere.example/semantic/',
            'https://w3id.org/dfc/ontology/v2.0.0/',
            'https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#',
            'https://w3id.org/dfc/ontology/src/DFC_TechnicalOntology.owl#',
        );
    }

    public function testNonHttpsContextUrlIsRejected(): void
    {
        // Loopback, so the plaintext guard does not fire first and the https
        // guard on the context URL is the one under test.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The DFC context URL must be https');

        new DfcReleaseConfig(
            '2.0.0',
            'http://localhost/dfc/ontology/v2.0.0/context/context_2.0.0.json',
            'https://platform.example/dfc/v2/',
            'https://platform.example/dfc/v2/webid',
            'https://platform.example/dfc/v2/identity-service',
            'https://platform.example/dfc/v2/semantic/',
            'https://w3id.org/dfc/ontology/v2.0.0/',
            'https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#',
            'https://w3id.org/dfc/ontology/src/DFC_TechnicalOntology.owl#',
        );
    }

    public function testLeadingSlashInAnOverrideCannotDoubleTheSeparator(): void
    {
        $config = DfcReleaseConfig::fromDescriptorArray(
            self::fixtureDescriptor(),
            [
                'platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI,
                'platform_web_id' => '/webid',
            ]
        );

        self::assertSame('https://platform.example/dfc/v2/webid', $config->platformWebIdUri());
    }

    public function testMissingDescriptorFileNamesThePackagingRequirement(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('NOT config/dfc-release.yaml');

        DfcReleaseConfig::fromDescriptorFile('/nonexistent/dfc-release.yaml');
    }

    // -- R1: a DFC version bump must not touch the deployment namespace -------

    public function testDfcVersionBumpChangesNoDeploymentUri(): void
    {
        $before = self::fixtureConfig();

        $descriptor = self::fixtureDescriptor();
        $descriptor['dfc_ontology_version'] = '2.1.0';
        $descriptor['ontology']['business_url'] = 'https://w3id.org/dfc/ontology/v2.1.0/src/DFC_BusinessOntology.rdf';
        $descriptor['ontology']['technical_url'] = 'https://w3id.org/dfc/ontology/v2.1.0/src/DFC_TechnicalOntology.rdf';
        $after = DfcReleaseConfig::fromDescriptorArray($descriptor, ['platform_base_uri' => IdentityFixtures::DEFAULT_BASE_URI]);

        self::assertSame('https://w3id.org/dfc/ontology/v2.0.0/context/context_2.0.0.json', $before->contextUrl());
        self::assertSame('https://w3id.org/dfc/ontology/v2.1.0/context/context_2.1.0.json', $after->contextUrl());

        // This is the assertion that carries R1. The version is the only thing
        // that moved, and it is not in any of these.
        foreach (
            [
                'platformBaseUri',
                'platformWebIdUri',
                'identityServiceUri',
                'semanticResourceBaseUri',
            ] as $accessor
        ) {
            self::assertSame($before->{$accessor}(), $after->{$accessor}(), $accessor . ' changed on a DFC bump');
        }
    }

    // -- Hostname change ------------------------------------------------------

    public function testWithPlatformBaseUriCarriesEverythingExceptTheAuthority(): void
    {
        $before = self::fixtureConfig();
        $after = $before->withPlatformBaseUri('https://new-platform.example/dfc/v2');

        self::assertSame('https://new-platform.example/dfc/v2/', $after->platformBaseUri());
        self::assertSame('https://new-platform.example/dfc/v2/webid', $after->platformWebIdUri());
        self::assertSame('https://new-platform.example/dfc/v2/identity-service', $after->identityServiceUri());
        self::assertSame('https://new-platform.example/dfc/v2/semantic/', $after->semanticResourceBaseUri());

        // Unchanged: the release contract is a property of DFC, not of the site.
        self::assertSame($before->dfcVersion(), $after->dfcVersion());
        self::assertSame($before->contextUrl(), $after->contextUrl());
        self::assertSame($before->ontologyFileBase(), $after->ontologyFileBase());
        self::assertSame($before->businessPrefixIri(), $after->businessPrefixIri());
        self::assertSame($before->technicalPrefixIri(), $after->technicalPrefixIri());
        self::assertSame('webid', $after->webIdLeaf());
        self::assertSame('identity-service', $after->identityServiceLeaf());
    }

    public function testWithPlatformBaseUriRejectsAnUnusableNewBase(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::fixtureConfig()->withPlatformBaseUri('http://public.example/dfc');
    }

    // -- Immutability ---------------------------------------------------------

    public function testConfigIsImmutable(): void
    {
        $config = self::fixtureConfig();

        $before = $config->toArray();
        $config->withPlatformBaseUri('https://elsewhere.example/');
        $config->curie('dfc-b', 'Person');
        $config->businessOntologyIri('Thing');

        self::assertSame($before, $config->toArray());
    }
}