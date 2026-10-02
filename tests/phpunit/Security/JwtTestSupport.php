<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Security\OidcClientConfig;
use Civi\Dfc\V2\Security\OidcEndpoints;
use Civi\Dfc\V2\Security\ScopePermissionRegistry;

/**
 * Shared constants and helpers for the OIDC unit suite.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 *
 * PHP 8.1 does not allow constants in traits, so the constants live here and the
 * helpers are static methods, matching the precedent set by
 * {@see \Civi\Dfc\Test\Identity\IdentityFixtures}.
 *
 * ============================================================================
 * WHY THE FIXTURES ARE BUILT AT RUNTIME RATHER THAN COMMITTED
 * ============================================================================
 * No private key is committed to a repository, for the same reason PRD-002 §9 forbids
 * logging keys: a committed key is a permanent, publicly-readable artefact whose
 * provenance can never be established. `openssl_pkey_new()` is available in every PHP
 * build CiviCRM runs on, and it takes about a millisecond, so each test run mints a
 * throwaway 2048-bit key and throws it away.
 *
 * The JWKS fixture on disk contains NO key material at all — only the two-key SHAPE
 * the live realm publishes (an `use: enc` key and a `use: sig` key), which is the
 * property worth pinning.
 *
 * @package Civi\Dfc
 */
final class JwtTestSupport
{
    /**
     * The DFC dev realm, as verified live 2026-10-02.
     *
     * Note what it is NOT: `https://login.fooddatacollaboration.org.uk/auth/realm/dev`,
     * which is what all four published OpenAPI documents use and which returns 404.
     * See PRD-002 "Upstream refresh" item 1.
     */
    public const REALM = 'https://login.fooddatacollaboration.org.uk/realms/dev';

    public const DISCOVERY_URI = self::REALM . '/.well-known/openid-configuration';

    public const JWKS_URI = self::REALM . '/protocol/openid-connect/certs';

    /**
     * The signing key's `kid` in the live key set (verified 2026-10-02). Recorded so a
     * test can assert the REALM's key-set SHAPE without depending on it.
     */
    public const REALM_SIGNING_KID = 'z1Cr6MYOwmV_35BK0k5dYRPJaQ6zEg2eohDh-aTy7CU';

    /**
     * The encryption key's `kid` in the live key set. Present so the "skip `use: enc`"
     * path can be tested against the realm's actual two-key shape.
     */
    public const REALM_ENCRYPTION_KID = 'Bt9lLwIGmNtDWCaRW0hq23m9FZ2qPHdGed2sbLHXYug';

    /**
     * This resource server's client id — the audience a valid token must name.
     *
     * Invented for the tests. The realm's real client ids are a deployment fact, and
     * BLK-014 records that no confidential client is available yet, so there is
     * nothing to copy and nothing to verify.
     */
    public const AUDIENCE = 'urn:dfc-civicrm:resource-server';

    /** Subject used by the tests. Also invented, and also meaningless on its own. */
    public const SUBJECT = 'oidc|5f2a1b3c4d5e';

    /** One hour, matching a typical access-token lifetime. */
    public const LIFETIME_SECONDS = 3600;

    /**
     * 2026-10-02T12:00:00Z — the instant every time-relative test is anchored to.
     *
     * A fixed instant, not `now()`, because a test that computes an expectation from
     * the current time asserts that the arithmetic works rather than that the rule
     * works. With a clock injected, "it is 12:00:00 and this token expired at 11:59:00"
     * is checkable by eye.
     */
    public const NOON_UTC = '2026-10-02T12:00:00+00:00';

    /**
     * Endpoints for the real realm, for tests that do not exercise discovery itself.
     */
    public static function endpoints(): OidcEndpoints
    {
        return OidcEndpoints::fromDiscoveryDocument([
            'issuer' => self::REALM,
            'jwks_uri' => self::JWKS_URI,
            'token_endpoint' => self::REALM . '/protocol/openid-connect/token',
            'userinfo_endpoint' => self::REALM . '/protocol/openid-connect/userinfo',
            'scopes_supported' => ScopePermissionRegistry::ADVERTISED_REALM_SCOPES,
        ], self::REALM);
    }

    public static function config(): OidcClientConfig
    {
        return new OidcClientConfig(self::AUDIENCE, ['RS256'], 30);
    }

    public static function fixturePath(string $relative): string
    {
        return __DIR__ . '/../../fixtures/security/' . $relative;
    }

    /**
     * The recorded discovery document, minus the `_`-prefixed commentary keys.
     *
     * Those keys exist so the fixture explains itself; a real discovery document has
     * no such members, and feeding them to {@see OidcEndpoints} would test a
     * tolerance the real thing does not need.
     *
     * @return array<string, mixed>
     */
    public static function discoveryFixture(): array
    {
        $raw = file_get_contents(self::fixturePath('oidc/dfc-dev-realm-discovery.json'));

        if ($raw === false) {
            throw new \RuntimeException('The discovery fixture could not be read.');
        }

        /** @var array<string, mixed> $document */
        $document = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);

        foreach (array_keys($document) as $key) {
            if (is_string($key) && str_starts_with($key, '_')) {
                unset($document[$key]);
            }
        }

        return $document;
    }
}