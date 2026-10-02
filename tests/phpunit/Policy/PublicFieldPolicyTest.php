<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Policy;

use Civi\Dfc\V2\Policy\DefaultExportPolicy;
use Civi\Dfc\V2\Policy\PredicateVisibility;
use Civi\Dfc\V2\Policy\PublicFieldPolicy;
use Civi\Dfc\V2\Policy\VisibilityReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The export allow-list: default-deny, and the three states a predicate can be in.
 *
 * CP-1 names the property: "public/private policy defaults to not-exported". It is
 * asserted structurally here — an EMPTY allow-list — rather than by asserting a set of
 * DFC predicates, because the PRD states no field-level decision and inventing one
 * would be a data-governance decision taken in a diff.
 */
#[CoversClass(PublicFieldPolicy::class)]
#[CoversClass(DefaultExportPolicy::class)]
#[CoversClass(VisibilityReason::class)]
#[CoversClass(PredicateVisibility::class)]
final class PublicFieldPolicyTest extends TestCase
{
    // -- The default ----------------------------------------------------------

    public function testTheShippedDefaultWithholdsEveryDataPredicate(): void
    {
        $policy = DefaultExportPolicy::none();

        self::assertTrue($policy->isDefaultDeny());
        self::assertSame([], $policy->publicPredicates());
        self::assertSame([], $policy->deniedPredicates());
    }

    public function testTheDefaultExportsNoKnownDfcPredicate(): void
    {
        // The headline assertion. Every predicate below is a real DFC predicate from the
        // manifest's resource inventory, and none of them is exported by default.
        $policy = DefaultExportPolicy::none();

        foreach (self::dfcPredicates() as $predicate) {
            self::assertFalse(
                $policy->isExported($predicate),
                sprintf('"%s" must not be exported by default.', $predicate)
            );
        }
    }

    public function testTheDefaultStillPassesTheJsonLdKeywordsThrough(): void
    {
        // A document made only of keywords is still an addressable DFC resource, and it
        // is still not empty of identity.
        $policy = DefaultExportPolicy::none();

        foreach (['@context', '@id', '@type', '@graph'] as $keyword) {
            self::assertTrue($policy->isExported($keyword));
            self::assertSame(
                PredicateVisibility::STRUCTURAL,
                $policy->visibilityOf($keyword)->visibility()
            );
            self::assertSame(VisibilityReason::JSON_LD_KEYWORD, $policy->visibilityOf($keyword)->reason());
        }
    }

    public function testTheDefaultIsStructurallyEmptyNotFullOfDenials(): void
    {
        // The allow-list is the DEFAULT. A policy full of explicit denials would be a
        // denylist wearing a mask, and its behaviour would invert the moment a new
        // predicate appeared.
        self::assertSame([], DefaultExportPolicy::none()->deniedPredicates());
    }

    public function testPublicOnlyAndTheConstructorAgree(): void
    {
        // `publicOnly` is a readability alias, not a different policy.
        self::assertEquals(
            new PublicFieldPolicy(['dfc-b:name'], ['dfc-b:vatNumber']),
            DefaultExportPolicy::publicOnly(['dfc-b:name'])
                ->withDenied('dfc-b:vatNumber')
        );

        self::assertEquals(
            DefaultExportPolicy::publicOnly(['dfc-b:name']),
            DefaultExportPolicy::allowList(['dfc-b:name'])
        );
    }

    // -- The three states -----------------------------------------------------

    public function testAPredicateOnTheAllowListIsPublic(): void
    {
        $policy = DefaultExportPolicy::publicOnly(['dfc-b:name']);

        $decision = $policy->visibilityOf('dfc-b:name');

        self::assertSame(PredicateVisibility::PUBLIC, $decision->visibility());
        self::assertSame(VisibilityReason::ON_ALLOW_LIST, $decision->reason());
        self::assertTrue($decision->isExported());
        self::assertFalse($decision->isStructural());
    }

    public function testAPredicateNotOnTheListIsWithheldForTheDocumentedReason(): void
    {
        $policy = DefaultExportPolicy::publicOnly(['dfc-b:name']);

        $decision = $policy->visibilityOf('dfc-b:vatNumber');

        self::assertSame(PredicateVisibility::WITHHELD, $decision->visibility());
        self::assertSame(VisibilityReason::NOT_ON_ALLOW_LIST, $decision->reason());
        self::assertFalse($decision->isExported());
    }

    public function testADeniedPredicateIsWithheldAndTheReasonSaysSo(): void
    {
        // The distinction an operator debugging "why is this field missing" needs: a
        // decision versus an absence.
        $policy = DefaultExportPolicy::publicOnly(['dfc-b:name'])->withDenied('dfc-b:name');

        $decision = $policy->visibilityOf('dfc-b:name');

        self::assertSame(PredicateVisibility::WITHHELD, $decision->visibility());
        self::assertSame(VisibilityReason::EXPLICITLY_DENIED, $decision->reason());
    }

    public function testTheDenyListWinsOverTheAllowList(): void
    {
        // A deny list is what a deployment uses to REMOVE a predicate an inherited or
        // generated allow-list added, without unpicking the allow-list.
        $policy = DefaultExportPolicy::allowList(
            ['dfc-b:name', 'dfc-b:vatNumber'],
            ['dfc-b:vatNumber']
        );

        self::assertFalse($policy->isExported('dfc-b:vatNumber'));
        self::assertTrue($policy->isExported('dfc-b:name'));
        self::assertSame(
            [VisibilityReason::EXPLICITLY_DENIED],
            [$policy->visibilityOf('dfc-b:vatNumber')->reason()]
        );
    }

