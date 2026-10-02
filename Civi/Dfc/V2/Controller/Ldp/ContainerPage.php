<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Ldp;

use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ProtocolError;
use Civi\Dfc\V2\Controller\Http\LinkRelation;

/**
 * One bounded page of a container's membership, with its position in the
 * collection and the navigation it implies.
 *
 * ============================================================================
 * THE FOUR INVARIANTS, AND WHY EACH ONE EXISTS
 * ============================================================================
 * 1. BOUNDED. `count($members) <= $limit`, always, checked in the constructor.
 *    A page that exceeds its limit is a bug in the source, and publishing it
 *    would defeat the entire reason this class exists (PRD-002 §10).
 *
 * 2. NON-NEGATIVE OFFSET. Clamped, not rejected. See "CLAMP, DON'T REJECT" below.
 *
 * 3. NO DUPLICATE MEMBER. Two entries with the same URI in one page means the
 *    source ordered its query by something unstable, or joined twice. Refusing the
 *    page with `membership_inconsistent` is the honest response: a client cannot
 *    distinguish "member 5 of 5" from "member 5 of 5, twice", and silently
 *    de-duplicating would also make the page SHORTER than the limit while the
 *    source believed it was full — corrupting the paging arithmetic for every
 *    subsequent page.
 *
 * 4. MEMBERS ARE ABSOLUTE http(s) URIs WITHOUT FRAGMENTS. A fragment in a
 *    containment triple would name a subject rather than a resource, which breaks
 *    the LDP containment invariant that the URI hierarchy encodes.
 *
 * ============================================================================
 * CLAMP, DON'T REJECT — AND THE ONE CASE THAT IS A 400
 * ============================================================================
 * A requested limit or offset outside the permitted range is CLAMPED, not
 * refused:
 *
 *   - `limit=10000` → clamped to {@see maxLimit()}. RFC 9110 lets a server return
 *     fewer results than asked for, so clamping is conformant, and refusing would
 *     make an over-eager client unusable rather than slow.
 *   - `limit=0` / `limit=-5` → the default. They ask for nothing, not for "as
 *     little as possible".
 *   - `offset=-1` → clamped to 0. Same reasoning: `OFFSET -1` has no meaning, and
 *     the client's intent is obviously "from the start".
 *
 * The one thing that IS a 400 is a syntactically broken value — `limit=abc` —
 * because a client that sent it has a bug and will keep sending it. That is
 * {@see resolveInteger()}'s job, deliberately separated from clamping so the two
 * policies cannot be confused.
 *
 * ============================================================================
 * BOUNDARY BEHAVIOUR, DEFINED
 * ============================================================================
 * | situation                                   | result                              |
 * |---------------------------------------------|-------------------------------------|
 * | `offset >= total` (total known)            | empty page, no next, no previous    |
 * | `offset == 0`, page empty, total 0         | empty page, no next                 |
 * | last page, full                            | previous only                       |
 * | `offset == total - 1` with total 1         | one member, no next                 |
 * | page short BUT `offset + count < total`    | STILL HAS NEXT — see below          |
 * | page short, total unknown                  | no next (nothing proves more exist) |
 * | `limit` clamped down                        | page reflects the CLAMPED limit     |
 *
 * The fourth row is the interesting one. A short page with a known total means
 * the collection CHANGED while it was being read: a concurrent delete. The
 * temptation is to treat "short page" as end-of-collection and stop, which
 * silently TRUNCATES DISCOVERY — a member that exists is never listed, and the
 * client has no way to know. So this class reports a next page whenever
 * `offset + count < total`, whatever the page size. Pagination is allowed to walk
 * a moving target; it is not allowed to lie about where the target ends.
 *
 * `count()` is also clamped by {@see availableMembers()}, so the arithmetic can
 * never produce a negative.
 *
 * @package Civi\Dfc
 */
