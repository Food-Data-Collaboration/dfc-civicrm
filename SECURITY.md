# Security Policy

## Reporting a vulnerability

**Do not open a public issue.**

Report privately to the maintainer named in `info.xml`
(`hello@fooddatacollaboration.org.uk`), or use GitHub's private reporting on
<https://github.com/Food-Data-Collaboration/dfc-civicrm/security/advisories/new>.

**Prefer the advisory route.** It keeps the report out of the public issue
tracker, which is the whole point of not opening an issue, and it lets the
maintainer stage disclosure.

The email address is the organisation's shared contact address, not a dedicated
security mailbox. It is routable and monitored, which is a real improvement on
the RFC 2606 placeholder that stood here until 2026-10-08 — but it is a general
inbox, so a dedicated security address and a `security.txt` would be better.
That gap is tracked in `README.md`.

What a working route must satisfy before any release:

- A monitored address, not a role mailbox.
- A stated response window. 7 days to acknowledge is the minimum worth
  publishing.
- A stated disclosure window. 90 days from the first report is a defensible
  default.
- A PGP key or a security.txt, so a first contact does not require trusting the
  same channel you are asking to be careful with.

Because the reporting route is not yet established, please do not assume a
report will reach anyone. If you find something before that is fixed, contact
the project owner directly rather than relying on this document.

### Severity

Judged by what an attacker gains, not by where the code sits.

| Severity | Criteria |
|---|---|
| **Critical** | Unauthenticated access to personal data; token forgery accepted; ability to mint a WebID for an arbitrary principal; injection into stored data that executes. |
| **High** | Authenticated bypass of a permission or scope; read of personal data belonging to another principal; SSRF reachable from an imported URI. |
| **Medium** | Information disclosure of internal structure; denial of service reachable by an unauthenticated caller; missing rate limit on an expensive path. |
| **Low** | Version or configuration disclosure with no exploitation path. |

### What to include

- The DFC version (`GET /civicrm/dfc/v2` `@context`, or the `<version>` in
  `info.xml`), the CiviCRM version, and the PHP version.
- The exact request, with every credential replaced by `[REDACTED]`.
- What you observed and what you expected.
- Whether the issue requires an `Authorization` header; if so, a token you minted
  yourself, never one from a real deployment.

## This extension is an OAuth 2.0 resource server

Not a client. It never asks for a token, never holds a client secret, and never
sends a credential to an identity provider. It accepts bearer access tokens that
something else obtained, and it is responsible for what it does with them.

That makes the following rules, not guidance.

### Never log an `Authorization` header

Not the full value, not a prefix, not the length, not a hash. There is no safe
truncation of a bearer token: any prefix of a credential is a credential, and a
hash of one is a lookup key for anyone holding a candidate list.

```
Authorization: Bearer eyJhbGciOi...
                        ^^^^^^^^ this is a live credential
```

CiviCRM logs request headers in several places by default — the debug log, the
`civicrm_log` table for API calls, PHP's own error output when an exception
carries a request object. A blanket "log the request headers" helper is a leak
with extra steps.

If you need to prove which authentication path a request took, log the
**correlation id**, the resolved issuer, the resolved `kid` and `alg`, and the
granted scopes. Never the token.

### Never log an access token, ID token or refresh token

Including at `debug` level, including "temporarily", including inside a test
fixture. `SECURITY.md` exists partly because "I will remove that log line later"
is a promise that has not been kept in a great many codebases.

A token in a log file is a token in every backup of that log file, in every
place that log file is shipped to, and in whatever aggregates it. Rotation does
not help: the token's `exp` may be an hour and the backup's lifetime is a year.

**ID tokens are not accepted at all.** They are issued for the client to present
to the *userinfo* endpoint. Accepting one as an API credential is a confused
deputy: an ID token proves who the subject is, not that the bearer may act. Any
change that makes ID tokens acceptable is a security regression, not a feature
— reject it in review.

### Never log a personal-data payload

DFC v2 resources are people and organisations: names, addresses, phone numbers,
email addresses, social media handles, place coordinates. A JSON-LD payload
logged for debugging is a database export.

- Log **field names**, never field values, when a payload shape needs recording.
- Log **counts** and **types**, not contents: `imported 3 Organizations, 0
  Persons`.
