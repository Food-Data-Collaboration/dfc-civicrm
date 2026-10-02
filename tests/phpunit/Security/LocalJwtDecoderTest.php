<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Security\JwtDecodeException;
use Civi\Dfc\V2\Security\LocalJwtDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The JWS verifier, against the four attacks it exists to refuse.
 *
 * The tokens are minted by {@see JwtFactory}, which builds them PROPERLY — an
 * `alg: none` token really does have an empty signature segment, and an HMAC
 * confusion token really is an HMAC over the real signing input. That matters: a
 * token built by string concatenation would make these tests pass for the wrong
 * reason.
 *
 * @see AccessTokenValidatorTest for the claim-level checks
 */
#[CoversClass(LocalJwtDecoder::class)]
final class LocalJwtDecoderTest extends TestCase
{
    private LocalJwtDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new LocalJwtDecoder();
    }

    // -- The happy path, so the rejections below mean something ----------------

    public function testAValidRs256TokenVerifies(): void
    {
        $kid = 'valid-kid';
        $claims = JwtFactory::claims(['sub' => 'abc']);

        $decoded = $this->decoder->decode(
            JwtFactory::accessToken($kid, $claims),
            JwtFactory::publicKeyPem($kid),
            'RS256'
        );

        self::assertSame('abc', $decoded['sub']);
        self::assertSame(JwtTestSupport::REALM, $decoded['iss']);
    }

    public function testSupportedAlgorithmsAreRsaOnlyAndExcludeNone(): void
    {
        $algorithms = $this->decoder->supportedAlgorithms();

        self::assertContains('RS256', $algorithms);
        self::assertNotContains('none', $algorithms);

        foreach ($algorithms as $algorithm) {
            self::assertStringStartsWith('RS', $algorithm, 'A symmetric or PSS algorithm must not be supported.');
        }
    }

    public function testHeaderIsReadableWithoutVerification(): void
    {
        $kid = 'header-kid';

        $header = $this->decoder->header(JwtFactory::accessToken($kid));

        self::assertSame('RS256', $header['alg']);
        self::assertSame($kid, $header['kid']);
    }

    // -- Attack 1: alg: none --------------------------------------------------

    public function testAlgNoneIsRejected(): void
    {
        $token = JwtFactory::unsignedToken(JwtFactory::claims());

        // The string really is the RFC 7515 A.1 shape: three segments, empty third.
        self::assertStringEndsWith('.', $token);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/empty|three dot-separated/i');

        $this->decoder->decode($token, JwtFactory::publicKeyPem('k1'), 'none');
    }

    public function testAlgNoneWithAnExpectedAlgorithmOfNoneIsStillRejected(): void
    {
        // Even a caller that passes `none` as the expected algorithm cannot get this
        // class to accept an unsigned token: the allow-list is checked before the
        // caller's value is consulted.
        $this->expectException(JwtDecodeException::class);

        $this->decoder->decode(
            JwtFactory::unsignedToken(JwtFactory::claims()),
            JwtFactory::publicKeyPem('k1'),
            'none'
        );
    }

    // -- Attack 2: HMAC signed with the RSA public key ------------------------

    public function testHmacSignedWithTheRsaPublicKeyIsRejected(): void
    {
        $kid = 'confusion-kid';
        $token = JwtFactory::hmacConfusionToken($kid, JwtFactory::claims());

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/symmetric algorithm is never accepted/');

        $this->decoder->decode($token, JwtFactory::publicKeyPem($kid), 'RS256');
    }

    public function testHmacConfirmationTheAttackTokenIsOtherwiseWellFormed(): void
    {
        // If this assertion fails, the rejection above is passing for a structural
        // reason and the confusion attack is untested.
        $kid = 'confusion-kid-2';

        $header = $this->decoder->header(JwtFactory::hmacConfusionToken($kid, JwtFactory::claims()));

        self::assertSame('HS256', $header['alg']);
        self::assertSame($kid, $header['kid']);
    }

    // -- Attack 3: a different algorithm than the deployment accepts -----------

    public function testAlgorithmMustMatchWhatTheCallerExpects(): void
    {
        $kid = 'mismatch-kid';

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/accepts only "RS384"/');

        $this->decoder->decode(
            JwtFactory::accessToken($kid),
            JwtFactory::publicKeyPem($kid),
            'RS384'
        );
    }

    public function testCallerCannotWidenThePolicyForOneToken(): void
    {
        // Passing a permissive $expectedAlgorithm must not make an unsupported
        // algorithm acceptable: the capability list is checked too.
        $kid = 'widen-kid';

        $this->expectException(JwtDecodeException::class);

        $this->decoder->decode(
            JwtFactory::hmacConfusionToken($kid, JwtFactory::claims()),
            JwtFactory::publicKeyPem($kid),
            'HS256'
        );
    }

    // -- Attack 4: an altered signature ---------------------------------------

    public function testAlteredSignatureIsRejected(): void
    {
        $kid = 'altered-kid';
        $token = JwtFactory::accessToken($kid, JwtFactory::claims(['sub' => 'alice']));

        $segments = explode('.', $token);
        self::assertCount(3, $segments);

        // Flip one byte of the payload, leaving the signature untouched.
        $segments[1] = self::flipLastChar($segments[1]);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/does not verify/');

        $this->decoder->decode(implode('.', $segments), JwtFactory::publicKeyPem($kid), 'RS256');
    }

    public function testSignatureFromADifferentKeyIsRejected(): void
    {
        $minted = 'key-a';
        $presented = 'key-b';
        JwtFactory::keyPair($presented);

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/does not verify/');

        $this->decoder->decode(
            JwtFactory::accessToken($minted),
            JwtFactory::publicKeyPem($presented),
            'RS256'
        );
    }

    // -- crit extensions ------------------------------------------------------

    public function testCritHeaderIsRejected(): void
    {
        $kid = 'crit-kid';

        $this->expectException(JwtDecodeException::class);
        $this->expectExceptionMessageMatches('/"crit"/');

        $this->decoder->decode(
            JwtFactory::accessToken($kid, null, ['crit' => ['urn:example:extension']]),
            JwtFactory::publicKeyPem($kid),
            'RS256'
        );
    }

    // -- Malformed tokens -----------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedVariants(): iterable
    {
        foreach ([
            'empty',
            'two-segments',
            'four-segments',
            'jwe',
            'padded',
            'not-base64url',
            'header-not-json',
            'header-is-array',
            'signature-empty',
            'impossible-length',
        ] as $variant) {
            yield $variant => [$variant];
        }
    }

    #[DataProvider('malformedVariants')]
    public function testMalformedTokensAreRejected(string $variant): void
    {
        $this->expectException(JwtDecodeException::class);

        $this->decoder->decode(
            JwtFactory::malformed($variant),
            JwtFactory::publicKeyPem('k1'),
            'RS256'
        );
    }

    public function testEveryMalformedVariantFailsInTheHeaderReaderToo(): void
    {
        // A caller that only reads the header must not be able to crash on these
        // either, or key selection becomes an unhandled throwable.
        foreach (['empty', 'two-segments', 'not-base64url', 'header-not-json'] as $variant) {
            try {
                $this->decoder->header(JwtFactory::malformed($variant));
                self::fail(sprintf('Variant "%s" should not produce a header.', $variant));
            } catch (JwtDecodeException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
    }

    // -- Key material ---------------------------------------------------------

    public function testAnHmacSecretPresentedAsAPemIsRejected(): void
    {
        // `kty: oct` keys publish a shared secret. If one were accepted as a signing
        // key, the algorithm-confusion attack would be reachable through the key set
        // rather than only through the token header.
        $this->expectException(JwtDecodeException::class);

        $this->decoder->decode(
            JwtFactory::accessToken('k1'),
            '-----BEGIN PUBLIC KEY-----\nnot a key\n-----END PUBLIC KEY-----\n',
            'RS256'
        );
    }

    public function testAValidTokenDoesNotLeakTheKeyOrTokenInAnyErrorMessage(): void
    {
        try {
            $this->decoder->decode(
                JwtFactory::accessToken('leaky-kid'),
                'not a pem at all',
                'RS256'
            );
            self::fail('A non-PEM key must be rejected.');
        } catch (JwtDecodeException $rejected) {
            self::assertStringNotContainsString('BEGIN', $rejected->getMessage());
            self::assertStringNotContainsString('leaky-kid', $rejected->getMessage());
            self::assertStringNotContainsString('openssl', $rejected->getMessage());
        }
    }

    private static function flipLastChar(string $base64Url): string
    {
        $last = $base64Url[strlen($base64Url) - 1];

        return substr($base64Url, 0, -1) . ($last === 'A' ? 'B' : 'A');
    }
}