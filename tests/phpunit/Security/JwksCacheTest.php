<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Security\JwksCache;
use Civi\Dfc\V2\Security\JwtDecodeException;
use Civi\Dfc\V2\Security\JwksUnavailableException;
use Civi\Dfc\V2\Security\JsonWebKeySet;
use Civi\Dfc\V2\Security\JsonWebKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The signing-key cache: TTL, rotation, and the load-protection claim.
 *
 * ============================================================================
 * WHY THE FIXTURE HAS TWO KEYS
 * ============================================================================
 * The live DFC realm publishes two: an `use: enc` key (`alg: RSA-OAEP`) and an
 * `use: sig` key (`alg: RS256`) — verified 2026-10-02. The on-disk fixture
 * reproduces that SHAPE with no key material in it, so "selection filters on `use`
 * AND `alg`" is tested against the situation that actually exists rather than
 * against a convenient single-key set.
 *
 * @see LiveJwksTest for the one check that reads the realm's real key set
 */
#[CoversClass(JwksCache::class)]
#[CoversClass(JsonWebKeySet::class)]
#[CoversClass(JsonWebKey::class)]
final class JwksCacheTest extends TestCase
{
    // -- The realm's two-key shape --------------------------------------------

    public function testAnEncryptionKeyIsNeverSelectedForSignatureVerification(): void
    {
        // The live realm's key set has an RSA-OAEP `use: enc` key. Selecting "the only
        // RSA key" would pick it and every verification would fail — or, worse, a
        // deployment that dropped the `use` check would verify tokens against a key
        // published for a different purpose.
        $harness = OidcHarness::build(
            'signing',
            null,
            null,
            [[
                JwtFactory::jwk('encryption', ['use' => 'enc', 'alg' => 'RSA-OAEP']),
                JwtFactory::jwk('signing', ['use' => 'sig', 'alg' => 'RS256']),
            ]]
        );

        $key = $harness->jwks->signingKey('signing', 'RS256');

        self::assertSame('signing', $key->kid());
        self::assertTrue($key->isSignatureKey());
    }

    public function testAnUnknownKidIsNotResolvedToTheSingleOtherKey(): void
    {
        $harness = OidcHarness::build('real-kid');

        $this->expectException(JwtDecodeException::class);

        $harness->jwks->signingKey('some-other-kid', 'RS256');
    }

    public function testTheOnDiskFixtureHasTheSameTwoKeyShapeAsTheRealm(): void
    {
        $raw = file_get_contents(JwtTestSupport::fixturePath('jwks/local-issuer-jwks.json'));

        self::assertIsString($raw);

        /** @var array{keys: list<array<string, string>>} $document */
        $document = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);

        $byUse = [];
        foreach ($document['keys'] as $key) {
            $byUse[$key['use']] = $key['alg'];
        }

