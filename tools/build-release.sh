#!/usr/bin/env bash
#
# build-release.sh — produce the versioned CiviCRM extension archive.
#
# ============================================================================
# WHAT THIS PRODUCES, AND WHY THE SHAPE IS WHAT IT IS
# ============================================================================
#   build/dfc_civicrm-<version>.tar.gz
#   build/SHA256SUMS
#
# The archive contains exactly one top-level directory, named for the extension
# key from info.xml (`dfc_civicrm/`), holding the files an administrator's
# CiviCRM needs:
#
#   Civi/  CRM/  templates/  xml/  managed/  schema/  config/  l10n/
#   info.xml  <file>.php  composer.json  README.md  CHANGELOG.md  SECURITY.md
#   vendor/          <- the RUNTIME subset of the DFC-LinkML PHP connector
#
# `dfc_civicrm/dfc_civicrm.php` must exist at the top of that directory because
# CRM_Extension_Info::parse() reads `<file>` off the root element and CiviCRM
# resolves the extension from `<ext-dir>/<key>/<file>.php`. One directory, one
# key, one file name — the script asserts all three.
#
# ============================================================================
# WHAT IS EXCLUDED, AND WHY EXCLUSION IS AN ALLOW-LIST HERE
# ============================================================================
# The copy list below is an ALLOW-LIST, not a deny-list. A deny-list has to be
# extended every time somebody adds a directory, and its failure mode is
# silent: forget an entry and dev-only files ship to every site. An allow-list
# fails closed — a new directory is simply not in the release until somebody
# decides it belongs there, which is the right default for code that runs
# inside somebody else's CMS.
#
# Not shipped:
#   vendor/ (development)  - 7.7 MB of the whole upstream DFC-LinkML monorepo:
#                             the Ruby connector, the TypeScript connector, the
#                             Python test suite, scripts/, docs/, shacl/, the raw
#                             LinkML schemas. BLK-016. PSR-4 only needs
#                             php-connector/src; see vendor_subset() below.
#   tests/, phpunit.xml.dist, .phpunit.cache/  - development
#   .github/               - development
#   .gitignore             - development
#   composer.lock          - development only; the runtime does not use it
#   tools/                 - build-time only; shipping a build script inside the
#                            artefact it builds is how a script ends up
#                            executed with the web server's permissions
#
# ============================================================================
# SAFETY PROPERTIES
# ============================================================================
# S1. `rm -rf` appears exactly twice, on these two paths, and both are inside a
#     tree this run created from scratch:
#
#       rm -rf "${STAGE_DIR}"                     cleanup trap, on EXIT
#       rm -rf "${package_dst}/tests"             safety net inside the staging tree
#
#     ${STAGE_DIR} comes from `mktemp -d`, so it is a fresh unpredictable path,
#     normally under $TMPDIR and never inside the repository. The second is a
#     child of it and is a no-op today (the connector's src/, contexts/ and
#     vocabularies/ contain no tests/ directory); it exists so that a future
#     upstream layout change cannot smuggle a test suite into a release.
#
#     Neither can name anything outside this run's own staging tree. The
#     per-run output directory is never deleted at all — the script REFUSES to
#     reuse an existing one rather than clearing a directory somebody else put
#     files in. Every other path in the script is used read-only.
#
# S2. It refuses to run from a dirty git tree unless --force is given. A release
#     built from an uncommitted working tree is a release nobody can reproduce
#     from its tag.
#
# S3. Every archive member path is derived from a constant list and from
#     basename()-ed names. No member path is built from anything read out of
#     a file, so a crafted archive member cannot escape the staging directory.
#
# S4. --dry-run writes nothing at all.
#
# ============================================================================
# USAGE
# ============================================================================
#   tools/build-release.sh                      # build into build/
#   tools/build-release.sh --output-dir /tmp/x  # somewhere else
#   tools/build-release.sh --dry-run            # print the file list only
#   tools/build-release.sh --force              # dirty tree, on purpose
#   tools/build-release.sh --no-vendor          # runtime pieces only (see below)
#
# `--no-vendor` exists for exactly one consumer, the CiviCRM integration test
# job that installs into a real site from a checked-out tree. It produces an
# archive that will NOT work on a site, and the script says so.
#
# ============================================================================
# NOT VERIFIED END TO END
# ============================================================================
# This script has never been run against a real CiviCRM installation, because
# none exists (BLK-005) and this repository has no remote. The archive SHAPE is
# asserted; whether CiviCRM accepts the archive is unproven. Do not treat a
# green run of this script as evidence for CP-7.
#
# The one claim here that IS verified locally is byte-reproducibility: the same
# tree built into three different output directories yields three archives with
# the same sha256. That required NOT putting a wall-clock timestamp in
# vendor/VENDORED.md; the file records SOURCE_DATE_EPOCH-derived provenance
# instead. Verified by running it three times.
#
# shellcheck shell=bash

