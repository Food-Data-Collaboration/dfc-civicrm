<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Security\AccessTokenValidator;
use Civi\Dfc\V2\Security\JwtDecodeException;
use Civi\Dfc\V2\Security\JwksUnavailableException;
use Civi\Dfc\V2\Security\OidcClientConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The claim-level checks, one hostile claim at a time.
 *
 * CP-3 names this suite: "OIDC adversarial suite: wrong issuer, wrong audience,
 * expired, nbf violation, unacceptable alg, altered signature, unknown `kid` + JWKS
 * refresh, insufficient scope, valid identity but unauthorized CiviCRM permission".
 * The `alg`, signature and `kid` cases live in {@see LocalJwtDecoderTest} and
 * {@see JwksCacheTest}; the rest are here.
 *
 * EVERY test anchors time to a fixed instant through {@see TestClock}, so an
 * "expired" assertion is a statement about the rule rather than about the wall clock.
 *
 * @see TokenProfileTest for the ID-token rejection in isolation
 * @see ScopePermissionRegistryTest for the scope → permission mapping
 */
#[CoversClass(AccessTokenValidator::class)]
#[CoversClass(\Civi\Dfc\V2\Security\AccessTokenClaims::class)]
final class AccessTokenValidatorTest extends TestCase
{
    private const KID = 'validator-kid';

    // -- The happy path -------------------------------------------------------

