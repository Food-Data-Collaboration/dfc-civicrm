<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * One JWK from a JWKS document, reduced to what signature verification needs.
 *
 * ============================================================================
 * WHY THE `n`/`e` -> PEM ENCODER IS IN HERE AT ALL
 * ============================================================================
 * A JWKS publishes RSA keys as base64url-encoded big-endian integers (RFC 7518
 * §6.3.1), not as PEM. Something has to bridge that, and the options are:
 *
 *   - vendor a JWT library (see {@see JwtDecoderInterface} for why not);
 *   - trust the optional `x5c` certificate chain;
 *   - encode the DER.
 *
 * `x5c` is OPTIONAL in RFC 7517 and absent from many issuers' key sets, so
 * relying on it alone means this extension silently stops verifying tokens for
 * some issuers. The DER encoder is 40 lines of well-specified ASN.1, and
 * {@see JsonWebKeyTest} asserts the result round-trips through
 * `openssl_pkey_get_public()` and that `openssl_pkey_get_details()` reports
 * `OPENSSL_KEYTYPE_RSA` — i.e. the encoder is tested against OpenSSL's parser
 * rather than against itself.
 *
 * `x5c` IS used as a fallback, because a JWKS carrying only a certificate is
 * legal and common.
 *
 * ============================================================================
 * ENCRYPTION KEYS ARE NOT SIGNING KEYS, AND THE LIVE REALM PROVES IT
 * ============================================================================
 * The target realm's JWKS (fetched live 2026-10-02) contains exactly two keys:
 *
 *     kid Bt9lLwIG…  alg RSA-OAEP  use enc   x5c …
 *     kid z1Cr6MYO…  alg RS256     use sig   x5c …
 *
 * Selecting "the only key", or "the first RSA key", would select the ENCRYPTION
 * key and every signature check would fail — or worse, a deployment that "fixed"
 * it by dropping the `use` check would end up verifying tokens against a key
 * published for a different purpose. {@see JsonWebKeySet} therefore filters on
 * `use` AND `alg`, and this class is what carries those two fields faithfully.
 *
 * ============================================================================
 * NOTHING IN THIS CLASS CAN REACH A LOG
 * ============================================================================
 * {@see describe()} returns kid, kty, alg, use and a boolean. The key material
 * itself is reachable only through {@see publicKeyPem()}, whose return value is
 * a string a careless caller could interpolate into a message — which is why the
 * method is named for what it returns and the docblock says so. There is no
 * `__toString()`: a value object that stringifies itself is one `sprintf('%s',
 * $key)` away from a log line containing a public key. A public key is not a
 * secret, but publishing one is a fingerprinting and correlation problem, and
 * PRD-002 §9's mitigation is "never log ... by default".
 *
 * @package Civi\Dfc
 */
final class JsonWebKey
{
    /** OID 1.2.840.113549.1.1.1 (rsaEncryption), DER-encoded. */
    private const OID_RSA_ENCRYPTION = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";

    private const ASN1_NULL = "\x05\x00";

    private readonly string $kid;

    private readonly string $keyType;

    private readonly ?string $algorithm;

    private readonly ?string $use;

    private readonly ?string $modulus;

    private readonly ?string $exponent;

    private readonly ?string $certificateDer;

    private function __construct(
        string $kid,
        string $keyType,
        ?string $algorithm,
        ?string $use,
        ?string $modulus,
        ?string $exponent,
        ?string $certificateDer
    ) {
        $this->kid = $kid;
        $this->keyType = $keyType;
        $this->algorithm = $algorithm;
        $this->use = $use;
        $this->modulus = $modulus;
        $this->exponent = $exponent;
        $this->certificateDer = $certificateDer;
    }

    /**
     * @param array<string, mixed> $jwk One member of the document's `keys` array.
     *
     * @throws \InvalidArgumentException when the object has no usable `kid` or
     *         `kty`. Those two are the minimum a JWKS entry can carry and still be
     *         addressable: without `kid`, selection among several keys is a guess,
     *         and guessing is the failure mode this class exists to prevent.
     */
    public static function fromArray(array $jwk): self
    {
        $kid = $jwk['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            throw new \InvalidArgumentException(
                'A JWKS entry has no "kid". A key set that cannot be addressed by key id forces a verifier '
                . 'to guess which key a token was signed with, and a guess is forgeable.'
            );
        }

