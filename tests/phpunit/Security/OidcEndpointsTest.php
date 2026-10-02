<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Security\OidcClientConfig;
use Civi\Dfc\V2\Security\OidcEndpoints;
use Civi\Dfc\V2\Security\TokenProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Endpoint discovery, and the reason there is no hardcoded base URI.
 *
 * PRD-002 "Upstream refresh" item 1: all four published OpenAPI files use
 * `https://login.fooddatacollaboration.org.uk/auth/realm/dev`, which 404s; the live
 * document is under `/realms/dev`. "A hardcoded `/auth/realm/dev` would pass review and
 * 401 on every request."
 *
 * The fixture is a recorded subset of the real document, so these tests run with no
 * network and still assert against the shape the real issuer publishes.
 */
#[CoversClass(OidcEndpoints::class)]
#[CoversClass(OidcClientConfig::class)]
#[CoversClass(TokenProfile::class)]
final class OidcEndpointsTest extends TestCase
{
    // -- The live realm, as recorded ------------------------------------------

    public function testTheRecordedDiscoveryDocumentResolves(): void
    {
        $endpoints = OidcEndpoints::fromDiscoveryDocument(
            JwtTestSupport::discoveryFixture(),
            JwtTestSupport::REALM
        );

        self::assertSame(JwtTestSupport::REALM, $endpoints->issuer());
        self::assertSame(JwtTestSupport::JWKS_URI, $endpoints->jwksUri());
        self::assertStringEndsWith('/protocol/openid-connect/token', (string) $endpoints->tokenEndpoint());
        self::assertStringEndsWith('/protocol/openid-connect/userinfo', (string) $endpoints->userinfoEndpoint());
    }

    public function testTheRealmHasNoAuthPathSegment(): void
    {
        // The regression this class exists to prevent: the published specs' URL.
        $endpoints = OidcEndpoints::fromDiscoveryDocument(JwtTestSupport::discoveryFixture());

        self::assertStringNotContainsString('/auth/realm/', $endpoints->issuer());
        self::assertStringNotContainsString('/auth/', $endpoints->jwksUri());
        self::assertStringStartsWith(
            'https://login.fooddatacollaboration.org.uk/realms/',
            $endpoints->issuer()
        );
    }

    public function testTheRealmAdvertisesTheSixteenScopesTheRegistryMaps(): void
    {
        $endpoints = OidcEndpoints::fromDiscoveryDocument(JwtTestSupport::discoveryFixture());

        self::assertCount(16, $endpoints->scopesSupported());

        foreach ($endpoints->scopesSupported() as $scope) {
            self::assertContains(
                $scope,
                \Civi\Dfc\V2\Security\ScopePermissionRegistry::ADVERTISED_REALM_SCOPES,
                sprintf('The registry must have a row for advertised scope "%s".', $scope)
            );
        }
    }

    public function testTheScopesInTheFixtureAndTheRegistryAreTheSameSet(): void
    {
        // The fixture was recorded from the realm; if upstream adds a scope, the fixture
        // and the registry must move together or the cross-check is worthless.
        $endpoints = OidcEndpoints::fromDiscoveryDocument(JwtTestSupport::discoveryFixture());

        $fixtureScopes = $endpoints->scopesSupported();
        $registryScopes = \Civi\Dfc\V2\Security\ScopePermissionRegistry::ADVERTISED_REALM_SCOPES;

        sort($fixtureScopes);
        sort($registryScopes);

        self::assertSame($registryScopes, $fixtureScopes);
    }

    public function testTheFixtureRecordsThatTheRealmAdvertisesSymmetricAlgorithms(): void
    {
        // Not used by this extension. Recorded because it is WHY the allow-list is a
        // fixed RS* list rather than "whatever the discovery document advertises":
        // trusting the advertisement would enable HMAC-with-public-key confusion.
        //
        // Read from the RAW fixture, not from discoveryFixture(), which strips the
        // `_`-prefixed commentary members — this member exists precisely to be
        // commentary.
        $raw = (string) file_get_contents(
            JwtTestSupport::fixturePath('oidc/dfc-dev-realm-discovery.json')
        );

        /** @var array<string, mixed> $document */
        $document = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);

        $note = $document['_signing_algorithms_advertised'];

