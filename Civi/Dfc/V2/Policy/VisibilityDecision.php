<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Policy;

/**
 * One predicate's verdict, with the reason.
 *
 * ============================================================================
 * WHY THE PREDICATE IS ECHOED AND YET STILL VALIDATED
 * ============================================================================
 * A predicate is an IRI or a CURIE that came from a DFC document, so it is
 * client-supplied text and {@see \Civi\Dfc\V2\Policy\PublicFieldPolicy} validates it
 * on the way in. This class does not re-validate: it only receives terms that came
 * out of {@see PublicFieldPolicy::visibilityOf()}, which is the single gate.
 *
 * That gate is why {@see ExportProjection} can put these decisions straight into an
 * audit record without another pass of sanitising, and why `withheld()` on the
 * projection yields values that are safe to log — they are predicate IRIs and
 * reasons, never a value the document carried.
 *
 * @package Civi\Dfc
 */
final class VisibilityDecision implements \JsonSerializable
{
    private readonly string $predicate;

    private readonly PredicateVisibility $visibility;

    private readonly VisibilityReason $reason;

    public function __construct(
        string $predicate,
        PredicateVisibility $visibility,
        VisibilityReason $reason
    ) {
        $this->predicate = $predicate;
        $this->visibility = $visibility;
        $this->reason = $reason;
    }

    public function predicate(): string
    {
        return $this->predicate;
    }

    public function visibility(): PredicateVisibility
    {
        return $this->visibility;
    }

    public function reason(): VisibilityReason
    {
        return $this->reason;
    }

    public function isExported(): bool
    {
        return $this->visibility !== PredicateVisibility::WITHHELD;
    }

    /**
     * Does this predicate carry a value, or is it syntax?
     *
     * A structural key has no value in the data sense, so a caller counting
     * "fields a client received" must not count it.
     */
    public function isStructural(): bool
    {
        return $this->visibility === PredicateVisibility::STRUCTURAL;
    }

    /**
     * @return array{predicate: string, visibility: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'predicate' => $this->predicate,
            'visibility' => $this->visibility->value,
            'reason' => $this->reason->value,
        ];
    }

    /**
     * @return array{predicate: string, visibility: string, reason: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}