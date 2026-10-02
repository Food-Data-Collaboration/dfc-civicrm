<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The seam between this extension and whatever actually verifies RSA signatures.
 *
 * ============================================================================
 * WHY AN INTERFACE AND NOT `firebase/php-jwt` DIRECTLY
 * ============================================================================
 * PRD-002 §5 lists "CiviCRM `firebase/php-jwt` + AuthX (core, available)" as a
 * dependency of the *deployment*, not of the protocol layer. Two consequences
 * follow, and both are about testability rather than abstraction aesthetics:
 *
 *   1. THE UNIT SUITE MUST NOT DEPEND ON A CMS-SHIPPED LIBRARY. The extension
 *      repository has no `firebase/php-jwt` in `vendor/`, and adding one just to
 *      verify a signature would put a crypto dependency in the path of a suite
 *      that is otherwise pure PHP. {@see LocalJwtDecoder} needs only `ext-openssl`,
 *      which is present in every PHP build CiviCRM runs on.
 *   2. THE CALLERS MUST NOT CHANGE WHEN THE LIBRARY ARRIVES. Every consumer of
 *      this interface — {@see AccessTokenValidator}, {@see JwksCache} — depends on
 *      the three methods below and nothing else, so substituting the real decoder
 *      is a one-line change in the wiring and zero changes in the callers.
 *
 * ============================================================================
 * THE DIVISION OF LABOUR, WHICH IS THE IMPORTANT PART
 * ============================================================================
 * `header()` returns DECODED BUT UNVERIFIED claims. It exists for exactly two
 * reads: `kid` (to select a key) and `alg` (to reject before doing any work).
 * Its docblock says so, and {@see AccessTokenValidator} treats its output as
 * hostile input. Nothing in this namespace makes a trust decision from it.
 *
 * Every policy decision — the algorithm allow-list, issuer, audience, expiry,
 * not-before, clock skew, token profile — belongs to
 * {@see AccessTokenValidator}, NOT to the decoder. A decoder that also validated
 * claims would make {@see AccessTokenValidator} untestable against a hostile
 * decoder, and would make "which layer enforces the algorithm allow-list" an
 * open question. One layer, one responsibility.
 *
 * ============================================================================
 * HOW TO ADAPT `firebase/php-jwt` (NOT SHIPPED HERE)
 * ============================================================================
 * Because the interface is three methods, the adapter is mechanical:
 *
 *     final class FirebaseJwtDecoder implements JwtDecoderInterface
 *     {
 *         public function supportedAlgorithms(): array
 *         {
 *             return ['RS256', 'RS384', 'RS512'];
 *         }
 *
 *         public function header(string $jwt): array
 *         {
 *             return (array) \Firebase\JWT\JWT::jsonDecode(
 *                 \Firebase\JWT\JWT::urlsafeB64Decode(explode('.', $jwt)[0])
 *             );
 *         }
 *
 *         public function decode(string $jwt, string $keyPem, string $expectedAlgorithm): array
 *         {
 *             // firebase/php-jwt 6.x: pass the key as a decoded object, or as a
 *             // PEM string with ['alg' => ...] in $headers.
 *             $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key($keyPem, $expectedAlgorithm));
 *
 *             return (array) $decoded;
 *         }
 *     }
 *
 * Two things the adapter must keep doing that this interface makes visible:
 * `JWT::decode()` must be given the algorithm EXPLICITLY (firebase/php-jwt 6.x
 * requires a `Key`, precisely to close the `alg: none` and HMAC-confusion holes
 * that {@see LocalJwtDecoder} also closes), and `$expectedAlgorithm` must come
 * from {@see \Civi\Dfc\V2\Security\OidcClientConfig::allowedAlgorithms()} rather
 * than from the token's own header.
 *
 * The realm this project targets advertises `HS256`, `HS512` and `none` in
 * `id_token_signing_alg_values_supported` and `userinfo_signing_alg_values_supported`
 * (verified live 2026-10-02), so "trust what the issuer says it can sign" is
 * demonstrably the wrong policy here.
 *
 * @package Civi\Dfc
 */
interface JwtDecoderInterface
{
    /**
     * The `alg` values this decoder can verify, and only those.
     *
     * A decoder that supports `none`, or a symmetric algorithm, does not belong
     * in a resource server; the list is the first line of defence and the
     * allow-list in {@see OidcClientConfig} is the second.
     *
     * @return list<string>
     */
    public function supportedAlgorithms(): array;

    /**
     * The JOSE header, DECODED AND UNVERIFIED.
     *
     * Returns whatever the header claims. The signature has not been checked
     * and nothing in the result may be trusted. It exists because selecting a
     * verification key requires `kid`, and reading `kid` requires parsing.
     *
     * @return array<string, mixed>
     *
     * @throws JwtDecodeException when the token is not a well-formed compact JWS.
     */
    public function header(string $jwt): array;

    /**
     * Verify the signature over $jwt using $keyPem and return the claim set.
     *
     * MUST reject on a signature mismatch, MUST NOT accept a token whose header
     * `alg` differs from $expectedAlgorithm, and MUST NOT itself apply any
     * claim-level policy. `$expectedAlgorithm` is passed in precisely so that the
     * decision is made by this layer's configuration and re-checked here.
     *
     * @param string $keyPem            A PEM-encoded public key. Never a private
     *                                 key, never an HMAC secret.
     * @param string $expectedAlgorithm The algorithm this deployment allows.
     *
     * @return array<string, mixed> The claim set, unfiltered.
     *
     * @throws JwtDecodeException on any structural or cryptographic failure.
     */
    public function decode(string $jwt, string $keyPem, string $expectedAlgorithm): array;
}