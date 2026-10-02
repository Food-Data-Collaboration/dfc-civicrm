<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Policy;

use Civi\Dfc\V2\Controller\Http\ETag;
use Civi\Dfc\V2\Controller\Serialisation\CanonicalJson;
use Civi\Dfc\V2\Policy\DefaultExportPolicy;
use Civi\Dfc\V2\Policy\ExportProjection;
use Civi\Dfc\V2\Policy\PublicFieldPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Applying the allow-list to a document, and what it does to the ETag.
 *
 * ============================================================================
 * THE ETAG CONSEQUENCE IS ASSERTED, NOT DESCRIBED
 * ============================================================================
 * {@see testTheStrongEtagIsPerCallerBecauseTheBytesArePerCaller()}. A per-caller
 * projection means a strong ETag is per-caller, and the only way to be sure of that is
 * to compute two of them from the same resource and compare. A shared cache that does
 * not key on the representation would serve one caller's fuller projection to another,
 * which is an information disclosure caused by caching — exactly the class of bug
 * PRD-002 §9 names.
 */
#[CoversClass(ExportProjection::class)]
#[CoversClass(\Civi\Dfc\V2\Policy\ProjectionResult::class)]
final class ExportProjectionTest extends TestCase
{
    // -- The default projection -----------------------------------------------

    public function testTheDefaultProjectionKeepsOnlyTheKeywords(): void
    {
        $result = (new ExportProjection(DefaultExportPolicy::none()))->project(self::organization());

        self::assertSame(
            ['@context', '@id', '@type'],
            array_keys($result->projected())
        );
    }

    public function testTheDefaultProjectionReportsWhatItDropped(): void
    {
        $result = (new ExportProjection(DefaultExportPolicy::none()))->project(self::organization());

        self::assertTrue($result->hasWithheld());
        self::assertSame(
            ['dfc-b:name', 'dfc-b:vatNumber', 'dfc-b:websiteAddress'],
            $result->withheldPredicates()
        );
    }

