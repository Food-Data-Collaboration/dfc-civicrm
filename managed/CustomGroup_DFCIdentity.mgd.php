<?php

declare(strict_types=1);

/**
 * Managed custom group + custom field: the contact-level DFC `webId` CACHE.
 *
 * ======================= READ THIS BEFORE EDITING =======================
 *
 * THE `webId` FIELD BELOW IS A CACHE FOR ADMINISTRATOR VISIBILITY.
 * IT IS **NOT** THE AUTHORITATIVE IDENTITY MAPPING.
 *
 * The authoritative mapping lives in the `civicrm_dfc_webid` table (declared in
 * schema/DfcWebId.entityType.php, class Civi\Api4\DfcWebId). That table is the
 * only thing that decides which WebID a contact, a user or the platform has,
 * what its verification status is, and how an OIDC subject resolves to it.
 *
 * This custom field exists so that a CiviCRM administrator looking at a contact
 * record can see the DFC WebID at a glance, in the normal UI, in SearchKit, in
 * reports and in FormBuilder - which is what PRD-002 CR-4 asks for. That is the
 * whole of its job.
 *
 * WHY IT MUST STAY A CACHE:
 *
 *   1. A contact may legitimately have more than one WebID, and a WebID may
 *      exist with no contact at all (the platform WebID). A single contact field
 *      cannot represent that, so if anything wrote to it authoritatively it
 *      would lose data.
 *   2. Writing to a contact custom field is a write to the CONTACT. If the DFC
 *      importer treated this field as the source of truth, an ordinary DFC
 *      import could silently mutate a CiviCRM record. PRD-002 forbids that.
 *   3. Uninstalling the extension drops this field with cleanup mode 'always'.
 *      If anything authoritative lived here, uninstalling would destroy it -
 *      which dfc_civicrm_civicrm_uninstall() explicitly promises it will not do.
 *
 * CORRECTION RULE (applies to lane-3 and lane-4): every write to this field
 * must be derived from civicrm_dfc_webid, and every read for DFC purposes must
 * ignore it. A divergence between the two is a bug, not a cache miss - the
 * correct repair is to re-derive the field from the table, never the reverse.
 *
 * GROUP SCOPE: the group extends Contact, so the field renders on Individual and
 * Organization - the two contact types the DFC in-scope surface (Person,
 * Organization) actually uses. It will also render on Household. Narrowing it to
 * Individual+Organization only would mean two subtype-extending groups instead
 * of one, which doubles the managed surface for no DFC benefit; the DFC
 * vocabulary has no Household class. Revisit if lane-4 finds Household data that
 * needs a WebID.
 *
 * @see ../managed/README.md
 * @see ../schema/DfcWebId.entityType.php
 * @see Civi\Api4\DfcWebId
 * @see https://docs.civicrm.org/dev/en/latest/extensions/managed/
 *
 * @package CRM
 */

return [
  // ---------------------------------------------------------------------
  // The custom group.
  //
  // 'match' => ['name'] is what makes this safe to re-run: the managed system
  // finds an existing group by name instead of creating a second one. 'name' is
  // the natural key here; do NOT match on 'title', which an administrator may
  // legitimately retitle.
  //
  // update: unmodified  - honour an administrator's retitling.
  // cleanup: always     - remove the group on uninstall. Safe because the group
  //                       holds no authoritative data (see the notice above).
  // ---------------------------------------------------------------------
  [
    'name' => 'dfc_civicrm_group_identity',
    'entity' => 'CustomGroup',
    'update' => 'unmodified',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'dfc_identity',
        'title' => ts('DFC Identity', ['domain' => 'dfc_civicrm']),
        'description' => ts(
          'DFC v2 identity display. The WebID shown here is a CACHE derived from civicrm_dfc_webid; it is not the authoritative mapping and is not editable as a source of truth.',
          ['domain' => 'dfc_civicrm']
        ),
        'extends' => 'Contact',
        // Single-record group: one cache value per contact, not a list. The
        // full set always lives in civicrm_dfc_webid.
        'is_multiple' => FALSE,
        'is_active' => TRUE,
        'group_display_type' => 'Form',
        'style' => 'Stack',
        'collapse_display' => FALSE,
        'help_pre' => '',
        'help_post' => ts(
          'Managed by the DFC v2 extension. Any edit here is overwritten on the next upgrade; the authoritative record is the DfcWebId table.',
          ['domain' => 'dfc_civicrm']
        ),
      ],
      'match' => ['name'],
    ],
  ],

  // ---------------------------------------------------------------------
  // The `webId` cache field.
  //
  // data_type String + input_type Text: CiviCRM has no dedicated URL custom
  // field type. 'Url' is not in the allowed data_type list
  // (CRM_Core_BAO_CustomField::getDataType(): String, Int, Float, Money, Memo,
  // Date, Boolean, Link, File, ContactReference, EntityReference), and 'Link' is
  // deprecated. A 255-char String renders as a plain text box, which is the
  // honest representation of an IRI we do not dereference in the UI.
  //
  // update: always  - machine-managed cache, so declaration changes must win.
  // cleanup: always - nothing authoritative is lost; see the notice above.
  // ---------------------------------------------------------------------
  [
    'name' => 'dfc_civicrm_field_identity_webid',
    'entity' => 'CustomField',
    'update' => 'always',
    'cleanup' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        // Dotted path to the parent group, matched by the group's NAME (not id)
        // so the file is reproducible across installs.
        'custom_group_id.name' => 'dfc_identity',
        'name' => 'webId',
        'label' => ts('DFC WebID', ['domain' => 'dfc_civicrm']),
        'description' => ts(
          'Absolute URI of this contact\'s DFC WebID. CACHE ONLY - authoritative data is in civicrm_dfc_webid. A contact may have several WebIDs; only one is shown here.',
          ['domain' => 'dfc_civicrm']
        ),
        'data_type' => 'String',
        'input_type' => 'Text',
        'is_required' => FALSE,
        'is_searchable' => FALSE,
        'is_active' => TRUE,
        'options' => [
          'maxlength' => 255,
        ],
      ],
      // Match on the parent group plus the field name. Without the parent,
      // the managed system can confuse fields of the same name in other groups.
      'match' => ['custom_group_id', 'name'],
    ],
  ],
];
