<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

use Civi\Dfc\V2\Controller\Error\ValidationDiagnostic;
use Civi\Dfc\V2\Policy\PredicateVisibility;

/**
 * A local SHACL validator for the documented subset — hermetic, fail-closed.
 *
 * ============================================================================
 * WHY A LOCAL VALIDATOR EXISTS AT ALL
 * ============================================================================
 * The pipeline needs a {@see ShaclValidatorInterface} implementation, and the only
 * one available in this repository without a dependency is one written here. It is
 * NOT a SHACL processor and does not claim to be: it implements the subset
 * {@see TurtleShapeParser} accepts, and the two are designed to fail in the same
 * direction — a constraint neither understands is a refusal, never a pass.
 *
 * Its purpose is to make the SHACL GATE testable: the thing worth asserting is
 * "when the shapes cannot be loaded, the request fails", and that assertion needs a
 * stage that loads shapes and a validator that runs. With a real processor behind the
 * same interface, that assertion is unchanged.
 *
 * ============================================================================
 * THE VALUE MODEL IT WORKS ON
 * ============================================================================
 * A JSON-LD node map, exactly as
 * {@see \Civi\Dfc\V2\Validation\JsonLdParserInterface} produces it: `@context`, and
 * one or more subjects. Two JSON-LD conventions matter and both are handled
 * explicitly:
 *
 *  - **A predicate's value may be a scalar or a list.** PRD-002 §4.2 calls out the
 *    singular-vs-multi distinction for singular references. Cardinality therefore
 *    counts VALUES, not the array-ness of the key: `{"dfc-b:name": "Acme"}` has one
 *    value, and `{"dfc-b:name": ["Acme"]}` has one too.
 *  - **A value may be wrapped.** `{"@value": …, "@type": …}` and `{"@id": …}` are
 *    unwrapped before any constraint sees them, and the wrapper's `@type` is what
 *    `sh:datatype` is compared against.
 *
 * ============================================================================
 * FOCUS NODES ARE EVERY NODE IN THE DOCUMENT, NOT ONLY THE ROOT
 * ============================================================================
 * A DFC Organization contains nested Address and Place node maps, and an upstream
 * shape targets those classes too. So the validator walks the whole document,
 * collects every node map with an `@id` or an `@type`, and applies every shape whose
 * target matches. A nested value with no `@type` is not a focus node for anything and
 * is skipped — it is a value, and its own predicates are checked by the shape of its
 * parent.
 *
 * ============================================================================
 * `sh:class` ON A BARE IRI REFERENCE IS NOT CHECKED, AND THAT IS DOCUMENTED
 * ============================================================================
 * SHACL is defined over an RDF graph, where a bare IRI has whatever `rdf:type` the
 * graph gives it — and a document that references an Address by IRI does not carry
 * its type inline. Checking it would mean dereferencing, which is lane-4's SSRF
 * policy and not this layer's business. So `sh:class` applies to an INLINE node map
 * and is skipped for a bare reference. The alternative — reporting a violation we
 * cannot substantiate — would be a false 422.
 *
 * ============================================================================
 * THE CONSTRAINTS, AND WHAT EACH MEANS FOR A NODE MAP
 * ============================================================================
 *  sh:minCount   values present (0 fails)
 *  sh:maxCount   values not excessive
 *  sh:datatype   the value's JSON scalar type matches the XSD type
 *  sh:nodeKind   IRI / Literal / BlankNode
 *  sh:class      an inline node map's @type includes the class
 *  sh:in         the value's lexical form is a member of the collection
 *  sh:pattern    PCRE against a string value
 *  sh:minLength  string length floor
 *  sh:maxLength  string length ceiling
 *  sh:hasValue   the value set contains this member
 *  sh:closed     no predicate outside the shape, minus sh:ignoredProperties and the
 *                JSON-LD keywords
 *
 * @package Civi\Dfc
 */
