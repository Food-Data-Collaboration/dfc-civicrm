<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Validation\ShaclPropertyShape;
use Civi\Dfc\V2\Validation\ShaclShapeSet;
use Civi\Dfc\V2\Validation\ShaclShapeUnavailableException;
use Civi\Dfc\V2\Validation\TurtleShapeParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Turtle subset parser, and the rule that makes shipping a subset defensible.
 *
 * ============================================================================
 * THE ASSERTION THAT MATTERS MOST
 * ============================================================================
 * {@see testAnUnimplementedShaclPredicateIsFatalRatherThanIgnored()}. A shape parser
 * that skipped the constraints it did not understand would produce a validator that
 * reports "valid" for documents the shapes forbid, with no evidence that anything was
 * missed. So an unknown `sh:` predicate refuses the SHAPE. Point this at the real
 * `dfc_business.shacl.ttl` and it will either work or refuse loudly — never quietly
 * validate less than the file says.
 */
#[CoversClass(TurtleShapeParser::class)]
#[CoversClass(ShaclShapeSet::class)]
final class TurtleShapeParserTest extends TestCase
{
    private TurtleShapeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TurtleShapeParser();
    }

    // -- The fixture shape ----------------------------------------------------

    public function testTheFixtureShapeParses(): void
    {
        $set = $this->parser->parse(self::fixture());

        self::assertSame(3, $set->count());
        self::assertFalse($set->isEmpty());
    }

    public function testTheFixtureExercisesAnnotationsThatMustBeDiscarded(): void
    {
        // `sh:message` is free text from a file an administrator controls, and
        // ValidationIssue::title() is the only human-readable text this layer emits. So
        // messages are dropped — and RECORDED, so a shape author can tell "my message is
        // ignored on purpose" from "my constraint was refused".
        $set = $this->parser->parse(self::fixture());

        $ignored = $set->ignoredAnnotations();

        self::assertContains('sh:message', $ignored);
        self::assertContains('sh:description', $ignored);
        self::assertContains('rdfs:label', $ignored);
    }

    public function testAnnotationsAreNotTurnedIntoConstraints(): void
    {
        $shape = $this->shapeFor($this->parser->parse(self::fixture()), 'OrganizationShape');

        foreach ($shape->properties() as $property) {
            self::assertNotSame('message', $property->path());
        }
    }

    // -- Targets --------------------------------------------------------------

    public function testATargetClassIsCapturedInBothForms(): void
    {
        $set = $this->parser->parse(self::fixture());
        $shape = $this->shapeFor($set, 'OrganizationShape');

        self::assertTrue($shape->appliesTo(['dfc-b:Organization'], null));
        self::assertFalse($shape->appliesTo(['dfc-b:Person'], null));
        self::assertFalse($shape->appliesTo([], null), 'An untyped node matches nothing here.');
    }

    public function testATargetNodeMatchesById(): void
    {
        $set = $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetNode ex:specific-resource ;
                sh:property [ sh:path ex:field ] .
            TTL);

        $shape = $this->shapeFor($set, 'S');

        self::assertTrue($shape->appliesTo([], 'https://example.org/shapes/specific-resource'));
        self::assertFalse($shape->appliesTo([], 'https://example.org/shapes/other'));
    }

    public function testAShapeTargetingNothingIsRefused(): void
    {
        // A shape with no target can never apply, so it can only be a mistake.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/targets nothing/');

        $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:property [ sh:path ex:field ] .
            TTL);
    }

    // -- Constraints ----------------------------------------------------------

    public function testEveryImplementedConstraintIsCaptured(): void
    {
        $set = $this->parser->parse(<<<'TTL'
            @prefix sh:   <http://www.w3.org/ns/shacl#> .
            @prefix xsd:  <http://www.w3.org/2001/XMLSchema#> .
            @prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .
            @prefix ex:   <https://example.org/shapes/> .

            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:closed true ;
                sh:ignoredProperties ( ex:internal ex:another ) ;
                sh:property [
                    sh:path ex:everything ;
                    sh:minCount 1 ;
                    sh:maxCount 9 ;
                    sh:datatype xsd:string ;
                    sh:nodeKind sh:IRI ;
                    sh:class ex:Other ;
                    sh:in ( "a" "b" "c" ) ;
                    sh:pattern "^[a-z]+$" ;
                    sh:flags "i" ;
                    sh:minLength 2 ;
                    sh:maxLength 40 ;
                    sh:hasValue "b" ;
                    sh:severity sh:Violation
                ] .
            TTL);

        $property = $this->shapeFor($set, 'S')->properties()[0];

        self::assertSame('ex:everything', $property->path());
        self::assertSame(1, $property->minCount());
        self::assertSame(9, $property->maxCount());
        self::assertSame('string', $property->datatype());
        self::assertSame('IRI', $property->nodeKind());
        self::assertSame(['https://example.org/shapes/Other'], $property->classes());
        self::assertSame(['a', 'b', 'c'], $property->vocabulary());
        self::assertSame('^[a-z]+$', $property->pattern());
        self::assertSame(2, $property->minLength());
        self::assertSame(40, $property->maxLength());
        self::assertSame('b', $property->hasValue());
        self::assertSame('Violation', $property->severity());
        self::assertTrue($property->isViolation());
    }

    public function testTheFixtureSeverityOfWarningIsCaptured(): void
    {
        $shape = $this->shapeFor($this->parser->parse(self::fixture()), 'OrganizationStatusShape');

        $property = $shape->properties()[0];

        self::assertSame('Warning', $property->severity());
        self::assertFalse($property->isViolation(), 'A warning must not fail a request.');
    }

    public function testAnAbsoluteIriIsAcceptedAsAShapePath(): void
    {
        $set = $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path <https://example.org/ns#absoluteField> ] .
            TTL);

        self::assertSame(
            'https://example.org/ns#absoluteField',
            $this->shapeFor($set, 'S')->properties()[0]->path()
        );
    }

    public function testClosedAndIgnoredPropertiesAreCaptured(): void
    {
        $shape = $this->shapeFor($this->parser->parse(self::fixture()), 'OrganizationShape');

        self::assertTrue($shape->isClosed());
        self::assertContains('https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#description', $shape->ignoredProperties());
    }

    public function testAPatternFlagIsCarriedAndThePatternStaysValid(): void
    {
        // The flag is captured as part of the shape; applying it is the validator's job,
        // and an UNUSABLE pattern is refused at parse time so a validator never has to
        // decide what a broken regular expression means.
        $set = $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:code ; sh:pattern "^ab$" ; sh:flags "u" ] .
            TTL);

        $property = $this->shapeFor($set, 'S')->properties()[0];

        self::assertSame('^ab$', $property->pattern());

        // The pattern is a bare SPARQL/PCRE fragment, so this layer has to delimit it.
        self::assertSame('/^ab$/u', ShaclPropertyShape::delimit($property->pattern(), 'u'));
    }

    public function testAnUnusablePatternIsRefusedAtParseTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a usable regular expression/');

        $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:code ; sh:pattern "^(unclosed" ] .
            TTL);
    }

    // -- The fail-closed rule -------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function unimplementedShaclPredicates(): iterable
    {
        // Every one of these is real SHACL that this subset does not evaluate. Each one
        // must REFUSE the shape rather than be skipped.
        yield 'sh:or' => ['sh:or'];
        yield 'sh:not' => ['sh:not'];
        yield 'sh:and' => ['sh:and'];
        yield 'sh:xone' => ['sh:xone'];
        yield 'sh:node' => ['sh:node'];
        yield 'sh:qualifiedValueShape' => ['sh:qualifiedValueShape'];
        yield 'sh:targetSubjectsOf' => ['sh:targetSubjectsOf'];
        yield 'sh:targetObjectsOf' => ['sh:targetObjectsOf'];
        yield 'sh:lessThan' => ['sh:lessThan'];
        yield 'sh:uniqueLang' => ['sh:uniqueLang'];
        yield 'sh:languageIn' => ['sh:languageIn'];
        yield 'sh:equals' => ['sh:equals'];
        yield 'sh:disjoint' => ['sh:disjoint'];
        yield 'sh:nodeKind with a bad value' => ['sh:sparql'];
    }

    #[DataProvider('unimplementedShaclPredicates')]
    public function testAnUnimplementedShaclPredicateIsFatalRatherThanIgnored(string $predicate): void
    {
        // THE assertion. A skipped constraint is an invisible weakening: the validator
        // would report documents the shapes forbid as valid, with no evidence anything
        // was missed.
        try {
            $this->parser->parse(sprintf(<<<'TTL'
                @prefix sh: <http://www.w3.org/ns/shacl#> .
                @prefix ex: <https://example.org/shapes/> .
                ex:S a sh:NodeShape ;
                    sh:targetClass ex:Thing ;
                    sh:property [ sh:path ex:field ; %s ex:other ] .
                TTL, $predicate));
            self::fail(sprintf('"%s" must refuse the shape.', $predicate));
        } catch (ShaclShapeUnavailableException $refused) {
            self::assertStringContainsString(
                'does not implement',
                $refused->getMessage(),
                'The refusal must say the constraint is unimplemented.'
            );
            self::assertStringContainsString(
                'silently ignored',
                $refused->getMessage(),
                'The refusal must say why refusing beats skipping.'
            );
            self::assertStringContainsString($predicate, $refused->getMessage());
        }
    }

    public function testAnUnimplementedShaclPredicateOnANodeShapeIsAlsoFatal(): void
    {
        $this->expectException(ShaclShapeUnavailableException::class);

        $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:node ex:OtherShape .
            TTL);
    }

    public function testAnUnknownPredicateNamespaceIsFatal(): void
    {
        // Even outside `sh:`, a constraint in a namespace this class has never heard of
        // cannot be silently ignored.
        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/neither a sh: constraint/');

        $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix custom: <https://example.org/custom#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ; custom:mustBeOdd ex:yes ] .
            TTL);
    }

    public function testEveryKnownAnnotationNamespaceIsAccepted(): void
    {
        // These namespaces carry labels and provenance in real shape files. Refusing them
        // would make the real upstream shapes unparseable for no security gain.
        $this->parser->parse(<<<'TTL'
            @prefix sh:   <http://www.w3.org/ns/shacl#> .
            @prefix owl:  <http://www.w3.org/2002/07/owl#> .
            @prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .
            @prefix skos: <http://www.w3.org/2004/02/skos/core#> .
            @prefix dct:  <http://purl.org/dc/terms/> .
            @prefix foaf: <http://xmlns.com/foaf/0.1/> .
            @prefix ex:   <https://example.org/shapes/> .

            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                rdfs:label "A shape" ;
                rdfs:comment "Why" ;
                owl:versionInfo "1.0" ;
                skos:definition "A definition" ;
                dct:source "upstream" ;
                foaf:homepage <https://example.org/> ;
                sh:property [ sh:path ex:field ] .
            TTL);

        self::assertTrue(true, 'The shape parsed; every annotation namespace was recognised.');
    }

    public function testShaclMessageOnAPropertyShapeIsDiscarded(): void
    {
        $set = $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ; sh:minCount 1 ; sh:message "Must exist" ] .
            TTL);

        self::assertContains('sh:message', $set->ignoredAnnotations());
        self::assertSame(1, $this->shapeFor($set, 'S')->properties()[0]->minCount());
    }

    // -- Malformed input ------------------------------------------------------

    public function testAPrefixRebindingIsRefused(): void
    {
        // A file that binds one prefix to two IRIs would make validation depend on which
        // definition the scanner read last.
        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/declared twice/');

        $this->parser->parse(<<<'TTL'
            @prefix ex: <https://example.org/one/> .
            @prefix ex: <https://example.org/two/> .
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ] .
            TTL);
    }

    public function testARebindingToTheSameIriIsAccepted(): void
    {
        $set = $this->parser->parse(<<<'TTL'
            @prefix ex: <https://example.org/one/> .
            @prefix ex: <https://example.org/one/> .
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ] .
            TTL);

        self::assertSame(1, $set->count());
    }

    public function testAnUndeclaredPrefixIsRefused(): void
    {
        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/used but never declared/');

        $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ] .
            TTL);
    }

    public function testEveryErrorNamesALine(): void
    {
        // "Your shapes are wrong" with no location is the error that costs an afternoon.
        try {
            $this->parser->parse(<<<'TTL'
                @prefix sh: <http://www.w3.org/ns/shacl#> .
                @prefix ex: <https://example.org/shapes/> .

                ex:S a sh:NodeShape ;
                    sh:targetClass ex:Thing ;
                    sh:property [ sh:path ex:field ; sh:or ex:Other ] .
                TTL);
            self::fail('Must be refused.');
        } catch (ShaclShapeUnavailableException $refused) {
            self::assertMatchesRegularExpression('/^SHACL shape line \d+:/', $refused->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTurtle(): iterable
    {
        yield 'unclosed IRI' => ['@prefix sh: <http://example.org/ns# .'];
        yield 'unclosed literal' => ['@prefix sh: <http://example.org/ns#> . ex:S "open .'];
        yield 'unexpected character' => ['@prefix sh: <http://example.org/ns#> . ex:S ! .'];
        yield 'a bare word as a predicate' => ['@prefix ex: <https://example.org/> . ex:S nakedword .'];
        yield '@base is unsupported' => ['@base <https://example.org/> .'];
        yield '@keywords is unsupported' => ['@keywords ex: .'];
        yield 'an unterminated blank node' => ['@prefix ex: <https://example.org/> . ex:S ex:p [ ex:q ex:r .'];
        yield 'an unterminated collection' => ['@prefix ex: <https://example.org/> . ex:S ex:p ( ex:a .'];
        yield 'an IRI with whitespace' => ['@prefix ex: <https://example.org/ bad> .'];
    }

    #[DataProvider('malformedTurtle')]
    public function testMalformedTurtleIsRefused(string $turtle): void
    {
        $this->expectException(ShaclShapeUnavailableException::class);

        $this->parser->parse($turtle);
    }

    /**
     * Cases the provider above cannot express as a bare prefix: they need the other
     * prefixes declared before the interesting part.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedTurtleWithPrefixes(): iterable
    {
        $header = "@prefix sh: <http://www.w3.org/ns/shacl#> .\n@prefix ex: <https://example.org/shapes/> .\n";

        yield 'a property shape by IRI reference' => [$header . <<<'TTL'
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property ex:SharedPropertyShape .
            TTL];

        yield 'sh:in without a collection' => [$header . <<<'TTL'
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ; sh:in ex:single ] .
            TTL];

        yield 'a collection as a subject' => [$header . <<<'TTL'
            ( ex:a ex:b ) ex:predicate ex:object .
            TTL];
    }

    #[DataProvider('malformedTurtleWithPrefixes')]
    public function testMalformedTurtleWithPrefixesIsRefused(string $turtle): void
    {
        $this->expectException(ShaclShapeUnavailableException::class);

        $this->parser->parse($turtle);
    }

    public function testAPropertyShapeByIriReferenceIsRefusedWithAReason(): void
    {
        // Legal SHACL that this subset does not resolve. The reason names what would be
        // needed, rather than reporting a bare parse error.
        try {
            $this->parser->parse(<<<'TTL'
                @prefix sh: <http://www.w3.org/ns/shacl#> .
                @prefix ex: <https://example.org/shapes/> .
                ex:S a sh:NodeShape ;
                    sh:targetClass ex:Thing ;
                    sh:property ex:SharedPropertyShape .
                TTL);
            self::fail('Must be refused.');
        } catch (ShaclShapeUnavailableException $refused) {
            self::assertStringContainsString('inline blank node', $refused->getMessage());
            self::assertStringContainsString('shape catalogue', $refused->getMessage());
        }
    }

    public function testShInMustBeACollection(): void
    {
        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/sh:in must be a collection/');

        $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ; sh:in ex:single ] .
            TTL);
    }

    public function testANegativeMinCountIsRefused(): void
    {
        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/non-negative integer/');

        $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ; sh:minCount "not a number" ] .
            TTL);
    }

    public function testMinCountAboveMaxCountIsRefusedAsADefectiveShape(): void
    {
        // Every document would violate it, so it is a bug in the shape file.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/defective shape/');

        $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ; sh:minCount 5 ; sh:maxCount 2 ] .
            TTL);
    }

    // -- Comments and whitespace ----------------------------------------------

    public function testCommentsAreIgnored(): void
    {
        $set = $this->parser->parse(<<<'TTL'
            # A comment on its own line.
            @prefix sh: <http://www.w3.org/ns/shacl#> .   # trailing comment
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;   # another
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ] .   # and one more
            TTL);

        self::assertSame(1, $set->count());
    }

    public function testAPrefixWithoutATerminatingDotIsAccepted(): void
    {
        // Both forms are legal Turtle and both appear in the wild.
        $set = $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#>
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ] .
            TTL);

        self::assertSame(1, $set->count());
    }

    public function testTrailingSemicolonsAreTolerated(): void
    {
        $set = $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [ sh:path ex:field ] ;
                ;
            TTL);

        self::assertSame(1, $set->count());
    }

    public function testACommaSeparatedObjectListIsAccepted(): void
    {
        $set = $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing, ex:AnotherThing ;
                sh:property [ sh:path ex:field ] .
            TTL);

        $shape = $this->shapeFor($set, 'S');

        self::assertTrue($shape->appliesTo(['ex:Thing'], null));
        self::assertTrue(
            $shape->appliesTo(['ex:AnotherThing'], null),
            'Both comma-separated target classes must be captured.'
        );
    }

    // -- Literals -------------------------------------------------------------

    public function testLiteralDatatypesAndLanguageTagsAreParsed(): void
    {
        $set = $this->parser->parse(<<<'TTL'
            @prefix sh:   <http://www.w3.org/ns/shacl#> .
            @prefix xsd:  <http://www.w3.org/2001/XMLSchema#> .
            @prefix ex:   <https://example.org/shapes/> .
            ex:S a sh:NodeShape ;
                sh:targetClass ex:Thing ;
                sh:property [
                    sh:path ex:typed ;
                    sh:datatype xsd:integer ;
                ] ;
                sh:property [
                    sh:path ex:viaDatatypeLiteral ;
                    sh:datatype <http://www.w3.org/2001/XMLSchema#boolean> ;
                ] .
            TTL);

        $properties = $this->shapeFor($set, 'S')->properties();

        self::assertSame('integer', $properties[0]->datatype());
        self::assertSame('boolean', $properties[1]->datatype());
    }

    public function testTheDefaultPrefixIsAccepted(): void
    {
        // `@prefix : <ns>` binds the empty prefix, so `:field` is `ns#field`.
        $set = $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix : <https://example.org/shapes/> .
            :S a sh:NodeShape ;
                sh:targetClass :Thing ;
                sh:property [ sh:path :field ] .
            TTL);

        $property = $this->shapeFor($set, 'S')->properties()[0];

        self::assertSame(':field', $property->path(), 'The written form is kept for reporting.');
        self::assertSame('https://example.org/shapes/field', $property->pathIri());
    }

    // -- Merging --------------------------------------------------------------

    public function testMergedSetsKeepBothGraphsPrefixesAndAnnotations(): void
    {
        $business = $this->parser->parse(self::fixture());
        $technical = $this->parser->parse(<<<'TTL'
            @prefix sh: <http://www.w3.org/ns/shacl#> .
            @prefix ex: <https://example.org/shapes/> .
            ex:Technical a sh:NodeShape ;
                sh:targetClass ex:TechnicalThing ;
                sh:property [ sh:path ex:technicalField ; sh:message "note" ] .
            TTL);

        $merged = ShaclShapeSet::merge([$business, $technical]);

        self::assertSame(4, $merged->count());
        // Shape IRIs are resolved, so the assertion is on the resolved IRI rather than
        // the CURIE that was written.
        self::assertContains('https://example.org/shapes/Technical', array_map(
            static fn (\Civi\Dfc\V2\Validation\ShaclNodeShape $shape): string => $shape->iri(),
            $merged->shapes()
        ));
        self::assertArrayHasKey('ex', $merged->prefixes());
        self::assertContains('sh:message', $merged->ignoredAnnotations());
    }

    public function testAnEmptySetIsEmptyRatherThanNull(): void
    {
        // The third fail-closed state: shapes loaded but no node shapes in them.
        $set = $this->parser->parse('# only a comment');

        self::assertTrue($set->isEmpty());
        self::assertSame(0, $set->count());
    }

    public function testAnEmptySetHasNoPrefixes(): void
    {
        $set = $this->parser->parse('');

        self::assertTrue($set->isEmpty());
        self::assertSame([], $set->prefixes());
    }

    // -- Helpers --------------------------------------------------------------

    private static function fixture(): string
    {
        $contents = file_get_contents(__DIR__ . '/../../fixtures/security/shacl/organization-fixture.shacl.ttl');

        if ($contents === false) {
            throw new \RuntimeException('The SHACL fixture could not be read.');
        }

        return $contents;
    }

    private function shapeFor(ShaclShapeSet $set, string $localName): \Civi\Dfc\V2\Validation\ShaclNodeShape
    {
        foreach ($set->shapes() as $shape) {
            if (str_ends_with($shape->iri(), $localName)) {
                return $shape;
            }
        }

        self::fail(sprintf('No shape named "%s" in the parsed set.', $localName));
    }
}