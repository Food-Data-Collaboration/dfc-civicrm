<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

use Civi\Dfc\V2\Controller\Error\DfcPath;
use Civi\Dfc\V2\Controller\Error\ValidationDiagnostic;
use Civi\Dfc\V2\Controller\Error\ValidationIssue;

/**
 * Turns a SHACL constraint component into sa-005's diagnostic vocabulary.
 *
 * ============================================================================
 * WHY THIS IS A MAPPING AND NOT A NEW VIOLATION TYPE
 * ============================================================================
 * Because {@see ValidationDiagnostic} is the single wire format for everything that
 * can be wrong with a submitted DFC document: PRD-002 §4.12/§4.15 and CP-1 both
 * require the failure to be actionable and structured, and
 * {@see \Civi\Dfc\V2\Controller\Error\ValidationIssue} is a CLOSED enum chosen so
 * that a violation's human-readable text is a literal rather than a message someone
 * interpolated.
 *
 * A `ShaclViolation` type alongside it would be a second wire format with a second
 * set of shapes, and the client's error handling would have to know which one it got.
 * So the output of every validator on this surface is
 * `list<ValidationDiagnostic>` and only that.
 *
 * ============================================================================
 * WHERE THE MACHINE-READABLE PART GOES
 * ============================================================================
 * `ValidationDiagnostic` has three caller-supplied slots — path, predicate,
 * constraint — and one fixed text. The mapping puts the SHACL constraint component in
 * the *predicate* position only when the predicate itself is unusable, and puts the
 * constraint's NAME AND BOUND in the `constraint` token, e.g.:
 *
 *     sh:MinCountConstraintComponent   ->  issue REQUIRED,    constraint "minCount>=1"
 *     sh:MaxCountConstraintComponent   ->  issue TOO_LARGE,  constraint "maxCount<=1"
 *     sh:DatatypeConstraintComponent    ->  issue TYPE_MISMATCH, constraint "datatype:string"
 *     sh:NodeKindConstraintComponent    ->  issue SHAPE,      constraint "nodeKind=IRI"
 *     sh:InConstraintComponent          ->  issue NOT_A_MEMBER, constraint "in"
 *     sh:PatternConstraintComponent     ->  issue MALFORMED,   constraint "pattern"
 *     sh:ClassConstraintComponent       ->  issue TYPE_MISMATCH, constraint "class:dfc-b:Address"
 *     sh:ClosedConstraintComponent      ->  issue UNKNOWN_PREDICATE, constraint "closed"
 *
 * `ValidationDiagnostic::CONSTRAINT_PATTERN` allows `[A-Za-z0-9_.:<>=!-]`, which
 * covers all of those. What is deliberately NOT carried across is SHACL's
 * `sh:message`: it is free text from a file an administrator controls, and
 * {@see ValidationIssue::title()} is the only human-readable text this layer emits.
 *
 * ============================================================================
 * UNKNOWN COMPONENTS MAP TO `MALFORMED`, NEVER TO A PASS
 * ============================================================================
 * A component this table has no row for produces a diagnostic with
 * {@see ValidationIssue::MALFORMED}. It does NOT produce nothing. A validator that
 * dropped the violations it could not classify would be the fail-open failure again,
 * one layer down.
 *
 * @package Civi\Dfc
 */
final class ShaclConstraintMapper
{
    private function __construct()
    {
        // Static mapping.
    }

    /**
     * The {@see ValidationIssue} for a SHACL constraint component.
     *
     * @param string $componentIri  An absolute IRI, or a local name.
     * @param string $componentName  The SHACL component name, e.g. `MinCountConstraintComponent`.
     *                                Accepted instead of the IRI for callers that
     *                                have already reduced it.
     */
    public static function toIssue(string $componentIri, ?string $componentName = null): ValidationIssue
    {
        $name = $componentName ?? self::componentNameOf($componentIri);

        return match ($name) {
            'MinCountConstraintComponent' => ValidationIssue::REQUIRED,
            'MaxCountConstraintComponent' => ValidationIssue::TOO_LARGE,
            'MaxLengthConstraintComponent' => ValidationIssue::TOO_LARGE,
            'MinLengthConstraintComponent' => ValidationIssue::MALFORMED,
            'DatatypeConstraintComponent' => ValidationIssue::TYPE_MISMATCH,
            'NodeKindConstraintComponent' => ValidationIssue::SHAPE,
            'ClassConstraintComponent' => ValidationIssue::TYPE_MISMATCH,
            'InConstraintComponent' => ValidationIssue::NOT_A_MEMBER,
            'PatternConstraintComponent' => ValidationIssue::MALFORMED,
            'HasValueConstraintComponent' => ValidationIssue::REQUIRED,
            'ClosedConstraintComponent' => ValidationIssue::UNKNOWN_PREDICATE,
            'LanguageInConstraintComponent' => ValidationIssue::MALFORMED,
            'UniqueLangConstraintComponent' => ValidationIssue::SHAPE,
            'LessThanConstraintComponent',
            'LessThanOrEqualsConstraintComponent' => ValidationIssue::MALFORMED,
            default => ValidationIssue::MALFORMED,
        };
    }

