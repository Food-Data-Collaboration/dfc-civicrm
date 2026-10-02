<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Http;

/**
 * The result of evaluating `If-Match`.
 *
 * PRD-002 CP-1 and §4.11 require conformant conditional requests. The three
 * outcomes a client cares about are {@see MATCH} (proceed), {@see MISMATCH}
 * (412) and {@see UNCONDITIONAL} (proceed — but see the note on
 * {@see IfMatchEvaluator::isUnconditionalPermitted()}).
 *
 * The remaining cases exist because collapsing them would lose information a
 * client can act on:
 *
 *   - {@see NO_REPRESENTATION}: `If-Match: *` on something that does not exist.
 *     RFC 9110 §13.1.1 requires this to FAIL, which is a 412 — but a client that
 *     tried to create-then-conditionally-POST deserves to know the difference.
 *   - {@see MALFORMED}: the header's meaning is unknown, so it must not be
 *     guessed at. 400, not a silent pass.
 *
 * @package Civi\Dfc
 */
enum IfMatchOutcome: string
{
    /** The current representation's strong tag equals one the client sent. */
    case MATCH = 'match';

    /** `If-Match` was present and no sent tag matches. 412. */
    case MISMATCH = 'mismatch';

    /**
     * `If-Match` was absent. The write proceeds.
     *
     * A lost-update hazard, deliberately permitted and deliberately *named*: see
     * {@see IfMatchEvaluator::isUnconditionalPermitted()} and sa-005's report.
     */
    case UNCONDITIONAL = 'unconditional';

    /** `If-Match: *` but there is no current representation to match against. */
    case NO_REPRESENTATION = 'no_representation';

    /** The header could not be parsed, so its intent is unknown. 400. */
    case MALFORMED = 'malformed';

    /** May the write proceed? */
    public function allowsWrite(): bool
    {
        return $this === self::MATCH || $this === self::UNCONDITIONAL;
    }

    /** Did the client actually ask for a precondition? */
    public function wasConditional(): bool
    {
        return $this !== self::UNCONDITIONAL;
    }

    /** Did the client's expectation hold? */
    public function isSatisfied(): bool
    {
        return $this === self::MATCH;
    }
}
