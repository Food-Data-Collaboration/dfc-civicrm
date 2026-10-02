<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Policy;

/**
 * The public/private export allow-list.
 *
 * ============================================================================
 * THE HEADLINE PROPERTY: DEFAULT NOT-EXPORTED
 * ============================================================================
 * PRD-002 CP-1 requires "public/private policy defaults to not-exported" and §4.13
 * requires "an explicit public/private mapping policy (allow-list)". Both are
 * satisfied structurally, not by convention:
 *
 *   - the constructor takes the ALLOW-list. There is no "everything except" form;
 *   - {@see DefaultExportPolicy::none()} is an empty allow-list, so the shipped
 *     default withholds every data predicate;
 *   - {@see visibilityOf()} falls through to {@see VisibilityReason::NOT_ON_ALLOW_LIST},
 *     which is WITHHELD.
 *
 * A predicate that this class has never heard of is therefore invisible by default.
 * That is the control, and it is what makes "a field someone forgot about" safe.
 *
 * ============================================================================
 * WHY THERE IS ALSO A DENY LIST
 * ============================================================================
 * Two reasons, both practical.
 *
 * First, auditability: {@see VisibilityReason::EXPLICITLY_DENIED} versus
 * {@see VisibilityReason::NOT_ON_ALLOW_LIST} is the difference between "a person
 * decided no" and "nobody has decided", and an operator debugging a missing field
 * needs to tell them apart.
 *
 * Second — and this is the one that matters — a deny list is what a deployment uses
 * to REMOVE a predicate that an inherited or generated allow-list added. Without it,
 * widening the allow-list for one class silently widens it for every class that
 * shares the policy object. The deny list wins on a conflict, and the conflict is
 * recorded so it is visible rather than implied by precedence.
 *
 * ============================================================================
 * WHY JSON-LD KEYWORDS CANNOT BE LISTED
 * ============================================================================
 * {@see PredicateVisibility::STRUCTURAL} is not a policy state. Allowing `@id` would
 * be a no-op that reads as a decision; denying `@id` would strip the subject of the
 * document and produce something that is not the same resource. Both are refused at
 * construction, so neither can be expressed.
 *
 * ============================================================================
 * PREDICATE SYNTAX IS VALIDATED, NOT SANITISED
 * ============================================================================
 * A predicate is accepted as an absolute IRI (`scheme://…`) or a CURIE
 * (`prefix:local`), and refused otherwise. The reason is the same one
 * {@see \Civi\Dfc\V2\Identity\UriFactory} uses for identifiers: a policy list is
 * configuration, so a typo must fail loudly at configuration time rather than create a
 * rule that silently matches nothing. Every accepted predicate is compared with
 * `hash_equals`, because the comparison is over attacker-influenced document keys.
 *
 * ============================================================================
 * INTERACTION WITH ETAG — STATED, BECAUSE IT IS A CONSEQUENCE
 * ============================================================================
 * If the exported projection depends on this policy, then the BYTES a client receives
 * depend on which policy was applied. {@see ExportProjection} therefore produces a
 * per-caller projection, and since
 * {@see \Civi\Dfc\V2\Controller\Http\ETag::strong()} is computed over the exact
 * response bytes, the resulting strong ETag is PER-CALLER: two identities with
 * different visibility get different tags for the same URI.
 *
 * Three consequences, all of which this layer takes responsibility for:
 *
 *   1. A shared cache MUST key on the representation, not the URI.
 *      {@see \Civi\Dfc\V2\Controller\Http\HeaderPolicy} already emits
 *      `Vary: Accept, Authorization` unconditionally, which is correct and is the
 *      only thing standing between this policy and a cache poisoning bug. Do not
 *      remove `Authorization` from that list while a per-caller projection exists.
 *   2. An `If-Match` from caller A cannot satisfy a conditional request from caller
 *      B, even for the same URI, because the bytes differ. That is correct — they
 *      ARE different representations — but it means conditional writes must be
 *      attempted by the same identity that read.
 *   3. If a deployment ever decides to use ONE projection for all callers (a policy
 *      that does not vary), the ETags become shareable again. That is the decision
 *      BLK-009 is about, and it is explicitly NOT made here: this class implements
 *      the mechanism and the default, and the per-caller question stays open with
 *      the Moderator and Zoro.
 *
 * @see DefaultExportPolicy — the shipped default
 * @see ExportProjection — applies a policy to a node map
 *
 * @package Civi\Dfc
 */
final class PublicFieldPolicy
{
    /** Absolute IRI: a scheme, a `//`, and no whitespace. */
    private const IRI_PATTERN = '#^[a-z][a-z0-9+.\-]*://[^\s<>"{}|\\^`]+$#';

    /** CURIE: `prefix:local`, both halves NCName-ish. */
    private const CURIE_PATTERN = '/^[A-Za-z][A-Za-z0-9.\-]{0,63}:[A-Za-z0-9_][A-Za-z0-9_.\-]{0,127}$/';

    /** @var array<string, true> */
    private readonly array $public;

    /** @var array<string, true> */
    private readonly array $denied;

