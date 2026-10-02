<?php

declare(strict_types=1);

/**
 * DfcIdentity entity-type declaration.
 *
 * Loaded two ways, and both matter:
 *
 *   1. hook_civicrm_entityTypes, via the entity-types-php@2 mixin declared in
 *      info.xml. That is what makes `Civi\Api4\DfcIdentity` queryable.
 *   2. CiviMix\Schema\SchemaHelper::install() -> SqlGenerator::createFromFolder(),
 *      which reads every schema/*.entityType.php and emits CREATE TABLE. The
 *      AutomaticUpgrader named in info.xml calls that on install and the
 *      matching DROP TABLE on uninstall.
 *
 * This file is therefore the ONLY place civicrm_dfc_identity is described.
 * There is no SQL file in this extension, by design: the whole point of the
 * entity-types mixin is that install/upgrade/uninstall is derived from the
 * declaration, so it cannot drift from what APIv4 exposes.
 *
 * @see Civi\Api4\DfcIdentity        - the APIv4 class bound to this declaration
 * @see Civi/Api4/DfcWebId.entityType.php
 * @see https://docs.civicrm.org/dev/en/latest/framework/entities/
 * @see https://docs.civicrm.org/dev/en/latest/api/v4/architecture/
 *
 * @package CRM
 */

/**
 * OWNERSHIP / HAND-OFF NOTICE.
 *
 * The 'class' key below names the DAO that APIv4 instantiates to read and write
 * this table: CRM_Dfc_DAO_DfcIdentity, expected at CRM/Dfc/DAO/DfcIdentity.php
 * (PSR-0, reachable through info.xml's `<psr0 prefix="CRM_Dfc_" path="CRM/Dfc"/>`).
 *
 * THAT DAO DOES NOT EXIST YET. `CRM/` is not owned by the extension-shell
 * lane, and lane-3 (sa-009, "Identity entities") is the lane whose assignment
 * names civicrm_dfc_identity and civicrm_dfc_webid explicitly. Creating the
 * file here would have collided with that agent.
 *
 * Consequence, stated plainly: install, enable, disable, the settings screen,
 * the routes, the managed entities and the *schema* all work without the DAO,
 * but any APIv4 get/save/create/update/delete against DfcIdentity fails with
 * "class not found" until the DAO lands. This is the single blocking gap for
 * CP-2 and is raised as a hand-off in sa-007's report.
 */
return [
  'name' => 'DfcIdentity',
  'table' => 'civicrm_dfc_identity',
  'class' => 'CRM_Dfc_DAO_DfcIdentity',

  'getInfo' => fn() => [
    'title' => ts('DFC Identity', ['domain' => 'dfc_civicrm']),
    'title_plural' => ts('DFC Identities', ['domain' => 'dfc_civicrm']),
    'description' => ts(
      'Stable DFC v2 semantic identifiers, mapped onto the CiviCRM table and row that carries each DFC resource. This is the authoritative identity mapping.',
      ['domain' => 'dfc_civicrm']
    ),
    // DFC identities are personal data in effect, so keep a change trail.
    'log' => TRUE,
    'icon' => 'crm-i fa-id-card',
    'label_field' => 'semantic_id',
    'add' => '0.1',
  ],

  // No 'getPaths' => admin screens for this entity are lane-4's
  // ("APIv4 administration actions") and do not exist yet. Declaring no paths
  // keeps the API explorer from advertising a route that 404s.

  'getIndices' => fn() => [
    // The DFC semantic identifier is the primary key of the DFC world. It must
    // be UNIQUE: two rows claiming the same DFC identifier is the exact
    // corruption that makes imports non-idempotent (PRD-002 CR-3).
    'UI_semantic_id' => [
      'fields' => [
        'semantic_id' => TRUE,
      ],
      'unique' => TRUE,
      'add' => '0.1',
    ],
    // Reverse lookup: given a CiviCRM row, which DFC identity claims it?
    'I_entity' => [
      'fields' => [
        'entity_table' => 64,
        'entity_id' => TRUE,
      ],
      'add' => '0.1',
    ],
    // Import dedupe: match on (dfc_type, semantic_id) within one resource type.
    'I_type' => [
      'fields' => [
        'dfc_type' => 128,
        'semantic_id' => TRUE,
      ],
      'add' => '0.1',
    ],
  ],

  'getFields' => fn() => [
    'id' => [
      'title' => ts('DFC Identity ID', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => ts('Unique DFC Identity ID', ['domain' => 'dfc_civicrm']),
      'add' => '0.1',
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],

    'semantic_id' => [
      'title' => ts('DFC Semantic Identifier', ['domain' => 'dfc_civicrm']),
      // The DFC spec caps identifiers well below 255; 255 is the portable
      // indexed width across MySQL/MariaDB on utf8mb4.
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => ts(
        'The DFC v2 semantic identifier for this resource, exactly as it appears in the DFC graph. UNIQUE, and stable across renames, hostname changes, duplicate imports and contact merges.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'entity_table' => [
      'title' => ts('CiviCRM Table', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => ts(
        'Name of the CiviCRM table this DFC resource is stored in, e.g. civicrm_contact. Polymorphic on purpose: DFC Organization and Person both map to contacts, and later DFC classes may not.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'entity_id' => [
      'title' => ts('CiviCRM Row ID', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => ts(
        'Primary key of the row named by entity_table. Deliberately NOT a foreign key: entity_table is polymorphic, so a single FK cannot be declared, and a wrong hard-coded FK would turn a contact delete into a constraint violation.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'dfc_type' => [
      'title' => ts('DFC Type', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(128)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => ts(
        'The DFC v2 class of the mapped resource, e.g. dfc-b:Organization. NOT a hardcoded vocabulary: the accepted values come from the DFC schema version resolved at runtime, so a DFC release that adds a class needs no schema change here.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'created_date' => [
      'title' => ts('Created Date', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'required' => TRUE,
      'description' => ts('When this DFC identity was first recorded.', ['domain' => 'dfc_civicrm']),
      'add' => '0.1',
      'default' => 'CURRENT_TIMESTAMP',
    ],

    'modified_date' => [
      'title' => ts('Modified Date', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'required' => TRUE,
      'description' => ts('When this DFC identity was created or last changed.', ['domain' => 'dfc_civicrm']),
      'add' => '0.1',
      'default' => 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
  ],
];