final class ContainerPage
{
    /** Page size when the client expresses no preference. */
    public const DEFAULT_LIMIT = 20;

    /**
     * Ceiling on a page.
     *
     * A protocol-layer default, not an operational limit: sa-014 owns the real
     * request/graph-size caps and may lower this per deployment. 100 is chosen
     * because a JSON-LD membership list is one triple per member and 100 members
     * is already a large response for a federated client.
     */
    public const MAX_LIMIT = 100;

    /** Guard against a query string asking for a 10^9-byte response. */
    private const ABSOLUTE_LIMIT_CEILING = 1000;

    /** Beyond this, paging is not the answer and clients should be told. */
    private const UNREASONABLE_OFFSET = 1000000;

    private const URI_PATTERN = '#^https?://[^\s/?\#]+(?:/[^\s?\#]*)?$#';

    private readonly string $containerUri;

    /** @var list<string> */
    private readonly array $members;

    private readonly int $limit;

    private readonly int $offset;

    private readonly ?int $total;

    /** What the client asked for, before clamping. Null when constructed directly. */
    private readonly ?int $requestedLimit;

    /**
     * @param list<string> $members Already-fetched member URIs. Not fetched here;
     *        use {@see fromSource()} to guarantee the bound.
     * @param int|null     $requestedLimit The pre-clamp limit, so that
     *        {@see limitWasClamped()} can report a reduction to the client.
     *
     * @throws DfcApiException 500 `membership_inconsistent` when the page
     *         violates an invariant. A protocol error rather than an
     *         `\InvalidArgumentException` because a source that breaks an
     *         invariant is a SERVER fault the client must be told about, and
     *         because the route layer will not catch an argument exception.
     */
    public function __construct(
        string $containerUri,
        array $members,
        int $limit,
        int $offset,
        ?int $total = null,
        ?int $requestedLimit = null
    ) {
        $container = trim($containerUri);

        if (!str_ends_with($container, '/')) {
            // Tolerated rather than refused, for the same reason
            // UriFactory::containedResourceUri() tolerates it: a caller holding a
            // URI from a routing layer should not have to normalise first.
            $container .= '/';
        }

        if (preg_match(self::URI_PATTERN, $container) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A container URI must be an absolute http(s) URI ending in "/". Got "%s".',
                $containerUri
            ));
        }

        if ($limit < 1) {
            throw new \InvalidArgumentException(sprintf('A page limit must be at least 1, got %d.', $limit));
        }

        if ($offset < 0) {
            throw new \InvalidArgumentException(sprintf('A page offset cannot be negative, got %d.', $offset));
        }

        if ($total !== null && $total < 0) {
            throw new \InvalidArgumentException(sprintf('A membership total cannot be negative, got %d.', $total));
        }

        if (count($members) > $limit) {
            throw DfcApiException::of(ProtocolError::membershipInconsistent());
        }

        $validated = [];
        $seen = [];

        foreach ($members as $position => $member) {
            $candidate = is_string($member) ? trim($member) : '';

            if (!is_string($member) || preg_match(self::URI_PATTERN, $candidate) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Container member #%d must be an absolute http(s) URI without a fragment. Got %s.',
                    $position + 1,
                    is_string($member) ? '"' . $member . '"' : get_debug_type($member)
                ));
            }

            if (isset($seen[$candidate])) {
                // See invariant 3. Refuse rather than de-duplicate.
                throw DfcApiException::of(ProtocolError::membershipInconsistent());
            }

            $seen[$candidate] = true;
            $validated[] = $candidate;
        }

        $this->containerUri = $container;
        $this->members = $validated;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->total = $total;
        $this->requestedLimit = $requestedLimit;
    }

    /**
     * Fetch one page from a source, resolving the requested bounds.
     *
     * This is the only path that guarantees the bound, because it is the only one
     * that takes the limit BEFORE the fetch. Constructing a page by hand from an
     * array the caller already built is possible, and the constructor's
     * `count($members) <= $limit` check is what stops that from producing an
     * oversized page.
     *
     * @param string|null $requestedLimit  Raw query value; see {@see resolveLimit()}.
     * @param string|null $requestedOffset Raw query value; see {@see resolveOffset()}.
     *
     * @throws DfcApiException 400 for a syntactically broken bound.
     */
    public static function fromSource(
        string $containerUri,
        MembershipSourceInterface $source,
        ?string $requestedLimit = null,
        ?string $requestedOffset = null,
        ?int $maxPageSize = null
    ): self {
        $ceiling = $maxPageSize === null
            ? self::MAX_LIMIT
            : max(1, min($maxPageSize, self::ABSOLUTE_LIMIT_CEILING));

        $limit = self::resolveLimit($requestedLimit, $ceiling);
        $offset = self::resolveOffset($requestedOffset);

        return new self(
            $containerUri,
            $source->page($containerUri, $limit, $offset),
            $limit,
            $offset,
            $source->count($containerUri),
            $requestedLimit === null || trim($requestedLimit) === '' ? null : self::requestedInteger($requestedLimit)
        );
    }

    // -- Query-string resolution ----------------------------------------------

    /**
     * Resolve a `limit` query parameter, clamping rather than refusing.
     *
     * @param int $ceiling The deployment's page-size cap.
     *
     * @throws DfcApiException 400 when the value is not an integer.
     */
    public static function resolveLimit(?string $raw, int $ceiling = self::MAX_LIMIT): int
    {
        if ($raw === null || trim($raw) === '') {
            return min(self::DEFAULT_LIMIT, max(1, $ceiling));
        }

        $value = self::resolveInteger($raw, 'limit');

        return self::clampLimit($value, max(1, $ceiling));
    }

    /**
     * Resolve an `offset` query parameter, clamping rather than refusing.
     *
     * @throws DfcApiException 400 when the value is not an integer.
     */
    public static function resolveOffset(?string $raw): int
    {
        if ($raw === null || trim($raw) === '') {
            return 0;
        }

        // A leading minus is accepted here and clamped below. `offset=-1` is
        // syntactically fine and semantically meaningless, and the client's intent is
        // obviously "from the start"; a 400 would teach it nothing.
        $value = self::resolveInteger($raw, 'offset', true);

        return max(0, min($value, self::UNREASONABLE_OFFSET));
    }

    /**
     * @throws DfcApiException 400 when $raw is not a plain integer.
     */
    private static function resolveInteger(string $raw, string $parameter, bool $allowNegative = false): int
    {
        $candidate = trim($raw);

        // Deliberately stricter than filter_var(): `+5`, `5.0`, ` 5 ` and `0x1A`
        // are all things a client can send, and accepting some of them silently
        // while rejecting others is how a paging bug becomes unreproducible.
        //
        // Reported as a 400 `invalid_request` rather than an
        // \InvalidArgumentException, because from the client's point of view this
        // IS a bad request — the same treatment
        // {@see \Civi\Dfc\V2\Controller\Negotiation\ContentNegotiator} gives a
        // broken header, and keeping the two consistent means a route has one
        // catch, not two.
        $pattern = $allowNegative ? '/^-?[0-9]{1,9}$/' : '/^[0-9]{1,9}$/';

        if (preg_match($pattern, $candidate) !== 1) {
            throw DfcApiException::of(ProtocolError::invalidRequest());
        }

        return (int) $candidate;
    }

    /**
     * The pre-clamp value of a limit parameter, for {@see limitWasClamped()}.
     *
     * Shares {@see resolveInteger()} so the value recorded here is exactly the
     * one that was clamped — a second, laxer parse would let the two disagree.
     *
     * @throws DfcApiException 400 when $raw is not an integer.
     */
    private static function requestedInteger(string $raw): int
    {
        return self::resolveInteger($raw, 'limit');
    }

    private static function clampLimit(int $requested, ?int $ceiling): int
    {
        $cap = $ceiling === null ? self::MAX_LIMIT : max(1, min($ceiling, self::ABSOLUTE_LIMIT_CEILING));

        // `limit=0` and `limit=-5` ask for nothing, not for "as little as possible".
        // The default is the only reading that does something useful, so that is what
        // they get. (A NON-numeric value is different: that is a client bug, and
        // resolveInteger() answers it 400.)
        if ($requested < 1) {
            return min(self::DEFAULT_LIMIT, $cap);
        }

        return min($requested, $cap);
    }

    // -- Accessors ------------------------------------------------------------

    public function containerUri(): string
    {
        return $this->containerUri;
    }

    /** @return list<string> */
    public function members(): array
    {
        return $this->members;
    }

    /** The EFFECTIVE limit, after clamping. Never larger than {@see maxLimit()}. */
    public function limit(): int
    {
        return $this->limit;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    /** Membership total, or null when unknown. */
    public function total(): ?int
    {
        return $this->total;
    }

    public function count(): int
    {
        return count($this->members);
    }

    public function isEmpty(): bool
    {
        return $this->members === [];
    }

    public function maxLimit(): int
    {
        return self::MAX_LIMIT;
    }

    /**
     * Was the requested limit reduced?
     *
     * Reportable in a `Link` or a log so a client that asked for 500 and got 20
     * can tell that paging is working rather than that it has seen everything.
     */
    public function limitWasClamped(): bool
    {
        return $this->requestedLimit !== null && $this->limit < $this->requestedLimit;
    }

    /** How many members exist at or after this page's offset. */
    public function availableMembers(): int
    {
        if ($this->total === null) {
            return $this->count();
        }

        return max(0, $this->total - $this->offset);
    }

    /**
     * Is there a page after this one?
     *
     * True when the page is full (the cheap, always-available signal) OR when a
     * known total says more exist beyond what was returned. The second clause is
     * what prevents a concurrent delete from truncating discovery.
     */
    public function hasNextPage(): bool
    {
        if ($this->total !== null) {
            return $this->offset + $this->count() < $this->total;
        }

        return $this->count() === $this->limit;
    }

    public function nextOffset(): ?int
    {
        return $this->hasNextPage() ? $this->offset + $this->count() : null;
    }

    public function previousOffset(): ?int
    {
        return $this->offset > 0 ? max(0, $this->offset - $this->limit) : null;
    }

    public function hasPreviousPage(): bool
    {
        return $this->offset > 0;
    }

    public function isFirstPage(): bool
    {
        return $this->offset === 0;
    }

    // -- Navigation -----------------------------------------------------------

    /**
     * The `next` page URI, or null at the end of the collection.
     *
     * Query parameters are emitted in a fixed order (`limit`, `offset`) so the
     * URI is byte-stable and can be asserted literally. The container URI already
     * ends in `/`, so a single `?` is correct.
     */
    public function nextPageUri(): ?string
    {
        $next = $this->nextOffset();

        return $next === null ? null : $this->pageUri($next);
    }

    /** The `prev` page URI, or null on the first page. */
    public function previousPageUri(): ?string
    {
        $previous = $this->previousOffset();

        return $previous === null ? null : $this->pageUri($previous);
    }

    /** Paging relations for a `Link` header. Empty on a single-page collection. */
    public function links(): array
    {
        $links = [];

        $next = $this->nextPageUri();
        if ($next !== null) {
            $links[] = LinkRelation::paging('next', $next);
        }

        $previous = $this->previousPageUri();
        if ($previous !== null) {
            $links[] = LinkRelation::paging('prev', $previous);
        }

        return $links;
    }

    public function pageUri(int $offset): string
    {
        return $this->containerUri . '?limit=' . $this->limit . '&offset=' . max(0, $offset);
    }
}
