<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Identity;

use Civi\Dfc\V2\Identity\ReleaseDescriptorParser;
use Civi\Dfc\V2\Identity\Exception\MalformedReleaseDescriptorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The restricted YAML reader.
 *
 * The fixture is a faithful mirror of upstream's `config/dfc-release.yaml`, so
 * these tests are simultaneously a check that the plumbing works and a check
 * that upstream's own shape is inside the supported grammar.
 */
#[CoversClass(ReleaseDescriptorParser::class)]
final class ReleaseDescriptorParserTest extends TestCase
{
    use IdentityFixtureTrait;

    // -- The fixture ----------------------------------------------------------

    public function testParsesTheUpstreamPins(): void
    {
        $descriptor = self::fixtureDescriptor();

        self::assertSame('2.0.0', $descriptor['dfc_ontology_version']);
        self::assertSame('2.0.0', $descriptor['dfc_taxonomy_version']);
        self::assertSame('2.0.4', $descriptor['sdk_version']);
    }

    public function testParsesNestedMappings(): void
    {
        $descriptor = self::fixtureDescriptor();

        self::assertSame(
            'https://w3id.org/dfc/ontology/v2.0.0/src/DFC_BusinessOntology.rdf',
            $descriptor['ontology']['business_url']
        );
        self::assertSame(
            'https://w3id.org/dfc/taxonomies',
            $descriptor['taxonomies']['base_url']
        );
        self::assertSame('config/dfc-default.yaml', $descriptor['schema']['config']);
        self::assertSame('ruby-gem/', $descriptor['outputs']['ruby']);
        self::assertSame('2.0.0.pre.beta8', $descriptor['original_test_versions']['ruby']);
    }

    public function testParsesThePrefixedMapWhoseKeysContainAColon(): void
    {
        $prefixes = self::fixtureDescriptor()['dfc_civicrm']['prefixes'];

        self::assertSame(
            [
                'dfc-b' => 'https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#',
                'dfc-t' => 'https://w3id.org/dfc/ontology/src/DFC_TechnicalOntology.owl#',
            ],
            $prefixes
        );
    }

    /**
     * Upstream config/dfc-default.yaml lists these alongside the DFC prefixes and
     * explains why vcard: matters ("vcard:Agent and dfc-b:Agent share a local
     * name"). They are recorded here for provenance; no URI is minted from them.
     */
    public function testParsesOtherUpstreamPrefixes(): void
    {
        $other = self::fixtureDescriptor()['dfc_civicrm']['other_prefixes'];

        self::assertSame('http://www.w3.org/2006/vcard/ns#', $other['vcard']);
        self::assertSame('https://w3id.org/linkml/', $other['linkml']);
    }

    /**
     * Sequences of scalars are supported — upstream's `skip_classes:` in
     * dfc-default.yaml is exactly this shape.
     */
    public function testParsesSequencesOfScalars(): void
    {
        $parsed = ReleaseDescriptorParser::parse(<<<'YAML'
            skip_classes:
              - Thing
              - Entity
              - "DFC_BusinessOntology_Subject"
              - 'it''s quoted'
            YAML);

        self::assertSame(
            ['Thing', 'Entity', 'DFC_BusinessOntology_Subject', "it's quoted"],
            $parsed['skip_classes']
        );
    }

    public function testASequenceUnderAnEmptyKeyParsesAsNull(): void
    {
        $parsed = ReleaseDescriptorParser::parse("nothing:\nother: 1\n");

        self::assertNull($parsed['nothing']);
    }

    public function testCommentsAreStrippedButNotInsideValues(): void
    {
        $parsed = ReleaseDescriptorParser::parse(<<<'YAML'
            # leading comment
            a: "value with # inside"   # trailing comment
            b: plain#notacomment
            YAML);

        self::assertSame(
            [
                'a' => 'value with # inside',
                'b' => 'plain#notacomment',
            ],
            $parsed
        );
    }

    public function testFromFileMatchesParseOfTheSameBytes(): void
    {
        $yaml = (string) file_get_contents(self::fixturePath());

        self::assertSame(ReleaseDescriptorParser::parse($yaml), ReleaseDescriptorParser::fromFile(self::fixturePath()));
    }

    // -- Quoting --------------------------------------------------------------

