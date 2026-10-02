<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

use Civi\Dfc\V2\Controller\Error\ValidationDiagnostic;

/**
 * Validates a decoded DFC document against a parsed shape set.
 *
 * ============================================================================
 * WHY THE SIGNATURE TAKES A NODE MAP AND NOT A DATA GRAPH SERIALISATION
 * ============================================================================
 * A standard SHACL processor takes the data as RDF — Turtle, or JSON-LD serialised to
 * RDF. This interface takes the decoded node map instead, and the reason is that this
 * layer must not depend on a JSON-LD serialiser: PRD-002 §1 rules out reimplementing
 * JSON-LD, and lane-3 owns expansion, so nothing here can turn a node map back into a
 * graph of triples.
 *
 * A real processor behind this interface therefore receives a pre-serialised graph,
 * and the conversion belongs in THAT implementation — where the JSON-LD dependency
 * already is. {@see LocalShaclValidator} needs nothing, which is what keeps the unit
 * suite hermetic.
 *
 * ============================================================================
 * THE RETURN TYPE IS NOT NEGOTIABLE
 * ============================================================================
 * `list<ValidationDiagnostic>`, never a validator-specific violation type. See
 * {@see ShaclConstraintMapper} on why: one wire format, one closed issue vocabulary,
 * one set of no-leak guarantees.
 *
 * ============================================================================
 * AN EMPTY RETURN MEANS "NO VIOLATIONS", NOT "COULD NOT CHECK"
 * ============================================================================
 * So a validator that cannot check MUST throw, and
 * {@see ShaclValidationStage} turns that into a 500. {@see LocalShaclValidator}
 * throws {@see ShaclShapeUnavailableException} when the shape set is empty. Returning
 * `[]` is a claim, and this interface requires that claim to be earned.
 *
 * @package Civi\Dfc
 */
interface ShaclValidatorInterface
{
    /**
     * @param ShaclShapeSet     $shapes   The parsed shapes.
     * @param array<string, mixed> $dataGraph The decoded JSON-LD node map.
     *
     * @return list<ValidationDiagnostic> Empty only when the document really satisfies
     *                                    the shapes.
     *
     * @throws ShaclShapeUnavailableException when validation could not be performed.
     */
    public function validate(ShaclShapeSet $shapes, array $dataGraph): array;
}