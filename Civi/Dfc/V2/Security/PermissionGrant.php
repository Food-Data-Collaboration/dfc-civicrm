<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * What one identity's token actually bought, and — as importantly — what it did not.
 *
 * ============================================================================
 * WHY THE UNMAPPED NAMES ARE CARRIED, NOT DISCARDED
 * ============================================================================
 * PRD-002 "Upstream refresh" item 2 is the requirement: "map them to the four
 * CiviCRM permissions in a single registry", and the behaviour for anything else
 * is that it is NOT full access. The dangerous failure mode is not that an unmapped
 * scope is denied — it is that it is denied SILENTLY, so nobody notices a client
 * that requested a scope this registry has never heard of and quietly receives a
 * 403 forever.
 *
 * So {@see unmappedScopes()} and {@see unmappedRoles()} are first-class output,
 * carried on the grant that produced them and rendered by
 * {@see toArray()}. The audit layer records them; the diagnostics endpoint can
 * list them; and a deployment can diff them against
 * {@see \Civi\Dfc\V2\Security\ScopePermissionRegistry::advertisedRealmScopes()}.
 *
 * ============================================================================
 * WHY UNMAPPED NAMES GRANT NOTHING RATHER THAN "READ SOMETHING"
 * ============================================================================
 * The alternative — treating an unrecognised scope as read-only, or as
 * "probably read" — is how a scope added by the issuer upstream for an unrelated
 * purpose becomes a data-access grant the moment someone edits this file. Nothing
 * outside {@see ScopePermissionRegistry} contributes to this object at all: the
 * grant is built from the registry's own rows or not at all.
 *
 * ============================================================================
 * `ACCESS_DFC_API` IS NOT HERE, AND THAT IS DELIBERATE
 * ============================================================================
 * `access dfc api` is a CiviCRM permission checked against a CiviCRM permission
 * store by the deployment's permission backend (lane-3), not something a realm
 * scope can confer. It appears in
 * {@see \Civi\Dfc\V2\Security\ScopePermissionRegistry::requiredPermissionsFor()} as
 * part of what an operation needs, but it can never appear in a {@see self}, so
 * there is no path by which a token grants itself the API gate.
 *
 * @package Civi\Dfc
 */
final class PermissionGrant
{
    /** @var list<ScopeGrant> */
    private readonly array $grants;

    /** @var list<string> */
    private readonly array $unmappedScopes;

    /** @var list<string> */
    private readonly array $unmappedRoles;

    /**
     * @param list<ScopeGrant> $grants
     * @param list<string>     $unmappedScopes
     * @param list<string>     $unmappedRoles
     */
    public function __construct(array $grants = [], array $unmappedScopes = [], array $unmappedRoles = [])
    {
        $this->grants = array_values($grants);
        $this->unmappedScopes = array_values(array_unique($unmappedScopes));
        $this->unmappedRoles = array_values(array_unique($unmappedRoles));
    }

    /** The empty grant: nothing is permitted, and that is a real answer. */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Does this identity hold $permission on ANY surface?
     *
     * Uses {@see DfcPermission::implies()}, so `administer dfc` answers true for
     * `read dfc data` without any call site knowing that.
     */
    public function allows(DfcPermission $permission): bool
    {
        foreach ($this->grants as $grant) {
            if ($grant->permission()->implies($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this identity hold the permission $action needs on $surface?
     *
     * A grant whose surface is null applies everywhere; a grant for another surface
     * does not apply here. So `ReadEnterprise` lets a client read the Organization
     * surface and nothing else — which is the whole point of having surfaces.
     */
    public function allowsOn(DfcSurface $surface, DfcAction $action): bool
    {
        $required = $action->requiredPermission();

        foreach ($this->grants as $grant) {
            if (!$grant->appliesTo($surface)) {
                continue;
            }

            if ($grant->permission()->implies($required)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ScopeGrant>
     */
    public function grants(): array
    {
        return $this->grants;
    }

    /**
     * Scope names the token carried that this registry has no row for.
     *
     * @return list<string>
     */
    public function unmappedScopes(): array
    {
        return $this->unmappedScopes;
    }

    /**
     * Role names the token carried that this registry has no row for.
     *
     * @return list<string>
     */
    public function unmappedRoles(): array
    {
        return $this->unmappedRoles;
    }

    public function isEmpty(): bool
    {
        return $this->grants === [];
    }

    /**
     * Audit record. The names and the notes, never the token.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'granted' => array_map(
                static fn (ScopeGrant $grant): array => $grant->toArray(),
                $this->grants
            ),
            'unmappedScopes' => $this->unmappedScopes,
            'unmappedRoles' => $this->unmappedRoles,
        ];
    }
}