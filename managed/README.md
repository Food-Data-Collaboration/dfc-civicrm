# `managed/` — reproducible configuration records

CiviCRM "managed entities" let an extension ship configuration (custom groups,
custom fields, relationship types, option values, navigation…) that is created
on install, kept in sync on upgrade, and removed on uninstall — without writing
any SQL and without an administrator clicking through the settings screens.

## How the mechanism is wired here

`info.xml` declares:

```xml
<mixin>mgd-php@1.1.0</mixin>
```

The `mgd-php` mixin registers a listener on `hook_civicrm_managed` that globs
`managed/*.mgd.php` and appends every record it returns. CiviCRM then reconciles
that list against the `civicrm_managed` table on the next cache flush:

* a record that is declared but missing is **created**;
* `update` decides whether a change to the declaration overwrites the stored row;
* `cleanup` decides whether the row is removed when the extension is uninstalled.

All files in this directory are `include`d and must **return** a list of record
arrays. This is the format `civix export` emits and the format the CiviCRM
managed docs describe; it is *not* the `CRM_Core_ManagedEntity` subclass style,
which is a pre-5.45 convention with no in-core examples left.

## Record shape

```php
return [
  [
    'name'     => 'unique_name_in_civicrm_managed',  // required, globally unique
    'entity'   => 'CustomField',                      // the APIv4 entity
    'update'   => 'always',                           // always|unmodified|never
    'cleanup'  => 'always',                           // always|unused|never
    'params'   => [
      'version' => 4,                                 // API version for params
      'values'  => [ /* APIv4 create params */ ],
      'match'   => [ /* fields that identify an existing row */ ],
    ],
  ],
];
```

Notes that bit us and will bite the next person:

* the keys are **`update`** and **`cleanup`**, not `update_mode`/`cleanup_mode`;
* `params` is nested under `version`/`values`/`match` — top-level create params
  are not read;
* `match` is what stops the managed system creating a duplicate of a record that
  already exists (e.g. one created by an earlier hand-rolled `hook_civicrm_install`
  in a 1.0 release). `civix export` adds it automatically;
* every `'values'` key is a real APIv4 field name on the target entity, and
  references to *other* records use dotted paths such as
  `'custom_group_id.name' => 'dfc_identity'`.

## `update` / `cleanup` policy used in this directory

| Record | `update` | `cleanup` | Why |
|---|---|---|---|
| `CustomGroup_DFCIdentity.mgd.php` group | `unmodified` | `always` | An administrator may reasonably retitle the group; don't stomp that, but do remove it on uninstall. |
| `CustomGroup_DFCIdentity.mgd.php` `webId` field | `always` | `always` | The field is a machine-managed cache, not a user document. Nothing an administrator does to it is meaningful, so propagate declaration changes unconditionally. |
| `RelationshipType_DFC.mgd.php` types | `unmodified` | `unused` | The DFC predicate URI attached to a relationship type must survive label edits, but a relationship type that is in use must not be deleted out from under existing relationships. |

## What is *not* here, and why

* **No SQL.** The two extension-owned tables are created and dropped by
  `CiviMix\Schema\DfcCivicrm\AutomaticUpgrader` (declared in `info.xml`), which
  derives the DDL from `schema/*.entityType.php`.
* **No DAO classes.** `CRM/Dfc/DAO/` belongs to lane-3 (sa-009). See the
  hand-off notice at the top of `schema/DfcIdentity.entityType.php`.
* **No nav link, no permission rows.** The settings page, its navigation entry
  and the `administer dfc_civicrm` permission are all created by the
  `setting-admin@1` mixin. The four DFC permissions are declared as plain
  `hook_civicrm_permission` entries in `../dfc_civicrm.php` — declaring them
  managed *as well* would double-register them.
* **No URL is hardcoded.** Relationship-type predicate URIs are recorded in
  comments only, because the DFC namespace is resolved at runtime. A future lane
  that needs the predicate URI in the database should add a managed
  `OptionGroup`/`OptionValue` pair fed from the resolved namespace, not a
  hardcoded string in a `.mgd.php` file.

## Adding a record

1. Prefer `civix export <Entity> <id>` over hand-writing the array — it fills in
   `match` and the right `params` shape.
2. Give the record a `'name'` that will never be reused. It is the primary key in
   `civicrm_managed`; changing it orphans the old record and creates a new one.
3. Pick `update`/`cleanup` from the table above (or extend it with a comment
   explaining a new case).
4. Re-run the cache flush and check `Civi\Api4\Managed::get()` to confirm the
   record was picked up.

## References

* <https://docs.civicrm.org/dev/en/latest/extensions/managed/>
* <https://docs.civicrm.org/dev/en/latest/api/v4/managed/>
* `../info.xml` — the mixin and upgrader declarations this directory depends on
