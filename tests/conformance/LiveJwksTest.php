<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Conformance;

use Civi\Dfc\V2\Security\HttpJwksFetcher;
use Civi\Dfc\V2\Security\JsonWebKey;
use Civi\Dfc\V2\Security\JsonWebKeySet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The ONE live check: the DFC realm's real JWKS parses.
 *
 * ============================================================================
 * WHY THIS IS NOT IN THE UNIT SUITE
 * ============================================================================
 * Because a network-dependent test fails when somebody's network does, and a suite that
 * does that is a suite people learn to skip. Everything the unit suite needs to know
 * about JWKS handling — rotation, an unknown `kid`, an `use: enc` key in the set, a
 * malformed entry — is exercised hermetically in
 * {@see \Civi\Dfc\Test\Security\JwksCacheTest} against generated keys and a fixture that
 * contains no key material at all.
 *
 * What the unit suite CANNOT tell us is whether the real issuer's key set is shaped the
 * way we assume. That is worth exactly one check, in the conformance suite, marked
 * `network` so it is obvious what it does.
 *
 * ============================================================================
 * WHAT IS ASSERTED, AND WHY IT IS THE RIGHT ASSERTION
 * ============================================================================
 * That every key the realm publishes PARSES into a usable RSA public key PEM, and that
 * the set's shape matches what {@see JsonWebKeySet} was built to select from. It
 * deliberately does NOT:
 *
 *   - verify a signature — that needs a token, which needs a client credential, and
 *     BLK-014 records that none is available;
 *   - assert a specific `kid` — those rotate, and a test that fails on rotation is a
 *     test that gets deleted rather than fixed;
 *   - store anything — the key material is never written to disk or to a fixture.
 *
 * @group network
 */
#[CoversClass(HttpJwksFetcher::class)]
#[CoversClass(JsonWebKey::class)]
#[CoversClass(JsonWebKeySet::class)]
final class LiveJwksTest extends TestCase
{
    /**
     * The realm, from PRD-002 "Upstream refresh" item 1.
     *
     * Resolved from the discovery document rather than written here: the published specs'
     * `.../auth/realm/dev` form returns 404, and hardcoding a base URI is the mistake
     * that item warns about.
     */
    private const DISCOVERY_URI =
        'https://login.fooddatacollaboration.org.uk/realms/dev/.well-known/openid-configuration';

    public function testTheRealmPublishesAParsableKeySet(): void
    {
        $jwksUri = $this->discoverJwksUri();

        $document = (new HttpJwksFetcher(10))->fetch($jwksUri);

        self::assertFalse(
            $document->isEmpty(),
            sprintf('The realm published no usable keys at %s.', $jwksUri)
        );

        $keys = $document->keys();

        foreach ($keys as $key) {
            self::assertSame('RSA', $key->keyType(), sprintf('Key %s is not RSA.', $key->kid()));

            // The assertion that matters: RFC 7518 `n`/`e` really does become a PEM
            // OpenSSL will use. If the DER encoder were wrong, every unit test using a
            // fixture key would still pass and every real token would fail.
            $pem = $key->publicKeyPem();

            self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
            self::assertNotFalse(
                openssl_pkey_get_public($pem),
                sprintf('Key %s did not produce a PEM OpenSSL can read.', $key->kid())
            );

            $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));
            self::assertIsArray($details);
            self::assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        }
    }

    public function testTheRealmPublishesAtLeastOneSigningKey(): void
    {
        $document = (new HttpJwksFetcher(10))->fetch($this->discoverJwksUri());

        $signing = array_values(array_filter(
            $document->keys(),
            static fn (JsonWebKey $key): bool => $key->isSignatureKey() && $key->serves('RS256')
        ));

        self::assertNotSame(
            [],
            $signing,
            'The realm must publish a key this extension can verify RS256 tokens with.'
        );
    }

    public function testTheKeySetResolvesAKidAndAnAlgorithm(): void
    {
        $document = (new HttpJwksFetcher(10))->fetch($this->discoverJwksUri());
        $set = JsonWebKeySet::of($document->keys());

        // Whatever the issuer publishes, selection must be by (kid, alg) and must not
        // hand back an encryption key. This is the live counterpart of the realm's
        // two-key shape: it publishes an `use: enc` key alongside its `use: sig` one.
        foreach ($set->keys() as $key) {
            if (!$key->isSignatureKey() || !$key->serves('RS256')) {
                continue;
            }

            $resolved = $set->resolve($key->kid(), 'RS256');

            self::assertNotNull($resolved);
            self::assertSame($key->kid(), $resolved->kid());
        }

        self::assertSame(
            0,
            $set->duplicateKidCount(),
            'Two keys claiming one kid would make verification ambiguous.'
        );
    }

    public function testNoKeyEntryIsSkippedAsUnusable(): void
    {
        // A skipped entry is a key the realm publishes that this extension cannot use.
        // Zero skipped is the healthy state; anything else is worth knowing about before
        // a real token fails.
        $document = (new HttpJwksFetcher(10))->fetch($this->discoverJwksUri());

        self::assertSame(
            0,
            $document->skippedKeyCount(),
            'The realm published an entry this extension could not address.'
        );
    }

    /**
     * Resolve the JWKS URI from the discovery document, the way production does.
     *
     * @throws \RuntimeException
     */
    private function discoverJwksUri(): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents(self::DISCOVERY_URI, false, $context);

        self::assertIsString($body, 'The discovery document could not be fetched.');

        /** @var array{issuer: string, jwks_uri: string} $document */
        $document = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('https://login.fooddatacollaboration.org.uk/realms/', $document['issuer']);
        self::assertStringNotContainsString('/auth/realm/', $document['issuer']);

        return $document['jwks_uri'];
    }
}