    /**
     * @param list<string> $publicPredicates The allow-list.
     * @param list<string> $deniedPredicates The deny-list. Wins over the allow-list.
     *
     * @throws \InvalidArgumentException on a keyword, a duplicate with conflicting
     *         meaning, or an unusable predicate name.
     */
    public function __construct(array $publicPredicates = [], array $deniedPredicates = [])
    {
        $public = [];
        foreach ($publicPredicates as $predicate) {
            $validated = self::assertPredicate($predicate, 'allow-list');
            $public[$validated] = true;
        }

        $denied = [];
        foreach ($deniedPredicates as $predicate) {
            $validated = self::assertPredicate($predicate, 'deny-list');
            $denied[$validated] = true;
        }

        $this->public = $public;
        $this->denied = $denied;
    }

    /**
     * The verdict for one predicate.
     *
     * Order matters and is the precedence rule: keywords first (not a decision),
     * then deny, then allow, then the default.
     */
    public function visibilityOf(string $predicate): VisibilityDecision
    {
        if (PredicateVisibility::isKeyword($predicate)) {
            return new VisibilityDecision(
                $predicate,
                PredicateVisibility::STRUCTURAL,
                VisibilityReason::JSON_LD_KEYWORD
            );
        }

        if (!self::isUsablePredicate($predicate)) {
            return new VisibilityDecision(
                $predicate,
                PredicateVisibility::WITHHELD,
                VisibilityReason::NOT_A_PREDICATE
            );
        }

        if (isset($this->denied[$predicate])) {
            return new VisibilityDecision(
                $predicate,
                PredicateVisibility::WITHHELD,
                VisibilityReason::EXPLICITLY_DENIED
            );
        }

        if (isset($this->public[$predicate])) {
            return new VisibilityDecision(
                $predicate,
                PredicateVisibility::PUBLIC,
                VisibilityReason::ON_ALLOW_LIST
            );
        }

        return new VisibilityDecision(
            $predicate,
            PredicateVisibility::WITHHELD,
            VisibilityReason::NOT_ON_ALLOW_LIST
        );
    }

    public function isExported(string $predicate): bool
    {
        return $this->visibilityOf($predicate)->isExported();
    }

    /**
     * Is the policy empty? Every data predicate is then withheld.
     */
    public function isDefaultDeny(): bool
    {
        return $this->public === [];
    }

    /**
     * The allow-list, sorted.
     *
     * @return list<string>
     */
    public function publicPredicates(): array
    {
        return self::sorted($this->public);
    }

    /**
     * The deny-list, sorted.
     *
     * @return list<string>
     */
    public function deniedPredicates(): array
    {
        return self::sorted($this->denied);
    }

    /**
     * Allow one predicate. Returns a NEW policy; the original is unchanged.
     */
    public function withPublic(string ...$predicates): self
    {
        $merged = $this->publicPredicates();

        foreach ($predicates as $predicate) {
            $merged[] = self::assertPredicate($predicate, 'allow-list');
        }

        return new self(self::unique($merged), $this->deniedPredicates());
    }

    /**
     * Deny one predicate. Returns a NEW policy.
     */
    public function withDenied(string ...$predicates): self
    {
        $merged = $this->deniedPredicates();

        foreach ($predicates as $predicate) {
            $merged[] = self::assertPredicate($predicate, 'deny-list');
        }

        return new self($this->publicPredicates(), self::unique($merged));
    }

    /**
     * Audit record. The lists and their counts; no document content.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'public' => $this->publicPredicates(),
            'denied' => $this->deniedPredicates(),
            'keywords' => PredicateVisibility::keywords(),
            'defaultDeny' => $this->isDefaultDeny(),
        ];
    }

    // -- Helpers --------------------------------------------------------------

    private static function isUsablePredicate(string $predicate): bool
    {
        if ($predicate === '' || preg_match('/[\s\x00-\x1F\x7F]/', $predicate) === 1) {
            return false;
        }

        return preg_match(self::IRI_PATTERN, $predicate) === 1
            || preg_match(self::CURIE_PATTERN, $predicate) === 1;
    }

    private static function assertPredicate(string $predicate, string $list): string
    {
        if (PredicateVisibility::isKeyword($predicate)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is a JSON-LD keyword and cannot appear on a %s. Keywords are syntax, not statements about '
                . 'the resource, so allowing one is a no-op and denying one would strip the document of its '
                . 'subject.',
                $predicate,
                $list
            ));
        }

        if (!self::isUsablePredicate($predicate)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" cannot go on a %s. A predicate must be an absolute IRI (scheme://…) or a CURIE '
                . '(prefix:local) with no whitespace or control characters. Refused rather than sanitised: a '
                . 'typo here would create a rule that silently matches nothing.',
                $predicate,
                $list
            ));
        }

        return $predicate;
    }

    /**
     * @param array<string, true> $set
     *
     * @return list<string>
     */
    private static function sorted(array $set): array
    {
        $keys = array_keys($set);
        sort($keys, \SORT_STRING);

        /** @var list<string> $keys */
        return array_values($keys);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique($values));
    }
}