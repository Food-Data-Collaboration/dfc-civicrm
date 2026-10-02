<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Ldp;

/**
 * A bounded read port over a container's membership.
 *
 * ============================================================================
 * WHY THE INTERFACE HAS NO `listAll()`
 * ============================================================================
 * PRD-002 §10 lists "LDP containment at scale" as a learning goal and asks the
 * question directly: "whether `ldp:contains` can be generated from bounded CiviCRM
 * queries without materializing unbounded result sets". The answer cannot be left
 * to the implementation of whoever writes the query, so it is answered here, in
 * the port:
 *
 *   - {@see page()} takes a limit and returns at most that many members. There is
 *     no method that returns "the members" without a bound, so an unbounded
 *     materialisation is not expressible against this interface;
 *   - {@see count()} is separate and separately optional, because a COUNT query
 *     over a large CiviCRM table is cheap but not free, and some deployments will
 *     legitimately decline to pay for it. `?total` being unknown is a state the
 *     page model handles explicitly rather than a failure.
 *
 * An implementation backed by APIv4 translates `page()` into a LIMIT/OFFSET
 * query. An implementation backed by anything else must do the same. There is no
 * third option available through this API.
 *
 * ============================================================================
 * ORDERING IS THE IMPLEMENTER'S RESPONSIBILITY, AND MUST BE STABLE
 * ============================================================================
 * Paging without a total order is a bug factory: OFFSET pagination over an
 * unordered query returns duplicates and gaps between pages, and the client sees
 * a member twice and never sees another at all. So this interface's contract
 * requires a *deterministic* order and says so, even though it cannot enforce it.
 * The order must be a function of the data only — the opaque DFC identifier, in
 * practice — never of a display name, never of a join order that a schema change
 * can reorder. A display-name sort would also break the second stability
 * requirement in {@see LdpBasicContainer}.
 *
 * @package Civi\Dfc
 */
interface MembershipSourceInterface
{
    /**
     * At most $limit member URIs, starting at $offset in a stable order.
     *
     * Returning FEWER than $limit means the end of the collection — unless
     * {@see count()} says otherwise, in which case the collection changed
     * underneath the reader and the page is simply short. Both are handled by
     * {@see ContainerPage}; neither is an error here.
     *
     * @return list<string> Absolute http(s) URIs. Duplicates are a defect; if one
     *         is impossible to avoid at this layer, {@see ContainerPage} will
     *         refuse the page rather than publish it twice.
     */
    public function page(string $containerUri, int $limit, int $offset): array;

    /**
     * Total membership, or null when it is unknown or too expensive to know.
     *
     * Returning null is legitimate and is not an error: {@see ContainerPage}
     * switches to "a next page exists iff this one was full".
     */
    public function count(string $containerUri): ?int;
}
