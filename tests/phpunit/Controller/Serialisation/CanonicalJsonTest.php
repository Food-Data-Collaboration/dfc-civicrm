<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Serialisation;

use Civi\Dfc\V2\Controller\Serialisation\CanonicalJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Canonical JSON: the property an entity tag depends on.
 *
 * If two assemblies of the same value can produce different bytes, every ETag
 * derived from them is unstable and every client re-reads forever.
 */
#[CoversClass(CanonicalJson::class)]
final class CanonicalJsonTest extends TestCase
{
    public function testObjectKeyOrderDoesNotAffectTheBytes(): void
    {
        self::assertSame(
            CanonicalJson::encode(['b' => 2, 'a' => 1, 'c' => 3]),
            CanonicalJson::encode(['c' => 3, 'a' => 1, 'b' => 2])
        );
    }

    public function testKeysAreSortedByByteValueNotByPhpCoercion(): void
    {
        // Numeric-looking string keys must sort as STRINGS, or "10" lands before "9"
        // on one build and differently on another.
        self::assertSame(
            '{"10":1,"9":2}',
            CanonicalJson::encode(['9' => 2, '10' => 1])
        );
    }

    public function testNestedObjectsAreSortedRecursively(): void
    {
        self::assertSame(
            '{"outer":{"a":{"x":1,"y":2},"b":[3,1,2]}}',
            CanonicalJson::encode(['outer' => ['b' => [3, 1, 2], 'a' => ['y' => 2, 'x' => 1]]])
        );
    }

    /**
     * List order is DATA, not noise. Sorting it would make `ldp:contains` — and
     * every DFC array — order-insensitive, which would silently change the document.
     */
    public function testListOrderIsPreserved(): void
    {
        self::assertSame('[3,1,2]', CanonicalJson::encode([3, 1, 2]));
    }

    public function testAnEmptyArrayEncodesAsAnArray(): void
    {
        // PHP cannot tell an empty array from an empty object. In this layer the only
        // place an empty collection legitimately occurs is `ldp:contains`, which is
        // an RDF set, so `[]` is the correct encoding.
        self::assertSame('[]', CanonicalJson::encode([]));
    }

    public function testAnEmptyObjectIsAvailableThroughStdClass(): void
    {
        self::assertSame('{}', CanonicalJson::encode(new \stdClass()));
    }

    public function testStdClassPropertiesAreAlsoSorted(): void
    {
        $first = new \stdClass();
        $first->z = 1;
        $first->a = 2;

        $second = new \stdClass();
        $second->a = 2;
        $second->z = 1;

        self::assertSame(CanonicalJson::encode($first), CanonicalJson::encode($second));
    }

    public function testSlashesAndUnicodeAreNotEscaped(): void
    {
        self::assertSame(
            '"https://platform.example/dfc/v2/organizations/Grüße/"',
            CanonicalJson::encode('https://platform.example/dfc/v2/organizations/Grüße/')
        );
    }

    public function testZeroFractionsSurviveSoAValueDoesNotBecomeAnInteger(): void
    {
        self::assertSame('{"scale":1.0}', CanonicalJson::encode(['scale' => 1.0]));
    }

    public function testThereIsNoInsignificantWhitespace(): void
    {
        self::assertSame('{"a":1,"b":[1,2]}', CanonicalJson::encode(['b' => [1, 2], 'a' => 1]));
    }

    public function testTheSameValueAlwaysProducesTheSameBytes(): void
    {
        $value = ['@context' => ['https://x.test/c.json', ['ldp' => 'http://www.w3.org/ns/ldp#']], '@id' => 'u'];

        self::assertSame(CanonicalJson::encode($value), CanonicalJson::encode($value));
    }

    public function testInvalidUtf8IsRefusedRatherThanSilentlySubstituted(): void
    {
        // json_encode would emit malformed UTF-8 or fail silently depending on flags;
        // a DFC document with a broken byte sequence is a producer defect.
        $this->expectException(\JsonException::class);

        CanonicalJson::encode("\xB1\x31");
    }

    public function testANonFiniteFloatIsRefused(): void
    {
        $this->expectException(\JsonException::class);
        $this->expectExceptionMessage('INF and NAN have no JSON representation');

        CanonicalJson::encode(['ratio' => INF]);
    }
}
