<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Controller\Error\ValidationIssue;
use Civi\Dfc\V2\Validation\LocalShaclValidator;
use Civi\Dfc\V2\Validation\ShaclShapeUnavailableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The local SHACL validator, constraint by constraint.
 *
 * The most important test in this file is
 * {@see testAnEmptyShapeSetIsRefusedRatherThanValidatingNothing()} — an empty shape set
 * that returns "no violations" is the fail-open failure, and it is the one that matters.
 */
#[CoversClass(LocalShaclValidator::class)]
final class LocalShaclValidatorTest extends TestCase
{
    private LocalShaclValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LocalShaclValidator();
    }

    // -- The fail-closed rule -------------------------------------------------

    public function testAnEmptyShapeSetIsRefusedRatherThanValidatingNothing(): void
    {
        // "Shapes loaded, nothing checked" is indistinguishable from "shapes loaded,
        // document valid" in a response — unless the empty set is refused.
        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/no node shapes/');

        $this->validator->validate(
            new \Civi\Dfc\V2\Validation\ShaclShapeSet([]),
            ['@id' => 'https://platform.example/dfc/v2/organizations/1']
        );
    }

    // -- Cardinality ----------------------------------------------------------

    public function testAMissingRequiredPredicateIsAViolation(): void
    {
        $violations = $this->validateFixture([
            '@id' => 'https://platform.example/dfc/v2/organizations/1',
            '@type' => 'dfc-b:Organization',
        ]);

        self::assertCount(1, $violations);
        self::assertSame(ValidationIssue::REQUIRED, $violations[0]->issue());
        self::assertSame('dfc-b:name', $violations[0]->predicate());
        self::assertSame('minCount>=1', $violations[0]->constraint());
    }

    public function testAPresentRequiredPredicatePasses(): void
    {
        self::assertSame([], $this->validateFixture($this->validOrganization()));
    }

    public function testAScalarAndAOneElementArrayHaveTheSameCardinality(): void
    {
        // PRD-002 §4.2 calls out the singular-vs-multi distinction. It is a question for
        // the DFC type/schema stage, NOT for a shape: one value either way.
        $scalar = $this->validOrganization();
        $scalar['dfc-b:name'] = 'Acme';

        $array = $this->validOrganization();
        $array['dfc-b:name'] = ['Acme'];

        self::assertSame([], $this->validateFixture($scalar));
        self::assertSame([], $this->validateFixture($array));
    }

    public function testTooManyValuesForASingularPredicateIsAViolation(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:description'] = ['one', 'two'];

        $violations = $this->validateFixture($document);

        self::assertCount(1, $violations);
        self::assertSame(ValidationIssue::TOO_LARGE, $violations[0]->issue());
        self::assertSame('maxCount<=1', $violations[0]->constraint());
    }

    // -- Datatype -------------------------------------------------------------

    public function testAWrongDatatypeIsAViolation(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:name'] = 42;

        $violations = $this->validateFixture($document);

        self::assertCount(1, $violations);
        self::assertSame(ValidationIssue::TYPE_MISMATCH, $violations[0]->issue());
        self::assertSame('datatype:string', $violations[0]->constraint());
    }

    // -- Length ---------------------------------------------------------------

    public function testAnOverLongStringIsAViolation(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:name'] = str_repeat('x', 256);

        $violations = $this->validateFixture($document);

        self::assertCount(1, $violations);
        self::assertSame(ValidationIssue::TOO_LARGE, $violations[0]->issue());
        self::assertSame('maxLength<=255', $violations[0]->constraint());
    }

    public function testExactlyTheMaximumLengthIsAccepted(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:name'] = str_repeat('x', 255);

        self::assertSame([], $this->validateFixture($document));
    }

    // -- Node kind ------------------------------------------------------------

    public function testANonIriWhereAnIriIsRequiredIsAViolation(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:websiteAddress'] = 'not-a-url';

        $violations = $this->validateFixture($document);

        // Two constraints fail on the same value, and both are reported: nodeKind=IRI
        // (it is not an IRI) and pattern ^https:// (it has no scheme). Reporting one
        // would send the client round the loop twice.
        self::assertCount(2, $violations);
        self::assertSame(
            ['nodeKind=IRI', 'pattern'],
            array_map(static fn ($v): string => (string) $v->constraint(), $violations)
        );
        self::assertSame(ValidationIssue::SHAPE, $violations[0]->issue());
    }

    public function testAnAbsoluteHttpsIriSatisfiesNodeKindIri(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:websiteAddress'] = 'https://acme.example/';

        self::assertSame([], $this->validateFixture($document));
    }

    // -- Pattern --------------------------------------------------------------

    public function testAFailingPatternIsAViolation(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:websiteAddress'] = 'http://insecure.example/';

        $violations = $this->validateFixture($document);

        self::assertCount(1, $violations);
        self::assertSame(ValidationIssue::MALFORMED, $violations[0]->issue());
        self::assertSame('pattern', $violations[0]->constraint());
    }

    // -- sh:closed ------------------------------------------------------------

    public function testAnUndeclaredPredicateIsAViolationUnderClosed(): void
    {
        // This is the constraint that makes a typo'd predicate a 422 rather than a field
        // that quietly disappears on import.
        $document = $this->validOrganization();
        $document['dfc-b:nam'] = 'Acme';

        $violations = $this->validateFixture($document);

        self::assertCount(1, $violations);
        self::assertSame(ValidationIssue::UNKNOWN_PREDICATE, $violations[0]->issue());
        self::assertSame('closed', $violations[0]->constraint());
        self::assertSame('dfc-b:nam', $violations[0]->predicate());
        self::assertSame('/dfc-b:nam', $violations[0]->path()->render());
    }

    public function testAnIgnoredPropertyIsNotAViolationUnderClosed(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:description'] = 'Allowed by sh:ignoredProperties.';

        self::assertSame([], $this->validateFixture($document));
    }

    public function testJsonLdKeywordsAreNeverAViolationUnderClosed(): void
    {
        // They are syntax, not statements about the resource.
        $document = $this->validOrganization();
        $document['@context'] = ['dfc-b' => 'https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#'];

        self::assertSame([], $this->validateFixture($document));
    }

    // -- Severity -------------------------------------------------------------

    public function testAWarningDoesNotFailTheRequest(): void
    {
        // The fixture's `sh:severity sh:Warning` predicate carries a value outside the
        // vocabulary. A warning that blocks a write is not a warning.
        $document = $this->validOrganization();
        $document['dfc-b:businessStatus'] = 'https://w3id.org/dfc/ontology/DFC-Business/Unknown';

        self::assertSame([], $this->validateFixture($document));
    }

    public function testAWarningWithAnAllowedValueIsAlsoAccepted(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:businessStatus'] = 'https://w3id.org/dfc/ontology/DFC-Business/Active';

        self::assertSame([], $this->validateFixture($document));
    }

    // -- sh:in ----------------------------------------------------------------

    public function testAViolationSeverityValueOutsideTheVocabularyIsRejected(): void
    {
        // The same predicate with a Violation severity, to prove the vocabulary check
        // works and the Warning in the fixture is what suppresses it.
        $shapes = (new \Civi\Dfc\V2\Validation\TurtleShapeParser())->parse(<<<'TTL'
            @prefix sh:  <http://www.w3.org/ns/shacl#> .
            @prefix xsd: <http://www.w3.org/2001/XMLSchema#> .
            @prefix dfc-b: <https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#> .
            @prefix ex: <https://example.org/shapes/> .

            ex:S a sh:NodeShape ;
                sh:targetClass dfc-b:Organization ;
                sh:property [
                    sh:path dfc-b:businessStatus ;
                    sh:maxCount 1 ;
                    sh:datatype xsd:string ;
                    sh:in ( "active" "inactive" ) ;
                    sh:severity sh:Violation
                ] .
            TTL);

        $violations = $this->validator->validate($shapes, [
            '@type' => 'dfc-b:Organization',
            'dfc-b:businessStatus' => 'unknown',
        ]);

        self::assertCount(1, $violations);
        self::assertSame(ValidationIssue::NOT_A_MEMBER, $violations[0]->issue());
        self::assertSame('in', $violations[0]->constraint());
    }

    public function testAMemberOfTheVocabularyPasses(): void
    {
        $shapes = (new \Civi\Dfc\V2\Validation\TurtleShapeParser())->parse(<<<'TTL'
            @prefix sh:  <http://www.w3.org/ns/shacl#> .
            @prefix dfc-b: <https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#> .
            @prefix ex: <https://example.org/shapes/> .

            ex:S a sh:NodeShape ;
                sh:targetClass dfc-b:Organization ;
                sh:property [
                    sh:path dfc-b:businessStatus ;
                    sh:in ( "active" "inactive" )
                ] .
            TTL);

        self::assertSame([], $this->validator->validate($shapes, [
            '@type' => 'dfc-b:Organization',
            'dfc-b:businessStatus' => 'active',
        ]));
    }

    // -- Focus nodes ----------------------------------------------------------

    public function testANestedNodeMapIsValidatedAgainstItsOwnShape(): void
    {
        // A DFC Organization contains an Address, and upstream has a shape for Address.
        $document = $this->validOrganization();
        $document['dfc-b:hasAddress'] = [
            [
                '@type' => 'dfc-b:Address',
                '@id' => 'https://platform.example/dfc/v2/semantic/address/abc',
            ],
        ];

        $violations = $this->validateFixture($document);

        // The nested Address has neither hasStreet nor hasPostalCode.
        self::assertCount(2, $violations);
        self::assertSame(
            ['dfc-b:hasStreet', 'dfc-b:hasPostalCode'],
            array_map(static fn ($v): string => $v->predicate(), $violations)
        );
    }

    public function testAValidNestedNodeMapPasses(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:hasAddress'] = [
            [
                '@type' => 'dfc-b:Address',
                '@id' => 'https://platform.example/dfc/v2/semantic/address/abc',
                'dfc-b:hasStreet' => '1 Example Street',
                'dfc-b:hasPostalCode' => 'SW1A 1AA',
            ],
        ];

        self::assertSame([], $this->validateFixture($document));
    }

    public function testANestedValueWithoutATypeIsNotAFocusNode(): void
    {
        // It is a VALUE, and its own predicates are the parent shape's business. Note
        // that `sh:class` is also not checked for it: an inline node map with no `@type`
        // has nothing to check against.
        $document = $this->validOrganization();
        $document['dfc-b:hasAddress'] = [['dfc-b:hasStreet' => '1 Example Street']];

        self::assertSame([], $this->validateFixture($document));
    }

    public function testANodeInsideAnArrayIsStillAFocusNode(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:hasAddress'] = [
            ['@type' => 'dfc-b:Address', 'dfc-b:hasStreet' => 'a', 'dfc-b:hasPostalCode' => 'b'],
            ['@type' => 'dfc-b:Address'],
        ];

        $violations = $this->validateFixture($document);

        // Three: two from the incomplete Address, plus the plural form of a singular
        // predicate on the parent. Shape order puts the parent's own violations first,
        // which is document order rather than depth-first — a validator that reported
        // the deepest problem first would be less useful, not more.
        self::assertSame(
            ['dfc-b:hasAddress', 'dfc-b:hasStreet', 'dfc-b:hasPostalCode'],
            array_map(static fn ($v): string => $v->predicate(), $violations)
        );
        self::assertSame('/dfc-b:hasAddress/1/dfc-b:hasStreet', $violations[1]->path()->render());
    }

    // -- sh:class -------------------------------------------------------------

    public function testShClassIsCheckedAgainstAnInlineNodeMap(): void
    {
        $shapes = (new \Civi\Dfc\V2\Validation\TurtleShapeParser())->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix dfc-b: <https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#> .
            @prefix ex: <https://example.org/shapes/> .

            ex:S a sh:NodeShape ;
                sh:targetClass dfc-b:Organization ;
                sh:property [
                    sh:path dfc-b:hasAddress ;
                    sh:maxCount 1 ;
                    sh:class dfc-b:Address
                ] .
            TTL);

        $wrong = $this->validator->validate($shapes, [
            '@type' => 'dfc-b:Organization',
            'dfc-b:hasAddress' => ['@type' => 'dfc-b:PhysicalPlace'],
        ]);

        self::assertCount(1, $wrong);
        self::assertSame(ValidationIssue::TYPE_MISMATCH, $wrong[0]->issue());

        $right = $this->validator->validate($shapes, [
            '@type' => 'dfc-b:Organization',
            'dfc-b:hasAddress' => ['@type' => 'dfc-b:Address'],
        ]);

        self::assertSame([], $right);
    }

    public function testShClassOnABareIriReferenceIsNotCheckedAndThatIsDocumented(): void
    {
        // SHACL is defined over a graph; a bare IRI reference carries no inline type, so
        // reporting a violation would be a false 422. Dereferencing is lane-4's SSRF
        // policy, not this layer's business.
        $shapes = (new \Civi\Dfc\V2\Validation\TurtleShapeParser())->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix dfc-b: <https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#> .
            @prefix ex: <https://example.org/shapes/> .

            ex:S a sh:NodeShape ;
                sh:targetClass dfc-b:Organization ;
                sh:property [ sh:path dfc-b:hasAddress ; sh:class dfc-b:Address ] .
            TTL);

        $violations = $this->validator->validate($shapes, [
            '@type' => 'dfc-b:Organization',
            'dfc-b:hasAddress' => 'https://platform.example/dfc/v2/semantic/address/abc',
        ]);

        self::assertSame([], $violations);
    }

    // -- Diagnostics ----------------------------------------------------------

    public function testTheViolationPathPointsAtTheOffendingPredicate(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:hasAddress'] = [
            ['@type' => 'dfc-b:Address'],
        ];
        unset($document['dfc-b:hasAddress'][0]['dfc-b:hasStreet']);

        $violations = $this->validateFixture($document);

        self::assertNotSame([], $violations);
        self::assertSame('/dfc-b:hasAddress/0/dfc-b:hasStreet', $violations[0]->path()->render());
    }

    public function testAnAbsoluteIriKeyIsRecognisedAsTheSamePredicate(): void
    {
        // A document produced by an expander uses absolute IRIs as keys. Both forms are
        // stored on a property shape precisely so this matches; matching only the written
        // CURIE would silently skip every constraint in an expanded document.
        $shapes = (new \Civi\Dfc\V2\Validation\TurtleShapeParser())->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix dfc-b: <https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#> .
            @prefix ex: <https://example.org/shapes/> .

            ex:S a sh:NodeShape ;
                sh:targetClass dfc-b:Organization ;
                sh:property [ sh:path dfc-b:name ; sh:minCount 1 ; sh:maxLength 3 ] .
            TTL);

        $violations = $this->validator->validate($shapes, [
            '@type' => 'dfc-b:Organization',
            'https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#name' => 'Acme',
        ]);

        self::assertCount(1, $violations, 'maxLength<=3 must be evaluated against the absolute key.');
        self::assertSame('dfc-b:name', $violations[0]->predicate(), 'The CURIE is what a client can act on.');
        self::assertSame(
            '/dfc-b:name',
            $violations[0]->path()->render(),
            'An absolute IRI is not a legal DfcPath token, so the shape predicate stands in.'
        );
    }

    public function testADiagnosticIsRenderableAndCarriesNoSubmittedValue(): void
    {
        $document = $this->validOrganization();
        $document['dfc-b:name'] = str_repeat('sensitive', 40);

        $violations = $this->validateFixture($document);
        $rendered = $violations[0]->toArray();

        self::assertSame(
            ['path', 'predicate', 'issue', 'constraint', 'detail'],
            array_keys($rendered)
        );
        self::assertSame('/dfc-b:name', $rendered['path']);
        self::assertSame('dfc-b:name', $rendered['predicate']);
        self::assertStringNotContainsString('sensitive', json_encode($rendered, JSON_THROW_ON_ERROR));
    }

    // -- hasValue -------------------------------------------------------------

    public function testAMissingRequiredMemberValueIsAViolation(): void
    {
        $shapes = (new \Civi\Dfc\V2\Validation\TurtleShapeParser())->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .

            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:role ; sh:hasValue "owner" ] .
            TTL);

        $missing = $this->validator->validate($shapes, [
            '@type' => 'ex:Thing',
            'ex:role' => ['editor'],
        ]);
        self::assertCount(1, $missing);
        self::assertSame(ValidationIssue::REQUIRED, $missing[0]->issue());
        self::assertSame('hasValue', $missing[0]->constraint());

        self::assertSame([], $this->validator->validate($shapes, [
            '@type' => 'ex:Thing',
            'ex:role' => ['editor', 'owner'],
        ]));
    }

    // -- Value wrapping -------------------------------------------------------

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function jsonLdValueWrappers(): iterable
    {
        // JSON-LD wraps a value with an explicit type. The wrapper's `@type` is what
        // `sh:datatype` is compared against, so unwrapping has to happen first.
        yield 'value wrapper with an explicit string type' => [
            ['@value' => 'Acme', '@type' => 'xsd:string'],
            'dfc-b:name',
            'xsd:string',
        ];

        yield 'a bare string' => ['Acme', 'dfc-b:name', 'xsd:string'];

        yield 'a value wrapper with an integer type' => [
            ['@value' => 42, '@type' => 'xsd:integer'],
            'dfc-b:name',
            'xsd:integer',
        ];

        yield 'a bare integer' => [42, 'dfc-b:name', 'xsd:integer'];

        yield 'a value wrapper with a boolean type' => [
            ['@value' => true, '@type' => 'xsd:boolean'],
            'dfc-b:name',
            'xsd:boolean',
        ];
    }

    #[DataProvider('jsonLdValueWrappers')]
    public function testDatatypeIsCheckedAgainstTheValueType(
        mixed $value,
        string $path,
        string $datatype
    ): void {
        $shapes = (new \Civi\Dfc\V2\Validation\TurtleShapeParser())->parse(sprintf(<<<'TTL'
            @prefix sh:  <http://www.w3.org/ns/shacl#> .
            @prefix xsd: <http://www.w3.org/2001/XMLSchema#> .
            @prefix ex:  <https://example.org/shapes/> .

            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ; sh:datatype %s ] .
            TTL, $datatype));

        self::assertSame([], $this->validator->validate($shapes, [
            '@type' => 'ex:Thing',
            $path => $value,
        ]));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @param array<string, mixed> $document
     *
     * @return list<\Civi\Dfc\V2\Controller\Error\ValidationDiagnostic>
     */
    private function validateFixture(array $document): array
    {
        $shapes = (new \Civi\Dfc\V2\Validation\TurtleShapeParser())->parse(
            (string) file_get_contents(__DIR__ . '/../../fixtures/security/shacl/organization-fixture.shacl.ttl')
        );

        return $this->validator->validate($shapes, $document);
    }

    /**
     * @return array<string, mixed>
     */
    private function validOrganization(): array
    {
        return [
            '@context' => [
                'dfc-b' => 'https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#',
            ],
            '@id' => 'https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V',
            '@type' => 'dfc-b:Organization',
            'dfc-b:name' => 'Acme Foods',
        ];
    }
}