<?php

declare(strict_types=1);

/**
 * Managed CiviCRM relationship types for the DFC v2 organization relationships.
 *
 * These are the *Civi-side* projection of DFC organization relationships. DFC
 * expresses a relationship as a predicate URI plus a direction; CiviCRM
 * expresses it as a relationship type with a label per direction. The mapping
 * between the two is lane-4's "DFC relationship registry" (PRD-002 Step 4) and
 * is the piece that has to carry direction and inverse correctly (CP-4).
 *
 * =================== THE PREDICATE URI IS NOT HARD-CODED ==================
 *
 * A DFC predicate looks like `dfc-b:hasMainContact` resolved against a versioned
 * namespace, e.g. `https://example.org/dfc-b/2.0.0/hasMainContact`. BOTH halves
 * of that are release data:
 *
 *   - the vocabulary/namespace is whatever the deployed DFC version declares,
 *     sourced from the connector's release configuration; and
 *   - the version segment changes with every DFC release.
 *
 * So no DFC predicate URI is written into this file, or into any other file in
 * this extension. A URI baked into a `.mgd.php` would be a release-time code
 * change and would silently start emitting the wrong namespace the moment DFC
 * shipped a new version.
 *
 * =================== IF A PREDICATE URI MUST REACH THE DATABASE ============
 *
 * The registry (lane-4) should read the resolved namespace at runtime and store
 * the predicate URI through APIv4, in this order of preference:
 *
 *   1. a managed OptionGroup/OptionValue pair whose values are *derived* from
 *      the resolved namespace at flush time, so the managed system owns the rows
 *      but the namespace stays a runtime fact; or
 *   2. a plain `civicrm_option_value` written by the registry with
 *      `is_reserved => TRUE`, so a later `civix export` round-trip is clean.
 *
 * What must NOT happen: someone "just adding the URI for now" writes
 * `dfc-b:hasMainContact` into a `'values'` array. That is the drift the farm's
 * curated-vs-generated lesson goal is about, in a new place.
 *
 * =================== LABEL CHANGES MUST NOT BREAK THE MAPPING ==============
 *
 * `label_a_b` and `label_b_a` are administrator-facing prose. They are NOT the
 * mapping. Two consequences:
 *
 *   1. An administrator retitling a relationship type is a legitimate, supported
 *      action and MUST NOT change which DFC predicate the type stands for. That
 *      is why the mapping is resolved by the registry, not by label matching.
 *   2. A rename of a Civi label must never orphan existing relationships. Hence
 *      'cleanup' => 'unused' below: the managed system refuses to delete a
 *      relationship type that is still in use, and 'update' => 'unmodified'
 *      means a new release's wording does not stomp a site's retitling.
 *
 * `name_a_b` / `name_b_a` are the machine names and ARE part of the mapping -
 * the registry keys off them. They are declared 'always'-updatable in effect by
 * being stable identifiers, but they are still written as ordinary values here;
 * changing one is a breaking change and needs a migration, not an edit.
 *
 * @see ../managed/README.md
 * @see ../schema/DfcIdentity.entityType.php
 * @see https://docs.civicrm.org/dev/en/latest/extensions/managed/
 * @see https://docs.civicrm.org/dev/en/latest/framework/entities/
 *
 * @package CRM
 */