    public function testTheReportCarriesNoSubmittedValue(): void
    {
        // The audit record is built from predicate IRIs and reasons only, so it cannot
        // leak the data that was withheld.
        $result = (new ExportProjection(DefaultExportPolicy::none()))->project([
            '@id' => 'https://platform.example/dfc/v2/organizations/1',
            '@type' => 'dfc-b:Organization',
            'dfc-b:name' => 'Acme Foods Ltd, VAT GB123456789',
        ]);

        $rendered = (string) json_encode($result->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('Acme', $rendered);
        self::assertStringNotContainsString('GB123', $rendered);
        self::assertStringContainsString('dfc-b:name', $rendered);
    }

    // -- An allow-list in force -----------------------------------------------

    public function testAnAllowedPredicateSurvivesTheProjection(): void
    {
        $projection = new ExportProjection(
            DefaultExportPolicy::publicOnly(['dfc-b:name', 'dfc-b:websiteAddress'])
        );

        $result = $projection->project(self::organization());

        self::assertSame(
            ['@context', '@id', '@type', 'dfc-b:name', 'dfc-b:websiteAddress'],
            array_keys($result->projected())
        );
        self::assertSame(['dfc-b:vatNumber'], $result->withheldPredicates());
    }

    public function testProjectNodeReturnsJustTheNode(): void
    {
        $projection = new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:name']));

        self::assertSame(
            'Acme Foods',
            $projection->projectNode(self::organization())['dfc-b:name']
        );
    }

    public function testTheProjectionExposesItsPolicy(): void
    {
        $policy = DefaultExportPolicy::publicOnly(['dfc-b:name']);
        $projection = new ExportProjection($policy);

        self::assertSame($policy, $projection->policy());
    }

    // -- Recursion ------------------------------------------------------------

    public function testNestedNodeMapsAreProjectedToo(): void
    {
        // A DFC Organization contains an Address, and the same policy governs both.
        $document = self::organization();
        $document['dfc-b:hasAddress'] = [
            [
                '@id' => 'https://platform.example/dfc/v2/semantic/address/abc',
                '@type' => 'dfc-b:Address',
                'dfc-b:hasStreet' => '1 Example Street',
                'dfc-b:hasPostalCode' => 'SW1A 1AA',
            ],
        ];

        // The parent is on the allow-list and its children are not, so the projection has
        // to decide SEPARATELY at each level rather than inheriting one answer.
        $result = (new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:name', 'dfc-b:hasAddress'])))
            ->project($document);

        $address = $result->projected()['dfc-b:hasAddress'][0];

        self::assertSame(['@id', '@type'], array_keys($address));
        self::assertContains('dfc-b:hasStreet', $result->withheldPredicates());
        self::assertContains('dfc-b:hasPostalCode', $result->withheldPredicates());
        self::assertNotContains('dfc-b:hasAddress', $result->withheldPredicates());
    }

    public function testAWithheldNestedParentTakesItsChildrenWithIt(): void
    {
        // Dropping the parent drops the subtree, which is the point: withholding a
        // predicate must not leave an orphaned value behind.
        $document = self::organization();
        $document['dfc-b:hasAddress'] = [
            ['@id' => 'https://platform.example/dfc/v2/semantic/address/abc', 'dfc-b:hasStreet' => 'x'],
        ];

        $result = (new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:name'])))
            ->project($document);

        self::assertArrayNotHasKey('dfc-b:hasAddress', $result->projected());
        self::assertContains('dfc-b:hasAddress', $result->withheldPredicates());
        self::assertNotContains(
            'dfc-b:hasStreet',
            $result->withheldPredicates(),
            'The subtree is removed with its parent, not reported separately.'
        );
    }

    public function testAFreeFloatingNodeIsProjected(): void
    {
        $result = (new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:name'])))
            ->project(self::organization());

        self::assertTrue($result->wasChanged());
    }

    // -- Lists ----------------------------------------------------------------

    public function testAListOfValuesIsCarriedThroughUnchanged(): void
    {
        $document = [
            '@id' => 'https://platform.example/dfc/v2/organizations/1',
            '@type' => 'dfc-b:Organization',
            'dfc-b:member' => [
                'https://platform.example/dfc/v2/organizations/2',
                'https://platform.example/dfc/v2/organizations/3',
            ],
        ];

        $projection = new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:member']));
        $result = $projection->project($document);

        self::assertCount(2, $result->projected()['dfc-b:member']);
        self::assertFalse($result->hasWithheld());
    }

    public function testANumericKeySurvivesUnchanged(): void
    {
        // A JSON object with a numeric-looking key is still an object, and its keys are
        // the document's business. Only STRING keys are predicates.
        $projection = new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:name']));
        $result = $projection->project(['0' => 'a value']);

        self::assertSame(['0' => 'a value'], $result->projected());
    }

    // -- The ETag consequence -------------------------------------------------

    public function testTheStrongEtagIsPerCallerBecauseTheBytesArePerCaller(): void
    {
        // The consequence stated in PublicFieldPolicy's docblock, computed. Two callers
        // with different visibility get different bytes for one URI, so they get
        // different strong entity tags — and a shared cache that keyed on the URI alone
        // would serve the fuller projection to the narrower caller.
        $resource = self::organization();

        $narrowBytes = CanonicalJson::encode(
            (new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:name'])))->projectNode($resource)
        );
        $wideBytes = CanonicalJson::encode(
            (new ExportProjection(
                DefaultExportPolicy::publicOnly(['dfc-b:name', 'dfc-b:vatNumber', 'dfc-b:websiteAddress'])
            ))->projectNode($resource)
        );

        self::assertNotSame($narrowBytes, $wideBytes);

        $narrowTag = ETag::strong($narrowBytes);
        $wideTag = ETag::strong($wideBytes);

        self::assertFalse($narrowTag->equals($wideTag), 'A per-caller projection is a per-caller tag.');
    }

    public function testTheSameCallerGetsAStableTagAcrossRequests(): void
    {
        $policy = DefaultExportPolicy::publicOnly(['dfc-b:name']);
        $projection = new ExportProjection($policy);
        $resource = self::organization();

        $first = ETag::strong(CanonicalJson::encode($projection->projectNode($resource)));
        $second = ETag::strong(CanonicalJson::encode($projection->projectNode($resource)));

        self::assertTrue($first->equals($second));
    }

    public function testAddingOnePredicateToTheAllowListChangesTheTag(): void
    {
        // Which is why a change to the policy is a change to every cached ETag — the
        // correct behaviour, and a reason the policy is configuration rather than a
        // per-request computation.
        $resource = self::organization();

        $before = ETag::strong(CanonicalJson::encode(
            (new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:name'])))->projectNode($resource)
        ));
        $after = ETag::strong(CanonicalJson::encode(
            (new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:name', 'dfc-b:vatNumber'])))
                ->projectNode($resource)
        ));

        self::assertFalse($before->equals($after));
    }

    public function testThePolicyItselfIsNotPartOfTheBytes(): void
    {
        // Only the PROJECTED NODE is encoded, so a policy with different notes but the
        // same allow-list produces identical bytes. A comment change must not
        // invalidate every client's cache.
        $narrow = DefaultExportPolicy::publicOnly(['dfc-b:name']);
        $sameNarrow = new PublicFieldPolicy(['dfc-b:name']);

        self::assertSame(
            CanonicalJson::encode((new ExportProjection($narrow))->projectNode(self::organization())),
            CanonicalJson::encode((new ExportProjection($sameNarrow))->projectNode(self::organization()))
        );
    }

    // -- Refusals -------------------------------------------------------------

    public function testAnOverDeepDocumentIsRefused(): void
    {
        // A chain of nested node maps deeper than any DFC resource. The depth cap is the
        // ONLY recursion bound (see ExportProjection's docblock on why there is no
        // visited-node set), so this assertion is what makes that cap load-bearing.
        $document = ['@id' => 'https://platform.example/x'];
        $cursor = &$document;

        for ($i = 0; $i < ExportProjection::MAX_DEPTH + 2; $i++) {
            $cursor['dfc-b:hasAddress'] = ['@id' => 'https://platform.example/x/' . $i];
            $cursor = &$cursor['dfc-b:hasAddress'];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nests more than/');

        (new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:hasAddress'])))->project($document);
    }

    public function testAReferenceCycleIsRefusedRatherThanRecursedForever(): void
    {
        // A decoded document cannot be cyclic — json_decode produces no references — but
        // a hand-built array in a caller can be, and PHP arrays have no stable identity
        // to test against. So the cycle is caught by the DEPTH cap: the outcome is still
        // a refusal rather than a hang, which is the property that matters.
        $node = ['@id' => 'https://platform.example/x'];
        $node['dfc-b:hasAddress'] = [
            '@type' => 'dfc-b:Address',
            // A REFERENCE, so the same array appears again as a node map.
            'dfc-b:hasCity' => &$node,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nests more than/');

        (new ExportProjection(DefaultExportPolicy::publicOnly([
            'dfc-b:hasAddress',
            'dfc-b:hasCity',
        ])))->project($node);
    }

    // -- The result object ----------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsThatWithdrawNothing(): iterable
    {
        yield 'keywords only' => [['@id' => 'https://platform.example/x', '@type' => 'dfc-b:Organization']];
        yield 'empty document' => [[]];
    }

    #[DataProvider('documentsThatWithdrawNothing')]
    public function testADocumentThePolicyDoesNotTouchIsUnchanged(array $document): void
    {
        $result = (new ExportProjection(DefaultExportPolicy::none()))->project($document);

        self::assertFalse($result->hasWithheld());
        self::assertFalse($result->wasChanged());
        self::assertSame($document, $result->projected());
    }

    public function testExplicitDenialsAreSeparableFromAbsentOnes(): void
    {
        $result = (new ExportProjection(
            DefaultExportPolicy::publicOnly(['dfc-b:name'])->withDenied('dfc-b:vatNumber')
        ))->project(self::organization());

        $denied = array_map(
            static fn ($decision): string => $decision->predicate(),
            $result->explicitlyDenied()
        );

        self::assertSame(['dfc-b:vatNumber'], $denied);
    }

    public function testTheResultIsJsonSerialisable(): void
    {
        $result = (new ExportProjection(DefaultExportPolicy::publicOnly(['dfc-b:name'])))
            ->project(self::organization());

        self::assertJson((string) json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function organization(): array
    {
        return [
            '@context' => [
                'dfc-b' => 'https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#',
            ],
            '@id' => 'https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V',
            '@type' => 'dfc-b:Organization',
            'dfc-b:name' => 'Acme Foods',
            'dfc-b:vatNumber' => 'GB123456789',
            'dfc-b:websiteAddress' => 'https://acme.example/',
        ];
    }
}