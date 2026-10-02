<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

/**
 * Mints compact JWS tokens for the tests, including the malformed and hostile ones.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 *
 * ============================================================================
 * WHY THE HOSTILE TOKENS ARE BUILT HERE RATHER THAN STRING-CONCATENATED IN TESTS
 * ============================================================================
 * Three reasons, and the first is the one that matters.
 *
 *   1. **The adversarial cases are real, not cosmetic.** `alg: none` is not
 *      `{"alg":"none"}` — it is a compact JWS whose THIRD SEGMENT IS EMPTY. A token
 *      built by string concatenation gets that wrong in a way that makes the test pass
 *      for the wrong reason. HMAC-signed-with-the-public-key is a real HMAC over the
 *      real signing input. Both are constructed properly here so that a validator
 *      which accepts them fails for the right reason.
 *   2. **Base64url encoding is easy to get subtly wrong**, and a test that builds its
 *      own tokens will eventually build one with `=` padding, which is a *different*
 *      string. One implementation, used everywhere.
 *   3. **No private key is ever committed.** `openssl_pkey_new()` runs per call.
 *
 * ============================================================================
 * WHY THIS IS NOT PRODUCTION CODE
 * ============================================================================
 * A token factory is an excellent way to accidentally ship a token minter inside a
 * security extension. It lives under `tests/` and is not autoloadable from the
 * extension's own namespace (`Civi\Dfc\Test\` is `autoload-dev`).
 *
 * @package Civi\Dfc
 */
final class JwtFactory
{
    /** @var array<string, \OpenSSLAsymmetricKey> */
    private static array $keys = [];

    private static int $keyCounter = 0;

    /**
     * Mint a throwaway RSA key pair, cached per kid for the life of the process.
     *
     * @return array{0: string, 1: \OpenSSLAsymmetricKey} The private key PEM and the
     *                                                     OpenSSL key object.
     */
    public static function keyPair(string $kid): array
    {
        if (isset(self::$keys[$kid])) {
            return self::$keys[$kid];
        }

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($key === false) {
            throw new \RuntimeException(
                'openssl_pkey_new() failed, so the OIDC tests cannot generate a key. ext-openssl must be '
                . 'available with RSA support; it is in every PHP build CiviCRM supports.'
            );
        }

        $pem = '';
        if (openssl_pkey_export($key, $pem) === false) {
            throw new \RuntimeException('openssl_pkey_export() failed; the RSA key could not be exported.');
        }

        self::$keys[$kid] = [$pem, $key];

        return self::$keys[$kid];
    }

    /**
     * The PEM public key for a kid, i.e. what a JWKS would publish.
     *
     * The public key is read out of the OpenSSL key OBJECT rather than by feeding the
     * private PEM to `openssl_pkey_get_public()`: on this PHP/OpenSSL build that call
     * returns false for a private-key PEM, and a fixture helper that silently produced
     * nothing would make every "signature does not verify" test pass for the wrong
     * reason.
     */
    public static function publicKeyPem(string $kid): string
    {
        $details = openssl_pkey_get_details(self::keyPair($kid)[1]);

        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            throw new \RuntimeException('openssl_pkey_get_details() returned no PEM for the generated key.');
        }

        if (!str_contains($details['key'], 'BEGIN PUBLIC KEY')) {
            throw new \RuntimeException(sprintf(
                'The PEM derived from the generated key is not a public-key PEM, so the HMAC-confusion '
                . 'fixture cannot be built.'
            ));
        }

