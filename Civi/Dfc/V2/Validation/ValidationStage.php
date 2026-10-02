<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * The pipeline's stages, in the order the PRD fixes them.
 *
 * ============================================================================
 * THE ORDER IS AN ENUM, NOT AN INSERTION ORDER
 * ============================================================================
 * PRD-002 §2 Step 2 and the request-validation order that every DFC surface
 * inherits are the same list: authentication → JSON-LD parse → DFC type/schema →
 * SHACL → identity/reference resolution → authorisation → mutation. If the order
 * lived in the array a caller passes to {@see ValidationPipeline}, a lane-3 stage
 * added in the wrong position would be accepted silently and the failure would
 * show up as "SHACL validation passed a document with an unresolvable reference".
 *
 * So a stage declares which ordinal it is ({@see ValidationStageInterface::stage()})
 * and {@see ValidationPipeline} sorts by it. Registering a stage out of order is a
 * no-op; registering TWO stages with the same ordinal is a construction error.
 *
 * ============================================================================
 * WHO OWNS WHICH STAGE
 * ============================================================================
 *  - AUTHENTICATION       this lane: {@see \Civi\Dfc\V2\Security\BearerAuthenticator}.
 *  - JSON_LD_PARSE        this lane: syntax only, behind an interface lane-3 fills
 *                          with the connector's real parser.
 *  - DFC_TYPE_SCHEMA       LANE 3. The LinkML-generated class and property
 *                          inventory. Not implemented here, and deliberately not
 *                          guessed at: inventing "the DFC type rules" without the
 *                          schema would be a second, wrong source of truth.
 *  - SHACL                this lane: {@see ShaclValidationStage}.
 *  - IDENTITY_RESOLUTION  LANE 3/4. Subject → WebID → contact, and reference
 *                          resolution within the submitted graph.
 *  - AUTHORIZATION        LANE 3. Scope → permission → CiviCRM permission check.
 *                          {@see \Civi\Dfc\V2\Security\ScopePermissionRegistry}
 *                          supplies the mapping; the CMS check does not exist yet.
 *  - MUTATION             the write itself. The pipeline runs everything BEFORE it
 *                          and nothing of it, and a mutating stage can only be
 *                          reached through {@see ValidationContext::assertMayMutate()}.
 *
 * ============================================================================
 * WHY MUTATION IS AN ORDINAL AND NOT A SPECIAL CASE
 * ============================================================================
 * Because "no mutation before every check has passed" is only enforceable if there
 * is one flag that means "every check has passed", and the flag has to be set by
 * the only class that knows what ran. MUTATION being last in this enum is what makes
 * that flag honest.
 *
 * @package Civi\Dfc
 */
enum ValidationStage: string
{
    /** Is there a usable, validated bearer token? */
    case AUTHENTICATION = 'authentication';

    /** Is the body well-formed JSON-LD? */
    case JSON_LD_PARSE = 'json_ld_parse';

    /** Does it declare, and use, the DFC classes and properties it claims? */
    case DFC_TYPE_SCHEMA = 'dfc_type_schema';

    /** Does it satisfy the DFC SHACL shapes? */
    case SHACL = 'shacl';

    /** Do its references resolve, and to what? */
    case IDENTITY_RESOLUTION = 'identity_resolution';

    /** Is the authenticated identity allowed to do this? */
    case AUTHORIZATION = 'authorization';

    /**
     * The write itself. Never run by the validation pipeline; it is here so that
     * "everything before this" is expressible.
     */
    case MUTATION = 'mutation';

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /** Every stage that must pass before a mutation may begin. */
    public static function preMutationStages(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $stage): bool => $stage !== self::MUTATION
        ));
    }

    public function isMutating(): bool
    {
        return $this === self::MUTATION;
    }

    public function title(): string
    {
        return match ($this) {
            self::AUTHENTICATION => 'authentication',
            self::JSON_LD_PARSE => 'JSON-LD parsing',
            self::DFC_TYPE_SCHEMA => 'DFC type and schema',
            self::SHACL => 'SHACL shape validation',
            self::IDENTITY_RESOLUTION => 'identity and reference resolution',
            self::AUTHORIZATION => 'authorisation',
            self::MUTATION => 'mutation',
        };
    }
}