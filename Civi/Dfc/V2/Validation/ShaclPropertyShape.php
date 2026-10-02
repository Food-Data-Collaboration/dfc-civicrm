<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * One `sh:property` constraint group, in the closed subset this layer implements.
 *
 * ============================================================================
 * WHY THIS IS AN EXPLICIT SET OF TYPED FIELDS AND NOT A `constraintBag`
 * ============================================================================
 * Because a bag would let the SHACL subset SILENTLY ignore a constraint it does not
 * understand — which is a fail-open validator. `sh:or`, `sh:not`, `sh:node`,
 * `sh:qualifiedValueShape` and the rest either appear as a named typed field or
 * {@see TurtleShapeParser} refuses the shape entirely. A shape the upstream DFC
 * files use that this layer cannot evaluate is a startup failure, not a document that
 * passes.
 *
 * ============================================================================
 * WHAT THE SUBSET IS, STATED PLAINLY
 * ============================================================================
 *   sh:path         the predicate
 *   sh:minCount     a required predicate
 *   sh:maxCount     a cardinality ceiling
 *   sh:datatype     the JSON scalar type
 *   sh:nodeKind     IRI / Literal / BlankNode
 *   sh:class        the value must be a node of this class
 *   sh:in           a closed vocabulary
 *   sh:pattern      a regular expression over a string value
 *   sh:flags        regular expression flags for sh:pattern
 *   sh:minLength    a string length floor
 *   sh:maxLength    a string length ceiling
 *   sh:hasValue     a required member value
 *   sh:severity     Violation (the only severity that fails a request)
 *
 * Everything else is either an annotation that carries no validation semantics
 * (`sh:message`, `sh:description`, `sh:name`, and the annotation vocabularies) or an
 * unimplemented constraint, and the parser treats the two differently.
 *
 * ============================================================================
 * WHY `sh:message` IS DROPPED RATHER THAN CARRIED
 * ============================================================================
 * Because it is free text from a file, and this layer's whole error contract is that
 * the only human-readable text a client sees comes from a closed enum
 * ({@see \Civi\Dfc\V2\Controller\Error\ValidationIssue::title()}). Carrying shape
 * messages would mean a second, unvalidated channel into a response body, reachable
 * from a file in a directory an administrator controls. The MACHINE-readable part —
 * which constraint failed and with what bound — is preserved in the `constraint`
 * token instead, which is what a client can actually act on.
 *
 * @package Civi\Dfc
 */
final class ShaclPropertyShape
{
    private const SUPPORTED_SEVERITIES = ['Violation', 'Warning', 'Info'];

    private readonly string $path;

    private readonly string $pathIri;

    private readonly ?int $minCount;

    private readonly ?int $maxCount;

    private readonly ?string $datatype;

    private readonly ?string $nodeKind;

    /** @var list<string> */
    private readonly array $classes;

    /** @var list<string> */
    private readonly array $vocabulary;

    private readonly ?string $pattern;

    private readonly ?string $patternFlags;

    private readonly ?int $minLength;

    private readonly ?int $maxLength;

    private readonly ?string $hasValue;

    private readonly string $severity;