        return $details['key'];
    }

    /**
     * The base64url `n` and `e` for a kid, i.e. an RFC 7518 RSA JWK.
     *
     * @return array{n: string, e: string}
     */
    public static function modulusAndExponent(string $kid): array
    {
        $details = openssl_pkey_get_details(self::keyPair($kid)[1]);

        if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new \RuntimeException('openssl_pkey_get_details() returned no RSA parameters.');
        }

        return [
            'n' => self::base64UrlEncode($details['rsa']['n']),
            'e' => self::base64UrlEncode($details['rsa']['e']),
        ];
    }

    /**
     * A JWKS entry for a kid.
     *
     * `$overrides` is spread LAST so a test can override `kid` — which is how "two
     * different key pairs published under one kid" is expressed, and therefore how
     * merge precedence in the key cache is tested. Key material always comes from
     * $kid, so overriding `kid` cannot change which key signs a token.
     *
     * @param array<string, string> $overrides Extra JWK members, e.g. `use`, `alg`,
     *                                        and a different published `kid`.
     *
     * @return array<string, mixed>
     */
    public static function jwk(string $kid, array $overrides = []): array
    {
        return [
            'kid' => $kid,
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            ...self::modulusAndExponent($kid),
            ...$overrides,
        ];
    }

    /**
     * A fully valid RS256 access token.
     *
     * @param array<string, mixed> $claimOverrides Replaces or adds claims.
     */
    public static function accessToken(
        string $kid,
        ?array $claimOverrides = null,
        array $headerOverrides = []
    ): string {
        return self::sign($kid, self::claims($claimOverrides ?? []), $headerOverrides);
    }

    /**
     * A claim set that passes every check, so each test overrides exactly one thing.
     *
     * `now` defaults to the suite's fixed instant, which is what makes an expired
     * token a statement about the RULE rather than about the wall clock.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function claims(array $overrides = [], ?int $now = null): array
    {
        $issued = $now ?? (new \DateTimeImmutable(JwtTestSupport::NOON_UTC))->getTimestamp();

        return array_merge([
            'iss' => JwtTestSupport::REALM,
            'sub' => JwtTestSupport::SUBJECT,
            'aud' => JwtTestSupport::AUDIENCE,
            'azp' => JwtTestSupport::AUDIENCE,
            'iat' => $issued - 10,
            'nbf' => $issued - 10,
            'exp' => $issued + JwtTestSupport::LIFETIME_SECONDS,
            'jti' => 'fixture-token-1',
            'scope' => 'openid webid',
            'realm_access' => ['roles' => []],
        ], $overrides);
    }

    /**
     * Sign claims with RS256 under $kid.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $headerOverrides
     */
    public static function sign(string $kid, array $claims, array $headerOverrides = []): string
    {
        return self::assemble(
            ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid, ...$headerOverrides],
            $claims,
            fn (string $input): string => self::rsaSign($kid, $input)
        );
    }

    /**
     * `alg: none`, done properly: an EMPTY signature segment.
     *
     * This is the exact string RFC 7515 Appendix A.1 specifies and every real
     * implementation must refuse.
     *
     * @param array<string, mixed> $claims
     */
    public static function unsignedToken(array $claims, array $headerOverrides = []): string
    {
        return self::assemble(
            ['alg' => 'none', 'typ' => 'JWT', ...$headerOverrides],
            $claims,
            static fn (): string => ''
        );
    }

    /**
     * HMAC-SHA256 over the signing input, keyed with the RSA PUBLIC key PEM.
     *
     * The algorithm-confusion attack. Any verifier that reads `alg` from the token and
     * then uses a symmetric routine with key material fetched from a PUBLIC JWKS
     * endpoint accepts this token — because anyone can read the JWKS.
     *
     * @param array<string, mixed> $claims
     */
    public static function hmacConfusionToken(string $kid, array $claims): string
    {
        $publicPem = self::publicKeyPem($kid);

        return self::assemble(
            ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $kid],
            $claims,
            static fn (string $input): string => hash_hmac('sha256', $input, $publicPem, true)
        );
    }

    /**
     * A structurally broken token. Each variant breaks one thing.
     */
    public static function malformed(string $variant, string $kid = 'k1'): string
    {
        $header = self::base64UrlEncode(self::canonicalJson(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]));
        $payload = self::base64UrlEncode(self::canonicalJson(self::claims()));

        return match ($variant) {
            'empty' => '',
            'two-segments' => $header . '.' . $payload,
            'four-segments' => $header . '.' . $payload . '.c2ln' . '.extra',
            'jwe' => $header . '.' . $payload . '.' . self::base64UrlEncode('iv') . '.c2ln.' . self::base64UrlEncode('t'),
            'padded' => $header . '=' . '.' . $payload . '.' . 'c2ln',
            'not-base64url' => 'not valid base64!' . '.' . $payload . '.' . 'c2ln',
            'header-not-json' => self::base64UrlEncode('this is not json') . '.' . $payload . '.' . 'c2ln',
            'header-is-array' => self::base64UrlEncode('[1,2,3]') . '.' . $payload . '.' . 'c2ln',
            'signature-empty' => $header . '.' . $payload . '.',
            'impossible-length' => 'A.' . $payload . '.' . 'c2ln',
            default => throw new \InvalidArgumentException(sprintf('Unknown malformed variant "%s".', $variant)),
        };
    }

    // -- Internals ------------------------------------------------------------

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    private static function assemble(array $header, array $claims, callable $sign): string
    {
        $encodedHeader = self::base64UrlEncode(self::canonicalJson($header));
        $encodedPayload = self::base64UrlEncode(self::canonicalJson($claims));
        $signingInput = $encodedHeader . '.' . $encodedPayload;

        return $signingInput . '.' . self::base64UrlEncode($sign($signingInput));
    }

    private static function rsaSign(string $kid, string $input): string
    {
        $signature = '';

        if (openssl_sign($input, $signature, self::keyPair($kid)[1], OPENSSL_ALGO_SHA256) === false) {
            throw new \RuntimeException('openssl_sign() failed while minting a fixture token.');
        }

        return $signature;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function canonicalJson(array $value): string
    {
        $keys = array_map('strval', array_keys($value));
        sort($keys, \SORT_STRING);

        $sorted = [];
        foreach ($keys as $key) {
            $sorted[$key] = $value[$key];
        }

        return json_encode(
            $sorted,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * A unique kid, so tests that assert "unknown kid" cannot collide with a real one.
     */
    public static function uniqueKid(string $hint = 'kid'): string
    {
        self::$keyCounter++;

        return sprintf('%s-%d', $hint, self::$keyCounter);
    }
}