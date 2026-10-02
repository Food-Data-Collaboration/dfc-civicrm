<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Policy;

/**
 * WHY a predicate got the visibility it got.
 *
 * ============================================================================
 * WHY THIS EXISTS ALONGSIDE THE VISIBILITY
 * ============================================================================
 * Because "withheld" is not an actionable audit entry. An operator debugging "why
 * does this field never appear in my WebID?" needs the difference between:
 *
 *   - it is not on the allow-list (nobody has decided yet), and
 *   - it is on the DENY list (somebody decided no, on purpose).
 *
 * Both produce WITHHELD, and only this enum tells them apart. The same distinction
 * separates "on the allow-list" from "the default for a class this deployment
 * treats as wholly public".
 *
 * Every reason also answers the question a security reviewer asks first — "what is
 * the WORST case here?" — by making the fail-closed answers the explicit ones:
 * {@see NOT_ON_ALLOW_LIST} and {@see EXPLICITLY_DENIED} both withhold.
 *
 * @package Civi\Dfc
 */
enum VisibilityReason: string
{
    /** `@context`, `@id`, `@type`, `@graph`. Not a decision. */
    case JSON_LD_KEYWORD = 'json_ld_keyword';

    /** On the deployment's allow-list. */
    case ON_ALLOW_LIST = 'on_allow_list';

    /** On the deny list: withheld on purpose. */
    case EXPLICITLY_DENIED = 'explicitly_denied';

    /**
     * Not on either list: withheld because the DEFAULT is withheld.
     *
     * The reason CP-1 exists. A predicate nobody has thought about is invisible, and
     * that is the correct behaviour for a field that might be an e-mail address.
     */
    case NOT_ON_ALLOW_LIST = 'not_on_allow_list';

    /**
     * The key is not a predicate at all — an empty string, or something with
     * whitespace in it.
     *
     * Withheld, and worth its own reason because it indicates a producer that is not
     * emitting JSON-LD properly rather than a policy question.
     */
    case NOT_A_PREDICATE = 'not_a_predicate';

    public function title(): string
    {
        return match ($this) {
            self::JSON_LD_KEYWORD => 'JSON-LD keyword; emitted unconditionally.',
            self::ON_ALLOW_LIST => 'On the deployment allow-list, so it is exported.',
            self::EXPLICITLY_DENIED => 'On the deny list, so it is withheld on purpose.',
            self::NOT_ON_ALLOW_LIST => 'Not on the allow-list, and the default is withheld.',
            self::NOT_A_PREDICATE => 'Not a usable predicate name, so it is withheld.',
        };
    }
}