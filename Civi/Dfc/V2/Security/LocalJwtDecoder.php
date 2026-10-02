<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * A real JWS verifier that needs nothing but `ext-openssl`.
 *
 * ============================================================================
 * WHY THIS EXISTS RATHER THAN A DEPENDENCY
 * ============================================================================
 * See {@see JwtDecoderInterface}. This is the implementation the unit suite runs
 * against, and it is a genuine verifier — not a stub that only ever succeeds —
 * because the adversarial cases PRD-002 CP-3 requires (altered signature,
 * unacceptable `alg`, `alg: none`, HMAC signed with the RSA public key) are only
 * meaningful against something that really does the cryptography.
 *
 * ============================================================================
 * THE FOUR ATTACKS THIS IS BUILT TO REFUSE, IN THE ORDER THEY ARE REFUSED
 * ============================================================================
 *
 * 1. `alg: none` — "no signature at all".
 *    Two independent refusals. A compact JWS with `alg: none` has an EMPTY
 *    signature segment, and `explode('.')` then yields a zero-length third part,
 *    which {@see requireCompactJws()} rejects. And `none` is not in
 *    {@see ALGORITHMS}, so the allow-list rejects it even if a token is
 *    constructed with a non-empty signature segment. Either refusal alone is
 *    sufficient; both are present because a token parser that grows a "be liberal
 *    in what you accept" branch later must not become the hole.
 *
 * 2. HMAC signed with the RSA PUBLIC key ("algorithm confusion").
 *    The token header says `HS256` and the signature is `HMAC-SHA256(public
 *    key)`. Any implementation that reads `alg` from the token and then
 *    "verifies" with a symmetric routine using key material it fetched from a
 *    PUBLIC JWKS endpoint accepts the token, because anyone can read the JWKS.
 *    The refusal here is structural rather than a denylist: the only algorithms
 *    this decoder will consider are RSA signature algorithms, the key PEM handed
 *    to `openssl_verify()` must parse as an RSA public key, and `HS*` never
 *    appears in {@see ALGORITHMS} — so there is no code path where a symmetric
 *    routine is reached with an asymmetric key, or an asymmetric one is reached
 *    with an octet string.
 *
 * 3. `alg` downgrade to something the deployment accepts but the token was not
 *    intended for. Refused by comparing the token's `alg` against
 *    $expectedAlgorithm as well as against {@see ALGORITHMS}: the deployment's
 *    allow-list is consulted first (in {@see AccessTokenValidator}) and this
 *    layer re-checks the agreement, so a caller cannot pass a permissive
 *    $expectedAlgorithm and silently widen the policy for one token.
 *
 * 4. `crit` extensions. A header carrying `crit` is asking the recipient to
 *    understand an extension this implementation does not. RFC 7515 §4.1.11
 *    requires such a token to be REJECTED, not processed optimistically. The
 *    check is `!empty($header['crit'])`, so `crit: []` is refused too.
 *
 * ============================================================================
 * WHAT THIS DOES NOT DO
 * ============================================================================
 * No claim-level policy: no `iss`, `aud`, `exp`, `nbf`, no token profile, no
 * scope. Those belong to {@see AccessTokenValidator}, and keeping them here is
 * what makes that class testable against a hostile decoder.
 *
 * RSA-PSS (`PS256`/`PS384`/`PS512`) is deliberately not implemented, although
 * the target realm advertises it in `id_token_signing_alg_values_supported`
 * (verified live 2026-10-02): PHP's `openssl_verify()` selects PKCS#1 v1.5
 * padding from the digest constant alone, so accepting a `PS*` header without
 * also selecting PSS padding would verify the signature under the WRONG padding
 * scheme. The live JWKS declares `"alg":"RS256"` on its signing key, so
 * {@see OidcClientConfig}'s default allow-list is exactly `['RS256']` and nothing
 * is lost. If PSS is ever needed, it needs the explicit padding constants and
 * its own tests, not a one-line addition here.
 *
 * @package Civi\Dfc
 */
