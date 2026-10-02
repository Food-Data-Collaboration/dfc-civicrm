{*
  DFC v2 admin configuration screen.

  STATUS: NOT CURRENTLY ROUTED. Read this before wiring it up.

  How the DFC settings screen actually renders today
  --------------------------------------------------
  The settings form is metadata-driven. The `setting-admin@1` mixin (declared in
  info.xml) registers the route

      civicrm/admin/setting/dfc_civicrm
          page_callback  CRM_Admin_Form_Generic
          page_arguments dfc_civicrm            <- the settings-page filter

  and `CRM_Admin_Form_Generic::preProcess()` selects every setting whose
  metadata has `settings_pages.dfc_civicrm` set. The `setting-admin` mixin assigns
  that automatically to every setting whose `group` is `dfc_civicrm`, which is
  what `dfc_civicrm_settings()` in ../dfc_civicrm.php declares. The screen is then
  rendered by core's own templates/CRM/Admin/Form/Generic.tpl.php.

  That path is fully supported and is what CP-2 will be verified against.

  Why this file exists anyway
  ---------------------------
  Two things the metadata-driven form cannot do, both of which are lane work and
  neither of which has landed yet:

    1. show a read-only summary of the RESOLVED DFC context namespace and
       version, resolved at runtime from the connector's release configuration
       (an administrator needs to see which DFC release this site actually
       serves, and `dfc_default_namespace` may be an override on top of it); and
    2. offer the actions that must not be bare form fields - run the vocabulary
       sync, re-derive the `webId` contact cache from civicrm_dfc_webid, and
       report DFC health. PRD-002 Step 6 owns all three, and they are actions,
       not settings.

  The CiviCRM convention for a hand-written admin page is a `templates/hook/`
  template. Caveat, stated because it matters: the documented routing schema
  (docs.civicrm.org/dev/en/latest/framework/routing/) does NOT list `hook` as an
  acceptable <page_type>, and no extension shipped with civicrm-core 6.x uses
  it. Before routing to this template, the lane that owns the DFC admin UX
  (lane-4) must confirm the page_type on a real instance across Drupal, WordPress
  and Joomla. Do not guess it into info.xml or the menu XML on the strength of
  this comment.

  Template contract, once routed
  ------------------------------
  CiviCRM assigns `settingPageName`, `settingSections` and `fields` (see
  CRM_Admin_Form_Generic::buildQuickForm). `settingSections` is keyed by section
  name; each has `title`, `weight`, and `fields` (a name => metadata map). The
  form elements themselves are rendered by the parent template, so this template
  only ever adds a preamble and a place for action buttons - it must not
  re-render the fields, or Save will post twice.

  Data contract (declared here, implemented by lane-4)
  ----------------------------------------------------
    $dfc_resolved_namespace  string  The DFC context namespace actually in effect
                                    (override if set, else the connector's release
                                    value). Never hardcoded in this extension.
    $dfc_resolved_version    string  The DFC version in effect. Same rule.
    $dfc_cache_drift         int    Number of contacts whose cached `webId`
                                    disagrees with civicrm_dfc_webid. Non-zero
                                    means "re-derive", not "trust the field".
    $dfc_actions             array  Buttons to render, e.g.
                                    ['label' => ..., 'name' => ...]. Rendered as
                                    post-redirect-free plain submit buttons that
                                    dispatch through hook_civicrm_preProcess /
                                    postProcess on the owning form class.

  All four are optional. The template degrades to the explanatory note when they
  are absent, so it is safe to route before lane-4 exists.
*}
<div class="crm-container">
  <div class="crm-content">

    <h3>{ts('DFC v2')}</h3>

    <p class="help">
      {ts('The settings below are the DFC v2 configuration contract. The DFC context namespace and version are never hardcoded: they are resolved at runtime from the connector\'s release configuration, and may be overridden per site.')}
    </p>

    {if isset($dfc_resolved_namespace) && $dfc_resolved_namespace}
      <table class="form-layout">
        <tr>
          <td class="label">{ts('Resolved DFC namespace')}</td>
          <td><code>{$dfc_resolved_namespace}</code></td>
        </tr>
        {if isset($dfc_resolved_version) && $dfc_resolved_version}
          <tr>
            <td class="label">{ts('Resolved DFC version')}</td>
            <td><code>{$dfc_resolved_version}</code></td>
          </tr>
        {/if}
        {if isset($dfc_cache_drift) && $dfc_cache_drift}
          <tr>
            <td class="label">{ts('WebID cache drift')}</td>
            <td>
              <span class="crm-error">
                {ts('%1 contact(s) have a cached DFC WebID that disagrees with the authoritative DfcWebId table. Re-derive the cache; do not edit the field by hand.', [1 => $dfc_cache_drift])}
              </span>
            </td>
          </tr>
        {/if}
      </table>
    {else}
      <p class="help">
        {ts('The resolved DFC namespace and version are not available yet. That part of the screen is owned by the DFC administration work and is not wired up.')}
      </p>
    {/if}

    {if !empty($dfc_actions)}
      <div class="crm-button-row">
        {foreach $dfc_actions as $dfcAction}
          <input type="submit" name="{$dfcAction.name}" value="{$dfcAction.label}" class="crm-button" />
        {/foreach}
      </div>
    {/if}

  </div>
</div>