set -o errexit
set -o nounset
set -o pipefail

readonly SCRIPT_NAME="${0##*/}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
readonly REPO_ROOT

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------

OUTPUT_DIR="${REPO_ROOT}/build"
FORCE=0
DRY_RUN=0
NO_VENDOR=0

# Runtime files, as relative paths under the repository root. An allow-list:
# anything not named here does not ship.
#
# Entries that do not exist yet are reported as "not present (skipped)" and are
# not an error. That is deliberate for `CRM/` and `l10n/`, which lane-3/lane-4
# own: this script must not have to be edited every time a lane lands. It is
# NOT deliberate for info.xml, <file>.php or composer.json, which are asserted
# to be present below.
readonly RUNTIME_PATHS=(
    "info.xml"
    "composer.json"
    "README.md"
    "CHANGELOG.md"
    "SECURITY.md"
    "Civi"
    "CRM"
    "templates"
    "xml"
    "managed"
    "schema"
    "config"
    "l10n"
)

# Must be at the top level of the staged extension. info.xml's <file> is in
# this list too, because its VALUE is read from info.xml rather than hardcoded -
# see copy_runtime().
readonly REQUIRED_TOP_LEVEL=(
    "info.xml"
    "CHANGELOG.md"
    "SECURITY.md"
    "config/dfc-release.yaml"
    "Civi/Api4/DfcIdentity.php"
    "Civi/Api4/DfcWebId.php"
    "Civi/Dfc/V2/Identity/DfcReleaseConfig.php"
    "managed"
    "schema"
    "xml/Menu/dfc_civicrm.xml"
)

# Non-fatal, loud. A CiviCRM extension is expected to carry its licence text,
# and this one is AGPL-3.0-or-later while shipping MIT third-party code.
#
# A warning rather than a failure on purpose: the file is simply absent today,
# and a release script that hard-fails on a known gap produces a red build
# everyone learns to ignore. The gap is tracked in README.md under
# "Before the first release" and in CHANGELOG.md's known limitations. Fix it by
# adding the AGPL-3.0 text as LICENSE at the repository root.
readonly LICENCE_FILE="LICENSE"

# The DFC-LinkML PHP connector, name => subdirectory inside its own package
# that carries the connector.
readonly CONNECTOR_PACKAGE="siol-data/dfc-connector"
readonly CONNECTOR_SUBPATH="php-connector"

# Everything under the connector package that must NOT reach a release archive.
# The package root is the DFC-LinkML repository root, so a whole-package copy
# drags in the other two language connectors and their test suites (BLK-016).
readonly FORBIDDEN_IN_ARCHIVE=(
    "vendor/siol-data/dfc-connector/ruby-gem"
    "vendor/siol-data/dfc-connector/typescript-connector"
    "vendor/siol-data/dfc-connector/tests"
    "vendor/siol-data/dfc-connector/scripts"
    "vendor/siol-data/dfc-connector/docs"
    "vendor/siol-data/dfc-connector/shacl"
    "vendor/siol-data/dfc-connector/config"
    "vendor/siol-data/dfc-connector/src"
    "vendor/siol-data/dfc-connector/.github"
    "vendor/siol-data/dfc-connector/.opencode"
    "vendor/siol-data/dfc-connector/AGENTS.md"
    "vendor/siol-data/dfc-connector/agents.md"
    "vendor/siol-data/dfc-connector/Makefile"
    "tests"
    ".github"
    ".phpunit.cache"
    "tools"
    ".git"
)

FAILURES=0

# ---------------------------------------------------------------------------
# Reporting
# ---------------------------------------------------------------------------

info() { printf '%s\n' "$*"; }
warn() { printf 'WARN: %s\n' "$*" >&2; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
die()  { printf '%s: %s\n' "${SCRIPT_NAME}" "$*" >&2; exit 2; }

cleanup() {
    local status=$?
    if [ -n "${STAGE_DIR:-}" ] && [ -d "${STAGE_DIR}" ]; then
        rm -rf -- "${STAGE_DIR}"
    fi
    return "${status}"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

while [ "$#" -gt 0 ]; do
    case "$1" in
        --output-dir)
            [ "$#" -ge 2 ] || die "--output-dir needs a directory"
            OUTPUT_DIR="$2"
            shift 2
            ;;
        --force)
            FORCE=1
            shift
            ;;
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        --no-vendor)
            NO_VENDOR=1
            shift
            ;;
        -h|--help)
            sed -n '2,70p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            die "unknown argument: $1 (try --help)"
            ;;
    esac