        self::assertIsArray($note);
        self::assertContains('HS256', $note['advertised_and_rejected']);
        self::assertContains('HS512', $note['advertised_and_rejected']);
        self::assertContains('none', $note['advertised_and_rejected']);
        self::assertSame(['RS256'], $note['accepted_by_this_extension']);
        self::assertSame(
            OidcClientConfig::DEFAULT_ALGORITHMS,
            $note['accepted_by_this_extension'],
            'The fixture and the code must not disagree about what is accepted.'
        );
    }

    public function testTheCommentaryMembersAreStrippedFromTheParsedDocument(): void
    {
        // A real discovery document has no `_`-prefixed members, and feeding them to
        // OidcEndpoints would test a tolerance the real thing does not need.
        foreach (array_keys(JwtTestSupport::discoveryFixture()) as $key) {
            self::assertStringStartsNotWith('_', (string) $key);
        }
    }

    // -- Issuer verification --------------------------------------------------

    public function testAnIssuerMismatchIsRefused(): void
    {
        // Without this check, an attacker who can answer the discovery request on any
        // host this server trusts redirects signature verification to keys they chose.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/names issuer .* but this deployment expects/');

        OidcEndpoints::fromDiscoveryDocument(
            JwtTestSupport::discoveryFixture(),
            'https://login.fooddatacollaboration.org.uk/realms/production'
        );
    }

    public function testAnIssuerComparisonIsExact(): void
    {
        // RFC 8414 §3.3: the issuer identifier is an exact string. A trailing-slash
        // tolerance is a realm-confusion hole.
        $document = JwtTestSupport::discoveryFixture();
        $document['issuer'] = JwtTestSupport::REALM . '/';

        $this->expectException(\InvalidArgumentException::class);

        OidcEndpoints::fromDiscoveryDocument($document, JwtTestSupport::REALM);
    }

    public function testAnIssuerCaseDifferenceIsRefused(): void
    {
        $document = JwtTestSupport::discoveryFixture();
        $document['issuer'] = strtoupper(JwtTestSupport::REALM);

        $this->expectException(\InvalidArgumentException::class);

        OidcEndpoints::fromDiscoveryDocument($document, JwtTestSupport::REALM);
    }

    // -- Malformed documents --------------------------------------------------

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedDocuments(): iterable
    {
        yield 'no issuer' => [['jwks_uri' => 'https://example.test/certs']];
        yield 'empty issuer' => [['issuer' => '', 'jwks_uri' => 'https://example.test/certs']];
        yield 'issuer not a string' => [['issuer' => 7, 'jwks_uri' => 'https://example.test/certs']];
        yield 'no jwks_uri' => [['issuer' => 'https://example.test/realms/r']];
        yield 'empty jwks_uri' => [['issuer' => 'https://example.test/realms/r', 'jwks_uri' => '']];
        yield 'relative issuer' => [[
            'issuer' => '/realms/dev',
            'jwks_uri' => 'https://example.test/certs',
        ]];
        yield 'plaintext issuer' => [[
            'issuer' => 'http://example.test/realms/r',
            'jwks_uri' => 'https://example.test/certs',
        ]];
        yield 'issuer with a fragment' => [[
            'issuer' => 'https://example.test/realms/r#me',
            'jwks_uri' => 'https://example.test/certs',
        ]];
        yield 'issuer with a query string' => [[
            'issuer' => 'https://example.test/realms/r?x=1',
            'jwks_uri' => 'https://example.test/certs',
        ]];
        yield 'plaintext jwks_uri' => [[
            'issuer' => 'https://example.test/realms/r',
            'jwks_uri' => 'http://example.test/certs',
        ]];
        yield 'jwks_uri with whitespace' => [[
            'issuer' => 'https://example.test/realms/r',
            'jwks_uri' => 'https://example.test/ certs',
        ]];
        yield 'scopes not a list' => [[
            'issuer' => 'https://example.test/realms/r',
            'jwks_uri' => 'https://example.test/certs',
            'scopes_supported' => 'openid',
        ]];
        yield 'scope entry not a string' => [[
            'issuer' => 'https://example.test/realms/r',
            'jwks_uri' => 'https://example.test/certs',
            'scopes_supported' => ['openid', 7],
        ]];
    }

    #[DataProvider('malformedDocuments')]
    public function testAMalformedDiscoveryDocumentIsRefused(array $document): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OidcEndpoints::fromDiscoveryDocument($document);
    }

    public function testAnAbsentTokenEndpointIsNotFatal(): void
    {
        // This server is a resource server: it verifies signatures and calls nothing.
        $endpoints = OidcEndpoints::fromDiscoveryDocument([
            'issuer' => 'https://example.test/realms/r',
            'jwks_uri' => 'https://example.test/certs',
        ]);

        self::assertNull($endpoints->tokenEndpoint());
        self::assertNull($endpoints->userinfoEndpoint());
        self::assertSame([], $endpoints->scopesSupported());
    }

    public function testTheFlatViewCarriesPublicIdentifiersOnly(): void
    {
        $flat = OidcEndpoints::fromDiscoveryDocument(JwtTestSupport::discoveryFixture())->toArray();

        self::assertSame(
            ['issuer', 'jwks_uri', 'token_endpoint', 'userinfo_endpoint'],
            array_keys($flat)
        );

        foreach ($flat as $value) {
            self::assertStringStartsWith('https://', $value);
        }
    }

    // -- The client config ----------------------------------------------------

    public function testTheDefaultConfigAcceptsOnlyRs256(): void
    {
        $config = new OidcClientConfig(JwtTestSupport::AUDIENCE);

        self::assertSame(['RS256'], $config->allowedAlgorithms());
        self::assertTrue($config->allowsAlgorithm('RS256'));
        self::assertFalse($config->allowsAlgorithm('RS512'));
        self::assertSame(30, $config->clockSkewSeconds());
        self::assertTrue($config->enforcesAuthorizedParty());
    }

    public function testTheAudienceIsRequiredAndNonEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OidcClientConfig('');
    }

    public function testAnAudienceWithWhitespaceIsRefused(): void
    {
        // The audience is echoed in an RFC 6750 challenge, so it is written into a
        // response header.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/written into a response header/');

        new OidcClientConfig("urn:dfc\r\nX-Injected: 1");
    }

    public function testAnAudienceWithASpaceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OidcClientConfig('two words');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRangeSkew(): iterable
    {
        yield 'negative' => [-1];
        yield 'an hour' => [3600];
        yield 'a day' => [86400];
    }

    #[DataProvider('outOfRangeSkew')]
    public function testAnOutOfRangeClockSkewIsRefused(int $seconds): void
    {
        // Ten minutes is the ceiling: beyond that, refusing to accept a token is safer
        // than accepting it.
        $this->expectException(\InvalidArgumentException::class);

        new OidcClientConfig(JwtTestSupport::AUDIENCE, ['RS256'], $seconds);
    }

    public function testTheMaximumSkewIsAccepted(): void
    {
        self::assertSame(600, (new OidcClientConfig(JwtTestSupport::AUDIENCE, ['RS256'], 600))->clockSkewSeconds());
    }

    public function testAMalformedAlgorithmNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OidcClientConfig(JwtTestSupport::AUDIENCE, ['RS 256']);
    }

    // -- The token profile ----------------------------------------------------

    public function testTheStandardProfileForbidsTheFourIdTokenMarkers(): void
    {
        $profile = TokenProfile::standard();

        self::assertSame(['nonce', 'at_hash', 'c_hash', 's_hash'], $profile->forbiddenClaims());
        self::assertSame(['iss', 'sub', 'aud', 'exp'], $profile->requiredClaims());
        self::assertSame(['jwt', 'at+jwt', 'application/at+jwt'], $profile->acceptedTypes());
    }

    public function testTheStandardProfileDoesNotForbidAuthTime(): void
    {
        // Keycloak emits `auth_time` on access tokens too, so forbidding it would reject
        // genuine credentials from this issuer. The unreliable signals belong in a
        // comment, not in a denylist that breaks production.
        self::assertNotContains('auth_time', TokenProfile::standard()->forbiddenClaims());
    }

    public function testThePermissiveProfileForbidsNothing(): void
    {
        $profile = TokenProfile::permissive();

        self::assertSame([], $profile->forbiddenClaims());
        self::assertSame([], $profile->acceptedTypes());
        self::assertSame(['iss', 'sub', 'aud', 'exp'], $profile->requiredClaims(), 'RFC 9068 is not optional.');
    }

    public function testAForbiddenClaimNameIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TokenProfile(null, ['has a space']);
    }

    public function testAnAcceptedTypeIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TokenProfile(['']);
    }
}