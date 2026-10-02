<?php

declare(strict_types=1);

namespace Civi\Api4;

/**
 * DFC v2 semantic-identity table.
 *
 * One row per DFC resource that has been given a stable DFC semantic
 * identifier. This is the durable identity record: it survives contact renames,
 * host renames, duplicate imports and contact merges, which is the single
 * highest-risk design decision in PRD-002 (learning goal "identity-model
 * durability").
 *
 * HOW APIv4 FINDS THIS CLASS
 *   Civi\Api4\Utils\CoreUtil::getApiClass($entityName) does
 *   `'Civi\Api4\\' . $entityName` and then `class_exists()`. So the class name
 *   MUST be exactly the entity name from schema/DfcIdentity.entityType.php, and
 *   the file MUST be reachable through info.xml's
 *   `<psr4 prefix="Civi\" path="Civi"/>`.
 *
 * WHY THIS CLASS IS EMPTY
 *   The table, its columns, indexes and foreign keys are declared in
 *   schema/DfcIdentity.entityType.php, not here. CiviCRM derives the API field
 *   metadata from that declaration, so repeating the columns in PHP would be a
 *   second source of truth that drifts. A modern APIv4 entity class for a
 *   schema-declared table is a namespace declaration plus whatever behaviour
 *   the entity actually needs.
 *
 *   It is therefore NOT correct to declare `getName()` or the table name here.
 *   The table lives at the entityType's 'table' key; the entity name at its
 *   'name' key. (Pre-APIv4 DAO conventions put `$_tableName` in the class; that
 *   is the DAO layer's job, not the APIv4 layer's.)
 *
 * @see \Civi\Api4\Generic\DAOEntity
 * @see schema/DfcIdentity.entityType.php
 * @see Civi\Api4\DfcWebId
 *
 * @since 0.1
 * @package Civi\Api4
 */
class DfcIdentity extends Generic\DAOEntity {

  /**
   * Permission required for each action.
   *
   * This is the APIv4-side counterpart to the route-level `access dfc api`
   * check in xml/Menu/dfc_civicrm.xml. Both are required: the route gate stops
   * an unauthorised caller reaching the page at all, and this gate stops them
   * reaching the rows if a future route forgets to declare one.
   *
   * 'meta' covers introspection (getFields and friends) - it must be readable
   * by anyone who can reach the DFC surface, otherwise the API explorer and
   * SearchKit break. 'default' covers get/save/create/update/delete.
   *
   * The write path additionally needs 'write dfc data', which implies 'read dfc
   * data', which implies 'access dfc api' (see dfc_civicrm_civicrm_permission()).
   * The finer-grained write gate belongs on the individual field specs in the
   * entityType declaration once lane-3 has defined the import/export policy.
   *
   * @return array<string, array<int, string>>
   *   Action => list of permissions, all of which must be held.
   */
  public static function permissions(): array {
    return [
      'meta' => ['access dfc api'],
      'default' => ['read dfc data'],
    ];
  }

}