    public function testAValidTokenIsAccepted(): void
    {
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'scope' => 'openid ReadEnterprise webid',
            'realm_access' => ['roles' => ['dfc-reader']],
        ]));

        self::assertSame(JwtTestSupport::SUBJECT, $claims->subject());
        self::assertSame(JwtTestSupport::REALM, $claims->issuer());
        self::assertSame([JwtTestSupport::AUDIENCE], $claims->audiences());
        self::assertTrue($claims->hasScope('ReadEnterprise'));
        self::assertTrue($claims->hasRole('dfc-reader'));
        self::assertSame('fixture-token-1', $claims->tokenId());
    }

    public function testExpiryAndNotBeforeAreReflectedAsInstants(): void
    {
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID));

        self::assertSame(
            $harness->clock->timestamp() + JwtTestSupport::LIFETIME_SECONDS,
            $claims->expiresAt()->getTimestamp()
        );
        self::assertNotNull($claims->notBefore());
        self::assertSame('UTC', $claims->expiresAt()->getTimezone()->getName());
    }

    public function testNoNetworkCallIsMadeForASecondTokenWithinTheTtl(): void
    {
        // The load-protection claim, asserted rather than intended.
        $harness = OidcHarness::build(self::KID);

        $harness->validator->validate(JwtFactory::accessToken(self::KID));
        $harness->validator->validate(JwtFactory::accessToken(self::KID));
        $harness->validator->validate(JwtFactory::accessToken(self::KID));

        self::assertSame(1, $harness->fetcher->callCount());
        self::assertSame([JwtTestSupport::JWKS_URI], $harness->fetcher->requestedUris());
    }

    // -- Wrong issuer ---------------------------------------------------------

    public function testWrongIssuerIsRejected(): void
    {
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/different issuer/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'iss' => 'https://evil.example/realms/dev',
        ]));
    }

    public function testAnIssuerThatDiffersOnlyByATrailingSlashIsRejected(): void
    {
        // The relaxation this guards against: RFC 8414 §3.3 defines the issuer
        // identifier as an exact string, and a trailing-slash-tolerant comparison is a
        // realm-confusion hole.
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'iss' => JwtTestSupport::REALM . '/',
        ]));
    }

    public function testTheIssuerIsNotEchoedInTheRejection(): void
    {
        $harness = OidcHarness::build(self::KID);

        try {
            $harness->validator->validate(JwtFactory::accessToken(self::KID, [
                'iss' => 'https://evil.example/realms/dev',
            ]));
            self::fail('A wrong issuer must be rejected.');
        } catch (JwtDecodeException $rejected) {
            self::assertStringNotContainsString('evil.example', $rejected->getMessage());
        }
    }

    // -- Wrong audience -------------------------------------------------------

    public function testWrongAudienceIsRejected(): void
    {
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/not for this API/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'aud' => 'some-other-client',
            'azp' => 'some-other-client',
        ]));
    }

    public function testThisApiAmongSeveralAudiencesIsAccepted(): void
    {
        // An access token legitimately names several audiences. Containment, not
        // equality — provided `azp` agrees.
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'aud' => [JwtTestSupport::AUDIENCE, 'another-api'],
            'azp' => JwtTestSupport::AUDIENCE,
        ]));

        self::assertContains('another-api', $claims->audiences());
        self::assertSame(JwtTestSupport::AUDIENCE, $claims->authorizedParty());
    }

    public function testSeveralAudiencesWithoutAzpIsRejected(): void
    {
        // RFC 8725 §3.1: several audiences and no `azp` means the token's intended
        // resource server is unknown, so it must not be assumed to be this one.
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/no "azp"/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'aud' => [JwtTestSupport::AUDIENCE, 'another-api'],
            'azp' => null,
        ]));
    }

    public function testAzpOutsideTheAudienceListIsRejected(): void
    {
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/internally inconsistent/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'aud' => [JwtTestSupport::AUDIENCE],
            'azp' => 'a-third-party',
        ]));
    }

    public function testAzpEnforcementCanBeDisabledByConfiguration(): void
    {
        // Configured off means the check is genuinely off, not merely silent.
        $harness = OidcHarness::build(
            self::KID,
            null,
            new OidcClientConfig(JwtTestSupport::AUDIENCE, ['RS256'], 30, null, false)
        );

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'aud' => [JwtTestSupport::AUDIENCE, 'another-api'],
            'azp' => null,
        ]));

        self::assertCount(2, $claims->audiences());
    }

    public function testATokenForTheUserinfoEndpointIsRejected(): void
    {
        // The confused-deputy case: legitimately issued, just not to us.
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'aud' => 'dfc-client-userinfo',
            'azp' => 'dfc-client-userinfo',
        ]));
    }

    // -- Expiry ---------------------------------------------------------------

    public function testExpiredTokenIsRejected(): void
    {
        $harness = OidcHarness::build(self::KID);
        $harness->clock->advanceTo('2026-10-02T14:00:00+00:00');

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/has expired/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID));
    }

    public function testATokenInsideTheClockSkewIsStillAccepted(): void
    {
        // Skew exists so that a token which expired 10 seconds ago on a host whose
        // clock runs slow is not rejected. 30 seconds of skew, 10 seconds past exp.
        $harness = OidcHarness::build(self::KID);
        $harness->clock->advance(JwtTestSupport::LIFETIME_SECONDS + 10);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID));

        self::assertSame(JwtTestSupport::SUBJECT, $claims->subject());
    }

    public function testATokenExpiredBeyondTheClockSkewIsRejected(): void
    {
        $harness = OidcHarness::build(self::KID);
        $harness->clock->advance(JwtTestSupport::LIFETIME_SECONDS + 31);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/has expired/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID));
    }

    public function testZeroSkewMeansStrictExpiry(): void
    {
        $harness = OidcHarness::build(
            self::KID,
            null,
            new OidcClientConfig(JwtTestSupport::AUDIENCE, ['RS256'], 0)
        );
        $harness->clock->advance(JwtTestSupport::LIFETIME_SECONDS + 1);

        $this->expectException(JwtDecodeException::class);

        $harness->validator->validate(JwtFactory::accessToken(self::KID));
    }

    // -- nbf ------------------------------------------------------------------

    public function testNbfInTheFutureBeyondTheSkewIsRejected(): void
    {
        $harness = OidcHarness::build(self::KID);
        $now = $harness->clock->timestamp();

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/not valid yet/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'nbf' => $now + 600,
        ]));
    }

    public function testNbfInsideTheSkewIsAccepted(): void
    {
        $harness = OidcHarness::build(self::KID);
        $now = $harness->clock->timestamp();

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'nbf' => $now + 10,
        ]));

        self::assertNotNull($claims->notBefore());
    }

    public function testAnIatInTheFutureBeyondTheSkewIsRejected(): void
    {
        // `iat` is otherwise unexploited: it is an input to several replay
        // constructions, so it is held to the same skew rule as `nbf`.
        $harness = OidcHarness::build(self::KID);
        $now = $harness->clock->timestamp();

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/issued in the future/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'iat' => $now + 3600,
            'nbf' => $now - 10,
        ]));
    }

    // -- Missing and malformed claims ----------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requiredClaims(): iterable
    {
        yield 'iss' => ['iss', '/no "iss" claim/'];
        yield 'sub' => ['sub', '/no "sub" claim/'];
        yield 'aud' => ['aud', '/no "aud" claim/'];
        yield 'exp' => ['exp', '/no "exp" claim/'];
    }

    #[DataProvider('requiredClaims')]
    public function testAMissingRequiredClaimIsRejected(string $claim, string $message): void
    {
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches($message);

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [$claim => null]));
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function malformedClaims(): iterable
    {
        yield 'exp as a word' => ['exp', 'tomorrow', '/not a NumericDate/'];
        yield 'exp as an empty string' => ['exp', '', '/not a NumericDate/'];
        yield 'exp as a date string' => ['exp', '2026-10-02T12:00:00Z', '/not a NumericDate/'];
        yield 'iss as an array' => ['iss', ['a'], '/"iss" claim is not a non-empty string/'];
        yield 'sub as a number' => ['sub', 42, '/"sub" claim is not a non-empty string/'];
        yield 'aud as an empty array' => ['aud', [], '/neither a string nor a non-empty array/'];
        yield 'aud element not a string' => ['aud', [123], '/element of the token/'];
        yield 'azp as a number' => ['azp', 7, '/"azp" claim is not a non-empty string/'];
        yield 'jti as an array' => ['jti', ['x'], '/"jti" claim is not a non-empty string/'];
        yield 'scope as an array' => ['scope', ['a', 'b'], '/"scope" claim is not a string/'];
    }

    #[DataProvider('malformedClaims')]
    public function testAMalformedClaimIsRejected(string $claim, mixed $value, string $message): void
    {
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches($message);

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [$claim => $value]));
    }

    public function testAnExpOfZeroIsRejectedRatherThanTreatedAsAnAbsentExpiry(): void
    {
        // `exp: 0` is 1970. A lenient cast would make an expired token look valid for
        // a moment and then silently fail later.
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/has expired/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'exp' => 0,
            'nbf' => null,
            'iat' => null,
        ]));
    }

    public function testAnExpSentAsANumericStringIsAccepted(): void
    {
        // Some issuers send NumericDate as a string. Refusing it is an
        // interoperability bug, not a security control.
        $harness = OidcHarness::build(self::KID);
        $expires = $harness->clock->timestamp() + 600;

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'exp' => (string) $expires,
        ]));

        self::assertSame($expires, $claims->expiresAt()->getTimestamp());
    }

    public function testAUnixExpAtEpochIsNotSilentlyCoercedToNow(): void
    {
        // The companion to the NumericDate check: a number that IS valid but ancient
        // must be evaluated, not assumed valid.
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'exp' => 1,
            'nbf' => null,
            'iat' => null,
        ]));
    }

    // -- Algorithm allow-list -------------------------------------------------

    public function testAnAlgorithmOutsideTheAllowListIsRejectedBeforeAnyKeyLookup(): void
    {
        // The allow-list is checked BEFORE the key is selected, so an attacker
        // cannot make this server fetch a key set by sending a token with a junk
        // algorithm. Asserted by the fetch count.
        $harness = OidcHarness::build(self::KID);

        try {
            $harness->validator->validate(JwtFactory::hmacConfusionToken(self::KID, JwtFactory::claims()));
            self::fail('HS256 must be rejected.');
        } catch (JwtDecodeException $rejected) {
            self::assertStringContainsString('does not accept', $rejected->getMessage());
        }

        self::assertSame(0, $harness->fetcher->callCount(), 'No key set may be fetched for a rejected alg.');
    }

    public function testAnEmptyAlgorithmAllowListIsRefusedAtConfigurationTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/may not be empty/');

        new OidcClientConfig(JwtTestSupport::AUDIENCE, []);
    }

    public function testNoneCanNeverBeInTheAllowList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"none" can never be/');

        new OidcClientConfig(JwtTestSupport::AUDIENCE, ['none']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function symmetricAlgorithms(): iterable
    {
        foreach (['HS256', 'HS384', 'HS512'] as $algorithm) {
            yield $algorithm => [$algorithm];
        }
    }

    #[DataProvider('symmetricAlgorithms')]
    public function testASymmetricAlgorithmCanNeverBeAllowed(string $algorithm): void
    {
        // The realm's discovery document advertises HS256 and HS512 (verified live
        // 2026-10-02), so "allow whatever the issuer advertises" is a real option and a
        // real vulnerability.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/algorithm-confusion/');

        new OidcClientConfig(JwtTestSupport::AUDIENCE, ['RS256', $algorithm]);
    }

    public function testTheDefaultAllowListIsRs256Only(): void
    {
        // The live realm's signing key declares "alg": "RS256".
        self::assertSame(['RS256'], OidcClientConfig::DEFAULT_ALGORITHMS);
        self::assertSame(['RS256'], (new OidcClientConfig(JwtTestSupport::AUDIENCE))->allowedAlgorithms());
    }

    public function testAnAudienceIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OidcClientConfig('   ');
    }

    public function testAnExcessiveClockSkewIsRefused(): void
    {
        // An hour of replay tolerance is not a clock-skew allowance.
        $this->expectException(\InvalidArgumentException::class);

        new OidcClientConfig(JwtTestSupport::AUDIENCE, ['RS256'], 3600);
    }

    // -- ID tokens ------------------------------------------------------------

    public function testAnIdTokenIsRejected(): void
    {
        // PRD-002 §4.7: "validated OIDC access tokens (never an ID token)".
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);

        $harness->validator->validate(JwtFactory::accessToken(self::KID, null, ['typ' => 'ID']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function idTokenMarkers(): iterable
    {
        yield 'nonce' => ['nonce', 'abcdef'];
        yield 'at_hash' => ['at_hash', 'qwerty'];
        yield 'c_hash' => ['c_hash', 'qwerty'];
        yield 's_hash' => ['s_hash', 'qwerty'];
    }

    #[DataProvider('idTokenMarkers')]
    public function testIdTokenOnlyClaimsAreRejectedEvenWithAnAcceptedTyp(
        string $claim,
        string $value
    ): void {
        // Three independent signals, because the JOSE header is attacker-controlled.
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/only an ID token has/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID, [$claim => $value]));
    }

    public function testAnAbsentTypIsAcceptedBecauseRfc7519MakesItOptional(): void
    {
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, null, ['typ' => null]));

        self::assertSame(JwtTestSupport::SUBJECT, $claims->subject());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedTypes(): iterable
    {
        // RFC 9068 §2.1, and Keycloak's actual value for access tokens.
        yield 'JWT' => ['JWT'];
        yield 'jwt' => ['jwt'];
        yield 'at+jwt' => ['at+jwt'];
        yield 'application/at+jwt' => ['application/at+jwt'];
    }

    #[DataProvider('acceptedTypes')]
    public function testEveryAccessTokenTypeIsAccepted(string $type): void
    {
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, null, ['typ' => $type]));

        self::assertSame(JwtTestSupport::SUBJECT, $claims->subject());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedTypes(): iterable
    {
        yield 'ID' => ['ID'];
        yield 'id' => ['id'];
        yield 'ID Token' => ['ID Token'];
        yield 'refresh' => ['refresh'];
    }

    #[DataProvider('rejectedTypes')]
    public function testEveryNonAccessTokenTypeIsRejected(string $type): void
    {
        $harness = OidcHarness::build(self::KID);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/not an access-token type/');

        $harness->validator->validate(JwtFactory::accessToken(self::KID, null, ['typ' => $type]));
    }

    // -- Scopes and roles -----------------------------------------------------

    public function testScopesAreSplitOnWhitespaceAndDeduplicatedInOrder(): void
    {
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'scope' => "  openid   webid \t ReadEnterprise ",
        ]));

        self::assertSame(['openid', 'webid', 'ReadEnterprise'], $claims->scopes());
    }

    public function testRealmAndClientRolesAreBothExtracted(): void
    {
        // Keycloak's two documented shapes. A registry that only read one of them
        // would silently grant nothing to half this realm's identities.
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'realm_access' => ['roles' => ['reader', 'writer']],
            'resource_access' => [
                'dfc-civicrm' => ['roles' => ['writer', 'admin']],
                'some-other-client' => ['roles' => ['ignored']],
            ],
        ]));

        self::assertSame(['admin', 'ignored', 'reader', 'writer'], $claims->roles());
    }

    public function testNoScopeClaimMeansNoScopes(): void
    {
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, ['scope' => null]));

        self::assertSame([], $claims->scopes());
    }

    // -- The IdP being unavailable is NOT a rejected token --------------------

    public function testAnUnobtainableKeySetIsAFailureNotARejection(): void
    {
        // The distinction that stops an IdP outage becoming a wave of "your token is
        // invalid" reports.
        $harness = OidcHarness::build(self::KID);
        $harness->fetcher->failNext();

        $this->expectException(JwksUnavailableException::class);

        $harness->validator->validate(JwtFactory::accessToken(self::KID));
    }

    // -- Claims are read, never logged ----------------------------------------

    public function testDebugInfoExposesClaimNamesNotValues(): void
    {
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'email' => 'someone@example.invalid',
        ]));

        $dump = print_r($claims, true);

        self::assertStringContainsString('claimNames', $dump);
        self::assertStringNotContainsString('someone@example.invalid', $dump);
        self::assertStringNotContainsString(JwtTestSupport::SUBJECT, $dump);
    }

    public function testToArrayIsSafeForAnAuditRecord(): void
    {
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID));

        self::assertSame(
            ['issuer', 'subject', 'audiences', 'scopes', 'roles', 'authorizedParty', 'tokenId', 'expiresAt',
                'issuedAt', 'notBefore'],
            array_keys($claims->toArray())
        );
    }

    public function testAnUnpromotedClaimIsReadableButStillUntrusted(): void
    {
        $harness = OidcHarness::build(self::KID);

        $claims = $harness->validator->validate(JwtFactory::accessToken(self::KID, [
            'preferred_username' => 'someone',
        ]));

        self::assertSame('someone', $claims->claim('preferred_username'));
        self::assertNull($claims->claim('no-such-claim'));
    }
}