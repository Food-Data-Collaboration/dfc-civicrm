<?php

declare(strict_types=1);

/**
 * dfc_civicrm - extension entry point.
 *
 * CiviCRM loads <info.xml><file> (i.e. dfc_civicrm.php) and calls the
 * functions named `<shortName>_civicrm_<hookName>` from it. The functions below
 * are the only supported extension point this file needs in order to:
 *
 *   - declare the CiviCRM version range it runs on,
 *   - participate in install / enable / uninstall,
 *   - register the four DFC permissions,
 *   - register the /civicrm/dfc/v2 route namespace, and
 *   - declare the admin settings.
 *
 * Deliberately NOT here: any DFC business logic, any SQL, and any hardcoded
 * DFC context URL or DFC version. The context namespace and version are read
 * from configuration resolved at runtime (see the dfc_default_namespace
 * setting, which defaults to empty on purpose).
 *
 * @package CRM
 * @see https://docs.civicrm.org/dev/en/latest/extensions/
 */

// -----------------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------------

/**
 * CiviCRM version range this extension is written for.
 *
 * MUST stay in sync with <compatibility> in info.xml, which is the gate CiviCRM
 * actually enforces at install time. The floor of 5.74 is the highest of:
 * 5.68 setting-admin@1, 5.71 smarty@1.0.3, 5.73 entity-types-php@2.0.0 and
 * 5.74 hook_civicrm_permission 'implies'.
 */
const DFC_CIVICRM_VERSION_MIN = '5.74';

/**
 * Upper bound. CiviCRM 6.x is the current series; 6.99 means "all of 6.x".
 */
const DFC_CIVICRM_VERSION_MAX = '6.99';

/**
 * Settings group / settings-page key.
 *
 * The setting-admin@1 mixin keys off this: it auto-places the route
 * civicrm/admin/setting/dfc_civicrm, links it from
 * Administer > System Settings, and assigns every setting whose
 * 'group' === 'dfc_civicrm' to that page. It also creates the separate
 * permission "administer dfc_civicrm" for the page.
 */
const DFC_SETTING_GROUP = 'dfc_civicrm';

/**
 * Text domain for ts(). Matches the extension key, per the
 * "Extension Structure" guide: ts('...', ['domain' => 'dfc_civicrm']).
 */
const DFC_I18N_DOMAIN = 'dfc_civicrm';

// -----------------------------------------------------------------------------
// Lifecycle
// -----------------------------------------------------------------------------

/**
 * Implements hook_civicrm_version.
 *
 * WARNING: this hook is NOT in the current CiviCRM hook list
 * (https://docs.civicrm.org/dev/en/latest/hooks/list/) and no extension shipped
 * with civicrm-core 6.x implements it. It is retained because it is harmless on
 * CMS adapters that still honour it, but it is NOT the authoritative
 * compatibility declaration - <compatibility> in info.xml is. If a future
 * CiviCRM drop makes this hook fatal, delete this function and keep
 * info.xml as the single source of truth.
 *
 * @return array{min: string, max: string}
 *   The accepted CiviCRM version range.
 *
 * @see https://docs.civicrm.org/dev/en/latest/extensions/info-xml/
 */
function dfc_civicrm_civicrm_version(): array
{
    return [
        'min' => DFC_CIVICRM_VERSION_MIN,
        'max' => DFC_CIVICRM_VERSION_MAX,
    ];
}

/**
 * Implements hook_civicrm_install.
 *
 * There is nothing to do here on purpose. Everything this extension ships is
 * declarative and is applied by the framework:
 *
 *   - schema/*.entityType.php + the CiviMix\Schema\DfcCivicrm\AutomaticUpgrader
 *     declared in info.xml create civicrm_dfc_identity and civicrm_dfc_webid.
 *   - managed/*.mgd.php create the custom group/field and relationship types.
 *   - xml/Menu/dfc_civicrm.xml is loaded by the menu-xml@1 mixin.
 *
 * DATA SAFETY: install must never touch contact data. It does not.
 *
 * @see \CiviMix\Schema\DfcCivicrm\AutomaticUpgrader
 * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install/
 */
function dfc_civicrm_civicrm_install(): void
{
    // Intentionally empty. See the docblock.
}

/**
 * Implements hook_civicrm_enable.
 *
 * CP-2 requires disable/re-enable to be clean. Disabling an extension makes
 * CiviCRM drop its routes, settings and entity declarations from the caches;
 * re-enabling must rebuild them. CiviCRM does the cache rebuild itself, so this
 * only has to make sure our own lazily-registered listeners are attached again
 * on the new page view.
 *
 * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable/
 */
