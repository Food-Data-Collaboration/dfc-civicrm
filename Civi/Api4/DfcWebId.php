<?php

declare(strict_types=1);

namespace Civi\Api4;

/**
 * DFC v2 WebID table.
 *
 * Holds the WebID documents this installation serves: the platform WebID, the
 * per-user WebIDs, and the Organization WebIDs. `web_id` is the absolute URI of
 * the document; `subject_uri` is the resource the document is about. The two
 * are separate because a WebID document may describe a resource at a different
 * URI than the document's own address (WebID v1 vs v2 style separation, and the
 * basis of the `foaf:primaryTopic`/inverse handling in lane-4).
 *
 * `verification_status` records how the WebID was established. PRD-002 CR-6 asks
 * for "a conformant public platform WebID plus supported user/Organization
 * WebIDs", and lane-3 owns the resolution logic; the enum values below are the
 * state machine that logic has to move through. Keep them stable: they are
 * persisted.
 *
 * `contact_id` is NULLABLE ON PURPOSE. The platform WebID describes the
 * installation and belongs to no contact. Do not add a NOT NULL constraint and
 * do not substitute a placeholder contact - that is exactly the "fake Person in
 * the database" anti-pattern the DFC mapping rules out.
 *
 * HOW APIv4 FINDS THIS CLASS
 *   Civi\Api4\Utils\CoreUtil::getApiClass($entityName) does
 *   `'Civi\Api4\\' . $entityName` and then `class_exists()`. So the class name
 *   MUST be exactly the entity name from schema/DfcWebId.entityType.php, and
 *   the file MUST be reachable through info.xml's
 *   `<psr4 prefix="Civi\" path="Civi"/>`.
 *
 * WHY THIS CLASS IS EMPTY
 *   Columns, indexes and the contact foreign key are declared in
 *   schema/DfcWebId.entityType.php. See Civi\Api4\DfcIdentity for the full
 *   rationale: a second copy of the column list in PHP is a second source of
 *   truth. The table name lives at the entityType's 'table' key - not here.
 *
 * @see \Civi\Api4\Generic\DAOEntity
 * @see schema/DfcWebId.entityType.php
 * @see Civi\Api4\DfcIdentity
 *
 * @since 0.1
 * @package Civi\Api4
 */
class DfcWebId extends Generic\DAOEntity {

  /**
   * WebID kinds, as persisted in the `kind` column.
   *
   * Declared here as documentation and as the single place to read the
   * vocabulary from; the entityType's field spec marks `kind` as a Select with
   * these values. Kept in sync by hand - if you add a kind, add it to both, and
   * note that adding a value to a persisted enum is backward compatible while
   * removing one is not.
   *
   * @var string
   */
  public const KIND_PLATFORM = 'platform';

  /**
   * @var string
   */
  public const KIND_USER = 'user';

  /**
   * @var string
   */
  public const KIND_ORGANIZATION = 'organization';

  /**
   * Verification states, as persisted in the `verification_status` column.
   *
   * @var string
   */
  public const VERIFICATION_UNVERIFIED = 'unverified';

  /**
   * @var string
   */
  public const VERIFICATION_PENDING = 'pending';

  /**
   * @var string
   */
  public const VERIFICATION_VERIFIED = 'verified';

  /**
   * @var string
   */
  public const VERIFICATION_FAILED = 'failed';

  /**
   * All valid `kind` values, in the order they are offered in the admin UI.
   *
   * @return array<string, string>
   *   value => label.
   */
  public static function kinds(): array {
    return [
      self::KIND_PLATFORM => ts('Platform', ['domain' => 'dfc_civicrm']),
      self::KIND_USER => ts('User', ['domain' => 'dfc_civicrm']),
      self::KIND_ORGANIZATION => ts('Organization', ['domain' => 'dfc_civicrm']),
    ];
  }

  /**
   * All valid `verification_status` values.
   *
   * @return array<string, string>
   *   value => label.
   */
  public static function verificationStatuses(): array {
    return [
      self::VERIFICATION_UNVERIFIED => ts('Unverified', ['domain' => 'dfc_civicrm']),
      self::VERIFICATION_PENDING => ts('Pending verification', ['domain' => 'dfc_civicrm']),
      self::VERIFICATION_VERIFIED => ts('Verified', ['domain' => 'dfc_civicrm']),
      self::VERIFICATION_FAILED => ts('Verification failed', ['domain' => 'dfc_civicrm']),
    ];
  }

  /**
   * Permission required for each action.
   *
   * Same two-layer model as Civi\Api4\DfcIdentity: the route gate is
   * `access dfc api`; this gate protects the rows.
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