final class LocalJwtDecoder implements JwtDecoderInterface
{
    /**
     * The algorithms this decoder verifies.
     *
     * Deliberately NOT read from the token, and deliberately not read from the
     * realm's discovery document either: a list an attacker-influenced document
     * can widen is not an allow-list. {@see OidcClientConfig} is the policy; this
     * is the capability.
     */
    private const ALGORITHMS = ['RS256', 'RS384', 'RS512'];

    /** JOSE `alg` -> the digest constant `openssl_verify()` expects. */
    private const DIGESTS = [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
    ];

    public function supportedAlgorithms(): array
    {
        return self::ALGORITHMS;
    }

    /**
     * @return array<string, mixed>
     */
    public function header(string $jwt): array
    {
        $segments = self::requireCompactJws($jwt);

        return self::decodeJsonObject(self::base64UrlDecode($segments[0]), 'JOSE header');
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $jwt, string $keyPem, string $expectedAlgorithm): array
    {
        $segments = self::requireCompactJws($jwt);
        $header = self::decodeJsonObject(self::base64UrlDecode($segments[0]), 'JOSE header');

        $algorithm = self::requireAlgorithm($header);
        self::assertAlgorithmPermitted($algorithm, $expectedAlgorithm);

        if (!empty($header['crit'])) {
            // RFC 7515 §4.1.11: a recipient that does not understand every value
            // in `crit` MUST reject the JWS. Understanding none of them is the
            // common case.
            throw new JwtDecodeException(
                'The token declares a "crit" header, so it requires JOSE extensions this server does not '
                . 'implement. Rejected rather than processed optimistically.'
            );
        }

        $signature = self::base64UrlDecode($segments[2]);
        if ($signature === '') {
            // Only reachable for an `alg` that somehow reached here with an empty
            // signature; `none` was already refused by the allow-list. Belt and
            // braces: an empty signature is never valid.
            throw new JwtDecodeException('The token carries an empty signature.');
        }

        $publicKey = openssl_pkey_get_public($keyPem);
        if ($publicKey === false) {
            // Do NOT include openssl_error_string(): it is free text from a crypto
            // library and this message reaches a log.
            throw new JwtDecodeException(
                'The signing key from the JWKS endpoint is not a usable public key. The issuer is publishing '
                . 'a key this server cannot use, which is an issuer-side problem.'
            );
        }

        $details = openssl_pkey_get_details($publicKey);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new JwtDecodeException(
                'The signing key is not an RSA key. An HMAC secret presented as a JWKS signing key is the '
                . 'signature of a misconfigured issuer, and accepting it would be the algorithm-confusion '
                . 'vulnerability this decoder exists to refuse.'
            );
        }

        // The signing input is the ASCII header.payload, per RFC 7515 §5.1 step 5.
        $signingInput = $segments[0] . '.' . $segments[1];

        $verified = openssl_verify($signingInput, $signature, $publicKey, self::DIGESTS[$algorithm]);
        if ($verified !== 1) {
            throw new JwtDecodeException(
                'The token signature does not verify against the key the issuer publishes for it.'
            );
        }