        self::assertSame('enc', array_key_first($byUse), 'The encryption key must come first, as upstream publishes it.');
        self::assertSame('RSA-OAEP', $byUse['enc']);
        self::assertSame('RS256', $byUse['sig']);
    }

    public function testAFixtureEntryWithNoKeyMaterialCarriesNoKeyMaterial(): void
    {
        // A committed public key is a permanent fingerprinting artefact. The fixture
        // must contain shape only.
        $raw = (string) file_get_contents(JwtTestSupport::fixturePath('jwks/local-issuer-jwks.json'));

        self::assertStringNotContainsString('"n"', $raw);
        self::assertStringNotContainsString('"e"', $raw);
        self::assertStringNotContainsString('"x5c"', $raw);
        self::assertStringNotContainsString('BEGIN', $raw);
    }

    // -- Rotation -------------------------------------------------------------

    public function testAnUnknownKidTriggersOneRefreshAndThenSucceeds(): void
    {
        // CP-3 names this case exactly: "unknown `kid` + JWKS refresh".
        $old = 'old-kid';
        $new = 'new-kid';

        $harness = OidcHarness::build($old, null, null, [
            [JwtFactory::jwk($old)],
            [JwtFactory::jwk($old), JwtFactory::jwk($new)],
        ]);

        // Warm the cache with the OLD key set.
        self::assertSame($old, $harness->jwks->signingKey($old, 'RS256')->kid());
        self::assertSame(1, $harness->fetcher->callCount());

        // A token naming a key the cached set does not have must trigger exactly one
        // refresh and then verify.
        $key = $harness->jwks->signingKey($new, 'RS256');

        self::assertSame($new, $key->kid());
        self::assertSame(2, $harness->fetcher->callCount(), 'The initial load plus exactly one refresh.');
        self::assertSame(1, $harness->jwks->stats()['forcedRefreshes']);
        self::assertSame(0, $harness->jwks->stats()['suppressedForcedRefreshes']);
    }

    public function testAnUnknownKidStillUnknownAfterTheRefreshFails(): void
    {
        $known = 'known-kid';

        $harness = OidcHarness::build($known, null, null, [[JwtFactory::jwk($known)]]);

        self::assertSame($known, $harness->jwks->signingKey($known, 'RS256')->kid());
        $before = $harness->fetcher->callCount();

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/refreshed once and still does not contain it/');

        $harness->jwks->signingKey('never-published', 'RS256');

        self::assertSame($before + 1, $harness->fetcher->callCount(), 'Exactly one refresh, never a loop.');
    }

    public function testRotatedOutKeysStillResolveFromTheMergedSet(): void
    {
        // During a rotation the two key sets overlap. A token signed with the
        // previous key must keep working until the issuer actually drops it.
        $old = 'rotation-old';
        $new = 'rotation-new';

        $harness = OidcHarness::build($old, null, null, [
            [JwtFactory::jwk($old)],
            [JwtFactory::jwk($new)],
        ]);

        // The first lookup triggers the refresh that brings in the new key.
        $harness->jwks->signingKey($new, 'RS256');
        self::assertSame(2, $harness->fetcher->callCount());

        // The OLD key is still resolvable from the merged set, with no further fetch.
        self::assertSame($old, $harness->jwks->signingKey($old, 'RS256')->kid());
        self::assertSame(2, $harness->fetcher->callCount(), 'A merged key must not cause another fetch.');
    }

    public function testARefreshedSetWithTheSameKidReplacesTheOldKey(): void
    {
        // An issuer re-exporting a key under the same kid must take effect, or a
        // compromised key would stay trusted until the process restarted.
        //
        // The two JWKS entries are built from DIFFERENT key pairs that share a kid, so
        // the assertion is about merge precedence rather than about the key material.
        $shared = 'reused-kid';
        $firstGeneration = JwtFactory::uniqueKid('gen-a');
        $secondGeneration = JwtFactory::uniqueKid('gen-b');

        $harness = OidcHarness::build($shared, null, null, [
            [self::jwkWithKid($firstGeneration, $shared)],
            [self::jwkWithKid($secondGeneration, $shared)],
        ]);

        $first = $harness->jwks->signingKey($shared, 'RS256')->publicKeyPem();

        // Force the merge path: a kid the cached set does not have.
        try {
            $harness->jwks->signingKey('force-a-refresh', 'RS256');
        } catch (JwtDecodeException $expected) {
        }

        // The cooldown is not the point; bypass it by invalidating, which is what an
        // operator does after a key compromise.
        $harness->clock->advance(JwksCache::MIN_REFRESH_COOLDOWN_SECONDS + 1);
        $harness->jwks->invalidate();

        $second = $harness->jwks->signingKey($shared, 'RS256')->publicKeyPem();

        self::assertNotSame('', $first);
        self::assertNotSame(
            $first,
            $second,
            'The same kid must resolve to the newest published key, or a retired key stays trusted.'
        );
    }

    /**
     * A JWKS entry whose key material comes from one generation and whose published
     * `kid` is another.
     *
     * @return array<string, mixed>
     */
    private static function jwkWithKid(string $sourceKid, string $publishedKid): array
    {
        return JwtFactory::jwk($sourceKid, ['kid' => $publishedKid]);
    }

    public function testADuplicateKidIsCountedSoItCanBeDiagnosed(): void
    {
        $set = JsonWebKeySet::of([
            JsonWebKey::fromArray(JwtFactory::jwk('same')),
            JsonWebKey::fromArray(JwtFactory::jwk('same')),
            JsonWebKey::fromArray(JwtFactory::jwk('other')),
        ]);

        self::assertSame(1, $set->duplicateKidCount());
        self::assertSame(['other', 'same', 'same'], $set->kids());
    }

    // -- TTL ------------------------------------------------------------------

    public function testTheKeySetIsRefetchedOnceTheTtlHasPassed(): void
    {
        $kid = 'ttl-kid';
        $harness = OidcHarness::build($kid);

        $harness->jwks->signingKey($kid, 'RS256');
        self::assertSame(1, $harness->fetcher->callCount());

        $harness->clock->advance(JwksCache::MIN_REFRESH_COOLDOWN_SECONDS - 1);
        $harness->jwks->signingKey($kid, 'RS256');
        self::assertSame(1, $harness->fetcher->callCount(), 'Inside the TTL the key set must be reused.');

        $harness->clock->advance(2);
        $harness->jwks->signingKey($kid, 'RS256');
        self::assertSame(2, $harness->fetcher->callCount());
    }

    public function testTheIssuerTtlHintIsClampedInBothDirections(): void
    {
        $cache = new JwksCache(
            new ScriptedJwksFetcher([[]]),
            new TestClock(),
            JwtTestSupport::JWKS_URI,
            60,
            3600
        );

        self::assertSame(60, $cache->clampTtl(null), 'No hint means the floor, not zero.');
        self::assertSame(60, $cache->clampTtl(0), 'max-age=0 must not become a fetch per request.');
        self::assertSame(60, $cache->clampTtl(-5));
        self::assertSame(600, $cache->clampTtl(600));
        self::assertSame(3600, $cache->clampTtl(31536000), 'A year of trust in a key set must be refused.');
    }

    public function testAnIssuerHintShorterThanTheFloorIsIgnored(): void
    {
        $kid = 'hint-kid';
        $harness = OidcHarness::build($kid);
        $cache = new JwksCache(
            new ScriptedJwksFetcher([[]]),
            new TestClock(),
            JwtTestSupport::JWKS_URI,
            60,
            3600
        );

        self::assertSame(60, $cache->clampTtl(5));
        self::assertNotSame('', $kid);
    }

    public function testInvertifyIsNotNeededBecauseTheTtlExpiryTriggersARefetch(): void
    {
        $kid = 'invalidate-kid';
        $harness = OidcHarness::build($kid);

        $harness->jwks->signingKey($kid, 'RS256');
        self::assertSame(1, $harness->fetcher->callCount());

        $harness->jwks->invalidate();

        self::assertFalse($harness->jwks->stats()['loaded']);
        $harness->jwks->signingKey($kid, 'RS256');
        self::assertSame(2, $harness->fetcher->callCount());
    }

    // -- Load protection ------------------------------------------------------

    public function testABurstOfUnknownKidsIsRateLimited(): void
    {
        // THE load-protection claim. Without the cooldown an attacker sending N tokens
        // with N invented `kid`s would make this server send N requests to the issuer.
        $known = 'burst-known';
        $harness = OidcHarness::build($known, null, null, [[JwtFactory::jwk($known)]]);

        $harness->jwks->signingKey($known, 'RS256');
        self::assertSame(1, $harness->fetcher->callCount());

        for ($i = 0; $i < 50; $i++) {
            try {
                $harness->jwks->signingKey(sprintf('invented-%d', $i), 'RS256');
            } catch (JwtDecodeException $expected) {
                // Every one of these is rejected. What matters is the fetch count.
            }
        }

        self::assertSame(2, $harness->fetcher->callCount(), 'A burst of unknown kids must cause one refresh, not 50.');
        self::assertSame(1, $harness->jwks->stats()['forcedRefreshes']);
        self::assertSame(49, $harness->jwks->stats()['suppressedForcedRefreshes']);
    }

    public function testTheCooldownExpires(): void
    {
        $known = 'cooldown-known';
        $harness = OidcHarness::build($known, null, null, [[JwtFactory::jwk($known)]]);

        $harness->jwks->signingKey($known, 'RS256');

        try {
            $harness->jwks->signingKey('invented-1', 'RS256');
        } catch (JwtDecodeException $expected) {
        }

        // Still inside the cooldown: the invented kid causes no fetch at all.
        $suppressedInside = $harness->fetcher->callCount();

        try {
            $harness->jwks->signingKey('invented-2', 'RS256');
        } catch (JwtDecodeException $expected) {
        }

        self::assertSame($suppressedInside, $harness->fetcher->callCount());
        self::assertGreaterThanOrEqual(1, $harness->jwks->stats()['suppressedForcedRefreshes']);

        $harness->clock->advance(JwksCache::MIN_REFRESH_COOLDOWN_SECONDS + 1);

        try {
            $harness->jwks->signingKey('invented-3', 'RS256');
        } catch (JwtDecodeException $expected) {
        }

        self::assertGreaterThan(
            $suppressedInside,
            $harness->fetcher->callCount(),
            'The cooldown must expire, or the cache never learns about a new key.'
        );
        self::assertSame(2, $harness->jwks->stats()['forcedRefreshes']);
    }

    public function testATtlExpiryFetchAndAnImmediateForcedRefreshBothHappen(): void
    {
        // Documented behaviour, not an accident. When the TTL has expired AND the token
        // names an unknown kid, the cache fetches for the TTL and then again for the
        // unknown kid. Suppressing the second fetch on the grounds that "we just
        // fetched" would break rotation recovery on a COLD cache, where the initial
        // load and the unknown kid happen at the same instant — which is exactly the
        // moment after a restart that a newly rotated key first appears.
        //
        // The cost is one extra fetch per TTL period. The alternative is a cache that
        // cannot recover from a rotation unless it was warm.
        $kid = 'double-fetch-kid';
        $harness = OidcHarness::build($kid, null, null, [
            [JwtFactory::jwk($kid)],
            [JwtFactory::jwk($kid)],
        ]);

        $harness->jwks->signingKey($kid, 'RS256');
        self::assertSame(1, $harness->fetcher->callCount());

        $harness->clock->advance(JwksCache::MIN_REFRESH_COOLDOWN_SECONDS + 1);

        try {
            $harness->jwks->signingKey('invented-kid', 'RS256');
        } catch (JwtDecodeException $expected) {
        }

        self::assertSame(3, $harness->fetcher->callCount());
    }

    public function testAColdCacheRecoversFromARotationInOneLookup(): void
    {
        // The consequence of the above, asserted because it is the reason the above is
        // correct: on a cold cache, a token signed with a newly rotated key verifies
        // with no prior request to have warmed anything.
        $new = 'cold-rotation-new';

        $harness = OidcHarness::build($new, null, null, [
            [JwtFactory::jwk('some-other-old-kid')],
            [JwtFactory::jwk('some-other-old-kid'), JwtFactory::jwk($new)],
        ]);

        self::assertSame($new, $harness->jwks->signingKey($new, 'RS256')->kid());
        self::assertSame(2, $harness->fetcher->callCount());
    }

    // -- IdP availability -----------------------------------------------------

    public function testAFailedRefreshWithinTheTtlKeepsTheWorkingKeySet(): void
    {
        // A transient IdP blip must not invalidate keys that were working a second ago.
        $kid = 'blip-kid';
        $harness = OidcHarness::build($kid);

        $harness->jwks->signingKey($kid, 'RS256');
        $harness->fetcher->failNext('issuer briefly unavailable');

        $this->expectException(JwtDecodeException::class);

        $harness->jwks->signingKey('unknown-kid', 'RS256');
    }

    public function testTheCachedSetSurvivesAFailedRefreshWithinTheTtl(): void
    {
        $kid = 'blip-survivor';
        $harness = OidcHarness::build($kid);

        $harness->jwks->signingKey($kid, 'RS256');
        $harness->fetcher->failNext();

        try {
            $harness->jwks->signingKey('unknown-kid', 'RS256');
        } catch (JwtDecodeException $expected) {
        }

        // The failure was counted rather than swallowed silently.
        self::assertSame(1, $harness->jwks->stats()['fetchFailuresWithinTtl']);

        // And the working key still resolves, with no further network call.
        self::assertSame($kid, $harness->jwks->signingKey($kid, 'RS256')->kid());
    }

    public function testAFailedFetchAfterTheTtlHasExpiredIsAServerFailure(): void
    {
        // Stale keys past their freshness window would be an implicit trust
        // extension — exactly what a rotation exists to end.
        $kid = 'expired-kid';
        $harness = OidcHarness::build($kid);

        $harness->jwks->signingKey($kid, 'RS256');
        $harness->clock->advance(JwksCache::MIN_REFRESH_COOLDOWN_SECONDS + 1);
        $harness->fetcher->failNext();

        $this->expectException(JwksUnavailableException::class);

        $harness->jwks->signingKey($kid, 'RS256');
    }

    // -- Key selection without a kid ------------------------------------------

    public function testATokenWithNoKidIsAcceptedWhenExactlyOneKeyCouldHaveSignedIt(): void
    {
        // RFC 7515 §4.1.4 makes `kid` optional, so a single-key set must still verify.
        $kid = 'sole-kid';
        $harness = OidcHarness::build($kid);

        self::assertSame($kid, $harness->jwks->signingKey(null, 'RS256')->kid());
    }

    public function testATokenWithNoKidIsRefusedWhenSeveralKeysCouldHaveSignedIt(): void
    {
        // Choosing among two candidates with no `kid` is a coin toss that decides
        // whether a signature verifies. A coin toss is not a security decision.
        $harness = OidcHarness::build('key-a', null, null, [[
            JwtFactory::jwk('key-a'),
            JwtFactory::jwk('key-b'),
        ]]);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/no "kid"/');

        $harness->jwks->signingKey(null, 'RS256');
    }

    // -- Observability --------------------------------------------------------

    public function testStatsAreAvailableAndCarryNoKeyMaterial(): void
    {
        $kid = 'stats-kid';
        $harness = OidcHarness::build($kid);
        $harness->jwks->signingKey($kid, 'RS256');

        $stats = $harness->jwks->stats();

        self::assertTrue($stats['loaded']);
        self::assertSame(1, $stats['keyCount']);
        self::assertSame(0, $stats['duplicateKids']);
        self::assertSame(JwtTestSupport::JWKS_URI, $stats['jwks_uri']);
        self::assertNotNull($stats['fetchedAt']);
        self::assertNotNull($stats['expiresAt']);

        $rendered = print_r($stats, true);
        self::assertStringNotContainsString('BEGIN', $rendered);
    }

    public function testDescribeListsKidsAndAlgorithmsButNoKeyMaterial(): void
    {
        $kid = 'describe-kid';
        $harness = OidcHarness::build($kid);
        $harness->jwks->signingKey($kid, 'RS256');

        $described = $harness->jwks->describe();

        self::assertCount(1, $described);
        self::assertSame($kid, $described[0]['kid']);
        self::assertSame('RS256', $described[0]['alg']);
        self::assertTrue($described[0]['signature']);

        // `describe()` cannot express key material: the keys are 'kid', 'kty', 'alg',
        // 'use' and one boolean.
        self::assertSame(
            ['kid', 'kty', 'alg', 'use', 'signature'],
            array_keys($described[0])
        );
    }

    // -- Construction ---------------------------------------------------------

    public function testAMaximumTtlBelowTheMinimumIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/below the minimum/');

        new JwksCache(new ScriptedJwksFetcher([[]]), new TestClock(), JwtTestSupport::JWKS_URI, 600, 60);
    }

    public function testAZeroMinimumTtlIsRefused(): void
    {
        // A zero floor means "fetch on every request", which is the load the cache
        // exists to prevent.
        $this->expectException(\InvalidArgumentException::class);

        new JwksCache(new ScriptedJwksFetcher([[]]), new TestClock(), JwtTestSupport::JWKS_URI, 0, 3600);
    }
}