    public function testUnquotesAndUnescapes(): void
    {
        $parsed = ReleaseDescriptorParser::parse(<<<'YAML'
            double: "a\tb\nc\\d\"eé"
            single: 'it''s here'
            plain: no escapes \n here
            YAML);

        self::assertSame("a\tb\nc\\d\"eé", $parsed['double']);
        self::assertSame("it's here", $parsed['single']);
        self::assertSame('no escapes \n here', $parsed['plain']);
    }

    public function testUnquotedNullIsParsedAsAnEmptyValueNotAsAString(): void
    {
        $parsed = ReleaseDescriptorParser::parse("key:\nnext: value\n");

        self::assertArrayHasKey('key', $parsed);
        self::assertNull($parsed['key']);
    }

    public function testHashInsideAQuotedValueSurvivesEvenWhenPrecededByWhitespace(): void
    {
        $parsed = ReleaseDescriptorParser::parse('iri: "https://example.org/ns#anchor"');

        self::assertSame('https://example.org/ns#anchor', $parsed['iri']);
    }

    // -- Rejections: each names a real YAML feature the reader will not guess --

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedYamlProvider(): array
    {
        return [
            'tab indentation' => ["a:\n\tb: 1\n", 'Tab used for indentation'],
            'anchors' => ["a: &anchor value\n", 'anchors and aliases'],
            'aliases' => ["a: *anchor\n", 'anchors and aliases'],
            'tags' => ["a: !!str value\n", 'YAML tags'],
            'flow mapping' => ["a: {b: 1}\n", 'Flow collections'],
            'flow sequence' => ["a: [1, 2]\n", 'Flow collections'],
            'block scalar' => ["a: |\n  text\n", 'Block scalars'],
            'folded scalar' => ["a: >\n  text\n", 'Block scalars'],
            'explicit key' => ["? a\n: b\n", 'is not a "key: value" mapping entry'],
            'mapping in a sequence' => ["a:\n  - b: 1\n", 'mapping inside a sequence'],
            'empty sequence entry' => ["a:\n  -\n", 'Empty sequence entry'],
            'inconsistent dedent' => ["a:\n    b: 1\n  c: 2\n", 'Unexpected indentation'],
            'duplicate key' => ["a: 1\na: 2\n", 'Duplicate key'],
            'no colon' => ["just a scalar\n", 'is not a "key: value" mapping entry'],
            'unterminated quote' => ["a: \"unclosed\n", 'Unterminated'],
            'content after a quoted scalar' => ["a: \"x\" trailing\n", 'Trailing content'],
            'missing colon after a quoted key' => ["\"a\" b: 1\n", 'Expected ":" after the quoted key'],
            'unsupported escape' => ["a: \"x\\qy\"\n", 'Unsupported escape sequence'],
        ];
    }

    #[DataProvider('rejectedYamlProvider')]
    public function testRejectsUnsupportedYaml(string $yaml, string $expectedMessageFragment): void
    {
        $this->expectException(MalformedReleaseDescriptorException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessageFragment, '/') . '/');

        ReleaseDescriptorParser::parse($yaml);
    }

    public function testEmptyDescriptorIsRejected(): void
    {
        $this->expectException(MalformedReleaseDescriptorException::class);
        $this->expectExceptionMessage('empty');

        ReleaseDescriptorParser::parse("# only a comment\n\n   \n");
    }

    public function testRejectionNamesTheLineNumber(): void
    {
        try {
            ReleaseDescriptorParser::parse("a: 1\nb: 2\nb: 3\n");
            self::fail('Expected MalformedReleaseDescriptorException.');
        } catch (MalformedReleaseDescriptorException $e) {
            self::assertStringContainsString('line 3', $e->getMessage());
        }
    }

    public function testUnreadableFileIsRejected(): void
    {
        $this->expectException(MalformedReleaseDescriptorException::class);
        $this->expectExceptionMessage('not a readable file');

        ReleaseDescriptorParser::fromFile('/nonexistent/dfc-release.yaml');
    }

    public function testWindowsLineEndingsParseIdentically(): void
    {
        $unix = ReleaseDescriptorParser::parse("a: 1\nb:\n  c: 2\n");
        $windows = ReleaseDescriptorParser::parse("a: 1\r\nb:\r\n  c: 2\r\n");

        self::assertSame($unix, $windows);
    }
}