final class LocalShaclValidator implements ShaclValidatorInterface
{
    /**
     * JSON-LD keywords, which are syntax rather than statements about the resource.
     *
     * Reused from {@see \Civi\Dfc\V2\Policy\PredicateVisibility} rather than
     * restated: `sh:closed` must agree with the export policy about which keys are
     * structure, and two lists that could drift apart is one more way for a document
     * to be accepted by one and rejected by the other.
     */
    private const KEYWORDS = PredicateVisibility::JSON_LD_KEYWORDS;

    /** @var list<array{node: array<string, mixed>, tokens: list<string>}> */
    private array $focusNodes = [];

    /**
     * The shape set's prefix map, captured for the duration of one {@see validate()}.
     *
     * Needed because a document's `@type` and a shape's `sh:class` are routinely written
     * in DIFFERENT forms — `dfc-b:Address` in the document, the resolved ontology IRI in
     * the shape file — and comparing them literally reports a type mismatch for a value
     * that is exactly right.
     *
     * @var array<string, string>
     */
    private array $prefixes = [];

    /**
     * @param array<string, mixed> $dataGraph
     *
     * @return list<ValidationDiagnostic>
     *
     * @throws ShaclShapeUnavailableException when the shape set is empty, which means
     *         "nothing could be checked", not "nothing is wrong".
     */
    public function validate(ShaclShapeSet $shapes, array $dataGraph): array
    {
        if ($shapes->isEmpty()) {
            throw new ShaclShapeUnavailableException(
                'The SHACL shape set contains no node shapes, so no document could be validated against it. '
                . 'This is refused rather than reported as a valid document: an empty shape set that validated '
                . 'everything would make the SHACL gate decorative.'
            );
        }

        $this->focusNodes = [];
        $this->prefixes = $shapes->prefixes();
        $this->collectFocusNodes($dataGraph, [], true);

        $violations = [];

        foreach ($shapes->shapes() as $shape) {
            foreach ($this->focusNodes as $focus) {
                $node = $focus['node'];

                if (!$shape->appliesTo(self::typesOf($node), self::idOf($node), $shapes->prefixes())) {
                    continue;
                }

                foreach ($this->validateNode($shape, $node, $focus['tokens']) as $diagnostic) {
                    $violations[] = $diagnostic;
                }
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string>         $tokens
     *
     * @return list<ValidationDiagnostic>
     */
    private function validateNode(ShaclNodeShape $shape, array $node, array $tokens): array
    {
        $diagnostics = [];
        $described = [];

        foreach ($shape->properties() as $property) {
            $described[] = $property->path();
            $described[] = $property->pathIri();

            // A shape marked sh:Warning or sh:Info does not fail a request. The
            // upstream DFC shapes are generated with severities (scripts/
            // add_enum_shacl.py), so this is a real distinction and treating a
            // warning as a 422 would reject documents the shapes permit.
            if (!$property->isViolation()) {
                continue;
            }

            $values = self::valuesFor($node, $property);

            // The violation is ABOUT the predicate, so the reported path is the node's
            // own location plus the predicate. Without the append a violation on a
            // top-level predicate would render as `/`, which names the whole document
            // and tells a client nothing about which field to fix.
            $at = [...$tokens, $property->path()];

            $diagnostics = [...$diagnostics, ...$this->checkCardinality($property, $values, $at)];
            $diagnostics = [...$diagnostics, ...$this->checkValues($property, $values, $at)];
        }

        if ($shape->isClosed()) {
            $diagnostics = [...$diagnostics, ...$this->checkClosed($shape, $node, $described, $tokens)];
        }

        return $diagnostics;
    }

    // -- Individual constraints -----------------------------------------------

    /**
     * @param list<array<string, mixed>> $values
     * @param list<string>               $tokens
     *
     * @return list<ValidationDiagnostic>
     */
    private function checkCardinality(ShaclPropertyShape $property, array $values, array $tokens): array
    {
        $diagnostics = [];

        $min = $property->minCount();
        if ($min !== null && $min > 0 && $values === []) {
            $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                'MinCountConstraintComponent',
                $property->path(),
                $tokens,
                $property
            );
        }

        $max = $property->maxCount();
        if ($max !== null && $max === 0 && $values !== []) {
            $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                'MaxCountConstraintComponent',
                $property->path(),
                $tokens,
                $property
            );
        } elseif ($max !== null && $max > 0 && count($values) > $max) {
            $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                'MaxCountConstraintComponent',
                $property->path(),
                $tokens,
                $property
            );
        }

        $hasValue = $property->hasValue();
        if ($hasValue !== null) {
            foreach ($values as $value) {
                if (self::lexicalForm($value) === $hasValue) {
                    $hasValue = null;

                    break;
                }
            }

            if ($hasValue !== null) {
                $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                    'HasValueConstraintComponent',
                    $property->path(),
                    $tokens,
                    $property
                );
            }
        }

