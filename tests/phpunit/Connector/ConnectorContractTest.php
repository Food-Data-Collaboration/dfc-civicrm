<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Connector;

use DataFoodConsortium\Connector\Connector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests pinning the behaviour of the upstream DFC-LinkML PHP connector
 * (siol-data/linkml-connector v2.0.5) that this extension depends on.
 *
 * These are not tests of our own code. They are tests of an assumption. Upstream
 * code is not ours to change, so the only defence against its behaviour changing
 * underneath us is to assert the behaviour we actually rely on. If a future
 * connector release breaks one of these, the failure tells us what to adapt —
 * rather than discovering it as a silent data-loss bug in production.
 *
 * @see https://github.com/Food-Data-Collaboration/DFC-LinkML/blob/main/php-connector/README.md
 */
final class ConnectorContractTest extends TestCase
{
    private const CONTEXT = 'https://w3id.org/dfc/ontology/v2.0.0/context/context_2.0.0.json';

    private Connector $connector;

    protected function setUp(): void
    {
        $this->connector = new Connector();
    }

    public function testTheConnectorLivesUnderTheDocumentedNamespace(): void
    {
        self::assertInstanceOf(Connector::class, $this->connector);
        self::assertSame(
            'DataFoodConsortium\\Connector\\Connector',
            $this->connector::class
        );
    }

    public function testAnOrganizationRoundTripsThroughJsonLd(): void
    {
        $semanticId = 'https://example.org/org/1';

        $exported = $this->connector->export(
            $this->connector->createOrganization($semanticId, ['name' => 'Acme Farms'])
        );

        $decoded = json_decode($exported, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(self::CONTEXT, $decoded['@context'], 'context is a URL, not an inline object');
        self::assertSame($semanticId, $decoded['@id']);
        self::assertSame('dfc-b:Organization', $decoded['@type']);
        self::assertSame('Acme Farms', $decoded['dfc-b:name'], 'predicates use short form, not dfc-b:Organization:name');
    }

    public function testImportAlwaysReturnsAnArray(): void
    {
        $objects = $this->connector->import($this->exportedOrganization());

        self::assertIsArray($objects, 'import() returns an array even for a single-entry document');
        self::assertCount(1, $objects);
        self::assertSame('https://example.org/org/1', $objects[0]->getSemanticId());
    }

    public function testTheEnterpriseAliasLoadsAsOrganization(): void
    {
        $legacy = str_replace('dfc-b:Organization', 'dfc-b:Enterprise', $this->exportedOrganization());

        $objects = $this->connector->import($legacy);

        self::assertInstanceOf('DataFoodConsortium\\Connector\\Organization', $objects[0]);
    }

    /**
     * FIXED UPSTREAM (2026-10-08, connector v2.0.7 — issue #36, filed by us).
     *
     * This test previously asserted the opposite: that import() silently
     * returned an empty array for malformed input. v2.0.7 added
     * JSON_THROW_ON_ERROR and an explicit non-document rejection, so unparseable
     * input now throws JsonException.
     *
     * The split it now enforces is the one worth pinning, and it is sharper
     * than "throws or not":
     *
     *   malformed (unparseable, or parses to a non-document) -> THROWS
     *   well-formed but carries no DFC graph                  -> []
     *
     * Only the second is a silent no-op, and it is the one we still have to
     * handle ourselves. JsonLdParseStage must therefore keep doing its own
     * @context checking: import() now catches the syntax error for us, but it
     * still cannot tell our code "you sent JSON, it just was not DFC".
     *
     * @see BLK-015
     */
    #[Group('upstream-defect')]
    #[DataProvider('malformedImportCases')]
    public function testImportThrowsOnMalformedInput(string $label, string $input): void
    {
        $this->expectException(\JsonException::class);

        $this->connector->import($input);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedImportCases(): array
    {
        return [
            'not JSON at all' => ['not JSON at all', '{not json'],
            'empty string' => ['empty string', ''],
            'JSON null' => ['JSON null', 'null'],
            'JSON scalar' => ['JSON scalar', '42'],
        ];
    }

    /**
     * The half of the old defect that is NOT fixed, and is ours to handle.
     *
     * These all parse as JSON documents and are simply not DFC, so import()
     * returns an empty array rather than complaining. A POST body of
     * {"foo":"bar"} therefore still imports as a silent no-op, and it is still
     * JsonLdParseStage's job to reject it with a 400 before we get here.
     *
     * @see BLK-015
     */
    #[Group('upstream-defect')]
    #[DataProvider('graphlessImportCases')]
    public function testGraphlessButWellFormedJsonStillImportsAsAnEmptyArray(string $label, string $input): void
    {
        $result = $this->connector->import($input);

        self::assertIsArray($result);
        self::assertSame([], $result, $label . ': still a silent no-op, so parse-stage validation is still required');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function graphlessImportCases(): array
    {
        return [
            'valid JSON, no graph' => ['valid JSON, no graph', '{"foo":"bar"}'],
            'root-level array' => ['root-level array', '[{"@type":"dfc-b:Organization"}]'],
            'genuinely empty' => ['genuinely empty', '[]'],
        ];
    }

    /**
     * The distinction the old single test could not express, now asserted
     * directly: malformed and genuinely-empty are no longer the same answer.
     *
     * Under connector v2.0.5 they were, which is exactly what made the old
     * defect dangerous - there was no way for a caller to tell "you sent
     * nonsense" from "you sent an empty document".
     */
    #[Group('upstream-defect')]
    public function testMalformedInputIsDistinguishableFromAnEmptyDocument(): void
    {
        $genuinelyEmpty = $this->connector->import('[]');

        $malformed = null;
        try {
            $this->connector->import('{not json');
        } catch (\JsonException $e) {
            $malformed = $e;
        }

        self::assertInstanceOf(\JsonException::class, $malformed,
            'malformed input must be reported, not folded into the empty-document result');
        self::assertSame([], $genuinelyEmpty);
    }

    public function testPropertyNamesFollowTheCamelCasePredicateLocalName(): void
    {
        // dfc-b:VATrate is spelled vatRate in PHP, not vat_rate.
        $price = $this->connector->createPrice('https://example.org/price/1', [
            'value' => 42.5,
            'vatRate' => 5.5,
        ]);

        $exported = json_decode($this->connector->export($price), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('dfc-b:VATrate', $exported, 'wire form stays the DFC predicate');
        self::assertSame(5.5, $exported['dfc-b:VATrate']);
    }

    /**
     * Price became a subclass of QuantitativeValue upstream on 2026-09-24, which
     * is the change that made this extension's parity map stale (REG-001). Pin
     * the inheritance so a future connector release that drops it is visible.
     */
    public function testPriceInheritsQuantitativeValue(): void
    {
        $price = $this->connector->createPrice('https://example.org/price/1', ['value' => 42.5]);

        $ancestors = class_parents($price);

        self::assertContains(
            'DataFoodConsortium\\Connector\\QuantitativeValue',
            array_values($ancestors),
            'Price is_a QuantitativeValue as of the 2026-09-24 schema change'
        );
    }

    private function exportedOrganization(): string
    {
        return $this->connector->export(
            $this->connector->createOrganization('https://example.org/org/1', ['name' => 'Acme Farms'])
        );
    }
}