function dfc_civicrm_civicrm_enable(): void
{
    dfc_civicrm_register_autoloader();
}

/**
 * Implements hook_civicrm_uninstall.
 *
 * ============================ DATA SAFETY =============================
 * Uninstalling (or disabling) dfc_civicrm MUST NOT delete, disable, merge,
 * rename or hide any underlying CiviCRM contact, address, phone, email,
 * website, relationship or custom-field value.
 *
 * Rationale: a CiviCRM contact is the authoritative record. DFC is an
 * interchange format projected onto it, not a replacement for it. PRD-002 makes
 * this an explicit requirement, and CR-4 ("represent DFC resources as useful
 * native CiviCRM records ... dedup, normal UI") is meaningless if removing the
 * extension destroys those records.
 *
 * What uninstall DOES touch, and only this:
 *   1. The two extension-owned tables (civicrm_dfc_identity,
 *      civicrm_dfc_webid), via the AutomaticUpgrader declared in info.xml.
 *      These hold only DFC identity bookkeeping and may be regenerated from a
 *      re-import, so dropping them loses no CiviCRM data.
 *   2. The managed custom group / custom field and managed relationship types
 *      declared in managed/*.mgd.php, with cleanup mode "always".
 *      The webId custom field is a CACHE (see
 *      managed/CustomGroup_DFCIdentity.mgd.php); the authoritative mapping lives
 *      in civicrm_dfc_webid and is being dropped anyway.
 *
 * What uninstall must never do - and does not do here:
 *   - delete or trash contacts, or run any merge/dedupe routine;
 *   - clear, blank or rewrite any contact custom-field value;
 *   - delete relationships between contacts;
 *   - touch civicrm_log, civicrm_entity_log or any audit trail.
 *
 * Any future change that would violate this must be a Reducer/Regression
 * (REG-NNN) per the farm rules, not a silent refactor.
 *
 * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall/
 * @see managed/README.md
 */
function dfc_civicrm_civicrm_uninstall(): void
{
    // Intentionally empty. Both effects are driven declaratively - see the
    // docblock. In particular, do NOT add contact-cleanup code here without
    // reopening the DATA SAFETY contract above.
}

// -----------------------------------------------------------------------------
// Permissions
// -----------------------------------------------------------------------------

/**
 * Implements hook_civicrm_permission.
 *
 * Registers the four DFC permissions as a chain:
 *
 *     access dfc api        (leaf - can reach the /civicrm/dfc/v2 routes at all)
 *       ^ read dfc data
 *           ^ write dfc data
 *               ^ administer dfc
 *
 * 'implies' is supported from CiviCRM 5.74 (which is why the compatibility
 * floor is 5.74 and not lower). Granting a higher permission automatically
 * grants everything below it; you cannot hold "write" without "read" and
 * "access". On an older core the 'implies' key is simply ignored, in which case
 * the permissions stay independent - the route-level check for
 * "access dfc api" still holds, so the exposure does not widen.
 *
 * Array shape: the hook consumes ['label' => ...] (required), 'description',
 * and optionally 'disabled', 'implies', 'implied_by'. There is no 'name', 'group'
 * or 'is_active' key here - the array KEY is the permission name, and
 * 'group'/'is_active' belong to the separate hook_civicrm_permissionList hook
 * (see CRM_Core_Permission_List::findCiviPermissions).
 *
 * @param array<string, array<string, mixed>> $permissions
 *   Associative array keyed by permission name. Modified in place.
 *
 * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_permission/
 */
