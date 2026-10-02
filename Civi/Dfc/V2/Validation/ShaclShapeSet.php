<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * A parsed set of SHACL node shapes, plus the prefix map they were written against.
 *
 * ============================================================================
 * AN EMPTY SET IS A FAILURE, NOT A NEUTRAL OUTCOME
 * ============================================================================
 * `isEmpty()` exists because {@see ShaclValidationStage} has to distinguish three
 * states and the difference between them is the whole fail-closed argument:
 *
 *   - shapes loaded and the document violates none -> pass;
 *   - shapes loaded and the document violates some -> 422;
 *   - shapes loaded but there are NONE -> a deployment fault, 500.
 *
 * A validator that treats the third like the first accepts every document. So
 * {@see ShaclValidationStage} checks {@see isEmpty()} before it checks anything else,
 * and this class makes the state reachable and obvious rather than implicit.
 *
 * ============================================================================
 * THE PREFIX MAP IS KEPT BECAUSE MATCHING NEEDS IT
 * ============================================================================
 * {@see ShaclNodeShape::appliesTo()} compares a document's `@type` against a shape's
 * targets in both written and expanded form, and "expanded" needs the prefixes the
 * shape file declared. The DOCUMENT's own `@context` is a separate map; the validator
 * uses both and never conflates them.
 *
 * @package Civi\Dfc
 */
final class ShaclShapeSet
{
    /** @var list<ShaclNodeShape> */
    private readonly array $shapes;

    /** @var array<string, string> */
    private readonly array $prefixes;

    /** @var list<string> */
    private readonly array $ignoredAnnotations;

    /**
     * @param list<ShaclNodeShape>  $shapes
     * @param array<string, string> $prefixes
     * @param list<string>          $ignoredAnnotations Prefixes whose predicates were
     *                                                  read and discarded.
     */
    public function __construct(array $shapes, array $prefixes = [], array $ignoredAnnotations = [])
    {
        $this->shapes = array_values($shapes);
        $this->prefixes = $prefixes;
        $this->ignoredAnnotations = array_values($ignoredAnnotations);
    }

    /**
     * Concatenate several parsed graphs into one set.
     *
     * Upstream ships a business and a technical graph; a document is validated
     * against both, and reporting violations from both in one 422 is what a client
     * needs.
     *
     * @param list<self> $sets
     */
    public static function merge(array $sets): self
    {
        $shapes = [];
        $prefixes = [];
        $ignored = [];

        foreach ($sets as $set) {
            foreach ($set->shapes as $shape) {
                $shapes[] = $shape;
            }

            // First declaration wins, and a later graph cannot rebind a prefix a
            // previous one used: rebinding would make earlier shapes evaluate against
            // IRIs they were not written against.
            $prefixes += $set->prefixes;
            $ignored = [...$ignored, ...$set->ignoredAnnotations];
        }

        return new self($shapes, $prefixes, $ignored);
    }

    /** @return list<ShaclNodeShape> */
    public function shapes(): array
    {
        return $this->shapes;
    }

    /** @return array<string, string> */
    public function prefixes(): array
    {
        return $this->prefixes;
    }

    /**
     * Annotation predicates that were read and discarded, e.g. `sh:message`.
     *
     * Surfaced so a shape author can tell the difference between "my `sh:message` is
     * being ignored on purpose" and "my `sh:or` was refused". See
     * {@see \Civi\Dfc\V2\Policy\PolicyDoc} for why messages are dropped.
     *
     * @return list<string>
     */
    public function ignoredAnnotations(): array
    {
        return array_values(array_unique($this->ignoredAnnotations));
    }

    public function isEmpty(): bool
    {
        return $this->shapes === [];
    }

    public function count(): int
    {
        return count($this->shapes);
    }
}