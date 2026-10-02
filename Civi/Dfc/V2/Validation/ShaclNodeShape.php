<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * One `sh:NodeShape`: what it applies to, and the property shapes it carries.
 *
 * ============================================================================
 * TARGET MATCHING IS BY BOTH FORMS
 * ============================================================================
 * A document declares `@type` in whichever form the producer used — `dfc-b:Organization`
 * (what the DFC-LinkML connector emits, per PRD-002 "Upstream refresh" item 6) or the
 * absolute IRI. Upstream shapes name their targets as
 * `sh:targetClass dfc-b:Organization` or the absolute IRI, and the two need not agree.
 * So each target class is stored as written AND expanded, and a node matches if either
 * form is among its `@type` values. Matching one form only would silently skip every
 * shape in whichever dialect the document and the shape file disagreed about — a
 * fail-open validator built out of a pedantic comparison.
 *
 * ============================================================================
 * `sh:closed` IS SUPPORTED, AND THAT IS A CLOSED-VALUE DECISION
 * ============================================================================
 * `sh:closed true` means "no predicate outside this shape's property list may appear".
 * It is the one SHACL feature that changes the default from permissive to strict, and
 * it is what makes a typo'd predicate (`dfc-b:nam` instead of `dfc-b:name`) a 422
 * rather than a field that quietly disappears on import. It is reported as
 * {@see \Civi\Dfc\V2\Controller\Error\ValidationIssue::UNKNOWN_PREDICATE}.
 *
 * JSON-LD keywords are never violations under `sh:closed`, whatever the shape says:
 * `@context`, `@id` and `@type` are syntax, not statements about the resource. See
 * {@see \Civi\Dfc\V2\Policy\PredicateVisibility} for the same classification applied
 * to export.
 *
 * @package Civi\Dfc
 */
final class ShaclNodeShape
{
    private readonly string $iri;

    /** @var list<string> */
    private readonly array $targetClasses;

    /** @var list<string> */
    private readonly array $targetClassIris;

    /** @var list<string> */
    private readonly array $targetNodes;

    /** @var list<ShaclPropertyShape> */
    private readonly array $properties;

    private readonly bool $closed;

    /** @var list<string> */
    private readonly array $ignoredProperties;

    /**
     * @param list<string>          $targetClasses     As written.
     * @param list<string>          $targetClassIris   Expanded.
     * @param list<string>          $targetNodes       As written.
     * @param list<ShaclPropertyShape> $properties
     * @param list<string>          $ignoredProperties As written.
     */
    public function __construct(
        string $iri,
        array $targetClasses,
        array $targetClassIris,
        array $targetNodes,
        array $properties,
        bool $closed = false,
        array $ignoredProperties = []
    ) {
        // $targetClasses and $targetClassIris are both accepted because a caller
        // building a shape programmatically may only know one form. When they are the
        // same list — which is what TurtleShapeParser produces, since it resolves
        // CURIEs while reading — the second is redundant and harmless.
        if (trim($iri) === '') {
            throw new \InvalidArgumentException('A SHACL node shape needs a non-empty IRI.');
        }

        if ($targetClasses === [] && $targetNodes === []) {
            throw new \InvalidArgumentException(sprintf(
                'The SHACL node shape "%s" targets nothing. It has neither sh:targetClass nor sh:targetNode, '
                . 'so it can never apply and can only be a mistake.',
                $iri
            ));
        }

        $this->iri = trim($iri);
        $this->targetClasses = array_values($targetClasses);
        $this->targetClassIris = array_values($targetClassIris);
        $this->targetNodes = array_values($targetNodes);
        $this->properties = array_values($properties);
        $this->closed = $closed;
        $this->ignoredProperties = array_values($ignoredProperties);
    }

    public function iri(): string
    {
        return $this->iri;
    }

    /**
     * Does this shape apply to the given node?
     *
     * A document declares `@type` in whichever form the producer used — `dfc-b:Organization`
     * (what the DFC-LinkML connector emits, per PRD-002 "Upstream refresh" item 6) or the
     * absolute IRI — and the shape may name its targets either way. So BOTH forms are
     * compared: the document's term against the shape's written target, and the document's
     * term expanded against the shape's expanded target.
     *
     * Matching one form only would silently skip every shape in whichever dialect the
     * document and the shape file disagreed about — a fail-open validator built out of a
     * pedantic comparison. That is why `$prefixes` is a parameter: the expansion must use
     * the SHAPE SET's prefix map, not the document's, because the shape file's targets
     * were written against the shape file's prefixes.
     *
     * @param list<string>          $nodeTypes The node's `@type` values, as the
     *                                         document wrote them.
     * @param array<string, string> $prefixes   The shape set's prefix map.
     */
    public function appliesTo(array $nodeTypes, ?string $nodeId, array $prefixes = []): bool
    {
        foreach ($nodeTypes as $type) {
            $expanded = self::expand($type, $prefixes);
            $candidates = [$type];
            if ($expanded !== null) {
                $candidates[] = $expanded;
            }

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $this->targetClasses, true)
                    || in_array($candidate, $this->targetClassIris, true)
                ) {
                    return true;
                }
            }

            // Last resort, and deliberately weak: a shape may name its target as a CURIE
            // while the document uses the absolute IRI, or the reverse, and comparing
            // local names closes that without requiring two prefix maps to agree. Two
            // vocabularies CAN reuse a local name, so this runs only after the exact and
            // expanded comparisons have failed — and it is the reason a shape authored in
            // one dialect is not silently skipped in the other.
            foreach ($candidates as $candidate) {
                foreach ($this->targetClassIris as $targetIri) {
                    if ($candidate !== $targetIri && self::localNameOf($candidate) === self::localNameOf($targetIri)) {
                        return true;
                    }
                }
            }
        }

        if ($nodeId !== null) {
            foreach ($this->targetNodes as $target) {
                if ($target === $nodeId) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function localNameOf(string $iri): string
    {
        $hash = strrpos($iri, '#');
        if ($hash !== false) {
            return substr($iri, $hash + 1);
        }

        $slash = strrpos($iri, '/');

        return $slash === false ? $iri : substr($iri, $slash + 1);
    }

    /** @return list<ShaclPropertyShape> */
    public function properties(): array
    {
        return $this->properties;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @return list<string> */
    public function ignoredProperties(): array
    {
        return $this->ignoredProperties;
    }

    /**
     * Expand a CURIE against a node's own `@context`.
     *
     * Returns null when it cannot, and the caller then compares the as-written form
     * instead. Refusing to guess is right: two prefix maps can bind `dfc-b` to
     * different IRIs, and picking one would make validation depend on which map
     * happened to be scanned first.
     *
     * @param array<string, string> $prefixes
     */
    public static function expand(string $term, array $prefixes = []): ?string
    {
        if (str_contains($term, '://')) {
            return $term;
        }

        $colon = strpos($term, ':');
        if ($colon === false || $colon === 0) {
            return null;
        }

        $prefix = substr($term, 0, $colon);
        if (!isset($prefixes[$prefix])) {
            return null;
        }

        return $prefixes[$prefix] . substr($term, $colon + 1);
    }
}