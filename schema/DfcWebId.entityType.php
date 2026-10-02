<?php

declare(strict_types=1);

/**
 * DfcWebId entity-type declaration.
 *
 * Loaded two ways, and both matter:
 *
 *   1. hook_civicrm_entityTypes, via the entity-types-php@2 mixin declared in
 *      info.xml. That is what makes `Civi\Api4\DfcWebId` queryable.
 *   2. CiviMix\Schema\SchemaHelper::install() -> SqlGenerator::createFromFolder(),
 *      which reads every schema/*.entityType.php and emits CREATE TABLE. The
 *      AutomaticUpgrader named in info.xml calls that on install and the
 *      matching DROP TABLE on uninstall.
 *
 * This file is therefore the ONLY place civicrm_dfc_webid is described. There is
 * no SQL file in this extension, by design: install/upgrade/uninstall is derived
 * from the declaration, so it cannot drift from what APIv4 exposes.
 *
 * @see Civi\Api4\DfcWebId           - the APIv4 class bound to this declaration
 * @see schema/DfcIdentity.entityType.php
 * @see managed/CustomGroup_DFCIdentity.mgd.php - the contact-level CACHE field
 * @see https://docs.civicrm.org/dev/en/latest/framework/entities/
 * @see https://docs.civicrm.org/dev/en/latest/api/v4/architecture/
 *
 * @package CRM
 */

/**
 * OWNERSHIP / HAND-OFF NOTICE.
 *
 * The 'class' key below names the DAO that APIv4 instantiates to read and write
 * this table: CRM_Dfc_DAO_DfcWebId, expected at CRM/Dfc/DAO/DfcWebId.php
 * (PSR-0, reachable through info.xml's `<psr0 prefix="CRM_Dfc_" path="CRM/Dfc"/>`).
 *
 * THAT DAO DOES NOT EXIST YET - see the identical notice in
 * schema/DfcIdentity.entityType.php. `CRM/` is not owned by the extension-shell
 * lane; lane-3 (sa-009) owns these two tables.
 */
