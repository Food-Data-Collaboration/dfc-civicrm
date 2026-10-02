<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Http;

use Civi\Dfc\V2\Controller\Http\ETag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Entity tags: stability, strength, and parsing.
 *
 * An ETag is a claim about BYTES. Everything here is about not making a claim the
 * server cannot keep.
 */
#[CoversClass(ETag::class)]
final class ETagTest extends TestCase
{
    private const REPRESENTATION = '{"@id":"https://platform.example/dfc/v2/x/","@type":"ldp:BasicContainer"}';

    // -- Stability ------------------------------------------------------------

    public function testTheSameBytesAlwaysProduceTheSameTag(): void
    {
        self::assertSame(
            ETag::strong(self::REPRESENTATION)->value(),
            ETag::strong(self::REPRESENTATION)->value(),
            'Two independent computations over the same bytes must agree, or a client re-reads forever.'
        );
    }

    public function testDifferentBytesProduceDifferentTags(): void
    {
        self::assertNotSame(
            ETag::strong(self::REPRESENTATION)->value(),
            ETag::strong(self::REPRESENTATION . ' ')->value()
        );
    }

    public function testTheDigestIsLabelledSoAFutureAlgorithmChangeCannotCollide(): void
    {
        self::assertStringStartsWith('"sha256-', ETag::strong(self::REPRESENTATION)->value());
    }

    public function testTheDigestIsBounded(): void
    {
        // 128 bits of SHA-256: indistinguishable in a header, and enough that a
        // collision in a realistic cache-key space is impossible.
        self::assertSame(32, strlen(ETag::strong(self::REPRESENTATION)->opaque()) - strlen('sha256-'));
    }

    public function testAnEmptyRepresentationStillHasATag(): void
    {
        // An empty body is a well-defined representation with a well-defined tag.
        self::assertNotSame('', ETag::strong('')->value());
        self::assertNotSame(ETag::strong('')->value(), ETag::strong(' ')->value());
    }

    // -- Strength -------------------------------------------------------------

    public function testAStrongTagHasNoWeakIndicator(): void
    {
        $tag = ETag::strong(self::REPRESENTATION);

        self::assertFalse($tag->isWeak());
        self::assertStringStartsWith('"', $tag->value());
        self::assertStringNotContainsString('W/', $tag->value());
    }

    public function testAWeakTagIsMarkedAndDeclinesByteEquality(): void
    {
        $tag = ETag::weak('projection-version-7');

        self::assertTrue($tag->isWeak());
        self::assertStringStartsWith('W/"', $tag->value());
        self::assertFalse(
            $tag->equalsStrong(ETag::weak('projection-version-7')),
            'A weak tag promises semantic equivalence, not byte equality, so it never matches under If-Match.'
        );
    }

    public function testStrongComparisonRequiresBothSidesToBeStrong(): void
    {
        $strong = ETag::strong(self::REPRESENTATION);

        self::assertTrue($strong->equalsStrong(ETag::strong(self::REPRESENTATION)));
        self::assertFalse($strong->equalsStrong(ETag::weak(self::REPRESENTATION)));
        self::assertFalse(ETag::weak(self::REPRESENTATION)->equalsStrong($strong));
    }

    public function testExactEqualityDistinguishesWeakFromStrong(): void
    {
        self::assertTrue(
            ETag::weak('x')->equals(ETag::weak('x')),
            'equals() is the "same tag" question; equalsStrong() is the "same bytes" question.'
        );
        self::assertFalse(ETag::weak('x')->equals(ETag::strong('x')));
    }

    // -- Parsing --------------------------------------------------------------

    public function testAHeaderValueRoundTrips(): void
    {
        $original = ETag::strong(self::REPRESENTATION);
        $parsed = ETag::fromHeaderValue($original->value());

        self::assertSame($original->opaque(), $parsed->opaque());
        self::assertFalse($parsed->isWeak());
        self::assertTrue($parsed->equalsStrong($original));
    }

    public function testAWeakHeaderValueParsesAsWeak(): void
    {
        $parsed = ETag::fromHeaderValue('W/"sha256-abc"');

        self::assertTrue($parsed->isWeak());
        self::assertSame('sha256-abc', $parsed->opaque());
    }

    public function testALowercaseWeakIndicatorIsAccepted(): void
    {
        self::assertTrue(ETag::fromHeaderValue('w/"sha256-abc"')->isWeak());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTagProvider(): iterable
    {
        yield 'unquoted' => ['sha256-abc'];
        yield 'empty' => ['""'];
        yield 'unterminated' => ['"sha256-abc'];
        yield 'leading weak indicator with no body' => ['W/"'];
        yield 'a quote inside the opaque part' => ['"sha2"46abc"'];
        yield 'a control character' => ["\"sha256\tabc\""];
    }

    #[DataProvider('refusedTagProvider')]
    public function testAnUnparseableTagIsRefusedRatherThanGuessedAt(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ETag::fromHeaderValue($value);
    }

    // -- If-Match splitting ---------------------------------------------------

    public function testAStarIsSplitAsTheStar(): void
    {
        self::assertSame(['*'], ETag::splitIfMatch('*'));
        self::assertSame(['*'], ETag::splitIfMatch('  *  '));
    }

    public function testAListOfTagsIsSplit(): void
    {
        self::assertSame(
            ['"a"', '"b"', 'W/"c"'],
            ETag::splitIfMatch('"a", "b", W/"c"')
        );
    }

    public function testASingleTagIsAListOfOne(): void
    {
        self::assertSame(['"a"'], ETag::splitIfMatch('"a"'));
    }

    public function testAnEmptyIfMatchIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty when present');

        ETag::splitIfMatch('   ');
    }

    public function testAnIfMatchOfOnlySeparatorsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ETag::splitIfMatch(',,,');
    }
}
