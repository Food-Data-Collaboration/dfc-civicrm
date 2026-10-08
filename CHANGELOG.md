# Changelog

All notable changes to `dfc_civicrm` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Release policy

| Rule | Statement |
|---|---|
| Version source of truth | `<version>` in `info.xml`. Never a `version` field in `composer.json` (Packagist derives it from the git tag for a `civicrm-extension`), never a hand-written number in this file. |
| Release date source of truth | `<releaseDate>` in `info.xml`. Must be the day the tag is pushed. |
| Git tag | A bare SemVer string equal to `<version>`, no `v` prefix — `1.2.0`, matching the example in [Publishing Extensions](https://docs.civicrm.org/dev/en/latest/extensions/publish/). |
| Automated | `tools/check-release-metadata.sh` asserts that `info.xml` and this file agree, and that a release tag agrees with both. It runs in CI on every push and again in `.github/workflows/release.yml`. |
| Stage | `<develStage>` is `alpha` today. CiviCRM does not list non-stable releases in the Extensions Directory, so this is installable from a GitHub release URL but not in-app. |

## Supported CiviCRM versions

`<compatibility><ver>5.74</ver></compatibility>` in `info.xml` means
**CiviCRM 5.74 and later, including all of 6.x**. A single `<ver>` implies
forward compatibility; there is no upper bound element.

That floor is the highest of the four things the extension actually uses:

| Needs | CiviCRM | Why |
|---|---|---|
| `setting-admin@1` | 5.68 | admin settings page and navigation |
| `smarty@1.0.3` | 5.71 | `templates/` |
| `entity-types-php@2.0.0` | 5.73 | `schema/*.entityType.php` |
| `hook_civicrm_permission` `implies` / `implied_by` | 5.74 | permission graph in `dfc_civicrm.php` |

`mgd-php` is deliberately pinned to the 1.x line (`mgd-php@1.1.0`): `mgd-php@2.0.0`
is `@since 6.9` and declaring it would raise the floor to 6.9.

### What "supported" means here, honestly

**Tested: PHP 8.1 only** — the one interpreter available in this project's
environment. The 1135-test unit suite passes there.

**Declared but not yet executed: PHP 8.2, 8.3, 8.4, 8.5.** The CI matrix that
covers them has never run, because the repository has no remote configured. An
earlier version of this file claimed "Tested: PHP 8.1–8.4"; that was false on
both counts — 8.1–8.4 was never run, and the file is not evidence.

**Not tested:** any CiviCRM version. There is no CiviCRM instance in this
project's environment (BLK-005), so nothing has exercised install, upgrade,
disable/re-enable or uninstall, or a single HTTP request through a real
routing layer. The 5.74 floor is a reasoned derivation from the four mixins
above, not a tested claim. Treat it as provisional until CP-2's lifecycle tests
run.

## Supported PHP versions

`8.1`, `8.2`, `8.3`, `8.4`, `8.5`, mirroring `<php_compatibility>` in
`info.xml` exactly. CI runs the unit suite on each — and see the honesty note
above: the matrix has been written but never executed.

`<php_compatibility>` is an **allow-list, not a floor.** CiviCRM's docs give
`<compatibility>` (CiviCRM versions) forward-compatibility semantics — "`<ver>`
elements imply forward compatibility" — and explicitly do **not** give it to
`<php_compatibility>`, where they say "Each `<ver>` child element should only
contain a single compatible version of PHP" and "It is not currently possible to
specify a 'maximum compatible version'". A PHP version absent from the list is
one this extension refuses, so the highest entry is a hard ceiling.

That ceiling was 8.4 until 2026-10-08, because sa-007 copied the reference
`info.xml` from docs.civicrm.org — which itself lists only 8.1–8.4 — without
checking it against what CiviCRM actually supports. `tools/preflight.sh` now
asserts the declared list, the CI matrix and `composer.json`'s PHP floor all
agree, because a comment stating the invariant did not prevent the drift.

---

## [Unreleased]

### Fixed

- **PHP 8.5 support declared.** `<php_compatibility>` listed only 8.1–8.4, which
  — because that element is an allow-list rather than a floor — meant a site on
  PHP 8.5 was told this extension was incompatible. Added `<ver>8.5</ver>` and
  `'8.5'` to the CI matrix; the coverage job moved to 8.5 so it measures a
  version people will actually install.
- **The drift that let that happen is now guarded.** `tools/preflight.sh` fails
  if `<php_compatibility>`, the CI matrix and `composer.json`'s PHP floor
  disagree. Verified by reverting `info.xml` alone and confirming preflight
  exits 1 with both version lists printed.
- **A false claim in this changelog.** It said "Tested: PHP 8.1–8.4". Only 8.1
  has ever run anything, because the repository has no remote and GitHub Actions
  has therefore never executed. Corrected above.

### Added

- **`LICENSE`** — the full AGPL-3.0 text. `info.xml` and `composer.json` have
  declared `AGPL-3.0-or-later` since 0.1.0 while the repository contained no
  licence file at all, which is a §4 compliance gap for a distributed work and
  blocks Extensions Directory listing. Taken from the SPDX canonical copy; its
  operative terms were verified byte-identical against a second independent copy
  before adding, and the one `{{ year }}  {{ organization }}` placeholder — in
  the "How to Apply" appendix, not in any operative clause — is filled with
  `2026  Food-Data-Collaboration`.

### Changed

- **Maintainer metadata is now real.** `<author>` was `zoro-jiro-san` with an
  RFC 2606 `maintainers@zoro-jiro-san.invalid` address and repository URLs
  pointing at a repository that did not exist. Now `Food Data Collaboration` /
  `hello@fooddatacollaboration.org.uk`, with URLs at
  `Food-Data-Collaboration/dfc-civicrm`, which is the remote this repository
  gained the same day. `composer.json`'s `name` is corrected to
  `food-data-collaboration/dfc-civicrm` to match. This is what made
  `SECURITY.md`'s reporting route non-functional rather than merely untidy.
- `SECURITY.md` now prefers GitHub private advisory reporting over the shared
  inbox, which is a general address rather than a monitored security mailbox.

### Fixed

- **The `import()` defect we reported as DFC-LinkML#36 was fixed upstream in
  v2.0.7** (released 2026-10-08, the same day CI first ran and caught it), so
  `ConnectorContractTest` was asserting the bug rather than the behaviour. It
  now pins the sharper contract v2.0.7 actually provides: malformed input
  *throws* `JsonException`, while well-formed-but-graphless JSON still returns
  `[]`. The second half is the part that remains ours — `JsonLdParseStage` must
  keep its own `@context` checking, because `import()` can catch a syntax error
  but still cannot tell us "you sent JSON, it just was not DFC".
- **`check-release-metadata.sh` no longer fails on CI.** It compared the
  extension key against the *checkout directory name*, which passed locally
  (the working copy is `dfc_civicrm/`) and failed on a GitHub runner, where
  `actions/checkout` names the directory after the repository
  (`dfc-civicrm`). Those are different namespaces: the repository is
  `dfc-civicrm` because GitHub permits only lowercase and hyphens, while the
  key is `dfc_civicrm`, and CiviCRM installs by key regardless of the remote's
  name. The check now asserts what actually matters — key and `<file>` agree,
  and `<key>.php` exists — and both real failures were re-verified against a
  simulated `dfc-civicrm` checkout.

- **`build-release.sh` now writes `SHA256SUMS` and `BUILD-METADATA.txt` to the
  output root as well as to the version directory.** `ci.yml` and `release.yml`
  both read and upload them from `build/`, and both would have silently attached
  nothing — an upload step with a path that never exists does not fail loudly.
- **Three workflow paths that never existed are fixed.** Both workflows assumed a
  flat `build/dfc_civicrm-<version>.tar.gz`, but the script nests the archive
  under `build/<key>-<version>/`. CI failed at the `test -f` on its very first
  run; `release.yml` had the same defect and would have failed *after a tag was
  pushed*. Both now derive the key and version from `info.xml` instead of
  repeating literals, so a version bump cannot break them again.

- **`#[CoversClass(ValidationRun::class)]` had no `use` statement.** The test's own
  namespace is `Civi\Dfc\Test\Validation`, so the bare name resolved to
  `Civi\Dfc\Test\Validation\ValidationRun` — a class that has never existed.
  Every other `CoversClass` on that class is properly imported, so it was one
  omission in a list that otherwise looked uniform. PHPUnit evaluates
  `CoversClass` only when a coverage driver is loaded, which is why 1137 tests
  passed locally and only the coverage job failed. `tools/preflight.sh` now
  resolves every `CoversClass` target by reflection — no driver required — and
  was verified by removing the import again and confirming it reproduces the
  original message and exits 1.

### Notes

- **CI has now run for the first time**, on PHP 8.5.11 among others, and it
  found both bugs above. Nothing about it is green yet in this release; see
  the note on PHPUnit below for the remaining work.
- PHPUnit stays on `10.5` rather than moving to 13. PHPUnit 13 requires
  `php >=8.4.1`, so it cannot run on the 8.1–8.3 legs of the matrix at all.
  PHPUnit 10.5 declares `php >=8.1` with no upper bound, so Composer will
  install it on 8.5; the open question is whether it *runs* cleanly there.
  Static checks found no 8.2–8.5 breakers in `Civi/` (no dynamic properties,
  no implicitly-nullable parameters, no `E_STRICT`), but that is a static check,
  not an execution.

## [0.1.0] - 2026-10-01

First tagged shape of the extension. `develStage` is `alpha`.

This release is **not installable on a CiviCRM site yet.** It installs; it does
not serve anything. What is here is the protocol and identity foundation plus
the extension shell, verified by unit tests only.

### Added

**Extension shell**

- `info.xml` declaring CiviCRM 5.74+, PHP 8.1–8.4, the four DFC permissions and
  the `/civicrm/dfc/v2` namespace, with the compatibility floor derived from
  the mixin versions it uses.
- `dfc_civicrm.php`: the four `hook_civicrm_permission` entries, the
  `dfc_base_path` and `dfc_default_namespace` settings, and a fallback PSR-4
  autoloader for `Civi\Dfc\` that stands down when CiviCRM's own class loader
  is present.
- `schema/DfcIdentity.entityType.php` and `schema/DfcWebId.entityType.php`,
  applied by `CiviMix\Schema\DfcCivicrm\AutomaticUpgrader`. No hand-written SQL
  anywhere in this extension.
- `managed/` — one custom group, one `webId` cache field, and the DFC
  relationship types, reconciled by `mgd-php@1.1.0`.
- `templates/hook/dfc_civicrm_config.tpl.php` — the admin settings form.
- `xml/Menu/dfc_civicrm.xml` — the settings route.

**HTTP protocol layer**

- One error model spanning 400, 401, 403, 404, 409, 412, 415, 422 and 500,
  with a `LeakGuard` that strips internal identifiers and stack detail from
  anything a caller sees.
- Content negotiation, including the 406-vs-415 split (406 when `Accept` has
  nothing acceptable, 415 when the request body is unacceptable).
- Conditional requests: `ETag`, `If-Match` evaluation, and `If-None-Match`.
- LDP basic containers and container paging, `Link rel="next"/"prev"`,
  `Vary: Accept, Authorization`.
- Header policy: which response headers may be emitted at all.

**Identity**

- `DfcReleaseConfig`: the DFC version, JSON-LD context URL and `dfc-b:` / `dfc-t:`
  prefix IRIs, read at runtime from `config/dfc-release.yaml`. Never hardcoded.
  Two rules hold the URI shape together: no DFC version in any minted URI, and
  no display name, email, hostname or CiviCRM integer id in any minted URI.
- `UriFactory` and `RandomIdentifierGenerator` — opaque, stable identifiers.
- `ReleaseDescriptorParser` — a restricted YAML reader, because an extension
  cannot gain a YAML dependency and CiviCRM core ships no YAML parser. It
  rejects anchors, tags, flow collections and block scalars with a line number
  rather than guessing.
- `config/dfc-release.yaml` ships in the release archive. It is the pinned copy
  of upstream's descriptor plus a `dfc_civicrm:` block carrying the prefix IRIs,
  which upstream keeps in `config/dfc-default.yaml`, the schema generator's
  input.

**Security — OAuth 2.0 resource server**

- OIDC discovery resolved at runtime. The published specs' `/auth/realm/dev`
  base returns 404; the live realm is under `/realms/`. Never hardcoded.
- `BearerTokenExtractor`, `BearerAuthenticator`, `AccessTokenValidator`,
  `LocalJwtDecoder`, `HttpJwksFetcher`, `JwksCache`, `JsonWebKeySet`. Access
  tokens only — never an ID token.
- `ScopePermissionRegistry`: OIDC scopes mapped to the four CiviCRM
  permissions in one place, so no controller makes its own auth decision.
- `x-dfc-version` required on every operation and echoed.

**Validation and export policy**

- A staged `ValidationPipeline` whose stage order and invariants are explicit,
  with a syntax/`@context` check that runs *before* anything reaches the
  connector.
- `DefaultExportPolicy` and `PublicFieldPolicy`: an explicit per-field
  allow-list that defaults to not-exported.

**Build and release**

- `.github/workflows/ci.yml`: PHP 8.1–8.4 matrix running the unit suite with
  `failOnWarning`/`failOnRisky` enforced, a separate non-blocking network
  conformance job, and a drift check over `composer.json`/`composer.lock` and
  `info.xml`/`CHANGELOG.md`.
- `.github/workflows/release.yml`: tag-triggered, builds the versioned tarball,
  attaches it to a GitHub release.
- `tools/build-release.sh` — builds `dfc_civicrm-<version>.tar.gz` containing
  the extension files plus the vendored connector runtime, and nothing else.
- `tools/check-release-metadata.sh` — the drift gate described above.
- `tests/phpunit/Connector/ConnectorContractTest.php` — pins the observable
  behaviour of `siol-data/linkml-connector` v2.0.5, including one documented
  upstream defect.

### Known limitations in this release

- **No DFC resource surface is served.** No `Organization`, `Person`,
  relationship or place endpoint exists yet. Success criteria 1–5 and 11–15 of
  PRD-002 are unmet.
- **Never tested against a running CiviCRM.** No install, upgrade or uninstall
  has been performed (BLK-005).
- **`vendor/` is not in the git tree**, so `composer install` is required
  before the unit suite will run at all. See `CONTRIBUTING.md`.
- **Upstream connector defect.** `Connector::import()` in v2.0.5 does not throw
  on malformed input; it returns an empty array. Left unguarded, a malformed
  request body would be a silent no-op returning 2xx. `JsonLdParseStage` must
  validate syntax and `@context` first and treat an empty import as an error.
  Pinned as a test so an upstream fix surfaces (BLK-015).
- **Upstream packaging bloat.** The connector's Composer package root is the
  repository root, so installing it brings 7.7 MB including the Ruby and
  TypeScript connectors. `tools/build-release.sh` copies only the PHP runtime
  subset (BLK-016).
- **Placeholder maintainer metadata.** `info.xml` carries
  `maintainers@zoro-jiro-san.invalid` and a repository URL that does not exist,
  because there is no remote. Both must be replaced before any release or
  Extensions Directory submission.
- **No `LICENSE` file.** `info.xml` and `composer.json` declare
  AGPL-3.0-or-later; the text is not in the repository.
- **Unratified decisions.** 406-vs-415, the product/order unsupported-resource
  status and code, per-caller export visibility, `ldp:contains` shape, and the
  pagination parameter names are all provisional (BLK-008 … BLK-011). They may
  change in a way that breaks clients.

[Unreleased]: https://github.com/Food-Data-Collaboration/dfc-civicrm/compare/0.1.0...HEAD
[0.1.0]: https://github.com/Food-Data-Collaboration/dfc-civicrm/releases/tag/0.1.0