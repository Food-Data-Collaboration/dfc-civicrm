# Contributing to `dfc_civicrm`

This extension has **no remote yet**. Until one exists, nothing here can be
pull-requested; the working history on `main` is the record. Everything below
still applies, because the moment a remote is added, these are the rules.

## Running the tests

```bash
composer install
./vendor/bin/phpunit --testsuite unit
```

`vendor/` is not in the git tree (see [composer.lock](#should-composerlock-be-committed)
and `.gitignore`), so a fresh clone cannot run anything until `composer install`
has run. This is the single most common confusion for a new contributor.

`composer install` needs the `ext-dom`, `ext-json`, `ext-libxml` and
`ext-mbstring` PHP extensions. PHPUnit 10.5 requires PHP 8.1 or later.

Before claiming support for a PHP version, add it to **both**
`<php_compatibility>` in `info.xml` and the matrix in
`.github/workflows/ci.yml`. `tools/preflight.sh` fails if the two disagree, or
if `composer.json`'s PHP floor is above the lowest declared version. That element
is an allow-list rather than a floor — a version absent from it is one the
extension refuses to install on — so omitting one does not degrade gracefully, it
locks users out.

### Why the unit suite runs without a CMS

`tests/phpunit/bootstrap.php` loads only `vendor/autoload.php`. No CiviCRM
bootstrap, no database, no site. This is deliberate: the protocol layer is
designed so its services can be tested without a CMS, which is why 1098 tests
can run in ~25 seconds on a laptop with nothing else installed. Keep new
services in that shape — if a class cannot be exercised without a site, it is
doing too much.

### Test suites

| Suite | Command | Network | What it covers |
|---|---|---|---|
| `unit` | `./vendor/bin/phpunit --testsuite unit` | no | Everything else |
| `conformance` | `./vendor/bin/phpunit --testsuite conformance` | **yes** | One class: `tests/conformance/LiveJwksTest.php` |

`phpunit.xml.dist` sets `failOnWarning="true"`, `failOnRisky="true"` and
`beStrictAboutOutputDuringTests="true"`. All three are load-bearing, not
decoration:

- **failOnWarning** — a test that emits a PHP warning is a test that is about to
  be ignored on the version where the warning becomes fatal.
- **failOnRisky** — catches a test with no assertions, which passes whether or
  not the code works.
- **beStrictAboutOutputDuringTests** — catches `echo`, `print_r` and `var_dump`
  in production paths. This extension returns personal data and access tokens
  to callers; a stray debug print is a data leak, so it fails the build.

Do not silence any of these in a test to make it pass. Fix the cause.

### The conformance suite is not in the gate

`LiveJwksTest` dereferences `https://login.fooddatacollaboration.org.uk/realms/dev`.
It is separated into its own CI job with `continue-on-error: ${{ github.event_name == 'pull_request' }}`
— non-blocking on pull requests, blocking on `push` to `main`, on the nightly
schedule and on manual dispatch.

The reasoning: everything this suite knows about JWKS handling — rotation, an
unknown `kid`, a `use: enc` key mixed into the set, a malformed entry — is
already exercised hermetically in `tests/phpunit/Security/JwksCacheTest.php`
against generated keys. What the conformance suite adds is one thing the unit
suite cannot know: whether the real issuer's key set is shaped the way we
assume. That is worth having, and it is not worth a permanently-red build. A
suite that fails when somebody else's server is down is a suite people learn
to skip, and then they skip the real failures too.

The job still runs, still shows its result on the pull request, and still goes
red nightly. It just does not block a contributor who changed a docstring.

## Generated versus hand-written

There is **no generator** in this project. Nothing under the repository is
produced by a tool from another artefact, so there is no "is the generated
tree up to date?" check to write, and CI does not pretend to have one.

The boundary that does exist:

### Hand-written — edit freely

| Path | Rule |
|---|---|
| `Civi/` | PSR-4 root for `Civi\Dfc\`. Must stay identical to `composer.json`'s `autoload.psr-4` and to `info.xml`'s `<classloader><psr4 prefix="Civi\Dfc\"/>`. Three declarations of one fact; `info.xml` says so in a comment and `tests/phpunit/Identity/UriFactoryStabilityTest.php` will fail if they drift. |
| `Civi/Api4/` | APIv4 entity classes for the two extension-owned tables. |
| `dfc_civicrm.php` | Hooks, permissions, settings. |
| `schema/*.entityType.php` | Column and table declarations. **Never hand-write SQL**; DDL is derived from these by the `AutomaticUpgrader`. |
| `managed/*.mgd.php` | Returns arrays of record definitions. `update`/`cleanup` policy per record is documented in `managed/README.md`; pick from that table or add a comment explaining a new case. |
| `xml/Menu/*.xml` | Routing. After changing a route, flush the menu cache: `/civicrm/menu/rebuild?reset=1`. |
| `templates/` | Smarty. `ts()` with `['domain' => 'dfc_civicrm']`. |
| `config/dfc-release.yaml` | A pinned copy of upstream's `config/dfc-release.yaml` plus a `dfc_civicrm:` block. Refresh procedure is in the file header. Must stay parseable by `Civi\Dfc\V2\Identity\ReleaseDescriptorParser`, which rejects anchors, tags, flow collections and block scalars — so **never round-trip it through a YAML emitter**. |
| `tests/` | PHPUnit. |

### Vendored — do not edit, do not vendor wholesale

`vendor/siol-data/linkml-connector` is third-party code (MIT). Its Composer
package root is the whole DFC-LinkML repository root, so a plain copy is 7.7 MB
carrying the Ruby connector, the TypeScript connector and a Python test suite
(BLK-016). `tools/build-release.sh` copies only `php-connector/{src,contexts,vocabularies,LICENSE}`.

`contexts/` and `vocabularies/` are read from disk at runtime by the connector
(`Connector.php` reads `__DIR__ . '/../contexts/…'` and
`__DIR__ . '/../vocabularies/…'`), so they are not optional decoration. Dropping
them produces a file-not-found at runtime, not a clear "dependency missing".

### Generated upstream, consumed here

`tests/phpunit/Connector/ConnectorContractTest.php` asserts the *observable
behaviour* of the upstream connector — including one defect it documents rather
than works around blindly. When you bump the connector version, that test is the
thing that tells you whether the bump broke you. Read its failures as
information about upstream, not as assertions about our own code.

## Adding a dependency

The hard constraint: **a CiviCRM extension must install without an
administrator running Composer in the checkout.** Nobody does that on a live
site, and asking them to is how an extension ends up broken in production.

That gives you one decision to make first:

### Is it needed at runtime?

**No → `require-dev`.** Nothing extra to do. This is the right home for
anything used only by tests.

**Yes → you have taken on release packaging.** Specifically:

1. Add it to `require` in `composer.json`.
2. Add the code to `tools/build-release.sh`'s `vendor_subset()`. A dependency
   nobody taught the packaging script to ship is a dependency the release
   archive does not have. Copy the runtime files, **and its licence file** — this
   extension is AGPL-3.0-or-later and third-party code must travel with its
   licence text. Update `FORBIDDEN_IN_ARCHIVE` if the new package brings polyglot
   bloat like the connector did.
3. If it ships classes under a namespace, add the PSR-4 prefix to `info.xml`'s
   `<classloader>`. CiviCRM's `CRM_Extension_ClassLoader::loadExtension()` feeds
   these to `Composer\Autoload\ClassLoader`, and **that is the only autoloader a
   CiviCRM site runs.** An extension cannot ship a `vendor/autoload.php` and
   expect anything to `require` it — there is no hook that does, and
   `dfc_civicrm.php` deliberately autoloads only `Civi\Dfc\`. Getting this wrong
   fails on a real site with `Class not found`, not in CI.
4. Pin its behaviour with a contract test before you rely on it.
5. Run `tools/build-release.sh --dry-run` and read the member list.

### Practical advice

Prefer zero dependencies. The two things this project needs that PHP and
CiviCRM core do not provide are already handled without a library: JSON-LD
parsing (`Civi/Dfc/V2/Validation/JsonSyntaxParser.php`) and YAML reading
(`ReleaseDescriptorParser`, because an extension cannot gain a YAML dependency
and CiviCRM core ships no YAML parser — see its header for the reasoning).

If you do add one, pin it **exactly**, not with `^`. `^2.0` in a repository
whose release tarball is assembled by a script means the bytes in a given tag
depend on what Packagist served when the script ran.

## Should `composer.lock` be committed?

Short answer: **yes, and I recommend un-ignoring it — but that is your call,
because `.gitignore` is yours.**

The reasoning, since this project is neither a plain application nor a plain
library:

**Against the habit, and for the habit.**

Every argument for committing a lock file comes from applications: "your
deployment must be reproducible". That argument does not transfer. Nobody runs
`composer install` to deploy this extension. Deployment is
`tar xzf dfc_civicrm-0.1.0.tar.gz` into `sites/all/modules/`, because that is
how CiviCRM installs an extension, and because asking an administrator to run
Composer on a live site is not a supportable instruction.

That argument is also, by the same reasoning, why extensions historically do not
commit a lock file. I checked twelve real CiviCRM extensions and none of them
ships one.

**But this extension is not those extensions.** Those twelve have no runtime
Composer dependencies, or they vendor them and don't use Composer at runtime
either. This one has a runtime dependency that it must vendor into the release
archive, which means the resolved dependency graph is an *input to a release
artefact*, not an implementation detail of a local build:

- Without a committed lock, `^2.0` resolves differently on different days. Two
  people building `0.1.0` a week apart ship **different bytes under the same
  tag**, and there is no record of which connector version any given release
  actually contains. That is not a theoretical reproducibility problem — it is
  the ability to answer "what is in this release?" at all.
- `tools/build-release.sh` stamps the connector version into
  `vendor/VENDORED.md`, but a stamp generated at build time from whatever was
  installed is a guess with a timestamp on it. A committed lock makes it a fact.
- The drift check in CI (`composer validate --strict`, which fails when the lock
  is stale relative to `composer.json`) is impossible without a committed lock.
  Today the dependency can drift from its pinned version through an ordinary
  `composer update` and nothing notices.

**What it costs.** A lock file in a repository that gains dependencies generates
churn, and `composer update` runs become review events. For an extension whose
dependencies do not move, that cost is near zero — which is exactly this
project's situation.

**How to do it.** Delete line 3 of `.gitignore` (`composer.lock`), then:

```bash
git add composer.lock .gitignore
```

If you decide not to, say so in a comment on that `.gitignore` line, because the
next person will otherwise re-derive this whole argument. Either way, do not
leave it ambiguous.

CI works either way: when `composer.lock` is present it runs
`composer install` and `composer validate --strict`; when it is absent it runs
`composer update`, logs a warning naming this file, and still performs the rest
of the checks. The dependency-resolution check is genuinely weaker without the
lock, and the workflow says so in its own log rather than pretending otherwise.

## Before you push

```bash
./vendor/bin/phpunit --testsuite unit    # must be green, with no new warnings
tools/check-release-metadata.sh           # only needed when info.xml or CHANGELOG.md changed
bash -n tools/*.sh && shellcheck tools/*.sh
```

`tools/check-release-metadata.sh` is cheap and only fails on things you
probably changed on purpose. Run it anyway when you touch `info.xml`.

## Cutting a release

1. `info.xml`: bump `<version>` (SemVer `x.y.z`) and set `<releaseDate>` to
   today.
2. `CHANGELOG.md`: add `## [<version>] - <releaseDate>` and move anything under
   `## [Unreleased]` into it.
3. `tools/check-release-metadata.sh --require-tag <version> --release-date "$(date -u +%F)"`.
4. Commit, tag with a bare SemVer string and no `v` prefix, push:
   ```bash
   git tag -a 0.2.0 -m "0.2.0"
   git push origin main 0.2.0
   ```
5. `.github/workflows/release.yml` runs on the tag, builds the tarball and
   attaches it to a GitHub release.

The tag is the release. CiviCRM's Extensions Directory publishes from the tag,
so a tag that disagrees with `info.xml` publishes the wrong bytes as the
version — which is why step 3 checks it before you push rather than after.

## Style

- `declare(strict_types=1);` at the top of every PHP file. No exceptions.
- PSR-4 under `Civi/Dfc/`; PSR-0 under `CRM/Dfc/`; four-space indent.
- Comments explain **why**, and cite the file, line or spec that forces the
  decision. The existing code does this heavily and it is the house style. A
  comment that restates the code is noise; a comment that says "upstream ships
  only src/, contexts/ and vocabularies/, so dropping the latter two is a
  runtime failure, not a size saving" is worth the bytes.
- No `TODO` without an owner and a blocker id.
- Never log or print `Authorization` headers, access tokens, client secrets, or
  personal-data payloads. See `SECURITY.md`.

## No CI, no linter, no build

There is nothing to run beyond PHPUnit and the two scripts in `tools/`. No
`npm`, no linter, no test framework other than PHPUnit. Keep it that way; every
tool added to this project has to be something a CiviCRM administrator's
server does *not* need.