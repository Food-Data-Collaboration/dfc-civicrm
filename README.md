# dfc_civicrm

Native CiviCRM extension exposing the DFC v2 JSON-LD HTTP interface.

**Status: empty. No code yet.**

This repository exists so that the af-002 work has somewhere to land. It is
tracked locally only — no remote is configured yet.

## What gets built here

Per [`PRD-002.md`](../PRD-002.md) and the source plan
(`~/.opencode/plan/DFC-CiviCRM-v2-implementation-plan.md`):

- CiviCRM extension skeleton (`civix`, `info.xml`, managed entities, permissions,
  config screen) — plan Phase 1 / `lane-2`
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

## Before the first real commit

- [ ] `civix` scaffold generated here
- [ ] `api-coverage-manifest.yaml` moved in from `../api-coverage-manifest.yaml`
      and wired to a **two-pass** generator: `src/dfc_business_linkml_v2_0.yaml`
      for resource rows (generated from the OWL ontology, current v2.0.0),
      `config/dfc-original-api.yaml` for the operations column. The parity map
      is curated by hand and lags the schema — never use it to decide whether a
      class exists. Until the generator exists the manifest stays a scaffold that
      claims no coverage.
- [ ] `composer.json` dependency on `siol-data/linkml-connector` resolved —
      publish-then-depend, or vendor the tested connector into the release
      archive. Blocked as BLK-004.
- [ ] CiviCRM dev instance and OIDC test IdP available (BLK-005)

## Conventions

Follow the parent project's conventions: SemVer releases, managed entities for
all schema-level assets, APIv4 as the internal persistence API only, and an
export visibility allow-list that defaults to not-exported.