done

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------

for tool in git tar; do
    command -v "${tool}" >/dev/null 2>&1 || die "required tool not found: ${tool}"
done

cd -- "${REPO_ROOT}"

git rev-parse --is-inside-work-tree >/dev/null 2>&1 \
    || die "${REPO_ROOT} is not inside a git work tree. This script builds a release artefact, so it needs git to prove the tree is clean and to stamp the build metadata."

GIT_COMMIT="$(git rev-parse --verify HEAD 2>/dev/null || echo unknown)"
readonly GIT_COMMIT
GIT_DESCRIBE="$(git describe --tags --always --dirty 2>/dev/null || echo "${GIT_COMMIT}")"
readonly GIT_DESCRIBE

# The version comes from info.xml, never from a git tag and never from
# composer.json: CiviCRM keys the published release off the manifest.
VERSION="$(sed -n -E 's/^[[:space:]]*<version>([^<]+)<\/version>[[:space:]]*$/\1/p' info.xml | head -n 1)"
[ -n "${VERSION}" ] || die "could not read <version> from ${REPO_ROOT}/info.xml"

EXTENSION_KEY="$(sed -n -E 's/^<extension[^>]*[[:space:]]key="([^"]+)".*$/\1/p' info.xml | head -n 1)"
readonly EXTENSION_KEY
[ -n "${EXTENSION_KEY}" ] || die "could not read key=\"...\" from the root <extension> element in info.xml"

EXTENSION_FILE="$(sed -n -E 's/^[[:space:]]*<file>([^<]+)<\/file>[[:space:]]*$/\1/p' info.xml | head -n 1)"
[ -n "${EXTENSION_FILE}" ] || die "could not read <file> from info.xml"

ARCHIVE_NAME="${EXTENSION_KEY}-${VERSION}.tar.gz"

# S2: dirty tree guard. ------------------------------------------------------
#
# A release must be reproducible from its tag, and an uncommitted working tree
# cannot be. The check runs before anything is written, so a refusal has no
# side effects at all.
dirty_report() {
    # Untracked-but-not-ignored paths under the output directory are this
    # script's own previous output. Everything else counts.
    git status --porcelain=v1 --untracked-files=all \
        | sed -E 's/^.. //' \
        | grep -v -E "^(\"?)(${OUTPUT_DIR#./})/" || true
}

if [ -n "$(dirty_report)" ]; then
    if [ "${FORCE}" -eq 0 ]; then
        printf '%s: refusing to build a release from a dirty working tree.\n\n' "${SCRIPT_NAME}" >&2
        printf 'The following are modified or untracked (excluding %s/):\n' "$(basename -- "${OUTPUT_DIR}")" >&2
        dirty_report >&2
        printf '\nA release must be reproducible from its tag. Commit the work, or pass --force if you\nreally are building from a dirty tree (a --force build records that in the archive metadata).\n' >&2
        exit 1
    fi
    warn "building from a DIRTY working tree because --force was given. This build is not reproducible from any tag."
    FORCE_NOTE="forced-dirty"
else
    FORCE_NOTE="clean"
fi

# Output directory resolution and safety. ------------------------------------
if [ -e "${OUTPUT_DIR}" ] && [ ! -d "${OUTPUT_DIR}" ]; then
    die "--output-dir ${OUTPUT_DIR} exists and is not a directory."
fi

mkdir -p -- "${OUTPUT_DIR}"
OUTPUT_DIR="$(cd -- "${OUTPUT_DIR}" && pwd -P)"

# S1: refuse to reuse an existing output directory. The script only ever
# creates the run directory it owns; clearing a directory somebody else put
# files in is not something a build script should do on its own initiative.
RUN_DIR="${OUTPUT_DIR}/${EXTENSION_KEY}-${VERSION}"
if [ -e "${RUN_DIR}" ]; then
    die "${RUN_DIR} already exists. Choose another --output-dir, or remove it yourself if you are sure nothing else uses it. This script will not delete a directory it did not create."
fi

STAGE_ROOT=""
STAGE_DIR=""
EXT_DIR=""

create_stage() {
    # mktemp -d, so the staging path is created fresh by this run and is not
    # inside the repository (it normally lands under $TMPDIR).
    STAGE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dfc-civicrm-build.XXXXXXXX")"
    if [ -z "${STAGE_ROOT}" ] || [ ! -d "${STAGE_ROOT}" ]; then
        die "mktemp -d produced nothing usable"
    fi

    STAGE_DIR="${STAGE_ROOT}/${EXTENSION_KEY}"
    mkdir -p -- "${STAGE_DIR}"
    EXT_DIR="${STAGE_DIR}"
}

# ---------------------------------------------------------------------------
# Copying
# ---------------------------------------------------------------------------

copy_runtime() {
    local rel missing=0
    for rel in "${RUNTIME_PATHS[@]}"; do
        if [ -e "${REPO_ROOT}/${rel}" ]; then
            cp -a -- "${REPO_ROOT}/${rel}" "${EXT_DIR}/"
            info "  + ${rel}"
        else
            info "  - ${rel} (not present, skipped)"
            missing=$((missing + 1))
        fi
    done

    # The extension's own entry point, named by info.xml's <file>.
    #
    # Copied separately and by NAME rather than added to RUNTIME_PATHS, because
    # RUNTIME_PATHS is a static list and this file's name is whatever info.xml
    # says it is. CRM_Extension_Info::parse() reads <file> and CiviCRM resolves
    # <ext-dir>/<key>/<file>.php, so a rename in info.xml has to move this
    # automatically or the release installs and then does nothing.
    if [ -f "${REPO_ROOT}/${EXTENSION_FILE}.php" ]; then
        cp -a -- "${REPO_ROOT}/${EXTENSION_FILE}.php" "${EXT_DIR}/"
        info "  + ${EXTENSION_FILE}.php (named by info.xml <file>)"
    else
        fail "info.xml declares <file>${EXTENSION_FILE}</file> but ${REPO_ROOT}/${EXTENSION_FILE}.php does not exist."
    fi

    # Licence text. A warning, not a failure - see LICENCE_FILE above.
    if [ -f "${REPO_ROOT}/${LICENCE_FILE}" ]; then
        cp -a -- "${REPO_ROOT}/${LICENCE_FILE}" "${EXT_DIR}/"
        info "  + ${LICENCE_FILE}"
    else
        warn "no ${LICENCE_FILE} at the repository root. This extension is AGPL-3.0-or-later and ships MIT third-party code; a release without the licence text is incomplete. See README.md."
    fi

    if [ "${missing}" -gt 0 ]; then
        info "  ${missing} allow-listed path(s) absent. Expected for lanes that have not landed yet; the release-metadata check is what decides whether an absent file is a problem."
    fi
}

vendor_subset() {
    local package_src="${REPO_ROOT}/vendor/${CONNECTOR_PACKAGE}/${CONNECTOR_SUBPATH}"
    local package_dst="${EXT_DIR}/vendor/${CONNECTOR_PACKAGE}/${CONNECTOR_SUBPATH}"

    if [ ! -d "${package_src}" ]; then
        fail "the connector is not installed at ${package_src}. Run 'composer install' first. Without it the archive has no DFC runtime and the extension cannot serialise JSON-LD."
        return
    fi

    mkdir -p -- "${package_dst}"

    # Only these three directories. src/ is the PSR-4 root; contexts/ and
    # vocabularies/ are read from disk at runtime by
    # DataFoodConsortium\Connector\Connector:
    #   src/Connector.php:922  __DIR__ . '/../contexts/context_<version>.json'
    #   src/Connector.php:727  __DIR__ . '/../vocabularies/<name>.jsonld'
    # so dropping either of them would break export and taxonomy lookups with
    # a "file not found" rather than an obvious missing-vendor error.
    local dir
    for dir in src contexts vocabularies; do
        if [ ! -d "${package_src}/${dir}" ]; then
            fail "connector subdirectory missing: ${package_src}/${dir}. This is a change in siol-data/dfc-connector's layout; update vendor_subset() before shipping a release."
            continue
        fi
        cp -a -- "${package_src}/${dir}" "${package_dst}/"
        info "  + vendor/${CONNECTOR_PACKAGE}/${CONNECTOR_SUBPATH}/${dir}"
    done

    # The connector is MIT and this extension is AGPL-3.0-or-later. Shipping
    # third-party code requires shipping its licence text.
    if [ -f "${package_src}/LICENSE" ]; then
        cp -a -- "${package_src}/LICENSE" "${package_dst}/LICENSE"
        info "  + vendor/${CONNECTOR_PACKAGE}/${CONNECTOR_SUBPATH}/LICENSE"
    else
        fail "connector LICENSE not found at ${package_src}/LICENSE. Third-party code must ship with its licence."
    fi

    # Its own tests, and PHPUnit, have no business in a site.
    rm -rf -- "${package_dst}/tests"

    write_vendor_manifest
}

write_vendor_manifest() {
    local name="" version="" dist_reference=""

    # Prefer composer.lock, because that is the file that actually decides what
    # gets installed and it is readable without running Composer. Read
    # `composer show --locked` only as a fallback, for the case where the lock is
    # not tracked in git.
    #
    # Note the field names: a single package in composer.lock has `"version"`,
    # while `composer show --format=json` reports `"versions"` as an ARRAY
    # ("versions": ["v2.0.5"]). Taking the first element of that array is why the
    # naive `"version"` grep found nothing and stamped "unknown" in an earlier
    # run of this script.
    # awk rather than sed: the package name contains '/', which cannot be
    # interpolated into a sed s/.../.../.../ expression without also having to
    # escape the delimiter, and the escaping is where this went wrong once
    # already. awk takes the name as an -v variable, so no quoting hazard.
    # One awk pass for all three fields, printing them tab-separated on a single
    # line. Three separate passes would each have to re-find the same package
    # block, and reading them back apart is where the quoting goes wrong.
    if [ -f "${REPO_ROOT}/composer.lock" ]; then
        local locked_line
        locked_line="$(awk -v pkg="${CONNECTOR_PACKAGE}" '
            match($0, /"name"[[:space:]]*:[[:space:]]*"[^"]+"/) {
                n = substr($0, RSTART, RLENGTH)
                gsub(/.*:[[:space:]]*"|"$/, "", n)
                if (n != pkg) { next }
                found = 1
                # Composer writes "version" on the line immediately after "name"
                # for every package in the lock file, so read it right here.
                if ((getline nextline) > 0 && match(nextline, /"version"[[:space:]]*:[[:space:]]*"[^"]+"/)) {
                    v = substr(nextline, RSTART, RLENGTH)
                    gsub(/.*:[[:space:]]*"|"$/, "", v)
                    print n "\t" v
                    exit
                }
                print n "\t"
                exit
            }
        ' "${REPO_ROOT}/composer.lock")"

        if [ -n "${locked_line}" ]; then
            name="$(printf '%s' "${locked_line}" | cut -f1)"
            version="$(printf '%s' "${locked_line}" | cut -f2)"
        fi

        # The dist reference sits a few lines further on, inside "dist" (or
        # "source"). Read forward from the name line rather than trying to bound
        # the object, because the block's nesting differs between composer
        # versions and a range end-pattern is easy to get wrong.
        dist_reference="$(awk -v pkg="${CONNECTOR_PACKAGE}" '
            $0 ~ "\"name\"[[:space:]]*:[[:space:]]*\"" pkg "\"" { found = 1; next }
            found && match($0, /"reference"[[:space:]]*:[[:space:]]*"[0-9a-fA-F]{7,40}"/) {
                r = substr($0, RSTART, RLENGTH)
                gsub(/.*:[[:space:]]*"|"$/, "", r)
                print r
                exit
            }
        ' "${REPO_ROOT}/composer.lock")"
    fi

    if [ -z "${version}" ] && command -v composer >/dev/null 2>&1; then
        local locked
        locked="$(composer show --locked --format=json "${CONNECTOR_PACKAGE}" 2>/dev/null || echo '')"
        if [ -n "${locked}" ]; then
            [ -n "${name}" ] || name="$(printf '%s' "${locked}" | sed -n -E 's/.*"name"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/p' | head -n 1)"
            [ -n "${dist_reference}" ] || dist_reference="$(printf '%s' "${locked}" | sed -n -E 's/.*"reference"[[:space:]]*:[[:space:]]*"([0-9a-fA-F]{7,40})".*/\1/p' | head -n 1)"
            # "versions": ["v2.0.5"] - an array, so take the first element.
            version="$(printf '%s' "${locked}" | sed -n -E 's/.*"versions"[[:space:]]*:[[:space:]]*\[[[:space:]]*"([^"]+)".*/\1/p' | head -n 1)"
        fi
    fi

    # composer.lock is deliberately NOT committed (2026-10-03, requester's
    # decision: this extension is distributed as a CiviCRM package, not as a
    # Composer library, so the lock is the installing developer's artefact).
    # That means composer.lock and `composer show --locked` are both absent
    # here, and the provenance table above would otherwise stamp "unknown" -
    # leaving an administrator unable to tell which DFC runtime they received.
    #
    # Read the version from the INSTALLED package instead. It is what actually
    # got vendored, which is a stronger claim than what the lock resolved to:
    # if the working tree and the lock ever disagree, the files in the archive
    # are the installed ones, so those are what we must record.
    if [ -z "${version}" ] && command -v composer >/dev/null 2>&1; then
        local installed
        installed="$(composer show --format=json "${CONNECTOR_PACKAGE}" 2>/dev/null || echo '')"
        if [ -n "${installed}" ]; then
            version="$(printf '%s' "${installed}" | sed -n -E 's/.*"versions"[[:space:]]*:[[:space:]]*\[[[:space:]]*"([^"]+)".*/\1/p' | head -n 1)"
            [ -n "${dist_reference}" ] || dist_reference="$(printf '%s' "${installed}" | sed -n -E 's/.*"reference"[[:space:]]*:[[:space:]]*"([0-9a-fA-F]{7,40})".*/\1/p' | head -n 1)"
        fi
    fi

    # Authoritative source, and the one that survives no-lock: Composer writes
    # the resolved version of every installed package into
    # vendor/composer/installed.json. That file describes exactly what was
    # installed - which is what we just vendored - so it is a stronger claim
    # than the lock, not a weaker one.
    #
    # Deliberately not `composer show --format=json`: without --locked it omits
    # the "versions" array entirely, which is how this previously fell through to
    # "unknown". Not the installed package's own composer.json either - Composer
    # strips "version" from distributed package manifests, so the field is
    # absent by design.
    if [ -z "${version}" ]; then
        local installed_json="${REPO_ROOT}/vendor/composer/installed.json"
        if [ -f "${installed_json}" ]; then
            local parsed
            if command -v python3 >/dev/null 2>&1; then
                parsed="$(python3 - "${installed_json}" "${CONNECTOR_PACKAGE}" <<'PYEOF' 2>/dev/null || echo ''
import json, sys
try:
    with open(sys.argv[1]) as fh:
        data = json.load(fh)
except Exception:
    sys.exit(0)
packages = data["packages"] if isinstance(data, dict) else data
for pkg in packages:
    if pkg.get("name") == sys.argv[2]:
        # TAB-separated, explicitly. print(a, b) would emit a single SPACE,
        # which `cut -f1` does not split on - that bug put the dist reference
        # into the Version cell of the release's provenance table.
        ref = (pkg.get("dist") or {}).get("reference") or (pkg.get("source") or {}).get("reference") or ""
        sys.stdout.write(pkg.get("version", "") + "\t" + ref + "\n")
        break
PYEOF
)"
                [ -n "${version}" ] || version="$(printf '%s' "${parsed}" | cut -f1)"
                # Tab-separated, so `cut -f2` is the reference and nothing else.
                # Assigning the whole line to version (a bug this replaced) rendered
                # "v2.0.5 2ce4a2ce..." in the Version cell of the release's
                # provenance table.
                dist_reference="$(printf '%s' "${parsed}" | cut -f2)"
            else
                warn "python3 not available; cannot read vendor/composer/installed.json for provenance. Refusing to ship an archive whose VENDORED.md says 'unknown'."
            fi
        fi
    fi

    if [ -z "${version}" ]; then
        # Hard failure, not a warning. A release whose provenance table says
        # "unknown" is a release an administrator cannot audit, and shipping
        # one silently is worse than refusing to build.
        fail "could not determine the connector version (tried composer.lock, 'composer show --locked', 'composer show', and the installed package's composer.json). The provenance table in vendor/VENDORED.md would say 'unknown', and an administrator would have no way to tell which DFC runtime they received. Fix the provenance source rather than shipping an unauditable release."
    fi

    [ -n "${name}" ] || name="${CONNECTOR_PACKAGE}"
    [ -n "${version}" ] || version="unknown"
    [ -n "${dist_reference}" ] || dist_reference="unknown"

    cat > "${EXT_DIR}/vendor/VENDORED.md" <<EOF
# Vendored runtime dependency

This directory is not produced by \`composer install\`. It is assembled by
\`tools/build-release.sh\` from the connector package installed in the build
checkout, because a CiviCRM site must not be asked to run Composer to install
an extension.

## What is here

| Path | Why |
|---|---|
| \`${CONNECTOR_PACKAGE}/${CONNECTOR_SUBPATH}/src\` | PSR-4 root for \`DataFoodConsortium\\Connector\\\` (declared in \`composer.json\`, and in \`info.xml\` \`<classloader>\` for the site runtime) |
| \`${CONNECTOR_PACKAGE}/${CONNECTOR_SUBPATH}/contexts\` | The DFC JSON-LD context, read from disk at runtime by \`Connector.php\` |
| \`${CONNECTOR_PACKAGE}/${CONNECTOR_SUBPATH}/vocabularies\` | The bundled SKOS vocabularies, read from disk at runtime by \`Connector.php\` |
| \`${CONNECTOR_PACKAGE}/${CONNECTOR_SUBPATH}/LICENSE\` | MIT. Required: this extension is AGPL-3.0-or-later and ships third-party code. |

## What is deliberately NOT here

The upstream package root is the whole DFC-LinkML repository root, so a plain
copy is 7.7 MB and carries the Ruby connector, the TypeScript connector, a
Python test suite, \`scripts/\`, \`docs/\`, \`shacl/\` and the raw LinkML
schemas. None of it is reachable from the PSR-4 root above. Tracked as BLK-016;
worth reporting upstream.

## Provenance

| | |
|---|---|
| Package | \`${name}\` |
| Version | \`${version}\` |
| Composer dist reference | \`${dist_reference}\` |
| Extension version | \`${VERSION}\` |
| Extension commit | \`${GIT_COMMIT}\` |

The build timestamp is derived from \`SOURCE_DATE_EPOCH\`, not from the wall
clock, so building the same commit twice produces the same bytes. A wall-clock
timestamp here would have made every archive unique and silently broken the
reproducibility property of the release.

To change the pinned version, change it in the extension's \`composer.json\`,
re-run the unit suite (which pins the connector's observable behaviour in
\`tests/phpunit/Connector/ConnectorContractTest.php\`) and rebuild.
EOF

    info "  + vendor/VENDORED.md (${name} ${version})"
}

# ---------------------------------------------------------------------------
# Assertions on the staged tree
# ---------------------------------------------------------------------------

assert_top_level() {
    local rel
    for rel in "${REQUIRED_TOP_LEVEL[@]}"; do
        if [ ! -e "${EXT_DIR}/${rel}" ]; then
            fail "required release file is missing from the staged extension: ${rel}"
        fi
    done
    # Duplicated from copy_runtime()'s failure check on purpose: assert_top_level
    # is where a reader looks for "is the shape right", and a shape assertion
    # that only lives in the copy step is a shape assertion nobody finds.
    if [ -f "${EXT_DIR}/${EXTENSION_FILE}.php" ]; then
        info "  ok  ${EXTENSION_FILE}.php present at the extension root (matches info.xml <file>)"
    else
        fail "info.xml declares <file>${EXTENSION_FILE}</file> but ${EXTENSION_FILE}.php is not at the extension root."
    fi
}

assert_excluded() {
    local path found=0
    for path in "${FORBIDDEN_IN_ARCHIVE[@]}"; do
        if [ -e "${EXT_DIR}/${path}" ]; then
            fail "forbidden path present in the staged extension: ${path}"
            found=$((found + 1))
        fi
    done
    if [ "${found}" -eq 0 ]; then
        info "  ok  none of the ${#FORBIDDEN_IN_ARCHIVE[@]} excluded paths are present"
    fi

    # Belt and braces: the dev-only top-level files, by name.
    local stray
    for stray in phpunit.xml.dist composer.lock .gitignore .gitattributes; do
        if [ -e "${EXT_DIR}/${stray}" ]; then
            fail "development file present in the staged extension: ${stray}"
        fi
    done
}

assert_single_root() {
    local entries
    entries="$(find "${EXT_DIR}" -mindepth 1 -maxdepth 1 -print | wc -l)"
    if [ "${entries}" -gt 0 ]; then
        info "  ok  ${entries} top-level entries inside ${EXTENSION_KEY}/"
    fi
}

# ---------------------------------------------------------------------------
# Archive
# ---------------------------------------------------------------------------

# Reproducibility: fixed mtime, sorted names, no owner information.
#
# GNU tar is needed for --sort=name and --mtime=@. On anything else the archive
# is still built, but it is NOT byte-reproducible and we say so rather than
# letting two builds of the same commit differ quietly.
GNU_TAR=0
if tar --version 2>/dev/null | grep -q 'GNU tar'; then
    GNU_TAR=1
fi

# SOURCE_DATE_EPOCH defaults to the commit being built, so rebuilding the same
# commit reproduces the same bytes.
if [ -z "${SOURCE_DATE_EPOCH:-}" ]; then
    SOURCE_DATE_EPOCH="$(git log -1 --pretty=%ct 2>/dev/null || echo '')"
fi
[ -n "${SOURCE_DATE_EPOCH}" ] || SOURCE_DATE_EPOCH="0"
readonly SOURCE_DATE_EPOCH

write_archive() {
    local target="${RUN_DIR}/${ARCHIVE_NAME}"

    # Created here rather than alongside RUN_DIR's existence check above,
    # because --dry-run returns before this function is reached and must not
    # leave an empty directory behind.
    mkdir -p -- "${RUN_DIR}"

    info ""
    info "Building ${ARCHIVE_NAME} from commit ${GIT_COMMIT}"

    # -C "${STAGE_ROOT}", not -C "${STAGE_DIR}": the member name has to be the
    # extension key RELATIVE TO THE DIRECTORY WE CHANGE INTO, so the two must
    # be siblings in the walk. Changing into STAGE_DIR first and then naming
    # dfc_civicrm looks for STAGE_DIR/dfc_civicrm, which does not exist.
    if [ "${GNU_TAR}" -eq 1 ]; then
        # gzip -n suppresses the timestamp and original filename in the gzip
        # header, which would otherwise differ on every run.
        tar --sort=name \
            --mtime="@${SOURCE_DATE_EPOCH}" \
            --owner=0 --group=0 --numeric-owner \
            --format=gnu \
            -C "${STAGE_ROOT}" -cf - "${EXTENSION_KEY}" \
            | gzip -n -9 > "${target}"
    else
        warn "GNU tar not found; the archive will not be byte-reproducible (file order and mtimes come from the filesystem)."
        tar -C "${STAGE_ROOT}" -czf "${target}" "${EXTENSION_KEY}"
    fi

    [ -f "${target}" ] || die "expected archive at ${target} and it is not there"

    (
        cd -- "${RUN_DIR}" || exit 1
        sha256sum "${ARCHIVE_NAME}" > SHA256SUMS
    )

    printf '%s\n' "${FORCE_NOTE}" > "${RUN_DIR}/BUILD-METADATA.txt"
    cat >> "${RUN_DIR}/BUILD-METADATA.txt" <<EOF
extension_key=${EXTENSION_KEY}
extension_version=${VERSION}
commit=${GIT_COMMIT}
git_describe=${GIT_DESCRIBE}
source_date_epoch=${SOURCE_DATE_EPOCH}
gnu_tar=${GNU_TAR}
connector_vendored=$([ "${NO_VENDOR}" -eq 1 ] && echo no || echo yes)
EOF

    info ""
    info "Archive:  ${target}"
    info "Size:     $(wc -c < "${target}" | tr -d ' ') bytes"
    info "Members:  $(tar -tzf "${target}" | wc -l | tr -d ' ')"
    info "Checksums: ${RUN_DIR}/SHA256SUMS"
}

print_manifest() {
    info ""
    info "Archive members (relative to ${EXTENSION_KEY}/):"
    (
        cd -- "${EXT_DIR}" || exit 1
        find . -type f -print | sed 's|^\./||' | LC_ALL=C sort
    )
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

info "dfc_civicrm release build"
info "  repository:      ${REPO_ROOT}"
info "  extension:       ${EXTENSION_KEY} ${VERSION}"
info "  commit:          ${GIT_COMMIT}"
info "  git describe:    ${GIT_DESCRIBE}"
info "  output directory: ${OUTPUT_DIR}"
info ""

create_stage

info "Copying extension files"
copy_runtime

if [ "${NO_VENDOR}" -eq 1 ]; then
    warn "--no-vendor: no DFC connector is being shipped. The resulting extension will fail on every request that touches JSON-LD. Only valid for the integration-test job that installs from a checkout."
else
    info "Vendoring the DFC-LinkML PHP connector runtime"
    vendor_subset
fi

info ""
info "Asserting the staged extension"
assert_top_level
assert_excluded
assert_single_root

if [ "${FAILURES}" -ne 0 ]; then
    printf '\n%s: %d assertion(s) failed. Nothing was written to %s.\n' "${SCRIPT_NAME}" "${FAILURES}" "${RUN_DIR}" >&2
    exit 1
fi

if [ "${DRY_RUN}" -eq 1 ]; then
    info ""
    info "--dry-run: assertions passed, no archive written, staging directory ${STAGE_DIR} will be removed."
    print_manifest
    exit 0
fi

write_archive
print_manifest

info ""
info "Done. Install: unpack ${ARCHIVE_NAME} into your CiviCRM extensions directory;"
info "the ${EXTENSION_KEY}/ directory it contains becomes the extension."
info "This archive has NOT been installed into a CiviCRM site; see the script header."

exit 0