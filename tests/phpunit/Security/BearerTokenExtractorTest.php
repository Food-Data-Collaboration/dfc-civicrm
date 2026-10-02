<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Controller\Error\AuthenticateChallenge;
use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ErrorCode;
use Civi\Dfc\V2\Identity\DfcReleaseConfig;
use Civi\Dfc\V2\Security\BearerToken;
use Civi\Dfc\V2\Security\BearerTokenExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6750 header extraction, and the 401 shape it produces.
 *
 * The interesting assertions here are about the CHALLENGE, not the token: two
 * different malformed headers must produce byte-identical challenges, so a client (or
 * an attacker enumerating malformations) learns nothing from which malformation it hit.
 */
#[CoversClass(BearerTokenExtractor::class)]
#[CoversClass(BearerToken::class)]
final class BearerTokenExtractorTest extends TestCase
{
    /**
     * With the trailing slash, because {@see \Civi\Dfc\V2\Identity\DfcReleaseConfig::platformBaseUri()}
     * normalises a directory base to exactly one — and the challenge echoes whatever the
     * config says, not a re-derived form of it.
     */
    private const REALM = 'https://platform.example/dfc/v2/';

    private BearerTokenExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new BearerTokenExtractor(OidcHarness::releaseConfig());
    }

    // -- The happy path -------------------------------------------------------

    public function testABearerTokenIsExtracted(): void
    {
        $token = $this->extractor->extract('Bearer abc.def.ghi');

        self::assertSame('abc.def.ghi', $token->value());
    }

    public function testTheSchemeIsCaseInsensitive(): void
    {
        // RFC 9110 §11.1: authentication schemes are case-insensitive.
        foreach (['Bearer', 'bearer', 'BEARER', 'BeArEr'] as $scheme) {
            self::assertSame('abc123', $this->extractor->extract($scheme . ' abc123')->value());
        }
    }

    public function testTheFullB64TokenCharacterSetIsAccepted(): void
    {
        // RFC 6750 §2.1 `b64token`: ALPHA / DIGIT / "-" / "." / "_" / "~" / "+" / "/" then
        // zero or more "=". Every one of those characters, plus padding, in one token.
        $token = $this->extractor->extract('Bearer aZ09-._~+/==');

        self::assertSame('aZ09-._~+/==', $token->value());
    }

    public function testOptionalWhitespaceAroundTheHeaderIsTolerated(): void
    {
        self::assertSame('abc123', $this->extractor->extract("  Bearer abc123 \t")->value());
    }

    public function testIsAbsentDistinguishesMissingFromPresent(): void
    {
        self::assertTrue($this->extractor->isAbsent(null));
        self::assertTrue($this->extractor->isAbsent(''));
        self::assertTrue($this->extractor->isAbsent('   '));
        self::assertFalse($this->extractor->isAbsent('Bearer abc'));
    }

    public function testTheRealmIsThePlatformBaseUri(): void
    {
        self::assertSame(self::REALM, $this->extractor->realm());
    }

    // -- Missing credentials: a PLAIN challenge -------------------------------

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function absentHeaders(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \t "];
    }

    #[DataProvider('absentHeaders')]
    public function testNoHeaderIsA401WithAPlainChallenge(?string $header): void
    {
        // RFC 6750 §3: a resource server that receives no token "SHOULD NOT include an
        // error code". Emitting `invalid_request` here would teach clients to expect a
        // recoverable protocol error when the real answer is "there was no credential".
        try {
            $this->extractor->extract($header);
            self::fail('A missing credential must be rejected.');
        } catch (DfcApiException $rejected) {
            $error = $rejected->error();

            self::assertSame(401, $error->status());
            self::assertSame(ErrorCode::AUTHENTICATION_REQUIRED, $error->code());

            $challenge = $error->challenge();
            self::assertNotNull($challenge);
            self::assertNull($challenge->error(), 'A missing credential must produce a plain challenge.');
            self::assertSame(
                'Bearer realm="' . self::REALM . '"',
                $challenge->headerValue()
            );
        }
    }

    // -- Malformed credentials: `invalid_request` ------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'no space' => ['Bearerabc123'];
        yield 'scheme only' => ['Bearer'];
        yield 'trailing space with nothing after it' => ['Bearer '];
        yield 'two credentials' => ['Bearer abc Bearer def'];
        yield 'three tokens' => ['Bearer abc def'];
        yield 'basic scheme' => ['Basic dXNlcjpwYXNz'];
        yield 'digest scheme' => ['Digest username="x"'];
        yield 'negotiate scheme' => ['Negotiate abcdef'];
        yield 'token with a space inside' => ['Bearer abc 123'];
        yield 'token with a comma' => ['Bearer abc,123'];
        yield 'token with a colon' => ['Bearer abc:123'];
        yield 'token with an asterisk' => ['Bearer abc*123'];
        yield 'token with a quote' => ['Bearer abc"123'];
        yield 'token with a brace' => ['Bearer abc{123}'];
        yield 'token with a pipe' => ['Bearer abc|123'];
        yield 'token with a backslash' => ['Bearer abc\\123'];
        yield 'token with a caret' => ['Bearer abc^123'];
        yield 'token with a backtick' => ['Bearer abc`123'];
        yield 'token with a percent' => ['Bearer abc%zz'];
        yield 'token with a semicolon' => ['Bearer abc;123'];
        yield 'scheme with a colon' => ['Bearer: abc123'];
        yield 'bearer only lowercase with no creds' => ['bearer'];
        yield 'scheme with a digit' => ['Bearer1 abc123'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function toleratedHeaders(): iterable
    {
        // RFC 7230 §3.2.2 and RFC 9110 §5.5 both permit runs of spaces around and
        // between the parts of a field value, so these are ACCEPTED rather than
        // rejected. Being strict here would break real clients for no security gain: the
        // grammar still has to reduce to exactly one scheme and one b64token.
        yield 'leading space' => [' Bearer abc123', 'abc123'];
        yield 'two spaces before the token' => ['Bearer  abc123', 'abc123'];
        yield 'many spaces' => ['Bearer      abc123', 'abc123'];
        yield 'trailing tab' => ["Bearer abc123\t", 'abc123'];
    }

    #[DataProvider('toleratedHeaders')]
    public function testOptionalWhitespaceIsToleratedNotRejected(string $header, string $expected): void
    {
        self::assertSame($expected, $this->extractor->extract($header)->value());
    }

    #[DataProvider('malformedHeaders')]
    public function testAMalformedHeaderIsA401WithAnInvalidRequestChallenge(string $header): void
    {
        try {
            $this->extractor->extract($header);
            self::fail(sprintf('"%s" must be rejected.', addcslashes($header, "\0..\37")));
        } catch (DfcApiException $rejected) {
            $error = $rejected->error();

            self::assertSame(401, $error->status());
            self::assertSame(ErrorCode::AUTHENTICATION_REQUIRED, $error->code());

            $challenge = $error->challenge();
            self::assertNotNull($challenge);
            self::assertSame('invalid_request', $challenge->error());
            self::assertSame(
                'Bearer realm="' . self::REALM . '", error="invalid_request"',
                $challenge->headerValue()
            );
        }
    }

    public function testEveryMalformationProducesTheSameChallengeBytes(): void
    {
        // Two different malformations producing two different challenges would let a
        // client enumerate which one it hit. This is the assertion that closes that.
        $challenges = [];

        foreach (self::malformedHeaders() as [$header]) {
            try {
                $this->extractor->extract($header);
            } catch (DfcApiException $rejected) {
                $challenges[] = $rejected->error()->challenge()?->headerValue();
            }
        }

        self::assertNotSame([], $challenges);
        self::assertCount(1, array_unique($challenges));
    }

    public function testAMissingAndAMalformedHeaderProduceDifferentChallenges(): void
    {
        // Plain versus `invalid_request` is a real distinction: the client can act on it.

        try {
            $this->extractor->extract(null);
        } catch (DfcApiException $absent) {
            $plain = $absent->error()->challenge()?->headerValue();
        }

        try {
            $this->extractor->extract('Basic x');
        } catch (DfcApiException $malformed) {
            $withError = $malformed->error()->challenge()?->headerValue();
        }

        self::assertNotSame($plain, $withError);
        self::assertStringNotContainsString('error=', (string) $plain);
        self::assertStringContainsString('invalid_request', (string) $withError);
    }

    // -- Control characters ---------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function controlCharacterHeaders(): iterable
    {
        yield 'CRLF injection' => ["Bearer abc\r\nX-Injected: 1"];
        yield 'bare CR' => ["Bearer abc\r"];
        yield 'bare LF' => ["Bearer abc\n"];
        yield 'NUL' => ["Bearer abc\0def"];
        yield 'DEL' => ["Bearer abc\x7Fdef"];
        yield 'tab inside the token' => ["Bearer abc\tdef"];
    }

    #[DataProvider('controlCharacterHeaders')]
    public function testAControlCharacterInTheHeaderIsRejectedNotSanitised(string $header): void
    {
        // Sanitising would change the meaning of a header the client believes it sent
        // correctly, which is the failure RFC 9110's field-value grammar exists to stop.
        try {
            $this->extractor->extract($header);
            self::fail('A header with a control character must be rejected.');
        } catch (DfcApiException $rejected) {
            self::assertSame(401, $rejected->status());
            self::assertSame('invalid_request', $rejected->error()->challenge()?->error());
        }
    }

    // -- The 401 is well-formed ------------------------------------------------

    public function testTheRejectionIsRenderableByTheErrorLayer(): void
    {
        // The 401 must go through sa-005's model with nothing left to construct: a
        // WWW-Authenticate header, a problem body, and no internal detail.
        try {
            $this->extractor->extract('Basic x');
            self::fail('Must be rejected.');
        } catch (DfcApiException $rejected) {
            $error = $rejected->error();

            self::assertSame(['WWW-Authenticate'], array_keys($error->headers()));

            $body = $error->toArray();
            self::assertSame(401, $body['status']);
            self::assertSame('authentication_required', $body['code']);
            self::assertSame('urn:dfc-civicrm:error:authentication_required', $body['type']);
            self::assertFalse($body['redacted']);

            $json = $error->toJson();
            self::assertJson($json);
            self::assertStringNotContainsString('Basic', $json);
        }
    }

    public function testTheRejectionCarriesNoCorrelationIdByDefault(): void
    {
        // The error layer backfills one; producing it here would mean two places
        // deciding.
        try {
            $this->extractor->extract(null);
        } catch (DfcApiException $rejected) {
            self::assertNull($rejected->error()->correlationId());
        }
    }

    // -- The token value object ------------------------------------------------

    /**
     * `print_r` and `var_dump` both honour `__debugInfo`.
     *
     * `var_export` is deliberately NOT asserted: it does not consult
     * `__debugInfo`, so a token appears in its output. That is a real, documented
     * limitation rather than an oversight — see {@see BearerToken}'s class docblock,
     * which names `var_export` as the one debug path this cannot cover and says why it
     * is acceptable.
     */
    public function testTheTokenIsRedactedInADebugDump(): void
    {
        $token = $this->extractor->extract('Bearer super-secret-value');

        $printed = print_r($token, true);
        self::assertStringContainsString('[REDACTED]', $printed);
        self::assertStringNotContainsString('super-secret-value', $printed);

        ob_start();
        var_dump($token);
        $dumped = (string) ob_get_clean();

        self::assertStringContainsString('[REDACTED]', $dumped);
        self::assertStringNotContainsString('super-secret-value', $dumped);
    }

    public function testTheDebugOutputNamesOnlyTheTokenSlot(): void
    {
        $token = $this->extractor->extract('Bearer secret');

        self::assertSame(['token' => '[REDACTED]'], $token->__debugInfo());
    }

    public function testTheTokenCannotBeStringifiedImplicitly(): void
    {
        // No `__toString()`: making the conversion explicit means every appearance of the
        // raw bytes in a string context is a line somebody typed.
        self::assertFalse(method_exists(BearerToken::class, '__toString'));
    }

    public function testTheTokenLengthIsObservableWithoutRevealingIt(): void
    {
        $token = $this->extractor->extract('Bearer abcdef');

        self::assertSame(6, $token->byteLength());
    }
}