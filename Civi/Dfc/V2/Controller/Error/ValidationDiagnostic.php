<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

/**
 * One structured statement about one place in a submitted DFC document.
 *
 * The wire shape is the whole point of this class: a client gets
 *
 *     {"path": "/organizations/0/name", "predicate": "dfc-b:name",
 *      "issue": "required", "constraint": "minCount>=1", "detail": "..."}
 *
 * and can act on it without parsing prose. PRD-002 §4.12/§4.15 and CP-1 both
 * require the failure to be actionable and structured; a single sentence
 * carrying all of it is neither.
 *
 * EVERY CALLER-SUPPLIED PART IS VALIDATED
 *   - the location is a {@see DfcPath}, which cannot be built from a bare string;
 *   - the predicate and the constraint are restricted to short token shapes with
 *     no whitespace, no separators and no control characters;
 *   - the explanation is {@see ValidationIssue::title()}, a literal.
 *
 * There is no `observedValue` / `actualValue` field, and that omission is
 * deliberate: it is the field every validation framework grows and the one that
 * leaks submitted personal data straight back out of a log or an error body.
 *
 * @package Civi\Dfc
 */
final class ValidationDiagnostic implements \JsonSerializable
{
    /** Vocabulary-ish identifier: `dfc-b:name`, `name`, `legalName`. */
    private const PREDICATE_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.\-]{0,127}(?::[A-Za-z0-9_][A-Za-z0-9_.\-]{0,127})?$/';

    /**
     * A short machine constraint reference: `minCount>=1`, `maxLength<=255`.
     *
     * Comparison operators are allowed because a constraint without one is not a
     * constraint, and the whole point of the field is to be checkable by a client
     * rather than read as prose.
     */
    private const CONSTRAINT_PATTERN = '/^[A-Za-z0-9_.:<>=!\-]{1,64}$/';

    private readonly DfcPath $path;

    private readonly string $predicate;

    private readonly ValidationIssue $issue;

    private readonly ?string $constraint;

    public function __construct(
        DfcPath $path,
        string $predicate,
        ValidationIssue $issue,
        ?string $constraint = null
    ) {
        $this->path = $path;

        if (preg_match(self::PREDICATE_PATTERN, $predicate) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A diagnostic predicate must be a DFC predicate name or CURIE such as "dfc-b:name" or "name". '
                . 'Got "%s". Validation diagnostics are echoed to clients, so the predicate slot accepts a '
                . 'name and nothing else.',
                $predicate
            ));
        }

        $this->predicate = $predicate;
        $this->issue = $issue;

        if ($constraint === null) {
            $this->constraint = null;

            return;
        }

        $trimmed = trim($constraint);
        if (preg_match(self::CONSTRAINT_PATTERN, $trimmed) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A diagnostic constraint must be a short machine token such as "minCount>=1". Got "%s".',
                $constraint
            ));
        }

        $this->constraint = $trimmed;
    }

    public function path(): DfcPath
    {
        return $this->path;
    }

    public function predicate(): string
    {
        return $this->predicate;
    }

    public function issue(): ValidationIssue
    {
        return $this->issue;
    }

    public function constraint(): ?string
    {
        return $this->constraint;
    }

    /**
     * @return array{path: string, predicate: string, issue: string, constraint?: string, detail: string}
     */
    public function toArray(): array
    {
        $diagnostic = [
            'path' => $this->path->render(),
            'predicate' => $this->predicate,
            'issue' => $this->issue->value,
            'detail' => $this->issue->title(),
        ];

        if ($this->constraint !== null) {
            // Inserted before `detail` so the machine-readable fields stay adjacent
            // in the encoded order CanonicalJson will keep.
            $diagnostic = [
                'path' => $diagnostic['path'],
                'predicate' => $diagnostic['predicate'],
                'issue' => $diagnostic['issue'],
                'constraint' => $this->constraint,
                'detail' => $diagnostic['detail'],
            ];
        }

        return $diagnostic;
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