    public function testAnAbsoluteIriPredicateIsAccepted(): void
    {
        $policy = DefaultExportPolicy::publicOnly(['https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#name']);

        self::assertTrue($policy->isExported('https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#name'));
        self::assertFalse($policy->isExported('https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#vatNumber'));
    }

    // -- Unusable predicates --------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusablePredicates(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'inner space' => ['dfc-b:legal name'];
        yield 'a bare local name' => ['name'];
        yield 'a newline' => ["dfc-b:name\n"];
        yield 'a NUL' => ["dfc-b:name\0"];
        yield 'a trailing colon' => ['dfc-b:'];
        yield 'a relative IRI' => ['/organizations/1'];
        yield 'a fragment only' => ['#me'];
    }

    #[DataProvider('unusablePredicates')]
    public function testAnUnusablePredicateIsWithheldRatherThanGuessedAt(string $predicate): void
    {
        $decision = DefaultExportPolicy::none()->visibilityOf($predicate);

        self::assertSame(PredicateVisibility::WITHHELD, $decision->visibility());
        self::assertSame(VisibilityReason::NOT_A_PREDICATE, $decision->reason());
        self::assertFalse($decision->isExported());
    }

    // -- Construction refuses bad configuration -------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function jsonLdKeywords(): iterable
    {
        foreach (['@context', '@id', '@type', '@graph'] as $keyword) {
            yield $keyword => [$keyword];
        }
    }

    #[DataProvider('jsonLdKeywords')]
    public function testAKeywordCannotBeAllowed(string $keyword): void
    {
        // Allowing one is a no-op that reads as a decision.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot appear on a allow-list/');

        new PublicFieldPolicy([$keyword]);
    }

    #[DataProvider('jsonLdKeywords')]
    public function testAKeywordCannotBeDenied(string $keyword): void
    {
        // Denying one would strip the document of its subject.
        $this->expectException(\InvalidArgumentException::class);

        new PublicFieldPolicy([], [$keyword]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPredicates(): iterable
    {
        yield 'a bare local name' => ['name'];
        yield 'inner whitespace' => ['dfc-b:legal name'];
        yield 'empty' => [''];
    }

    #[DataProvider('malformedPredicates')]
    public function testAMalformedPredicateIsRefusedAtConfigurationTime(string $predicate): void
    {
        // Validated, not sanitised: a typo in a policy list would otherwise create a
        // rule that silently matches nothing, which is the failure a reader cannot see.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute IRI|CURIE/');

        new PublicFieldPolicy([$predicate]);
    }

    // -- Immutability and audit -----------------------------------------------

    public function testWithPublicReturnsANewPolicy(): void
    {
        $original = DefaultExportPolicy::none();
        $extended = $original->withPublic('dfc-b:name');

        self::assertNotSame($original, $extended);
        self::assertTrue($original->isDefaultDeny());
        self::assertFalse($extended->isDefaultDeny());
        self::assertSame(['dfc-b:name'], $extended->publicPredicates());
    }

    public function testWithDeniedReturnsANewPolicy(): void
    {
        $original = DefaultExportPolicy::publicOnly(['dfc-b:name']);
        $restricted = $original->withDenied('dfc-b:name');

        self::assertTrue($original->isExported('dfc-b:name'));
        self::assertFalse($restricted->isExported('dfc-b:name'));
    }

    public function testAListIsDeduplicatedAndSorted(): void
    {
        $policy = DefaultExportPolicy::publicOnly([
            'dfc-b:vatNumber',
            'dfc-b:name',
            'dfc-b:vatNumber',
        ]);

        self::assertSame(['dfc-b:name', 'dfc-b:vatNumber'], $policy->publicPredicates());
    }

    public function testTheAuditRecordIsDeterministic(): void
    {
        $first = DefaultExportPolicy::publicOnly(['dfc-b:b', 'dfc-b:a'])->toArray();
        $second = DefaultExportPolicy::publicOnly(['dfc-b:a', 'dfc-b:b'])->toArray();

        self::assertSame($first, $second);
        self::assertSame(
            ['public', 'denied', 'keywords', 'defaultDeny'],
            array_keys($first)
        );
    }

    public function testTheDecisionIsJsonSerialisable(): void
    {
        $decision = DefaultExportPolicy::publicOnly(['dfc-b:name'])->visibilityOf('dfc-b:name');

        self::assertSame(
            ['predicate' => 'dfc-b:name', 'visibility' => 'public', 'reason' => 'on_allow_list'],
            $decision->toArray()
        );
        self::assertJson((string) json_encode($decision, JSON_THROW_ON_ERROR));
    }

    // -- The keywords are borrowed, not chosen --------------------------------

    public function testTheKeywordListIsTheJsonLdOne(): void
    {
        // RFC 8259 §3 / JSON-LD 1.1. Borrowed from the specification, not this project.
        self::assertSame(
            ['@context', '@id', '@type', '@graph'],
            PredicateVisibility::keywords()
        );
        self::assertSame(PredicateVisibility::keywords(), PredicateVisibility::JSON_LD_KEYWORDS);

        foreach (PredicateVisibility::keywords() as $keyword) {
            self::assertTrue(PredicateVisibility::isKeyword($keyword));
        }

        self::assertFalse(PredicateVisibility::isKeyword('@included'));
    }

    /**
     * @return list<string>
     */
    private static function dfcPredicates(): array
    {
        return [
            'dfc-b:name',
            'dfc-b:description',
            'dfc-b:legalName',
            'dfc-b:vatNumber',
            'dfc-b:hasMainContact',
            'dfc-b:hasAddress',
            'dfc-b:websiteAddress',
            'dfc-b:member',
            'dfc-b:affiliates',
            'dfc-t:hasVersion',
        ];
    }
}