    /**
     * The machine-readable constraint token.
     *
     * @param string               $componentName SHACL component name.
     * @param ShaclPropertyShape|null $shape      The constraint that failed, for the
     *                                             numeric and vocabulary bounds.
     */
    public static function toConstraint(string $componentName, ?ShaclPropertyShape $shape = null): string
    {
        return match ($componentName) {
            'MinCountConstraintComponent' => 'minCount>=' . (string) ($shape?->minCount() ?? 1),
            'MaxCountConstraintComponent' => 'maxCount<=' . (string) ($shape?->maxCount() ?? 1),
            'MaxLengthConstraintComponent' => 'maxLength<=' . (string) ($shape?->maxLength() ?? 1),
            'MinLengthConstraintComponent' => 'minLength>=' . (string) ($shape?->minLength() ?? 1),
            'DatatypeConstraintComponent' => 'datatype:' . self::tokenSafe((string) ($shape?->datatype() ?? 'any')),
            'NodeKindConstraintComponent' => 'nodeKind=' . self::tokenSafe((string) ($shape?->nodeKind() ?? 'any')),
            'ClassConstraintComponent' => 'class:' . self::tokenSafe(implode(',', $shape?->classes() ?? [])),
            'InConstraintComponent' => 'in',
            'PatternConstraintComponent' => 'pattern',
            'HasValueConstraintComponent' => 'hasValue',
            'ClosedConstraintComponent' => 'closed',
            default => self::tokenSafe(self::tokenSafe($componentName)),
        };
    }

    /**
     * Build the diagnostic for one violation.
     *
     * @param string                    $componentName SHACL component name.
     * @param string                    $predicate     The shape's `sh:path`, as
     *                                                written. Used for the
     *                                                diagnostic's predicate slot.
     * @param list<string>              $pathTokens    Tokens walked in the submitted
     *                                                document, root first.
     * @param ShaclPropertyShape|null   $shape         The constraint, for the bound.
     */
    public static function toDiagnostic(
        string $componentName,
        string $predicate,
        array $pathTokens,
        ?ShaclPropertyShape $shape = null
    ): ValidationDiagnostic {
        return new ValidationDiagnostic(
            self::pathFor($pathTokens, $predicate),
            self::tokenSafe($predicate),
            self::toIssue(self::SHACL_NS . $componentName, $componentName),
            self::toConstraint($componentName, $shape)
        );
    }

    private const SHACL_NS = 'http://www.w3.org/ns/shacl#';

    /**
     * Build a {@see DfcPath} that is guaranteed constructible.
     *
     * ============================================================================
     * WHY A PATH CAN FAIL TO BE BUILT AND WHAT HAPPENS THEN
     * ============================================================================
     * {@see DfcPath} accepts only NCName-like and CURIE-like tokens, which excludes
     * absolute IRIs — and an absolute IRI is exactly what a document key looks like
     * when it was produced by an expander rather than a compactor. A violation at
     * such a key cannot be reported at that key.
     *
     * So the path degrades, in two documented steps: unusable tokens are replaced by
     * the SHAPE's predicate (which is what the client has to fix, and is a CURIE in
     * every upstream DFC shape), and if even that is unusable the path becomes the
     * root. The degradation is a narrowing of precision, never a change of meaning:
     * the predicate slot always carries the same information.
     */
    public static function pathFor(array $pathTokens, string $predicate): DfcPath
    {
        $safe = [];
        foreach ($pathTokens as $token) {
            $safe[] = self::isTokenSafe($token) ? $token : self::tokenSafe($predicate);
        }

        try {
            return DfcPath::fromList($safe);
        } catch (\InvalidArgumentException $unusable) {
            return DfcPath::root();
        }
    }

    /**
     * Mirrors {@see DfcPath}'s token grammar so the mapper can decide without
     * provoking an exception for every absolute IRI in a document.
     */
    public static function isTokenSafe(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\-]{0,127}$/', $token) === 1
            || preg_match(
                '/^[A-Za-z][A-Za-z0-9.\-]{0,63}:[A-Za-z0-9_][A-Za-z0-9_.\-]{0,127}$/',
                $token
            ) === 1;
    }

    /**
     * Reduce a term to something {@see ValidationDiagnostic} accepts.
     *
     * A predicate is echoed to the client, so it must fit the short-token grammar.
     * An absolute IRI does not, so its local name is used — which is what a
     * developer reads anyway — and a term with no usable fragment falls back to
     * `predicate`.
     */
    public static function tokenSafe(string $term): string
    {
        if (self::isTokenSafe($term)) {
            return $term;
        }

        $local = TurtleShapeParser::localName($term);

        return self::isTokenSafe($local) ? $local : 'predicate';
    }

    private static function componentNameOf(string $componentIri): string
    {
        return TurtleShapeParser::localName($componentIri);
    }
}