return [
  // ---------------------------------------------------------------------
  // "Organization X is a member of / subsidiary of Organization Y".
  //
  // This is the DFC organization-relationship shape that maps most cleanly onto
  // a single CiviCRM relationship type, because both directions are the same
  // predicate: A is a member of B <-> B has member A.
  //
  // is_directional: TRUE, because the registry resolves direction and inverse
  // explicitly (CP-4) rather than relying on a symmetric label.
  // ---------------------------------------------------------------------
  [
    'name' => 'dfc_civicrm_reltype_organization_member_of',
    'entity' => 'RelationshipType',
    'update' => 'unmodified',
    'cleanup' => 'unused',
    'params' => [
      'version' => 4,
      'values' => [
        'name_a_b' => 'DFC_Member_Of',
        'label_a_b' => ts('DFC: member of', ['domain' => 'dfc_civicrm']),
        'name_b_a' => 'DFC_Has_Member',
        'label_b_a' => ts('DFC: has member', ['domain' => 'dfc_civicrm']),
        'description' => ts(
          'DFC v2 organization membership. The DFC predicate URI is NOT stored on this row; it is resolved by the DFC relationship registry from the deployed DFC namespace.',
          ['domain' => 'dfc_civicrm']
        ),
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'is_directional' => TRUE,
        'contact_type_a' => 'Organization',
        'contact_type_b' => 'Organization',
        'weight' => 0,
      ],
      'match' => ['name_a_b', 'name_b_a'],
    ],
  ],

  // ---------------------------------------------------------------------
  // "Person X acts for / is an agent of Organization Y".
  //
  // Deliberately NOT contact_type-restricted to Individual: the DFC in-scope
  // surface includes Agent/Who_Subject, and an Agent may be represented by an
  // Organization record as well as a Person. The registry applies the real
  // constraint; pinning it here would reject valid DFC data at the schema layer
  // where the error message would be useless.
  // ---------------------------------------------------------------------
  [
    'name' => 'dfc_civicrm_reltype_agent_of',
    'entity' => 'RelationshipType',
    'update' => 'unmodified',
    'cleanup' => 'unused',
    'params' => [
      'version' => 4,
      'values' => [
        'name_a_b' => 'DFC_Agent_Of',
        'label_a_b' => ts('DFC: agent of', ['domain' => 'dfc_civicrm']),
        'name_b_a' => 'DFC_Has_Agent',
        'label_b_a' => ts('DFC: has agent', ['domain' => 'dfc_civicrm']),
        'description' => ts(
          'DFC v2 Agent/Who_Subject relationship between a subject (Person or Organization) and an acting organization. The DFC predicate URI is NOT stored on this row.',
          ['domain' => 'dfc_civicrm']
        ),
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'is_directional' => TRUE,
        'min_cardinality' => 0,
        'contact_type_a' => NULL,
        'contact_type_b' => 'Organization',
        'weight' => 0,
      ],
      'match' => ['name_a_b', 'name_b_a'],
    ],
  ],

  // ---------------------------------------------------------------------
  // "Person X is the main contact of Organization Y".
  //
  // This one is modelled as a *distinct, non-directional-ish* type rather than
  // as a flag, because PRD-002 Step 4 calls out "main-contact semantics" as its
  // own concern: the DFC main-contact predicate is singular per organization,
  // while a CiviCRM relationship is not. The "at most one" rule therefore
  // cannot live in this row - it has to be enforced by the registry when it
  // writes, and surfaced as a reviewable conflict when a second main contact
  // arrives. Recording the intent here is all the schema can honestly do.
  // ---------------------------------------------------------------------
  [
    'name' => 'dfc_civicrm_reltype_main_contact_of',
    'entity' => 'RelationshipType',
    'update' => 'unmodified',
    'cleanup' => 'unused',
    'params' => [
      'version' => 4,
      'values' => [
        'name_a_b' => 'DFC_Main_Contact_Of',
        'label_a_b' => ts('DFC: main contact of', ['domain' => 'dfc_civicrm']),
        'name_b_a' => 'DFC_Has_Main_Contact',
        'label_b_a' => ts('DFC: has main contact', ['domain' => 'dfc_civicrm']),
        'description' => ts(
          'DFC v2 main-contact relationship. The DFC main-contact predicate is singular per organization; that constraint is enforced by the relationship registry, not by this row. The DFC predicate URI is NOT stored here.',
          ['domain' => 'dfc_civicrm']
        ),
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'is_directional' => TRUE,
        'min_cardinality' => 0,
        'contact_type_a' => NULL,
        'contact_type_b' => 'Organization',
        'weight' => 0,
      ],
      'match' => ['name_a_b', 'name_b_a'],
    ],
  ],
];