    /**
     * @param list<string> $classes    As written in the shape.
     * @param list<string> $vocabulary As written in the shape.
     */
    public function __construct(
        string $path,
        string $pathIri,
        ?int $minCount = null,
        ?int $maxCount = null,
        ?string $datatype = null,
        ?string $nodeKind = null,
        array $classes = [],
        array $vocabulary = [],
        ?string $pattern = null,
        ?string $patternFlags = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $hasValue = null,
        string $severity = 'Violation'
    ) {
        if (trim($path) === '') {
            throw new \InvalidArgumentException('A SHACL property shape needs a non-empty sh:path.');
        }

        if ($minCount !== null && $minCount < 0) {
            throw new \InvalidArgumentException('sh:minCount may not be negative.');
        }

        if ($maxCount !== null && $maxCount < 0) {
            throw new \InvalidArgumentException('sh:maxCount may not be negative.');
        }

        if ($minCount !== null && $maxCount !== null && $minCount > $maxCount) {
            throw new \InvalidArgumentException(sprintf(
                'A SHACL property shape has sh:minCount %d above sh:maxCount %d, so every document would '
                . 'violate it. That is a defective shape, not a document.',
                $minCount,
                $maxCount
            ));
        }

        if ($nodeKind !== null
            && !in_array($nodeKind, ['IRI', 'Literal', 'BlankNode', 'BlankNodeOrIRI', 'BlankNodeOrLiteral', 'IRIOrLiteral'], true)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'sh:nodeKind must be one of IRI, Literal, BlankNode, BlankNodeOrIRI, BlankNodeOrLiteral or '
                . 'IRIOrLiteral. Got "%s".',
                $nodeKind
            ));
        }

        if (!in_array($severity, self::SUPPORTED_SEVERITIES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'sh:severity must be one of %s. Got "%s".',
                implode(', ', self::SUPPORTED_SEVERITIES),
                $severity
            ));
        }

        if ($pattern !== null && @preg_match(self::delimit($pattern, $patternFlags ?? ''), '') === false) {
            throw new \InvalidArgumentException(sprintf(
                'sh:pattern "%s" is not a usable regular expression in this PHP build.',
                $pattern
            ));
        }

        $this->path = trim($path);
        $this->pathIri = trim($pathIri);
        $this->minCount = $minCount;
        $this->maxCount = $maxCount;
        $this->datatype = $datatype;
        $this->nodeKind = $nodeKind;
        $this->classes = array_values($classes);
        $this->vocabulary = array_values($vocabulary);
        $this->pattern = $pattern;
        $this->patternFlags = $patternFlags;
        $this->minLength = $minLength;
        $this->maxLength = $maxLength;
        $this->hasValue = $hasValue;
        $this->severity = $severity;
    }

    /**
     * The predicate as the shape wrote it — a CURIE in upstream DFC shapes, which is
     * also what the submitted document uses.
     */
    public function path(): string
    {
        return $this->path;
    }

    /** The same predicate expanded to its absolute IRI. */
    public function pathIri(): string
    {
        return $this->pathIri;
    }

    /**
     * Does this shape's predicate appear under either form in a document?
     *
     * Compaction is lossy in both directions: a document may write `dfc-b:name` while
     * the shape writes the absolute IRI, or vice versa. Comparing both forms is what
     * lets a shape file be authored either way.
     */
    public function matchesPredicate(string $documentKey): bool
    {
        return $documentKey === $this->path || $documentKey === $this->pathIri;
    }

    public function minCount(): ?int
    {
        return $this->minCount;
    }

    public function maxCount(): ?int
    {
        return $this->maxCount;
    }

    public function datatype(): ?string
    {
        return $this->datatype;
    }

    public function nodeKind(): ?string
    {
        return $this->nodeKind;
    }

    /** @return list<string> */
    public function classes(): array
    {
        return $this->classes;
    }

    /** @return list<string> */
    public function vocabulary(): array
    {
        return $this->vocabulary;
    }

    public function pattern(): ?string
    {
        return $this->pattern;
    }

    public function minLength(): ?int
    {
        return $this->minLength;
    }

    public function maxLength(): ?int
    {
        return $this->maxLength;
    }

    public function hasValue(): ?string
    {
        return $this->hasValue;
    }

    /**
     * Only a Violation fails a request.
     *
     * A shape marked `sh:Warning` or `sh:Info` still produces a diagnostic — a client
     * that wants to know should be told — but {@see LocalShaclValidator} does not
     * count it towards the failure, because a *warning* that blocks a write is not a
     * warning. Upstream DFC shapes are generated by `scripts/add_enum_shacl.py` and
     * do use severities, so this distinction is real rather than theoretical.
     */
    public function isViolation(): bool
    {
        return $this->severity === 'Violation';
    }

    public function severity(): string
    {
        return $this->severity;
    }

    /**
     * Wrap a bare SHACL regular expression in delimiters, since the shape carries the
     * pattern without them and PCRE requires them.
     */
    public static function delimit(string $pattern, string $flags): string
    {
        return '/' . str_replace('/', '\\/', $pattern) . '/' . $flags;
    }
}