- Use the correlation id to join a log line to a specific request. `Civi\Dfc\V2\
  Controller\Error\CorrelationId` exists for this.
- When a real payload is genuinely needed to debug something, reproduce it in a
  fixture under `tests/fixtures/` with synthetic values, and delete the capture
  afterwards.

### Client secrets and the IdP

- Client credentials belong to whatever obtains tokens, never to this extension.
  This extension needs an issuer, a client id for `audience` validation, and a
  JWKS URI. Nothing else.
- A JWKS document is **public key material** and is safe to log. A token is not.
  Do not confuse them because they travel in the same request.
- TLS is required to the identity provider, and certificate verification is on.
  `DfcReleaseConfig` refuses `http` on any non-loopback host for the same reason
  it refuses an `http` context URL: a pinned endpoint reached over plaintext is a
  downgrade waiting to be used.

## Data this extension will and will not expose

The rules the code enforces, restated here because a security policy that only
describes the code is not a policy.

**Defaults to not-exported.** `PublicFieldPolicy` is an explicit per-field
allow-list. A predicate that is not on the list is not published, regardless of
what the underlying CiviCRM contact contains. There is no "export everything and
filter later" mode.

**Two layers of authorisation, both required.** An OIDC scope maps to a CiviCRM
permission through one registry, `ScopePermissionRegistry`. Holding a valid
token is not sufficient; the mapped permission must also be held. A token issued
to a service account that lacks the CiviCRM permission gets `403`, not data.

**Nothing is authenticated before anything is mutated.** Token validation is a
stage in the validation pipeline that runs before any write, not a check
inside the handler that performs the write. This is the reason
`ValidationPipeline` exists and the reason stage order is asserted rather than
documented.

**Disabling or uninstalling the extension never deletes, merges or hides
underlying CiviCRM contacts.** Only the extension's own two tables and its own
managed custom data are affected
(`dfc_civicrm_civicrm_uninstall()`). Uninstalling an API extension must not be
able to destroy a CRM's customer data.

**Errors do not leak.** `Civi\Dfc\V2\Controller\Error\LeakGuard` strips internal
identifiers, SQL, stack traces and PHP exception detail from everything a caller
sees. A client gets a correlation id and a stable error code. `beStrictAboutOutputDuringTests`
in `phpunit.xml.dist` exists so a stray debug print cannot ship.

## What a reviewer should check

1. **New log statements.** Does any of them touch a header, a token, or a
   payload? `grep -nE 'Authorization|Bearer|token' <changed files>`.
2. **New `echo`/`print_r`/`var_dump`.** Fails the build via
   `beStrictAboutOutputDuringTests`, but only if a test exercises that path.
   Check the test exists.
3. **Auth decisions made outside the pipeline.** A controller that checks a
   scope itself, rather than consulting the registry, is the failure mode PRD-002
   §9 names. Controllers must not make independent auth, media-type or
   error-model decisions.
4. **Order of operations.** Anything that mutates before validation has run.
5. **New outbound requests.** Remote dereferencing of an imported URI is an SSRF
   surface. There must be an allow/deny policy, bounded timeouts, and bounded
   redirects behind it.
6. **New settings.** A setting that weakens the export allow-list or the
   required scopes needs the same scrutiny as a permission check, because it is
   one.
7. **New dependencies.** See `CONTRIBUTING.md` — including the licence file, and
   the `<classloader>` entry.

## Out of scope for this extension

- **Token issuance.** Not a responsibility; this extension never mints,
  refreshes or revokes tokens.
- **Transport security.** TLS termination is the web server's job.
- **CiviCRM core vulnerabilities.** Report those to CiviCRM.
- **The DFC standard itself.** Report ambiguity in the specification to the Data
  Food Consortium. Where the spec is ambiguous this extension picks the
  conservative reading and says so in a comment; if you believe a reading is
  wrong, the comment is where to argue.

## Known issues in this release

Stated plainly rather than left to be discovered:

- No real vulnerability is known.
- **No code path has ever executed.** The entire test suite runs without a
  CiviCRM bootstrap, and no installation exists (BLK-005). Every property above
  is enforced by unit tests against isolated services, not by an attacker
  trying anything.
- **The maintainer route in `info.xml` is a placeholder**, so the reporting path
  above is not yet functional.
- **Placeholder URLs** in `info.xml` point at a repository that does not exist.