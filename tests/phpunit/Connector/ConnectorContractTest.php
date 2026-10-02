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
     * DEFECT PINNED (2026-10-02, connector v2.0.5).
     *
     * import() does NOT throw for malformed input. It returns an empty array.
     * That includes input that is not valid JSON at all, and input that is
     * valid JSON but carries no DFC graph.
     *
     * Consequence for us: we cannot treat an empty result as "nothing to do".
     * A POST body of "{not json" would import as a silent no-op and answer 2xx,
     * losing the client's data with no error. Our JsonLdParseStage therefore
     * MUST do its own syntax and @context checking before handing anything to
     * the connector, and must treat an empty import result as an error rather
     * than a success.
     *
     * @see BLK-015
     */
    #[Group('upstream-defect')]
    #[DataProvider('silentEmptyImportCases')]
    public function testImportReturnsAnEmptyArrayRatherThanThrowing(string $label, string $input): void
    {
        $result = $this->connector->import($input);

        self::assertIsArray($result);
        self::assertSame([], $result, $label . ': import silently returns an empty array');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function silentEmptyImportCases(): array
    {
        return [
            'not JSON at all' => ['not JSON at all', '{not json'],
            'empty string' => ['empty string', ''],
            'valid JSON, no graph' => ['valid JSON, no graph', '{"foo":"bar"}'],
            'root-level array' => ['root-level array', '[{"@type":"dfc-b:Organization"}]'],
            'JSON null' => ['JSON null', 'null'],
        ];
    }

    /**
     * The corollary of the defect above, stated as a rule for our own code.
     *
     * If import() ever starts throwing on malformed input, this fails and we
     * revisit JsonLdParseStage rather than leaving a redundant guard in place.
     */
    #[Group('upstream-defect')]
    public function testMalformedInputIsDistinguishableFromAnEmptyDocument(): void
    {
        $malformed = $this->connector->import('{not json');
        $genuinelyEmpty = $this->connector->import('[]');

        self::assertSame($malformed, $genuinelyEmpty,
            'if these ever differ, import() gained error reporting and JsonLdParseStage should be simplified');
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