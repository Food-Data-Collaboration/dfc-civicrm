<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Negotiation;

use Civi\Dfc\Test\Controller\ControllerFixtureTrait;
use Civi\Dfc\Test\Controller\ControllerFixtures;
use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ErrorCode;
use Civi\Dfc\V2\Controller\Negotiation\ContentNegotiator;
use Civi\Dfc\V2\Controller\Negotiation\MediaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Content negotiation in both directions.
 *
 * The overriding rule under test throughout: the server never sends a
 * representation the client did not ask for, and never fails to tell the client
 * what it could have had.
 */
#[CoversClass(ContentNegotiator::class)]
#[CoversClass(MediaType::class)]
final class ContentNegotiatorTest extends TestCase
{
    use ControllerFixtureTrait;

    // -- Exact matches --------------------------------------------------------

    #[DataProvider('exactAcceptProvider')]
    public function testAnExactMatchSelectsThatType(string $accept, string $expected): void
    {
        $chosen = self::negotiator()->negotiateResponse($accept, ControllerFixtures::standardOffers());

        self::assertSame($expected, $chosen->essence());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function exactAcceptProvider(): iterable
    {
        yield 'JSON-LD' => ['application/ld+json', MediaType::JSON_LD];
        yield 'Turtle' => ['text/turtle', MediaType::TURTLE];
        yield 'a charset parameter is honoured' => ['application/ld+json; charset=utf-8', MediaType::JSON_LD];
        yield 'case is folded' => ['APPLICATION/LD+JSON', MediaType::JSON_LD];
        yield 'surrounding whitespace is tolerated' => ['  text/turtle ;  charset=UTF-8 ', MediaType::TURTLE];
        yield 'an empty list element is tolerated' => [', application/ld+json,', MediaType::JSON_LD];
        yield 'a quoted parameter value' => ['application/ld+json;profile="https://example.test/p"', MediaType::JSON_LD];    }

    // -- Wildcards ------------------------------------------------------------

    public function testAWildcardAcceptTakesTheServersFirstOffer(): void
    {
        // The offer order is the server's preference, and it always starts with
        // application/ld+json because the DFC contract requires it.
        $chosen = self::negotiator()->negotiateResponse('*/*', ControllerFixtures::standardOffers());

        self::assertSame(MediaType::JSON_LD, $chosen->essence());
    }

    public function testASubtypeWildcardAcceptsEveryOfferOfThatType(): void
    {
        $chosen = self::negotiator()->negotiateResponse('application/*', ControllerFixtures::standardOffers());

        self::assertSame(MediaType::JSON_LD, $chosen->essence());
    }

    public function testASubtypeWildcardDoesNotReachAcrossTypes(): void
    {
        $this->expectException(DfcApiException::class);

        self::negotiator()->negotiateResponse('text/*', [MediaType::jsonLd()]);
    }

    /**
     * Specificity beats offer-list position: a client that names a type explicitly
     * gets it even when the server prefers another.
     */
    public function testAnExactRangeWinsOverTheServersPreference(): void
    {
        $offers = [MediaType::turtle(), MediaType::jsonLd()];

        $chosen = self::negotiator()->negotiateResponse('application/ld+json', $offers);

        self::assertSame(MediaType::JSON_LD, $chosen->essence());
    }

    // -- q weights ------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function qualityProvider(): iterable
    {
        yield 'the higher q wins' => [
            'application/ld+json;q=0.1, text/turtle;q=0.9',
            MediaType::TURTLE,
        ];

        yield 'a specific low q loses to a wildcard high q' => [
            'application/ld+json;q=0.1, */*;q=0.9',
            MediaType::TURTLE,
        ];

        yield 'a specific high q beats a wildcard low q' => [
            '*/*;q=0.1, application/ld+json;q=0.9',
            MediaType::JSON_LD,
        ];

        yield 'equal q falls back to offer order' => [
            'text/turtle;q=0.5, application/ld+json;q=0.5',
            MediaType::JSON_LD,
        ];

        yield 'a missing q means 1.0' => [
            'text/turtle, application/ld+json;q=0.9',
            MediaType::TURTLE,
        ];

        yield 'three decimal places are honoured' => [
            'application/ld+json;q=0.001, text/turtle;q=0.002',
            MediaType::TURTLE,
        ];

        yield 'a named type at 0.8 still loses to everything at 0.9' => [
            '*/*;q=0.9, application/ld+json;q=0.8',
            MediaType::TURTLE,
        ];

        yield 'a named type at 0.9 beats everything at 0.8' => [
            '*/*;q=0.8, application/ld+json;q=0.9',
            MediaType::JSON_LD,
        ];

        yield 'two ranges for one type: the highest q of that specificity' => [
            'application/ld+json;q=0.2, application/ld+json;q=0.7, text/turtle;q=0.3',
            MediaType::JSON_LD,
        ];
    }

    #[DataProvider('qualityProvider')]
    public function testQualityWeightsAreHonoured(string $accept, string $expected): void
    {
        self::assertSame(
            $expected,
            self::negotiator()->negotiateResponse($accept, ControllerFixtures::standardOffers())->essence()
        );
    }

    // -- q=0 ------------------------------------------------------------------

    #[DataProvider('zeroQualityProvider')]
    public function testQZeroExcludesRatherThanDeprioritises(string $accept, string $expected): void
    {
        $chosen = self::negotiator()->negotiateResponse($accept, ControllerFixtures::standardOffers());

        self::assertSame($expected, $chosen->essence());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function zeroQualityProvider(): iterable
    {
        yield 'a wildcard of zero leaves only the named type' => [
            '*/*;q=0, application/ld+json',
            MediaType::JSON_LD,
        ];

        yield 'an explicit zero beats a permissive wildcard' => [
            'application/ld+json;q=0, */*;q=1',
            MediaType::TURTLE,
        ];
    }

    // -- The 415 path ---------------------------------------------------------

    #[DataProvider('unsupportedAcceptProvider')]
    public function testNothingAcceptableIsA415AndNeverAFallback(string $accept): void
    {
        try {
            $chosen = self::negotiator()->negotiateResponse($accept, ControllerFixtures::standardOffers());
            self::fail(sprintf(
                'Expected a 415 for Accept: "%s", got "%s". Silently falling back is the failure this class '
                . 'exists to prevent.',
                $accept,
                $chosen->essence()
            ));
        } catch (DfcApiException $exception) {
            $error = $exception->error();

            self::assertSame(415, $error->status());
            self::assertSame(ErrorCode::UNSUPPORTED_MEDIA_TYPE, $error->code());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedAcceptProvider(): iterable
    {
        yield 'an empty Accept header value' => [''];
        yield 'a whitespace-only Accept header value' => ['   '];
        yield 'HTML only' => ['text/html, application/xhtml+xml'];
        yield 'a wildcard excluded wholesale' => ['*/*;q=0'];
        yield 'both required types excluded' => ['application/ld+json;q=0, text/turtle;q=0'];
        yield 'a legacy browser codings-only Accept' => ['gzip, deflate'];
        yield 'a charset this server will not lie about' => ['text/turtle; charset=iso-8859-1'];
        yield 'an over-specific parameter' => ['application/ld+json;level=2'];
        yield 'a mis-spelled type' => ['applicaton/ld+json'];
    }

    public function testA415NamesWhatIsAcceptable(): void
    {
        try {
            self::negotiator()->negotiateResponse('text/html', ControllerFixtures::standardOffers());
            self::fail('Expected a 415.');
        } catch (DfcApiException $exception) {
            self::assertSame(
                ['application/ld+json', 'text/turtle'],
                $exception->error()->supportedMediaTypes()
            );
        }
    }

    // -- Broken headers -------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenAcceptProvider(): iterable
    {
        yield 'a q above one' => ['application/ld+json;q=2'];
        yield 'a negative q' => ['application/ld+json;q=-1'];
        yield 'a non-numeric q' => ['application/ld+json;q=high'];
        yield 'a parameter with no value' => ['application/ld+json;charset'];
        yield 'an unterminated quoted string' => ['application/ld+json;profile="unclosed'];
        yield 'a misplaced wildcard' => ['appl*/*'];
        yield 'a wildcard type with a concrete subtype' => ['*/json'];
    }

    #[DataProvider('brokenAcceptProvider')]
    public function testABrokenHeaderIsA400NotA415AndNotAnIgnore(string $accept): void
    {
        try {
            self::negotiator()->negotiateResponse($accept, ControllerFixtures::standardOffers());
            self::fail(sprintf('Expected a 400 for Accept: "%s".', $accept));
        } catch (DfcApiException $exception) {
            self::assertSame(400, $exception->error()->status());
            self::assertSame(ErrorCode::INVALID_HEADER, $exception->error()->code());
        }
    }

    // -- Absent Accept --------------------------------------------------------

    public function testAnAbsentAcceptHeaderMeansNoPreference(): void
    {
        self::assertSame(
            MediaType::JSON_LD,
            self::negotiator()->negotiateResponse(null, ControllerFixtures::standardOffers())->essence()
        );
    }

    public function testAnAbsentAcceptHeaderStillRespectsTheOfferOrder(): void
    {
        self::assertSame(
            MediaType::TURTLE,
            self::negotiator()->negotiateResponse(null, [MediaType::turtle(), MediaType::jsonLd()])->essence()
        );
    }

    // -- Offer-list validation ------------------------------------------------

    public function testAnEmptyOfferListIsAProgrammingErrorNotA415(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one offered media type');

        self::negotiator()->negotiateResponse('application/ld+json', []);
    }

    public function testAWildcardCannotBeOffered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('wildcard');

        self::negotiator()->negotiateResponse('application/ld+json', [
            MediaType::parse('*/*'),
        ]);
    }

    public function testADuplicateOfferIsCollapsedRatherThanRejected(): void
    {
        $chosen = self::negotiator()->negotiateResponse('application/ld+json', [
            MediaType::jsonLd(),
            MediaType::jsonLd(),
        ]);

        self::assertSame(MediaType::JSON_LD, $chosen->essence());
    }

    // -- Request bodies -------------------------------------------------------

    #[DataProvider('acceptableBodyProvider')]
    public function testAnAcceptableRequestBodyIsAccepted(string $contentType, string $expected): void
    {
        $accepted = self::negotiator()->assertAcceptableRequestBody(
            $contentType,
            [MediaType::jsonLd(), MediaType::turtle()]
        );

        self::assertSame($expected, $accepted->essence());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptableBodyProvider(): iterable
    {
        yield 'JSON-LD' => ['application/ld+json', MediaType::JSON_LD];
        yield 'JSON-LD with a charset' => ['application/ld+json; charset=utf-8', MediaType::JSON_LD];
        yield 'Turtle' => ['text/turtle', MediaType::TURTLE];
    }

    #[DataProvider('unacceptableBodyProvider')]
    public function testAnUnacceptableRequestBodyIsA415(string|array|null $contentType, string $label): void
    {
        try {
            $accepted = self::negotiator()->assertAcceptableRequestBody(
                is_array($contentType) ? implode(', ', $contentType) : $contentType,
                [MediaType::jsonLd()]
            );
            self::fail(sprintf('Expected a 415 for the %s body, got %s.', $label, $accepted->essence()));
        } catch (DfcApiException $exception) {
            self::assertSame(415, $exception->error()->status());
            self::assertSame(
                ['application/ld+json'],
                $exception->error()->supportedMediaTypes()
            );
        }
    }

    /**
     * @return iterable<string, array{string|array<string>|null, string}>
     */
    public static function unacceptableBodyProvider(): iterable
    {
        yield 'plain text' => ['text/plain', 'text/plain'];
        yield 'form encoding' => ['application/x-www-form-urlencoded', 'form-encoded'];
        yield 'an absent Content-Type, which means octet-stream' => [null, 'absent'];
        yield 'an empty Content-Type' => ['', 'empty'];
        yield 'a list of types in a Content-Type' => [
            ['application/ld+json', 'text/turtle'],
            'multi-valued',
        ];
    }

    /**
     * `application/json` is the SAME BYTES as JSON-LD, and accepting it would be
     * exactly the silent format fallback this class exists to prevent: a media type
     * that promises no JSON-LD semantics, accepted on a resource whose whole point
     * is JSON-LD semantics. A deployment that wants it adds it to the accepted list
     * explicitly, which makes it a decision.
     */
    public function testPlainJsonIsNotSilentlyAcceptedAsJsonLd(): void
    {
        $this->expectException(DfcApiException::class);

        self::negotiator()->assertAcceptableRequestBody('application/json', [MediaType::jsonLd()]);
    }

    public function testPlainJsonCanBeAcceptedWhenItIsOfferedExplicitly(): void
    {
        $accepted = self::negotiator()->assertAcceptableRequestBody(
            'application/json',
            [MediaType::jsonLd(), MediaType::of(MediaType::JSON)]
        );

        self::assertSame(MediaType::JSON, $accepted->essence());
    }

    // -- MediaType primitives -------------------------------------------------

    public function testSpecificityIsRanked(): void
    {
        self::assertSame(2, MediaType::parse('application/ld+json')->specificity());
        self::assertSame(1, MediaType::parse('application/*')->specificity());
        self::assertSame(0, MediaType::parse('*/*')->specificity());
    }

    public function testAConcreteTypeNeverMatchesAWildcardOffer(): void
    {
        self::assertFalse(MediaType::jsonLd()->matches(MediaType::parse('*/*')));
    }

    public function testAcceptExtensionsAfterQDoNotAffectMatching(): void
    {
        $range = MediaType::parse('application/ld+json;q=0.5;ext=thing');

        self::assertSame(0.5, $range->quality());
        self::assertSame(['ext' => 'thing'], $range->extensions());
        self::assertTrue($range->matches(MediaType::jsonLd()));
    }

    public function testQuotedStringEscapesAreDecoded(): void
    {
        $range = MediaType::parse('application/ld+json;profile="a\\"b"');

        self::assertSame('a"b', $range->parameter('profile'));
    }

    public function testARenderingOmitsTheQualitySoItCanBeUsedAsAContentType(): void
    {
        self::assertSame('application/ld+json', MediaType::parse('application/ld+json;q=0.4')->toString());
        self::assertSame(
            'application/ld+json, text/turtle',
            self::negotiator()->renderList(ControllerFixtures::standardOffers())
        );
    }

    public function testTheVaryHeaderIsAlwaysAdvertisedByThisLayer(): void
    {
        self::assertSame(['Accept', 'Authorization'], ContentNegotiator::VARY);
    }
}
