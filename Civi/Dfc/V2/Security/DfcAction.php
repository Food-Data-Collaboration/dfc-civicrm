<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * What a caller is trying to do, as authorisation sees it.
 *
 * ============================================================================
 * THE DERIVATION, IN ONE PLACE
 * ============================================================================
 * `requiredPermission()` is the only statement in the repository of the form
 * "action X needs permission Y". It is deliberately a function of the action
 * alone and NOT of the surface, because that is what the four CiviCRM
 * permissions support: `read dfc data` is not parameterised by resource class in
 * CiviCRM, and pretending otherwise would mean inventing a permission per class
 * that the CMS does not have.
 *
 * What the surface DOES parameterise is the *scope*: which realm scope grants the
 * read on which surface. That is {@see ScopePermissionRegistry}'s job, and it is
 * where the DFC-specific knowledge lives.
 *
 * ============================================================================
 * MUTATION IS A PROPERTY OF THE ACTION, NOT OF THE METHOD
 * ============================================================================
 * `isMutating()` exists so that the validation pipeline can assert "no mutation
 * happens before every non-mutating check has passed" without a string
 * comparison on the HTTP method. `DELETE` and `PUT` and `PATCH` are all mutating;
 * `GET`, `HEAD` and `OPTIONS` are not; and the action a route declares is a
 * better statement of intent than the verb it happens to use.
 *
 * @package Civi\Dfc
 */
enum DfcAction: string
{
    case READ = 'read';
    case WRITE = 'write';
    case ADMINISTER = 'administer';

    /**
     * The single statement of "this action needs this permission".
     */
    public function requiredPermission(): DfcPermission
    {
        return match ($this) {
            self::READ => DfcPermission::READ_DATA,
            self::WRITE => DfcPermission::WRITE_DATA,
            self::ADMINISTER => DfcPermission::ADMINISTER,
        };
    }

    /**
     * May this action change stored state?
     *
     * Every consumer of this flag is a fail-closed check, so the safe default for
     * a new case is what matters: a new case added without updating this `match`
     * is a PHP "Unhandled match" error, i.e. a refusal rather than a silent
     * "no, this is read-only".
     */
    public function isMutating(): bool
    {
        return $this !== self::READ;
    }
}