return [
  'name' => 'DfcWebId',
  'table' => 'civicrm_dfc_webid',
  'class' => 'CRM_Dfc_DAO_DfcWebId',

  'getInfo' => fn() => [
    'title' => ts('DFC WebID', ['domain' => 'dfc_civicrm']),
    'title_plural' => ts('DFC WebIDs', ['domain' => 'dfc_civicrm']),
    'description' => ts(
      'WebID documents served by this installation: the platform WebID, per-user WebIDs and Organization WebIDs. This is the authoritative WebID mapping; the contact custom field of the same name is only a cache.',
      ['domain' => 'dfc_civicrm']
    ),
    'log' => TRUE,
    'icon' => 'crm-i fa-id-badge',
    'label_field' => 'web_id',
    'add' => '0.1',
  ],

  // No 'getPaths' => admin screens are lane-4's work and do not exist yet.

  'getIndices' => fn() => [
    // A WebID is a URI: two rows with the same document address is corruption.
    'UI_web_id' => [
      'fields' => [
        'web_id' => TRUE,
      ],
      'unique' => TRUE,
      'add' => '0.1',
    ],
    // The OIDC subject -> WebID/contact resolution path (lane-3, sa-011).
    // Deliberately NOT unique: one subject may legitimately hold several WebIDs.
    'I_oidc_subject' => [
      'fields' => [
        'oidc_issuer' => 255,
        'oidc_subject' => 255,
      ],
      'add' => '0.1',
    ],
    // WebID -> contact, the hot path for every authenticated DFC request.
    'I_contact_id' => [
      'fields' => [
        'contact_id' => TRUE,
      ],
      'add' => '0.1',
    ],
    'I_kind' => [
      'fields' => [
        'kind' => TRUE,
      ],
      'add' => '0.1',
    ],
  ],

  'getFields' => fn() => [
    'id' => [
      'title' => ts('DFC WebID ID', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => ts('Unique DFC WebID ID', ['domain' => 'dfc_civicrm']),
      'add' => '0.1',
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],

    'web_id' => [
      'title' => ts('WebID', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => ts(
        'Absolute URI of this WebID document. UNIQUE. This is the address the document is dereferenced at; it is not the DFC semantic identifier, which lives in civicrm_dfc_identity.semantic_id.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'subject_uri' => [
      'title' => ts('Subject URI', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => ts(
        'URI of the resource this WebID describes. Kept separate from web_id because a WebID document may be about a resource at a different URI; the relationship between the two is a lane-4 (relationship registry) concern, not a storage concern.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'contact_id' => [
      'title' => ts('CiviCRM Contact', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      // NULLABLE ON PURPOSE. The platform WebID describes the installation and
      // belongs to no contact. Do not make this NOT NULL and do not invent a
      // placeholder contact to satisfy a constraint.
      'required' => FALSE,
      'description' => ts(
        'The contact this WebID belongs to. NULL for the platform WebID. ON DELETE SET NULL, so removing a contact orphans the WebID row instead of deleting it - a contact must never be destroyed by DFC bookkeeping, and a WebID must not block a legitimate contact deletion.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],

    'kind' => [
      'title' => ts('WebID Kind', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Select',
      'required' => TRUE,
      'description' => ts(
        'One of platform, user, organization. Persisted vocabulary - the constants and option list live in Civi\Api4\DfcWebId.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
      // Literal, not \Civi\Api4\DfcWebId::KIND_USER: this array is evaluated the
      // moment the file is included, so referencing a class here would make the
      // schema depend on autoloader load order. Civi\Api4\DfcWebId::KIND_* is the
      // normative definition of the vocabulary; keep the two in step.
      'default' => 'user',
      'pseudoconstant' => [
        'callback' => [\Civi\Api4\DfcWebId::class, 'kinds'],
      ],
    ],

    'oidc_issuer' => [
      'title' => ts('OIDC Issuer', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => FALSE,
      'description' => ts(
        'Issuer URL from the access token\'s "iss" claim. Only meaningful for kind=user, where it pairs with oidc_subject to resolve a token to a WebID and a contact. NULL for platform and organization WebIDs.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'oidc_subject' => [
      'title' => ts('OIDC Subject', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => FALSE,
      'description' => ts(
        'Subject identifier from the access token\'s "sub" claim. Paired with oidc_issuer, never used alone: "sub" is only unique within an issuer.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'profile_url' => [
      'title' => ts('Profile URL', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => ts(
        'Location of the WebID profile document itself. Stored rather than derived because a reverse proxy may publish DFC under a different public prefix than the internal route table.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'private_preferences_url' => [
      'title' => ts('Private Preferences URL', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => ts(
        'Location of the private preferences document. Private means authenticated-only and default-not-exported, per PRD-002 CR-13.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'private_type_index_url' => [
      'title' => ts('Private Type Index URL', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => ts(
        'Location of the private TypeIndex document. The TypeIndex is where public-vs-private per-predicate decisions become visible, so it is stored rather than templated.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
    ],

    'verification_status' => [
      'title' => ts('Verification Status', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Select',
      'required' => TRUE,
      'description' => ts(
        'How this WebID was established: unverified, pending, verified or failed. The state machine is defined by Civi\Api4\DfcWebId; lane-3 owns the transitions.',
        ['domain' => 'dfc_civicrm']
      ),
      'add' => '0.1',
      // Literal, not \Civi\Api4\DfcWebId::VERIFICATION_UNVERIFIED - see the note
      // on the 'kind' default. The constants are the normative definition.
      'default' => 'unverified',
      'pseudoconstant' => [
        'callback' => [\Civi\Api4\DfcWebId::class, 'verificationStatuses'],
      ],
    ],

    'created_date' => [
      'title' => ts('Created Date', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'required' => TRUE,
      'description' => ts('When this WebID was first recorded.', ['domain' => 'dfc_civicrm']),
      'add' => '0.1',
      'default' => 'CURRENT_TIMESTAMP',
    ],

    'modified_date' => [
      'title' => ts('Modified Date', ['domain' => 'dfc_civicrm']),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'required' => TRUE,
      'description' => ts('When this WebID was created or last changed.', ['domain' => 'dfc_civicrm']),
      'add' => '0.1',
      'default' => 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
  ],
];