        $keyType = $jwk['kty'] ?? null;
        if (!is_string($keyType) || $keyType === '') {
            throw new \InvalidArgumentException(sprintf(
                'The JWKS entry "%s" has no "kty", so this server cannot know what kind of key it is.',
                $kid
            ));
        }

        $certificate = null;
        $chain = $jwk['x5c'] ?? null;
        if (is_array($chain) && $chain !== [] && is_string($chain[0]) && $chain[0] !== '') {
            // Standard base64, NOT base64url: RFC 7517 §4.7 says the certificate is
            // base64 DER.
            $certificate = base64_decode($chain[0], true);
            if ($certificate === false) {
                $certificate = null;
            }
        }

        return new self(
            $kid,
            $keyType,
            self::optionalString($jwk, 'alg'),
            self::optionalString($jwk, 'use'),
            self::optionalString($jwk, 'n'),
            self::optionalString($jwk, 'e'),
            $certificate
        );
    }

    public function kid(): string
    {
        return $this->kid;
    }

    public function keyType(): string
    {
        return $this->keyType;
    }

    /** The `alg` this key is declared for, or null when the issuer declares none. */
    public function algorithm(): ?string
    {
        return $this->algorithm;
    }

    /** `sig` or `enc`, or null when the issuer declares neither. */
    public function use(): ?string
    {
        return $this->use;
    }

    /**
     * May this key be used to VERIFY A SIGNATURE?
     *
     * RFC 7517 §4.2: `use` is OPTIONAL, so its absence is not evidence of anything.
     * A key with `use: enc` is refused outright — that is the live realm's
     * encryption key, and using it would be meaningless at best.
     */
    public function isSignatureKey(): bool
    {
        return $this->use === null || $this->use === 'sig';
    }

    public function isEncryptionKey(): bool
    {
        return $this->use === 'enc';
    }

    /**
     * Does this key serve the given `alg`?
     *
     * An entry with no `alg` is treated as compatible with any algorithm: RFC 7517
     * §4.4 makes `alg` optional and says its absence means "this key can be used
     * with any algorithm appropriate to its type". The deployment's algorithm
     * ALLOW-LIST is applied by {@see AccessTokenValidator} before this is ever
     * consulted, so a missing `alg` cannot widen what is accepted — only which key
     * within the accepted set is used.
     */
    public function serves(string $algorithm): bool
    {
        return $this->algorithm === null || $this->algorithm === $algorithm;
    }

    /**
     * The PEM-encoded RSA public key.
     *
     * Returns a string, so it can be interpolated into a message by a careless
     * caller. Do not. There is deliberately no `__toString()`.
     *
     * @throws \RuntimeException when the key is not RSA, or is RSA but carries
     *         neither `n`/`e` nor a usable `x5c` certificate. That is an
     *         issuer-side or configuration fault, never a client fault, so it is
     *         deliberately NOT a {@see JwtDecodeException}: mapping it to 401 would
     *         tell a well-behaved client its token is bad.
     */
    public function publicKeyPem(): string
    {
        if ($this->keyType !== 'RSA') {
            throw new \RuntimeException(sprintf(
                'The signing key "%s" is of type "%s". This server verifies RS256/RS384/RS512 only; see '
                . 'LocalJwtDecoder for why PSS and the symmetric algorithms are not implemented.',
                $this->kid,
                $this->keyType
            ));
        }

        if ($this->modulus !== null && $this->exponent !== null) {
            return self::pemFromModulusAndExponent($this->modulus, $this->exponent);
        }

        if ($this->certificateDer !== null) {
            $pem = self::pemFromCertificate($this->certificateDer, $this->kid);
            if ($pem !== null) {
                return $pem;
            }
        }

        throw new \RuntimeException(sprintf(
            'The RSA signing key "%s" publishes neither "n"/"e" nor a usable "x5c" certificate, so there is '
            . 'nothing to verify against.',
            $this->kid
        ));
    }

    /**
     * Audit-safe description. Carries no key material by construction.
     *
     * @return array<string, string|bool>
     */
    public function describe(): array
    {
        return [
            'kid' => $this->kid,
            'kty' => $this->keyType,
            'alg' => $this->algorithm ?? '',
            'use' => $this->use ?? '',
            'signature' => $this->isSignatureKey(),
        ];
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private static function optionalString(array $jwk, string $member): ?string
    {
        $value = $jwk[$member] ?? null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    // -- DER / PEM ------------------------------------------------------------

    /**
     * Encode `n` and `e` as a SubjectPublicKeyInfo and wrap it as PEM.
     *
     * The structure, per RFC 5280 §4.1:
     *
     *     SubjectPublicKeyInfo ::= SEQUENCE {
     *       algorithm         AlgorithmIdentifier,
     *       subjectPublicKey  BIT STRING }
     *     AlgorithmIdentifier ::= SEQUENCE {
     *       algorithm         OBJECT IDENTIFIER,   -- rsaEncryption
     *       parameters        NULL }
     *
     * and RFC 7518 §6.3.1's RSA key is `RSAPublicKey ::= SEQUENCE { modulus INTEGER,
     * publicExponent INTEGER }` living INSIDE that BIT STRING.
     */
    private static function pemFromModulusAndExponent(string $modulus, string $exponent): string
    {
        $n = self::base64UrlToUnsignedInteger($modulus);
        $e = self::base64UrlToUnsignedInteger($exponent);

        if ($n === '' || $e === '') {
            throw new \RuntimeException('A published RSA key has an empty modulus or exponent.');
        }

        $rsaPublicKey = self::derSequence(
            self::derInteger($n),
            self::derInteger($e)
        );

        $algorithmIdentifier = self::derSequence(self::OID_RSA_ENCRYPTION, self::ASN1_NULL);

        // The leading 0x00 is BIT STRING's "unused bits" octet, which is 0 for a
        // whole number of bytes.
        $subjectPublicKeyInfo = self::derSequence(
            $algorithmIdentifier,
            self::derElement(0x03, "\x00" . $rsaPublicKey)
        );

        return self::toPem($subjectPublicKeyInfo);
    }

    private static function pemFromCertificate(string $der, string $kid): ?string
    {
        $certificate = openssl_x509_read($der);
        if ($certificate === false) {
            return null;
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            return null;
        }

        $pem = openssl_pkey_get_details($publicKey);
        if (!is_array($pem) || !isset($pem['key']) || !is_string($pem['key'])) {
            return null;
        }

        return $pem['key'];
    }

    /**
     * Base64url -> the unsigned big-endian byte string DER's INTEGER wraps.
     *
     * DER INTEGERs are SIGNED, so a modulus whose top bit is set needs a leading
     * zero octet or it reads as negative. Leading zeros in the base64url encoding
     * are not part of the value and are dropped, per RFC 7518 §2 which specifies
     * "the base64url encoding of the value's unsigned big-endian representation
     * as an octet sequence" with no length prefix.
     */
    private static function base64UrlToUnsignedInteger(string $base64Url): string
    {
        if (preg_match('/[^A-Za-z0-9_\-]/', $base64Url) === 1) {
            throw new \RuntimeException(
                'A published RSA key carries a character outside the base64url alphabet.'
            );
        }

        $remainder = strlen($base64Url) % 4;
        $standard = strtr($base64Url, '-_', '+/');
        if ($remainder !== 0) {
            $standard .= str_repeat('=', 4 - $remainder);
        }

        /** @var string|false $bytes */
        $bytes = base64_decode($standard, true);
        if ($bytes === false) {
            throw new \RuntimeException('A published RSA modulus or exponent is not decodable base64url.');
        }

        // Strip leading zero octets: they are encoding padding, not value.
        $trimmed = ltrim($bytes, "\x00");

        return $trimmed === '' ? "\x00" : $trimmed;
    }

    private static function derInteger(string $unsignedBytes): string
    {
        $bytes = ltrim($unsignedBytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }

        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::derElement(0x02, $bytes);
    }

    private static function derSequence(string ...$elements): string
    {
        return self::derElement(0x30, implode('', $elements));
    }

    private static function derElement(int $tag, string $contents): string
    {
        return chr($tag) . self::derLength(strlen($contents)) . $contents;
    }

    /**
     * DER definite length, short form below 128 and long form above.
     */
    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function toPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}