        return self::decodeJsonObject(self::base64UrlDecode($segments[1]), 'claim set');
    }

    // -- Structure ------------------------------------------------------------

    /**
     * @return non-empty-list<string>
     */
    private static function requireCompactJws(string $jwt): array
    {
        if ($jwt === '') {
            throw new JwtDecodeException('The bearer token is empty.');
        }

        // A JWS in compact serialisation is exactly three BASE64URL segments, so a
        // fourth `.` means either a JWE (five segments) or garbage. Rejecting on
        // the count is what makes `alg: none` — whose signature segment is empty —
        // a structural failure before any algorithm is consulted.
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            throw new JwtDecodeException(sprintf(
                'A bearer token must be a compact JWS of exactly three dot-separated segments, got %d. A JWE '
                . 'is not accepted on this interface.',
                count($segments)
            ));
        }

        foreach ($segments as $position => $segment) {
            if ($segment === '') {
                throw new JwtDecodeException(sprintf(
                    'Segment #%d of the bearer token is empty. An empty segment is the signature of an '
                    . 'unsigned token ("alg: none") or a truncated one.',
                    $position + 1
                ));
            }
        }

        /** @var non-empty-list<string> $segments */
        return $segments;
    }

    /**
     * @param array<string, mixed> $header
     */
    private static function requireAlgorithm(array $header): string
    {
        $algorithm = $header['alg'] ?? null;

        if (!is_string($algorithm) || $algorithm === '') {
            throw new JwtDecodeException('The token header declares no "alg".');
        }

        return $algorithm;
    }

    private static function assertAlgorithmPermitted(string $algorithm, string $expectedAlgorithm): void
    {
        if (!in_array($algorithm, self::ALGORITHMS, true)) {
            throw new JwtDecodeException(sprintf(
                'The token is signed with "alg: %s", which this server does not accept. Accepted algorithms: %s. '
                . 'A symmetric algorithm is never accepted, because the key material would come from a PUBLIC '
                . 'JWKS endpoint.',
                $algorithm,
                implode(', ', self::ALGORITHMS)
            ));
        }

        if (!in_array($expectedAlgorithm, self::ALGORITHMS, true)) {
            throw new JwtDecodeException(sprintf(
                'The deployment allow-list names "%s", which is not an algorithm this decoder implements. A '
                . 'configuration error, not a client error.',
                $expectedAlgorithm
            ));
        }

        if ($algorithm !== $expectedAlgorithm) {
            throw new JwtDecodeException(sprintf(
                'The token is signed with "alg: %s" but this deployment accepts only "%s" for it.',
                $algorithm,
                $expectedAlgorithm
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonObject(string $json, string $what): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $invalidJson) {
            throw new JwtDecodeException(sprintf('The %s is not valid JSON.', $what));
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new JwtDecodeException(sprintf(
                'The %s is not a JSON object. A JWT header and a JWT claim set are both objects; a bare array '
                . 'means the producer did not sign what this server expects.',
                $what
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Strict base64url decode.
     *
     * Strict in three ways, each of which `base64_decode($s, false)` would get
     * wrong or lenient about:
     *
     *   - the alphabet. Compact JWS uses the URL-safe alphabet with NO padding;
     *     `+` and `/` are not in it and `=` may not appear. Accepting the standard
     *     alphabet would mean two different strings decode to the same bytes.
     *   - the length. A base64 string whose length mod 4 is 1 cannot be a whole
     *     number of bytes. `base64_decode` returns garbage; strict mode returns
     *     false for malformed input, which is checkable.
     *   - strict mode itself, so a trailing partial group is an error rather than
     *     a truncated value.
     */
    private static function base64UrlDecode(string $segment): string
    {
        if (str_contains($segment, '=')) {
            throw new JwtDecodeException(
                'A compact JWS segment is unpadded base64url. Padding is not part of the format.'
            );
        }

        if (preg_match('/[^A-Za-z0-9_\-]/', $segment) === 1) {
            throw new JwtDecodeException(
                'A compact JWS segment contains a character outside the base64url alphabet.'
            );
        }

        if (strlen($segment) % 4 === 1) {
            throw new JwtDecodeException('A compact JWS segment has an impossible base64 length.');
        }

        $standard = strtr($segment, '-_', '+/');
        // Pad to a multiple of four for base64_decode. PHP's strict mode still
        // rejects anything that is not valid base64 underneath the padding.
        $remainder = strlen($standard) % 4;
        if ($remainder !== 0) {
            $standard .= str_repeat('=', 4 - $remainder);
        }

        /** @var string|false $decoded */
        $decoded = base64_decode($standard, true);
        if ($decoded === false) {
            throw new JwtDecodeException('A compact JWS segment is not decodable base64url.');
        }

        return $decoded;
    }
}