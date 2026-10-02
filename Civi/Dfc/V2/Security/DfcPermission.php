<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The four CiviCRM permissions this interface speaks in, and nothing else.
 *
 * ============================================================================
 * WHY AN ENUM WHOSE VALUES ARE LITERAL PERMISSION STRINGS
 * ============================================================================
 * PRD-002 §"Upstream refresh" item 2 is explicit: "map them to the four
 * CiviCRM permissions in one registry". The four names are not this code's to
 * choose, so they are written as the enum's backing values and used verbatim
 * wherever a CiviCRM permission check happens. A typo here is a permission that
 * silently never matches, so the set is closed and every use site names a case
 * rather than a string.
 *
 * Note the composition: CiviCRM permission names are `action entity`-shaped, so
 * the DFC API gate (`access dfc api`) is deliberately a SEPARATE permission from
 * the data permissions rather than an instance of one. A caller must hold
 * `access dfc api` to reach this interface at all, and separately hold
 * `read dfc data` to read it. That is two checks, and the pipeline needs both;
 * collapsing them would make "can this client use the API" and "can this client
 * see this resource" the same question.
 *
 * ============================================================================
 * `implies()` IS AUTHORISATION, NOT COSMETICS
 * ============================================================================
 * {@see ADMINISTER} implies the two data permissions. That implication is what stops
 * every call site from hand-writing `if ($admin || $write)`, which is exactly the kind
 * of place a future permission gets forgotten. It lives here, once, with the
 * derivation stated.
 *
 * It deliberately does NOT imply {@see ACCESS_API}. See {@see implies()} for why: the
 * API gate is checked against CiviCRM and nothing a token carries may satisfy it, so
 * an implication here would create a second, wrong answer to that question.
 *
 * @package Civi\Dfc
 */
enum DfcPermission: string
{
    /** The gate: the identity may use the DFC HTTP interface at all. */
    case ACCESS_API = 'access dfc api';

    /** May read DFC resources. */
    case READ_DATA = 'read dfc data';

    /** May create, modify and delete DFC resources. */
    case WRITE_DATA = 'write dfc data';

    /** May administer the DFC layer: configuration, mappings, re-publication. */
    case ADMINISTER = 'administer dfc';

    /**
     * Every permission, in declaration order. Used by diagnostics and by tests
     * that assert the set is exactly these four.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::ACCESS_API,
            self::READ_DATA,
            self::WRITE_DATA,
            self::ADMINISTER,
        ];
    }

    /**
     * Does holding this permission satisfy holding $required?
     *
     * The implication graph, stated once:
     *
     *   administer dfc  =>  read dfc data, write dfc data
     *   write dfc data  =>  read dfc data        (you cannot write what you
     *                                                cannot read: the write
     *                                                check is always paired
     *                                                with a read of the same
     *                                                resource)
     *   everything else =>  itself only
     *
     * `write => read` is defensible on its own terms and is asserted in
     * `ScopePermissionRegistryTest`. It is NOT asserted for the reverse: a reader
     * does not thereby gain a writer.
     *
     * ============================================================================
     * `ADMINISTER` DOES NOT IMPLY `ACCESS_DFC_API`, AND THAT IS DELIBERATE
     * ============================================================================
     * It would be the tidier graph, and it would be wrong here. `access dfc api` is
     * not a DATA permission: it is the gate a CiviCRM permission backend checks, and
     * nothing a TOKEN carries may satisfy it — see
     * {@see \Civi\Dfc\V2\Security\ScopePermissionRegistry} for why, and
     * {@see \Civi\Dfc\V2\Security\PermissionGrant} for the consequence that no
     * {@see \Civi\Dfc\V2\Security\PermissionGrant} can ever contain it.
     *
     * If ADMINISTER implied it, then `PermissionGrant::allows(ACCESS_API)` would
     * answer "true" for an administrator's token while the actual gate still had to be
     * checked against the CMS — two sources of truth for one question, and the wrong
     * one available to a caller who forgets to check. The graph therefore stops at
     * ACCESS_API, and the gate has exactly one place that can satisfy it.
     */
    public function implies(self $required): bool
    {
        if ($this === $required) {
            return true;
        }

        return match ($this) {
            self::ADMINISTER => $required === self::READ_DATA || $required === self::WRITE_DATA,
            self::WRITE_DATA => $required === self::READ_DATA,
            default => false,
        };
    }

    /** A short, stable, human-readable summary. Fixed text; never interpolated. */
    public function title(): string
    {
        return match ($this) {
            self::ACCESS_API => 'use the DFC API',
            self::READ_DATA => 'read DFC data',
            self::WRITE_DATA => 'write DFC data',
            self::ADMINISTER => 'administer the DFC layer',
        };
    }
}