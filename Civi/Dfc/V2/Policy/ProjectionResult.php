<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Policy;

/**
 * What a projection kept, and what it dropped — and why.
 *
 * ============================================================================
 * WHY THE WITHHELD LIST IS PART OF THE RESULT RATHER THAN A LOG CALL
 * ============================================================================
 * Because the caller of {@see ExportProjection} is a route, and a route that logs is
 * a route whose logging policy differs from the extension's. Returning the decisions
 * makes the REPORTING decision the caller's, while keeping the DECISION itself this
 * layer's — and it means the audit trail is available to the diagnostics endpoint
 * (sa-014) and to a conformance test, neither of which is a request.
 *
 * ============================================================================
 * WHY THE PROJECTED NODE IS NOT SERIALISED HERE
 * ============================================================================
 * An ETag is a claim about bytes, and this class does not produce bytes. The tag is
 * computed by {@see \Civi\Dfc\V2\Controller\Http\HeaderPolicy::respond()} over
 * whatever {@see \Civi\Dfc\V2\Controller\Http\ETag::strong()} is handed, and
 * {@see \Civi\Dfc\V2\Controller\Serialisation\CanonicalJson::encode()} is what makes
 * the encoding deterministic.
 *
 * This layer contributes the projected ARRAY, which is the last point at which a
 * field can be removed — which is exactly why the projection has to happen here and
 * not in the serialiser. See {@see PublicFieldPolicy}'s docblock on the per-caller
 * ETag consequence.
 *
 * ============================================================================
 * `hasWithheld()` IS THE ASSERTION A TEST MAKES
 * ============================================================================
 * "The projection changed something" and "the projection withheld nothing" are
 * different facts, and a test that only asserts the second would pass against a
 * projection that drops the entire document.
 *
 * @package Civi\Dfc
 */
final class ProjectionResult implements \JsonSerializable
{
    /** @var array<string, mixed> */
    private readonly array $projected;

    /** @var list<VisibilityDecision> */
    private readonly array $withheld;

    /**
     * @param array<string, mixed>   $projected
     * @param list<VisibilityDecision> $withheld
     */
    public function __construct(array $projected, array $withheld = [])
    {
        $this->projected = $projected;
        $this->withheld = array_values($withheld);
    }

    /**
     * @return array<string, mixed>
     */
    public function projected(): array
    {
        return $this->projected;
    }

    /**
     * One decision per dropped key, in document order.
     *
     * @return list<VisibilityDecision>
     */
    public function withheld(): array
    {
        return $this->withheld;
    }

    /**
     * The dropped predicates, without the reasons. For a compact audit field.
     *
     * @return list<string>
     */
    public function withheldPredicates(): array
    {
        return array_values(array_map(
            static fn (VisibilityDecision $decision): string => $decision->predicate(),
            $this->withheld
        ));
    }

    /**
     * @return list<VisibilityDecision>
     */
    public function explicitlyDenied(): array
    {
        return array_values(array_filter(
            $this->withheld,
            static fn (VisibilityDecision $decision): bool => $decision->reason() === VisibilityReason::EXPLICITLY_DENIED
        ));
    }

    public function hasWithheld(): bool
    {
        return $this->withheld !== [];
    }

    /**
     * Did the projection change anything at all?
     *
     * A document made only of JSON-LD keywords survives a default-deny projection
     * unchanged, and this returns false — which is the correct answer, not a bug.
     */
    public function wasChanged(): bool
    {
        return $this->hasWithheld();
    }

    /**
     * The audit record. Predicate IRIs, decisions and reasons; no values.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'withheld' => array_map(
                static fn (VisibilityDecision $decision): array => $decision->toArray(),
                $this->withheld
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}