        return $diagnostics;
    }

    /**
     * @param list<array<string, mixed>> $values
     * @param list<string>               $tokens
     *
     * @return list<ValidationDiagnostic>
     */
    private function checkValues(ShaclPropertyShape $property, array $values, array $tokens): array
    {
        $diagnostics = [];

        foreach ($values as $value) {
            $lexical = self::lexicalForm($value);

            $datatype = $property->datatype();
            if ($datatype !== null && !self::matchesDatatype($value, $datatype)) {
                $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                    'DatatypeConstraintComponent',
                    $property->path(),
                    $tokens,
                    $property
                );
            }

            $nodeKind = $property->nodeKind();
            if ($nodeKind !== null && !self::matchesNodeKind($value, $nodeKind)) {
                $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                    'NodeKindConstraintComponent',
                    $property->path(),
                    $tokens,
                    $property
                );
            }

            $classes = $property->classes();
            if ($classes !== [] && self::isInlineNode($value)) {
                $types = self::typesOf($value);
                $matched = false;

                foreach ($classes as $class) {
                    foreach ($types as $type) {
                        if ($this->sameTerm($type, $class)) {
                            $matched = true;

                            break 2;
                        }
                    }
                }

                if (!$matched) {
                    $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                        'ClassConstraintComponent',
                        $property->path(),
                        $tokens,
                        $property
                    );
                }
            }

            $vocabulary = $property->vocabulary();
            if ($vocabulary !== [] && !in_array($lexical, $vocabulary, true)) {
                $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                    'InConstraintComponent',
                    $property->path(),
                    $tokens,
                    $property
                );
            }

            $pattern = $property->pattern();
            if ($pattern !== null && is_string($lexical)
                && preg_match(ShaclPropertyShape::delimit($pattern, ''), $lexical) !== 1
            ) {
                $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                    'PatternConstraintComponent',
                    $property->path(),
                    $tokens,
                    $property
                );
            }

            $minLength = $property->minLength();
            if ($minLength !== null && is_string($lexical) && mb_strlen($lexical) < $minLength) {
                $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                    'MinLengthConstraintComponent',
                    $property->path(),
                    $tokens,
                    $property
                );
            }

            $maxLength = $property->maxLength();
            if ($maxLength !== null && is_string($lexical) && mb_strlen($lexical) > $maxLength) {
                $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                    'MaxLengthConstraintComponent',
                    $property->path(),
                    $tokens,
                    $property
                );
            }
        }

        return $diagnostics;
    }

    /**
     * `sh:closed`: any predicate the shape does not describe is a violation.
     *
     * @param array<string, mixed> $node
     * @param list<string>         $described
     * @param list<string>         $tokens
     *
     * @return list<ValidationDiagnostic>
     */
    private function checkClosed(
        ShaclNodeShape $shape,
        array $node,
        array $described,
        array $tokens
    ): array {
        $diagnostics = [];
        $allowed = [...$described, ...$shape->ignoredProperties(), ...self::KEYWORDS];

        foreach (array_keys($node) as $key) {
            if (!is_string($key) || in_array($key, $allowed, true)) {
                continue;
            }

            $diagnostics[] = ShaclConstraintMapper::toDiagnostic(
                'ClosedConstraintComponent',
                ShaclConstraintMapper::tokenSafe((string) $key),
                [...$tokens, (string) $key]
            );
        }

        return $diagnostics;
    }

    // -- Document walking -----------------------------------------------------

    /**
     * Collect every node in the document a shape could target.
     *
     * THE ROOT COUNTS. A DFC document submitted to an organization container IS the
     * Organization, with its `@type` at the top level — so excluding the root would
     * validate nothing at all for the most common request on this interface.
     *
     * A nested node map is a focus node when it declares `@id` or `@type`. A nested map
     * with NEITHER is a value: it carries properties but says nothing about its own
     * identity, so its predicates belong to the parent shape's business and not to a
     * shape of its own.
     *
     * @param array<string, mixed> $node
     * @param list<string>         $tokens
     */
    private function collectFocusNodes(array $node, array $tokens, bool $isRoot = false): void
    {
        if ($isRoot || array_key_exists('@id', $node) || array_key_exists('@type', $node)) {
            $this->focusNodes[] = ['node' => $node, 'tokens' => $tokens];
        }

        foreach ($node as $key => $value) {
            if ($key === '@context' || !is_string($key)) {
                continue;
            }

            $this->descend($value, [...$tokens, $key]);
        }
    }

    private function descend(mixed $value, array $tokens): void
    {
        if (!is_array($value)) {
            return;
        }

        if (array_is_list($value)) {
            foreach ($value as $position => $entry) {
                $this->descend($entry, [...$tokens, (string) $position]);
            }

            return;
        }

        $this->collectFocusNodes($value, $tokens);
    }

    // -- Value handling -------------------------------------------------------

    /**
     * The values of one predicate, normalised to a list.
     *
     * A single value and a one-element array are the same cardinality, which is what
     * makes PRD-002 §4.2's singular-vs-multi distinction a question for the DFC
     * type/schema stage rather than for a shape.
     *
     * @param array<string, mixed> $node
     *
     * @return list<array<string, mixed>>
     */
    private static function valuesFor(array $node, ShaclPropertyShape $property): array
    {
        foreach ([$property->path(), $property->pathIri()] as $candidate) {
            if (!array_key_exists($candidate, $node)) {
                continue;
            }

            $raw = $node[$candidate];

            if (!is_array($raw)) {
                return [$raw];
            }

            if (array_is_list($raw)) {
                return $raw;
            }

            // A single node map, not a list of them.
            return [$raw];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<string>
     */
    private static function typesOf(array $node): array
    {
        $types = $node['@type'] ?? null;

        if (is_string($types)) {
            return [$types];
        }

        if (!is_array($types)) {
            return [];
        }

        $list = [];
        foreach ($types as $type) {
            if (is_string($type)) {
                $list[] = $type;
            }
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function idOf(array $node): ?string
    {
        $id = $node['@id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Is this value an inline node map rather than a scalar or a bare reference?
     *
     * Takes `mixed` because the caller is walking arbitrary decoded JSON: `mixed` here
     * is the honest type, and a narrower one would be a lie the type system could not
     * catch.
     */
    private static function isInlineNode(mixed $value): bool
    {
        if (!is_array($value) || array_is_list($value)) {
            return false;
        }

        return array_key_exists('@id', $value) || array_key_exists('@value', $value)
            || array_key_exists('@type', $value);
    }

    /**
     * A value's lexical form: the string, number or boolean it denotes.
     *
     * A bare IRI string is returned as itself, because that is the form `sh:in` and
     * `sh:hasValue` compare against in a shape that lists IRIs.
     */
    private static function lexicalForm(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            if (isset($value['@id']) && is_string($value['@id'])) {
                return $value['@id'];
            }

            if (array_key_exists('@value', $value)) {
                return self::lexicalForm($value['@value']);
            }
        }

        return '';
    }

    /**
     * Are two vocabulary terms the same, in whichever forms they were written?
     *
     * Three comparisons, in descending order of confidence:
     *
     *   1. exact — both written the same way;
     *   2. prefix-expanded — one is a CURIE the shape set's prefix map resolves into the
     *      other's namespace, which is the ordinary case for a shape file and a
     *      document disagreeing about dialect;
     *   3. local name — a last resort, because two vocabularies CAN reuse a local name.
     *
     * Only the SHAPE SET's prefix map is used, never the document's: the term being
     * resolved belongs to the shape, and a document is free to bind `dfc-b` to anything.
     */
    private function sameTerm(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        $candidates = [$left];

        if (str_contains($left, ':') && !str_contains($left, '://')) {
            $expanded = ShaclNodeShape::expand($left, $this->prefixes);
            if ($expanded !== null) {
                $candidates[] = $expanded;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate === $right) {
                return true;
            }
        }

        foreach ($candidates as $candidate) {
            if (
                $candidate !== $right
                && TurtleShapeParser::localName($candidate) === TurtleShapeParser::localName($right)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a value's JSON type satisfy the declared XSD datatype?
     *
     * Matched on the XSD local name, so a shape may write `xsd:string` or the full
     * `http://www.w3.org/2001/XMLSchema#string` and get the same answer. `xsd:anyURI`
     * and the date and time types all match a JSON string, which is what a JSON-LD
     * document can carry for them.
     *
     * An IRI written as a bare string is accepted for `xsd:anyURI` and for
     * `xsd:string`, and refused for the numeric and boolean types — which is the
     * check that catches `"memberId": 42`.
     */
    private static function matchesDatatype(mixed $value, string $datatype): bool
    {
        return match ($datatype) {
            'string', 'normalizedString', 'token', 'anyURI', 'date', 'dateTime', 'time', 'duration', 'language',
            'Name', 'NCName', 'NMTOKEN' => is_string($value),
            'boolean' => is_bool($value),
            'int', 'integer', 'long', 'short', 'byte', 'nonNegativeInteger', 'positiveInteger',
            'nonPositiveInteger', 'negativeInteger', 'unsignedInt', 'unsignedLong', 'unsignedShort',
            'unsignedByte' => is_int($value) && !is_bool($value),
            'decimal', 'double', 'float' => (is_float($value) || is_int($value)) && !is_bool($value),
            default => is_string($value) || is_int($value) || is_float($value) || is_bool($value),
        };
    }

    /**
     * @param array<string, mixed>|string|int|float|bool|null $value
     */
    private static function matchesNodeKind(mixed $value, string $nodeKind): bool
    {
        $isIri = is_string($value) && preg_match('#^[a-z][a-z0-9+.\-]*://#', $value) === 1;
        $isIriObject = is_array($value) && isset($value['@id']);
        $isLiteral = is_string($value) || is_int($value) || is_float($value) || is_bool($value)
            || (is_array($value) && array_key_exists('@value', $value));
        $isBlank = is_array($value) && array_key_exists('@id', $value)
            && str_starts_with((string) $value['@id'], '_:');

        $iri = $isIri || $isIriObject;
        $literal = $isLiteral && !$iri;

        return match ($nodeKind) {
            'IRI' => $iri,
            'Literal' => $literal,
            'BlankNode' => $isBlank,
            'BlankNodeOrIRI' => $isBlank || $iri,
            'BlankNodeOrLiteral' => $isBlank || $literal,
            'IRIOrLiteral' => $iri || $literal,
            default => true,
        };
    }
}