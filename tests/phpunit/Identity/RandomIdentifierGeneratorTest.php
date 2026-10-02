<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Identity;

use Civi\Dfc\V2\Identity\RandomIdentifierGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The production identifier generator's contract (I1-I5).
 */
#[CoversClass(RandomIdentifierGenerator::class)]
final class RandomIdentifierGeneratorTest extends TestCase
{
    public function testIdentifierIsTheAdvertisedFixedLength(): void
    {
        // 128 bits at log2(32) == 5 bits per symbol is ceil(128/5) == 26 symbols.
        self::assertSame(26, strlen((new RandomIdentifierGenerator())->generate('contact')));
    }

    public function testIdentifierUsesOnlyCrockfordBase32(): void
    {
        $generator = new RandomIdentifierGenerator();

        for ($i = 0; $i < 200; $i++) {
            $identifier = $generator->generate('contact');

            // No I, L, O or U: a human re-typing an identifier from a support
            // ticket cannot produce a different one.
            self::assertMatchesRegularExpression('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $identifier);
        }
    }

    public function testIdentifierNeedsNoPercentEncoding(): void
    {
        $generator = new RandomIdentifierGenerator();

        for ($i = 0; $i < 200; $i++) {
            $identifier = $generator->generate('contact');

            self::assertSame($identifier, rawurlencode($identifier), 'the alphabet must be a URI-safe subset');
        }
    }

    public function testIdentifiersAreDistinct(): void
    {
        $generator = new RandomIdentifierGenerator();
        $seen = [];

        for ($i = 0; $i < 2000; $i++) {
            $identifier = $generator->generate('contact');
            self::assertArrayNotHasKey($identifier, $seen, 'collision in 2000 draws from a 128-bit space');
            $seen[$identifier] = true;
        }
    }

    /**
     * Crockford base32 is *decoded* case-insensitively, but a URI path is
     * case-sensitive, so the lowercase spelling of an identifier is a DIFFERENT
     * string and must never be silently accepted as the same identity.
     *
     * Asserted because it is a real trap: a case-insensitive lookup in the
     * identity table would treat `01hzy8qk...` as the record minted as
     * `01HZY8QK...`. The generator only emits uppercase and the URI factory does
     * not normalise case, so the store has to compare exactly.
     */
    public function testLowercaseSpellingIsADifferentIdentifier(): void
    {
        $identifier = (new RandomIdentifierGenerator())->generate('contact');

        self::assertNotSame($identifier, strtolower($identifier));
        self::assertNotSame(
            rawurlencode($identifier),
            rawurlencode(strtolower($identifier)),
            'percent-encoding preserves case, so the two spellings differ in the URI too'
        );
    }

    /**
     * I1/I2: the generator has no access to anything but its `$kind` argument, so
     * it cannot be leaking a record, a name or a host.
     */
    public function testTheKindArgumentDoesNotChangeTheShape(): void
    {
        $generator = new RandomIdentifierGenerator();

        $shapes = [];
        foreach (['contact', 'organization', 'address', ''] as $kind) {
            $shapes[$kind] = (bool) preg_match('/^[0-9A-Z]{26}$/', $generator->generate($kind));
        }

        self::assertSame([true, true, true, true], array_values($shapes));
    }
}