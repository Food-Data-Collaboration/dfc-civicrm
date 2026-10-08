# dfc_civicrm

Native CiviCRM extension exposing the DFC v2 JSON-LD / LDP / WebID HTTP
interface, mapping DFC v2 identities and relationships onto native CiviCRM
entities.

> **Status: pre-alpha. Installs nothing usable.**
>
> The protocol, identity, security and validation layers exist and are covered
> by 1098 unit tests. **No DFC resource endpoint is served**, and nothing here
> has ever run inside a CiviCRM installation — no CiviCRM instance exists in this
> project's environment (BLK-005). `<develStage>alpha`, so CiviCRM will not list
> it in the Extensions Directory either.
>
> Read [`CHANGELOG.md`](./CHANGELOG.md) before forming an opinion about what
> works. It is specific about what does not.

Tracked locally only — **no remote is configured yet**. See
[Before the first release](#before-the-first-release).

## What gets built here

Per [`PRD-002.md`](../PRD-002.md) and the source plan
(`~/.opencode/plan/DFC-CiviCRM-v2-implementation-plan.md`):

- CiviCRM extension skeleton (`info.xml`, managed entities, permissions, config
  screen, release packaging) — plan Phase 1 / `lane-2`
- DFC v2 JSON-LD HTTP routes under `/civicrm/dfc/v2/...` with LDP containers,
  WebID discovery and the DFC Identity Service — plan Phase 0 / `lane-1`
- Organization/Person mapping onto native CiviCRM `Contact` records, plus
  extension-owned semantic-ID and WebID entities — plan Phase 2 / `lane-3`
- Relationships, places, remaining in-scope v2 classes, reconciliation and
  operational safety limits — plan Phases 3-5 / `lane-4`
- Conformance suite, security review and release packaging — plan Phase 6 / `lane-5`

Out of scope, permanently for now: `Order`, `OrderLine` and all product classes.
Those must return a deterministic unsupported-resource error, never a partial
persist.

## Try it

```bash
composer install                              # vendor/ is not committed
./vendor/bin/phpunit --testsuite unit         # 1098 tests, no CMS needed
```

The unit suite needs no CiviCRM, no database and no site. That is deliberate:
the protocol layer is designed so its services can be tested in isolation, which
is why the whole thing runs in about 25 seconds on a laptop.

```bash
./vendor/bin/phpunit --testsuite conformance  # ONE network test, see below
```

`conformance` is a single class, `tests/conformance/LiveJwksTest.php`, which
dereferences the DFC dev realm
`https://login.fooddatacollaboration.org.uk/realms/dev` and asserts its
published key set is shaped the way this extension assumes. It is a separate
CI job, non-blocking on pull requests. See
[`CONTRIBUTING.md`](./CONTRIBUTING.md#the-conformance-suite-is-not-in-the-gate).

There is nothing to install into a site yet. When there is, it is
`build/dfc_civicrm-<version>.tar.gz`, unpacked into the CiviCRM extensions
directory.

## Layout

| Path | What it is |
|---|---|
| `Civi/Dfc/V2/` | Protocol, identity, security, validation and export-policy services. The bulk of the code. |
| `Civi/Api4/` | APIv4 entity classes for the two extension-owned tables. |
| `schema/` | `*.entityType.php`. Column and table declarations. **No SQL anywhere in this repository** — DDL is derived from these by the upgrader. |
| `managed/` | Managed custom data and relationship types, reconciled on install/upgrade/uninstall. See `managed/README.md`. |
| `xml/`, `templates/` | Routes and the admin settings form. |
| `config/dfc-release.yaml` | The pinned DFC release descriptor. Source of the DFC version, JSON-LD context URL and `dfc-b:`/`dfc-t:` prefix IRIs. **Read at runtime, never hardcoded.** Refresh procedure in the file header. |
| `tools/` | Release build and release-metadata check. Development only; excluded from the release archive. |
| `tests/phpunit/` | The unit suite. |
| `tests/conformance/` | The live JWKS check. |

`vendor/` is not committed. `composer install` first.

## How the DFC namespace is decided

The DFC version, the `@context` URL and the two ontology prefix IRIs come from
`config/dfc-release.yaml` at runtime. Nothing in the code hardcodes a DFC
version or a context URL, so a DFC release bumps the namespace without a code
change.

Two rules hold URI stability together:

- **No DFC version in any minted URI.** The version travels in `@context`.
  Upgrading 2.0.0 → 2.0.1 changes the `@context` of every document and changes
  not one `@id` this deployment has ever minted. The upstream prefix IRIs are
  unversioned, which is what makes this true.
- **No display name, email, hostname or CiviCRM integer id in any minted URI.**
  Only opaque identifiers, minted once and stored.

A hostname change swaps the authority and mints a new set of URIs. That layer
cannot keep the old ones alive — see
`Civi/Dfc/V2/Identity/UriFactory.php` and `UriFactoryStabilityTest.php`, which
assert the behaviour rather than the wish.

## Security

This extension is an **OAuth 2.0 resource server**. It validates access tokens
and never issues them. It never logs an `Authorization` header, an access or ID
token, or a personal-data payload — correlation IDs and stable error codes
instead. [`SECURITY.md`](./SECURITY.md) has the rules and the reporting route.

Two things worth knowing before you read the code:

- Export visibility is an **explicit allow-list that defaults to
  not-exported** (`Civi/Dfc/V2/Policy/PublicFieldPolicy.php`). A predicate that
  is not on the list is not published, whatever the underlying contact contains.
- Token validation runs in the **validation pipeline before any write**, not
  inside the handler that writes. That is why `ValidationPipeline` exists and why
  its stage order is asserted by a test rather than documented in a comment.

## Contributing

[`CONTRIBUTING.md`](./CONTRIBUTING.md). The short version:

```bash
./vendor/bin/phpunit --testsuite unit
tools/check-release-metadata.sh       # when info.xml or CHANGELOG.md changed
bash -n tools/*.sh && shellcheck tools/*.sh
```

`CONTRIBUTING.md` also covers the generated-vs-handwritten boundary (there is
**no** generator in this project) and the rules for adding a dependency — which
is the hard one, because a CiviCRM extension must install without an
administrator running Composer in the checkout.

## Before the first release

Checked means verified, not "believed". The left column is what a release
needs; the right is the state today.

| Item | State | Blocker |
|---|---|---|
| `civix` scaffold generated here | ✅ done — `info.xml`, `dfc_civicrm.php`, `managed/`, `schema/`, `xml/`, `templates/` are all present and wired | — |
| Connector dependency resolved | ✅ done — `siol-data/linkml-connector` **v2.0.5** is installed, pinned by `composer.lock`, working, and its observable behaviour is pinned by `tests/phpunit/Connector/ConnectorContractTest.php`. BLK-004 resolved | — |
| Connector runtime ships in the release archive | ✅ done — `tools/build-release.sh` vendors `php-connector/{src,contexts,vocabularies,LICENSE}` and asserts the Ruby/TypeScript connectors and the Python test suite are absent. BLK-016 mitigated (the bloat is upstream's) | — |
| `config/dfc-release.yaml` shipped and pinned | ✅ done — present in `config/`, asserted present by the packaging script, parsed at runtime by `ReleaseDescriptorParser` | — |
| `composer.lock` tracked | ⚠️ **not committed** — see [`CONTRIBUTING.md`](./CONTRIBUTING.md#should-composerlock-be-committed). Recommendation: commit it; the decision is `.gitignore`'s | — |
| `api-coverage-manifest.yaml` moved in and wired to a two-pass generator | ❌ **not done** — the manifest is still outside this directory (`../api-coverage-manifest.yaml`). The generator does not exist, so the manifest remains a scaffold claiming no coverage | BLK-003 |
| A git remote | ✅ **done 2026-10-08** — `Food-Data-Collaboration/dfc-civicrm`. Public, untagged: no release tag exists yet, so there is nothing to install until CP-7 | BLK-001 closed |
| Real maintainer name and email in `info.xml` | ✅ **done 2026-10-08** — `Food Data Collaboration` / `hello@fooddatacollaboration.org.uk` (the org's shared inbox, not a dedicated security address; `SECURITY.md` prefers GitHub private advisory reporting). `composer.json` `name` corrected to `food-data-collaboration/dfc-civicrm` | BLK-001 closed |
| `LICENSE` file | ✅ **added 2026-10-08** — full AGPL-3.0 text, from the SPDX canonical copy, with the appendix `{{ year }}  {{ organization }}` placeholder filled (`2026  Food-Data-Collaboration`). Verified byte-identical operative terms against a second independent copy before adding | — |
| CiviCRM dev instance | ❌ **none** — no install, upgrade, disable/re-enable, uninstall or HTTP request has been tested on any CiviCRM version. `info.xml` claims 5.74+; that claim is derived from the four mixin versions used, not tested | BLK-005 |
| OIDC test IdP with a `client_credentials` client | ❌ **blocked** — the dev realm is reachable and its discovery document is verified, but no confidential client with a service account exists, so no test can obtain an access token and therefore no test can verify a real signature | BLK-014 |
| Conformance suite beyond the live JWKS check | ❌ one test class only — no cross-connector parity assertions, no HTTP/LDP/WebID black-box suite | CP-7 |
| Ruling on the undecided protocol questions | ❌ open — 406-vs-415, the product/order unsupported-resource status and code, per-caller export visibility, `ldp:contains` shape, pagination parameter names | BLK-008…011 |

### The remote, specifically

No remote is configured. Consequences, in order of how much they block:

1. `SECURITY.md` has no working vulnerability reporting route. The maintainer
   address in `info.xml` is deliberately non-routable. **This is the one item
   here with a security consequence rather than a schedule one.**
2. `.github/workflows/release.yml` cannot run. It is written and syntax-checked
   but has never executed — see the header comment in that file, which says so
   in the same terms.
3. `info.xml`'s repository URLs resolve nowhere.
4. No review, no second pair of eyes, no issue tracker.

## Conventions

Follow the parent project's conventions: SemVer releases, managed entities for
all schema-level assets, APIv4 as the internal persistence API only, and an
export visibility allow-list that defaults to not-exported.