function dfc_civicrm_civicrm_permission(array &$permissions): void
{
    $permissions['access dfc api'] = [
        'label' => ts('DFC: access the DFC v2 API', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'Reach the /civicrm/dfc/v2 routes. This is the gateway permission only - it grants no data access by itself.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
    ];

    $permissions['read dfc data'] = [
        'label' => ts('DFC: read DFC data', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'Read DFC v2 resources over HTTP, subject to the public/private export allow-list (default: not exported).',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'implies' => ['access dfc api'],
    ];

    $permissions['write dfc data'] = [
        'label' => ts('DFC: write DFC data', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'Create and update DFC v2 resources over HTTP, which may write to the underlying CiviCRM records.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'implies' => ['read dfc data'],
    ];

    $permissions['administer dfc'] = [
        'label' => ts('DFC: administer DFC', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'Full administrative control of the DFC interface: trusted issuers, audiences, accepted algorithms, scope-to-permission mapping, read/write policy, auto-create policy and vocabulary sync.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'implies' => ['write dfc data'],
    ];
}

// -----------------------------------------------------------------------------
// Menu
// -----------------------------------------------------------------------------

/**
 * Implements hook_civicrm_alterMenu.
 *
 * The concrete DFC routes are declared in xml/Menu/dfc_civicrm.xml and reach
 * CiviCRM through the menu-xml@1 mixin. This hook only adds the routes that are
 * PHP-only and have no XML representation, and it is also the seam where the
 * extension registers its own admin entry points.
 *
 * NOTE: do not duplicate the /civicrm/dfc/v2 routes here. The XML file is the
 * single declaration site for the protocol surface; declaring it twice produces
 * two civicrm_menu rows and an ambiguous route table.
 *
 * @param array<string, array<string, mixed>> $items
 *   HTTP routes keyed by relative path. Modified in place.
 *
 * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterMenu/
 * @see https://docs.civicrm.org/dev/en/latest/framework/routing/
 * @see xml/Menu/dfc_civicrm.xml
 */
function dfc_civicrm_civicrm_alterMenu(array &$items): void
{
    // The admin settings page (civicrm/admin/setting/dfc_civicrm, served by
    // CRM_Admin_Form_Generic) and the "Administer > System Settings" link are
    // both created by the setting-admin@1 mixin. Nothing to add.
    //
    // Deliberately NOT registered here:
    //   - any /civicrm/dfc/v2/* route  -> see xml/Menu/dfc_civicrm.xml
    //   - a bare civicrm/dfc/v2 route  -> a service-description document is not
    //     named in PRD-002; inventing one here would put an undocumented URL in
    //     the DFC contract. Lane-1 should add it if the contract requires it.
}

// -----------------------------------------------------------------------------
// Configuration / admin settings
// -----------------------------------------------------------------------------

/**
 * Implements hook_civicrm_config.
 *
 * Attaches the settings-metadata listener. The settings themselves are
 * declared by dfc_civicrm_settings(); this hook only wires them into
 * Civi\Core\SettingsMetadata, which is what CRM_Admin_Form_Generic and
 * \Civi::settings() read.
 *
 * Business logic for every setting lives elsewhere (or not yet); this file only
 * declares names, types, defaults, labels and help text.
 *
 * @param mixed $config
 *   The global config array. Unused - kept for signature compatibility.
 *
 * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 * @see https://docs.civicrm.org/dev/en/latest/framework/setting/definitions/
 */
function dfc_civicrm_civicrm_config(&$config): void
{
    if (!empty(Civi::$statics[__FUNCTION__])) {
        return;
    }
    Civi::$statics[__FUNCTION__] = 1;

    Civi::dispatcher()->addListener('&hook_civicrm_alterSettingsMetaData', 'dfc_civicrm_alterSettingsMetaData');

    dfc_civicrm_register_autoloader();
}

/**
 * Listener for hook_civicrm_alterSettingsMetaData.
 *
 * Appends the DFC settings to the metadata list. CiviCRM passes
 * $settingsMetaData as a numerically-indexed array of specs, each carrying a
 * 'name' key; the setting-admin@1 mixin then adds every spec whose
 * 'group' === 'dfc_civicrm' to the civicrm/admin/setting/dfc_civicrm page.
 *
 * Declared this way (rather than as settings/*.setting.php files) so the whole
 * settings contract is readable in one place next to the permissions it gates.
 *
 * @param array<int, array<string, mixed>> $settingsMetaData
 *   All settings metadata. Modified in place.
 * @param mixed $domainID
 *   The domain the metadata is being built for. Left untyped on purpose: the
 *   hook is dispatched by CRM_Utils_Hook::alterSettingsMetaData() and this file
 *   declares strict_types, so a narrow type here would turn a future core change
 *   into a fatal error rather than a coercion.
 * @param mixed $profile
 *   Unused; present for signature compatibility.
 *
 * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsMetaData/
 * @see \Civi\Core\SettingsMetadata::getFullMetadata()
 */
function dfc_civicrm_alterSettingsMetaData(array &$settingsMetaData, $domainID = NULL, $profile = NULL): void
{
    $settingsMetaData = array_merge($settingsMetaData, dfc_civicrm_settings());
}

/**
 * The DFC v2 settings contract.
 *
 * Names, types and defaults only. Each entry follows the CiviCRM settings
 * metadata spec (see ext/authx/settings/authx.setting.php):
 *
 *   name          string   the setting name; also the value key
 *   title         string   form label
 *   description   string   one-line help
 *   help_text     string   long help
 *   type          string   Boolean | Integer | String | Float
 *   html_type     string   form control: CheckBox | Select | Text | Number
 *   options       array    value => label, for html_type=Select
 *   default       mixed    the value used until an administrator saves
 *   group         string   must be DFC_SETTING_GROUP for the settings page
 *   is_domain     int      1 = per-domain (multi-site safe)
 *   is_contact    int      0 = not a per-contact setting
 *   add           string   the extension version that introduced it
 *
 * SECURITY NOTES, all deliberate:
 *   - dfc_enabled defaults to FALSE. An extension that serves a data API does
 *     not come up enabled.
 *   - dfc_oidc_accepted_algorithms defaults to RS256 only. 'none' and the
 *     HMAC family are never defaults; an administrator who widens this is
 *     opting out of asymmetric verification.
 *   - dfc_default_namespace defaults to ''. The DFC context URL and version are
 *     NOT hardcoded anywhere in this extension; they are resolved at runtime
 *     from the connector's release configuration. This setting is only an
 *     administrator override for a site that serves a pinned DFC version.
 *   - dfc_auto_create_contacts defaults to FALSE. Creating contacts is
 *     irreversible-ish and must be an explicit choice.
 *   - dfc_read_policy defaults to the allow-list branch, i.e. default-deny on
 *     export, per the farm's "export is an allow-list, default is not-exported"
 *     rule.
 *
 * @return array<int, array<string, mixed>>
 *   List of settings metadata specs.
 *
 * @see dfc_civicrm_civicrm_config()
 * @see https://docs.civicrm.org/dev/en/latest/framework/setting/definitions/
 */
function dfc_civicrm_settings(): array
{
    $common = [
        'group' => DFC_SETTING_GROUP,
        'group_name' => ts('DFC v2', ['domain' => DFC_I18N_DOMAIN]),
        'is_domain' => 1,
        'is_contact' => 0,
        'add' => '0.1',
    ];

    $settings = [];

    // -- 1. Master switch ----------------------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_enabled',
        'title' => ts('Serve the DFC v2 interface', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'Master switch. When disabled, every /civicrm/dfc/v2 route returns 404 and no DFC data is exported.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'help_text' => ts(
            'Off by default. Turning this on does NOT make DFC data public: the routes still require the "access dfc api" permission and the export allow-list still defaults to not-exported.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'Boolean',
        'html_type' => 'CheckBox',
        'default' => FALSE,
    ];

    // -- 2. Public base path -------------------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_base_path',
        'title' => ts('Public API base path', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'Path the DFC interface is advertised under, relative to the site base URL.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'help_text' => ts(
            'This is the PUBLIC path advertised in WebID documents and the Identity Service response. The internal CiviCRM route table lives under civicrm/dfc/v2 and is not configurable. Change this only when the DFC base path is fronted by a reverse proxy that rewrites the prefix.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Text',
        'default' => 'dfc/v2',
    ];

    // -- 3. Auth mode --------------------------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_auth_mode',
        'title' => ts('Authentication mode', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'How a caller proves who it is before any DFC resource is served.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'help_text' => ts(
            'oidc validates OIDC ACCESS tokens (never ID tokens) against the trusted issuers below. api_key_fallback additionally accepts a CiviCRM API key, for trusted service-to-service callers. disabled serves nothing.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Select',
        'options' => [
            'oidc' => ts('OIDC access tokens only', ['domain' => DFC_I18N_DOMAIN]),
            'oidc_api_key' => ts('OIDC access tokens, with API-key fallback', ['domain' => DFC_I18N_DOMAIN]),
            'disabled' => ts('Disabled', ['domain' => DFC_I18N_DOMAIN]),
        ],
        'default' => 'oidc',
    ];

    // -- 4. Trusted issuers --------------------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_oidc_trusted_issuers',
        'title' => ts('OIDC trusted issuers', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'Issuer URLs whose access tokens are accepted, one per line.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'help_text' => ts(
            'A token whose "iss" claim is not in this list is rejected before any data mutation. JWKS is fetched per issuer and cached; an unknown "kid" forces one refresh. Do not use "*" - there is no wildcard, an empty list means no issuer is trusted.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Text',
        'default' => '',
    ];

    // -- 5. Expected audience ------------------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_oidc_expected_audience',
        'title' => ts('Expected audience', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'Value the access token "aud" claim must match, one per line.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'help_text' => ts(
            'Leave empty to skip audience validation. A non-empty list is enforced strictly: a token valid for a different resource server is rejected.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Text',
        'default' => '',
    ];

    // -- 6. Accepted signing algorithms ---------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_oidc_accepted_algorithms',
        'title' => ts('Accepted signing algorithms', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts('JWS "alg" values accepted for access-token signatures, one per line.', ['domain' => DFC_I18N_DOMAIN]),
        'help_text' => ts(
            'Defaults to RS256 only. "none" and the HMAC family (HS256/HS384/HS512) are never defaults: accepting them lets a caller forge tokens with public information. Widen this only after a security review (CP-7).',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Text',
        'default' => 'RS256',
    ];

    // -- 7. Scope -> permission mapping --------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_scope_permission_map',
        'title' => ts('Scope-to-permission mapping', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'Maps OIDC scopes to CiviCRM permissions, one "<scope> = <permission>" per line.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'help_text' => ts(
            'Example: "dfc.read = read dfc data". A token must carry BOTH the mapped permission on the calling CiviCRM user AND the scope, so a scope can never widen what the user may do. An unmapped scope grants nothing. Mapping a scope to a permission the user does not hold is a federation problem, not a config bug - see PRD-002 learning goal "scope-to-permission coherence".',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Text',
        'default' => '',
    ];

    // -- 8. Default DFC namespace / base URI ---------------------------------

    $settings[] = $common + [
        'name' => 'dfc_default_namespace',
        'title' => ts('DFC namespace and base URI override', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts(
            'Overrides the DFC context namespace and base URI. Empty means "use the connector\'s released values".',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'help_text' => ts(
            'INTENTIONALLY EMPTY BY DEFAULT. The DFC context URL and the DFC version are never hardcoded in this extension: they are resolved at runtime from the DFC connector\'s release configuration, so a DFC release bumps the namespace without a code change here. Set this only to pin a site to a specific DFC version.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Text',
        'default' => '',
    ];

    // -- 9/10. Read and write policy -----------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_read_policy',
        'title' => ts('Read policy', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts('Which checks must pass before a DFC resource is exported.', ['domain' => DFC_I18N_DOMAIN]),
        'help_text' => ts(
            'permission_and_allowlist (default) requires the read permission AND that the predicate is on the export allow-list. A predicate that is not on the list is NOT exported - default deny. allowlist_only drops the CiviCRM permission check and is unsafe.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Select',
        'options' => [
            'permission_and_allowlist' => ts('Permission AND export allow-list (default deny)', ['domain' => DFC_I18N_DOMAIN]),
            'allowlist_only' => ts('Export allow-list only (no permission check)', ['domain' => DFC_I18N_DOMAIN]),
        ],
        'default' => 'permission_and_allowlist',
    ];

    $settings[] = $common + [
        'name' => 'dfc_write_policy',
        'title' => ts('Write policy', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts('Which checks must pass before a DFC resource is created or updated.', ['domain' => DFC_I18N_DOMAIN]),
        'help_text' => ts(
            'disabled (default) refuses every write. permission_and_allowlist additionally requires a write policy for the affected predicate, so a field with no write rule stays read-only.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Select',
        'options' => [
            'disabled' => ts('Disabled - read only (default)', ['domain' => DFC_I18N_DOMAIN]),
            'permission_and_allowlist' => ts('Permission AND write allow-list', ['domain' => DFC_I18N_DOMAIN]),
        ],
        'default' => 'disabled',
    ];

    // -- 11. Auto-create contacts --------------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_auto_create_contacts',
        'title' => ts('Auto-create contacts on import', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts('Allow a DFC import to create new CiviCRM contacts.', ['domain' => DFC_I18N_DOMAIN]),
        'help_text' => ts(
            'Off by default. With this off, an import that meets no contact creates nothing and reports the miss. With it on, a DFC resource still never silently MERGES an existing contact - an ambiguous match becomes a reviewable conflict record (PRD-002 CR-5, critical rule 5).',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'Boolean',
        'html_type' => 'CheckBox',
        'default' => FALSE,
    ];

    // -- 12. Unknown-type policy ---------------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_unknown_type_policy',
        'title' => ts('Unknown DFC type policy', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts('What to do with a DFC resource whose type this release does not implement.', ['domain' => DFC_I18N_DOMAIN]),
        'help_text' => ts(
            'reject (default) returns the spec-defined unsupported-resource error. passthrough stores nothing and exports nothing - it is a diagnostic mode, not a mapping. Product and Order surfaces are ALWAYS rejected regardless of this setting; they are out of scope, not merely unmapped.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Select',
        'options' => [
            'reject' => ts('Reject with the unsupported-resource error (default)', ['domain' => DFC_I18N_DOMAIN]),
            'passthrough' => ts('Pass through unchanged, persist nothing', ['domain' => DFC_I18N_DOMAIN]),
        ],
        'default' => 'reject',
    ];

    // -- 13. Log level -------------------------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_log_level',
        'title' => ts('DFC log level', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts('Verbosity of the extension\'s own audit log.', ['domain' => DFC_I18N_DOMAIN]),
        'help_text' => ts(
            'Structured entries only: a correlation id and an error code. Access tokens, refresh tokens, client secrets, Authorization headers and full personal-data JSON-LD are never logged at any level.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Select',
        'options' => [
            'error' => ts('Errors only', ['domain' => DFC_I18N_DOMAIN]),
            'warning' => ts('Errors and warnings (default)', ['domain' => DFC_I18N_DOMAIN]),
            'info' => ts('Informational', ['domain' => DFC_I18N_DOMAIN]),
            'debug' => ts('Debug (never enable on a site holding personal data)', ['domain' => DFC_I18N_DOMAIN]),
        ],
        'default' => 'warning',
    ];

    // -- 14. Vocabulary sync policy ------------------------------------------

    $settings[] = $common + [
        'name' => 'dfc_vocabulary_sync_policy',
        'title' => ts('Controlled vocabulary sync policy', ['domain' => DFC_I18N_DOMAIN]),
        'description' => ts('How DFC controlled vocabularies are brought into this installation.', ['domain' => DFC_I18N_DOMAIN]),
        'help_text' => ts(
            'manual (default) applies nothing until an administrator runs the sync from the DFC admin screen. scheduled runs it from a cron job. Never auto-sync a vocabulary: the vocabulary and the schema are separate sources and the curated parity map lags the generated schema (PRD-002 risk table), so an automatic sync can silently change meaning.',
            ['domain' => DFC_I18N_DOMAIN]
        ),
        'type' => 'String',
        'html_type' => 'Select',
        'options' => [
            'manual' => ts('Manual, from the DFC admin screen (default)', ['domain' => DFC_I18N_DOMAIN]),
            'scheduled' => ts('Scheduled from a cron job', ['domain' => DFC_I18N_DOMAIN]),
            'off' => ts('Off - no vocabulary data at all', ['domain' => DFC_I18N_DOMAIN]),
        ],
        'default' => 'manual',
    ];

    return $settings;
}

// -----------------------------------------------------------------------------
// Class loading
// -----------------------------------------------------------------------------

/**
 * Fallback PSR-4 autoloader for the Civi\Dfc\ namespace.
 *
 * In a normal CiviCRM page view this is a no-op, because two other loaders
 * already own the prefix:
 *
 *   1. info.xml <classloader><psr4 prefix="Civi\Dfc\" path="Civi/Dfc"/> is read
 *      by CRM_Extension_Info::parse() and applied by
 *      CRM_Extension_ClassLoader::loadExtension() via
 *      Composer\Autoload\ClassLoader::addPsr4().
 *   2. composer.json autoload.psr-4 maps the same prefix to the same directory
 *      whenever vendor/ is present (unit tests, CI, static analysis).
 *
 * The fallback exists for the contexts where neither is bootstrapped yet - an
 * early CLI invocation, or a schema/codegen run - so that the two never drift
 * apart silently. It deliberately refuses to register while CiviCRM's own class
 * loader is present, to avoid shadowing core classes.
 *
 * Keep the prefix and directory in sync with composer.json's autoload block and
 * with info.xml's <classloader>.
 *
 * @return void
 */
function dfc_civicrm_register_autoloader(): void
{
    if (class_exists('CRM_Extension_ClassLoader', FALSE)) {
        // CiviCRM's extension class loader is active; it already has this
        // prefix from info.xml. Do not compete with it.
        return;
    }

    $prefix = 'Civi\\Dfc\\';
    $baseDir = __DIR__ . '/Civi/Dfc/';

    spl_autoload_register(
        static function (string $class) use ($prefix, $baseDir): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        }
    );
}

dfc_civicrm_register_autoloader();
