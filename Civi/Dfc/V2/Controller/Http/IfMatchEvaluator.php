<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Http;

use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ProtocolError;

/**
 * Evaluates `If-Match` against a resource's current entity tag, and — when
 * configured to — against the mere presence of one.
 *
 * ============================================================================
 * THE ABSENT-`If-Match` DECISION — STATED, BECAUSE IT IS A SAFETY DECISION
 * ============================================================================
 * DEFAULT: an absent (or empty) `If-Match` PERMITS the write.
 *
 * This is what RFC 9110 requires, and it is also the correct default for
 * interoperability: a DFC client that has never heard of entity tags, a browser
 * form, a `curl -X PUT` from an integrator and sa-006's Identity Service all
 * write without it. Requiring it would make the extension unusable by every
 * conforming generic HTTP client, and "conformant with HTTP but unusable in
 * practice" loses to "conformant and lost-updatable".
 *
 * The cost is real and is not waved away: **absent `If-Match` permits a
 * last-writer-wins overwrite.** Two clients that read an Organization, edit
 * different fields and PUT back will silently lose one edit, and the loser
 * receives a 2xx. No error model detects this, because nothing failed. It is the
 * one data-loss path in the whole protocol layer.
 *
 * Three things contain it, in increasing strength:
 *
 *   1. `IfMatchOutcome::UNCONDITIONAL` is a distinct value from `MATCH`, so a
 *      caller can always tell afterwards that the write was unconditioned — the
 *      hazard is observable rather than invisible. This is what "say so in the
 *      API" means in practice: `wasConditional()` is false and `allowsWrite()` is
 *      true, and both are part of the public contract.
 *   2. {@see requirePrecondition()} turns absent into 412 without a code change
 *      anywhere else. A deployment that only ever writes through a synchroniser
 *      that sends `If-Match` can enforce it site-wide, from config.
 *   3. `guard()` is the only write path a route should use, so the policy is
 *      applied in exactly one place rather than per controller — PRD-002 §9's
 *      "validation done per-controller instead of centrally" risk, avoided.
 *
 * Recommendation recorded in sa-005's report: leave the default permissive (it
 * is the interoperable one) and make `requirePrecondition()` a per-deployment
 * setting that an operator can turn on for the write paths where lost updates
 * actually cost something.
 *
 * ============================================================================
 * WHY A MALFORMED HEADER IS 400 AND NOT IGNORED
 * ============================================================================
 * The tempting leniency is "if we cannot parse it, ignore it and write". That
 * silently converts a client's precondition into no precondition at all — the
 * precondition is the only protection the client had, and dropping it because of
 * a typo is the worst possible outcome. A malformed `If-Match` is a client
 * defect, reported as 400 so it gets fixed.
 *
 * @package Civi\Dfc
 */
final class IfMatchEvaluator
{
    private readonly bool $preconditionRequired;

    private function __construct(bool $preconditionRequired)
    {
        $this->preconditionRequired = $preconditionRequired;
    }

    /**
     * @param bool $preconditionRequired When true, an absent `If-Match` yields
     *        {@see IfMatchOutcome::MISMATCH} (412) instead of proceeding. The
     *        site-wide lost-update guard described in the class docblock.
     */
    public static function create(bool $preconditionRequired = false): self
    {
        return new self($preconditionRequired);
    }

    public function preconditionRequired(): bool
    {
        return $this->preconditionRequired;
    }

    /**
     * Evaluate the header against the resource's current tag.
     *
     * @param string|null    $ifMatchHeader The raw header value, or null when
     *                                      the header was not sent. An empty or
     *                                      whitespace-only value is treated as
     *                                      absent, because a proxy that emits an
     *                                      empty header has not asked for
     *                                      anything and must not be answered 400.
     * @param ETag|null      $current       The current representation's tag, or
     *                                      null when the resource does not exist.
     *
     * @return IfMatchOutcome Never throws; {@see guard()} is the throwing form.
     */
    public function evaluate(?string $ifMatchHeader, ?ETag $current): IfMatchOutcome
    {
        $header = $ifMatchHeader === null ? '' : trim($ifMatchHeader);

        if ($header === '') {
            return $this->preconditionRequired
                ? IfMatchOutcome::MISMATCH
                : IfMatchOutcome::UNCONDITIONAL;
        }

        try {
            $candidates = ETag::splitIfMatch($header);
        } catch (\InvalidArgumentException) {
            return IfMatchOutcome::MALFORMED;
        }

        if ($candidates === ['*']) {
            // RFC 9110 §13.1.1: "*" fails when there is no current representation.
            return $current === null
                ? IfMatchOutcome::NO_REPRESENTATION
                : IfMatchOutcome::MATCH;
        }

        if ($current === null) {
            // A concrete tag was required but nothing exists to compare with.
            return IfMatchOutcome::NO_REPRESENTATION;
        }

        foreach ($candidates as $candidate) {
            // ETag::splitIfMatch() has already proved this parses, so there is no
            // failure mode left to handle here and no branch to get wrong.
            if (ETag::fromHeaderValue($candidate)->equalsStrong($current)) {
                return IfMatchOutcome::MATCH;
            }
        }

        return IfMatchOutcome::MISMATCH;
    }

    /**
     * Evaluate and throw unless the write may proceed.
     *
     * The method a route calls. Throwing here rather than returning a boolean
     * means a route cannot accidentally ignore the outcome: forgetting to check a
     * returned enum compiles, forgetting to call this does not.
     *
     * @return IfMatchOutcome {@see IfMatchOutcome::MATCH} or
     *                        {@see IfMatchOutcome::UNCONDITIONAL} — the caller can
     *                        still ask {@see IfMatchOutcome::wasConditional()} to
     *                        find out which.
     *
     * @throws DfcApiException 412 for MISMATCH / NO_REPRESENTATION, 400 for
     *         MALFORMED.
     */
    public function guard(?string $ifMatchHeader, ?ETag $current): IfMatchOutcome
    {
        $outcome = $this->evaluate($ifMatchHeader, $current);

        // A `match` over the enum, so exhaustiveness is a compile-time fact rather
        // than a hope: adding a sixth outcome becomes a visible error here instead of
        // a silently permitted write.
        return match ($outcome) {
            IfMatchOutcome::MATCH,
            IfMatchOutcome::UNCONDITIONAL => $outcome,

            IfMatchOutcome::MISMATCH,
            IfMatchOutcome::NO_REPRESENTATION => throw DfcApiException::of(ProtocolError::preconditionFailed()),

            IfMatchOutcome::MALFORMED => throw DfcApiException::of(ProtocolError::invalidHeader()),
        };
    }
}
