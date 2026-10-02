<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Policy;

/**
 * Applies a {@see PublicFieldPolicy} to a decoded DFC node map.
 *
 * ============================================================================
 * THIS IS THE MECHANISM, AND IT IS DELIBERATELY SMALL
 * ============================================================================
 * The brief was the MECHANISM and the DEFAULT, not the decisions. So this class does
 * exactly three things:
 *
 *   1. walk a node map;
 *   2. drop every key the policy withholds, recursively;
 *   3. REPORT what it dropped.
 *
 * Point 3 is not optional. A projection that silently removes a field leaves no
 * evidence, and the first symptom of a misconfigured allow-list is "the WebID has no
 * address on it" with nothing in the logs to explain it. {@link ProjectionResult}
 * carries a {@see VisibilityDecision} per dropped key so the caller can log it, and
 * the decisions carry predicate IRIs and reasons only — never a value — so an audit
 * record built from them cannot leak the data that was withheld.
 *
 * ============================================================================
 * WHY `@context` IS NEVER TOUCHED, EVEN UNDER `sh:closed`
 * ============================================================================
 * A JSON-LD document with no `@context` is not JSON-LD. Dropping it because it is
 * not on the allow-list would produce bytes that no DFC client can expand, and the
 * failure would look like a client bug. The same applies to `@id` and `@type`, which
 * are the node's identity.
 *
 * So keywords are classified {@see PredicateVisibility::STRUCTURAL} and are passed
 * through unchanged, and {@see PublicFieldPolicy} refuses to let a keyword onto
 * either list at all. See {@link PublicFieldPolicy} on the ETag consequence.
 *
 * ============================================================================
 * RECURSION, AND WHY THE DEPTH CAP IS THE ONLY BOUND
 * ============================================================================
 * A DFC Organization nests Address, PhoneNumber and Place node maps, so the projection
 * recurses. Depth is bounded at {@see MAX_DEPTH} and that bound is the ONLY bound —
 * there is no visited-node set, because PHP arrays have no stable identity:
 * `spl_object_id((object) $array)` mints a fresh object per call and PHP recycles those
 * handles, so an identity test on a temporary is either always-false (useless) or
 * spuriously-true (a false cycle report on an ordinary document).
 *
 * The residual is named rather than papered over: a caller-built array containing a PHP
 * REFERENCE cycle would recurse until it hit {@see MAX_DEPTH} and then be refused with
 * the depth message. That is still a refusal and not a hang, so the bound does its job;
 * only the diagnosis is coarser than a dedicated cycle detector would give. A document
 * from {@see \Civi\Dfc\V2\Validation\JsonLdParserInterface} cannot be cyclic at all,
 * because `json_decode` produces no references.
 *
 * Arrays of nodes (the plural form of a repeatable predicate) are walked with their
 * indexes preserved, because the index is part of the addressable location of a
 * withheld field and {@see \Civi\Dfc\V2\Policy\DefaultExportPolicy} promises a
 * report, not a shrug.
 *
 * ============================================================================
 * WHAT IT IS NOT
 * ============================================================================
 * Not a serialiser. It returns arrays; turning them into `application/ld+json` bytes
 * is lane-3's JSON-LD work (PRD-002 "Upstream refresh" item 6: the connector's
 * `import()`/export handle it). And it does NOT write the result back into an
 * `@context` — if a projected document no longer uses a prefix its `@context` declares,
 * that is harmless, because a JSON-LD context may declare more terms than a document
 * uses.
 *
 * @package Civi\Dfc
 */
final class ExportProjection
{
    /**
     * Real DFC nesting is Address -> Place -> geo:Point, three deep.
     *
     * Eight is generous; hitting it means the document is not a DFC resource, and
     * continuing would mean unbounded recursion on a client-supplied structure.
     */
    public const MAX_DEPTH = 8;

    private readonly PublicFieldPolicy $policy;

    public function __construct(PublicFieldPolicy $policy)
    {
        $this->policy = $policy;
    }

    public function policy(): PublicFieldPolicy
    {
        return $this->policy;
    }

    /**
     * Project one node map.
     *
     * @param array<string, mixed> $node
     */
    public function project(array $node): ProjectionResult
    {
        $withheld = [];

        $projected = $this->walk($node, $withheld, 0);

        return new ProjectionResult($projected, $withheld);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<VisibilityDecision> $withheld
     *
     * @return array<string, mixed>
     */
    private function walk(array $node, array &$withheld, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(sprintf(
                'A DFC document nests more than %d levels deep. The deepest real DFC structure is an Address '
                . 'holding a Place holding a geo:Point, so this is not a DFC resource and will not be '
                . 'projected.',
                self::MAX_DEPTH
            ));
        }

        $projected = [];

        foreach ($node as $key => $value) {
            if (!is_string($key)) {
                $projected[$key] = $value;

                continue;
            }

            $decision = $this->policy->visibilityOf($key);

            if (!$decision->isExported()) {
                $withheld[] = $decision;

                continue;
            }

            // ============================================================================
            // A JSON-LD KEYWORD'S VALUE IS NEVER A RESOURCE NODE
            // ============================================================================
            // `@context` maps prefix names to IRIs. Its entries are TERM DEFINITIONS,
            // not statements about the resource, so recursing into it would evaluate
            // `dfc-b` as if it were a predicate and drop the context — which would make
            // the document unexpandable and the failure look like a DFC client bug.
            if ($decision->isStructural()) {
                $projected[$key] = $value;

                continue;
            }

            if (is_array($value) && !array_is_list($value)) {
                $projected[$key] = $this->walk($value, $withheld, $depth + 1);

                continue;
            }

            // ====================================================================
            // A LIST OF NODE MAPS IS PROJECTED TOO
            // ====================================================================
            // A plural DFC predicate holds an array of node maps — an Organization's
            // addresses, its members. Passing the list through untouched would leave
            // every nested predicate unprojected, which is the fail-OPEN direction and
            // would defeat the allow-list for the whole nested graph.
            if (is_array($value) && array_is_list($value)) {
                $items = [];
                foreach ($value as $position => $item) {
                    $items[$position] = is_array($item) && !array_is_list($item)
                        ? $this->walk($item, $withheld, $depth + 1)
                        : $item;
                }

                $projected[$key] = $items;

                continue;
            }

            $projected[$key] = $value;
        }

        return $projected;
    }

    /**
     * Convenience for callers that only want the node.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    public function projectNode(array $node): array
    {
        return $this->project($node)->projected();
    }
}