<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Policy;

/**
 * The policy this extension ships with, and the two ways to depart from it.
 *
 * ============================================================================
 * WHAT THE DEFAULT IS, PRECISELY
 * ============================================================================
 * An EMPTY allow-list. Not "a sensible set of public fields" — empty. Every data
 * predicate is withheld, and the only keys that survive a projection are the JSON-LD
 * keywords.
 *
 * That is what CP-1's "public/private policy defaults to not-exported" asks for, and
 * it is the only default that could honestly be shipped here for three reasons:
 *
 *   1. **PRD-002 does not say which DFC fields are public.** §4.13 requires an
 *      explicit allow-list and §9 warns about "public WebID exposure of data that
 *      should stay private" at MEDIUM/HIGH risk. Choosing the contents is a
 *      DATA-GOVERNANCE decision — it needs to know whether this deployment's
 *      organizations publish their VAT numbers, their addresses, their phone numbers
 *      to anyone at all. That is the requester's call, and inventing a list here would
 *      bury it in a diff.
 *   2. **A wrong allow-list is worse than an empty one.** Every entry that turns out
 *      to be wrong is a disclosure that already happened. An empty list discloses
 *      nothing, and a deployment adds entries when it has decided to.
 *   3. **The mechanism is what this lane was asked for.** The brief is "implement the
 *      MECHANISM and a documented default policy; do not invent DFC-specific field
 *      decisions beyond what the PRD already states". The PRD states no field-level
 *      decision, so the default states none.
 *
 * ============================================================================
 * WHY `publicOnly()` IS SAFE TO NAME THAT WAY
 * ============================================================================
 * It sounds like the opposite of the default, and it is: it makes every listed
 * predicate public and everything else withheld, which is what an allow-list already
 * does. The name exists for readability at call sites that are building a policy from
 * a short list — `DefaultExportPolicy::publicOnly(['dfc-b:name'])` reads better than
 * `new PublicFieldPolicy(['dfc-b:name'], [])` — and it changes no behaviour. The two
 * are asserted to be equal in {@see DefaultExportPolicyTest}.
 *
 * ============================================================================
 * WHAT IS STRUCTURALLY EXPORTABLE REGARDLESS
 * ============================================================================
 * The JSON-LD keywords, which are {@see PredicateVisibility::STRUCTURAL}. A document
 * whose every predicate is withheld is still a valid JSON-LD document with a subject
 * and a type — it says almost nothing, and it is still addressable. That is the
 * correct starting point: an identity is publishable before its data is.
 *
 * @package Civi\Dfc
 */
final class DefaultExportPolicy
{
    private function __construct()
    {
        // Named constructors only.
    }

    /**
     * The shipped default: nothing but the JSON-LD keywords is exported.
     */
    public static function none(): PublicFieldPolicy
    {
        return new PublicFieldPolicy();
    }

    /**
     * A policy exporting exactly the given predicates. Everything else withheld.
     *
     * @param list<string> $predicates
     */
    public static function publicOnly(array $predicates): PublicFieldPolicy
    {
        return new PublicFieldPolicy($predicates);
    }

    /**
     * A policy that also withholds specific predicates from an allow-list.
     *
     * @param list<string> $publicPredicates
     * @param list<string> $deniedPredicates
     */
    public static function allowList(array $publicPredicates, array $deniedPredicates = []): PublicFieldPolicy
    {
        return new PublicFieldPolicy($publicPredicates, $deniedPredicates);
    }
}