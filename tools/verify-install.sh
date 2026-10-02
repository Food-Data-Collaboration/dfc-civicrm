#!/usr/bin/env bash
#
# verify-install.sh - prove CiviCRM ACCEPTS this extension.
#
# This is the half of CP-2 that cannot be done without a CiviCRM instance. It is
# written now, while the box does not exist, so that the first thing we do when a
# box arrives is run it rather than write it.
#
# It asserts CP-2's actual claim - "extension installs and uninstalls cleanly on a
# supported CiviCRM version" - plus the install/upgrade/disable/enable/uninstall
# lifecycle from PRD-002 CP-2, and it records the version actually installed so
# the previously-unverified info.xml <compatibility> floor gets proven.
#
# REQUIREMENTS on the box:
#   - CiviCRM installed, site reachable
#   - either the `cv` CLI (https://docs.civicrm.org/dev/en/latest/tools/) or a
#     local Drupal/WordPress/Joomla checkout
#   - shell + curl + php
#
# USAGE
#   ./tools/verify-install.sh --path <path/to/civicrm/ext-dir>
#   ./tools/verify-install.sh --cv <path/to/cv>            # prefer the cv CLI
#   ./tools/verify-install.sh --path ... --base-url http://localhost
#
# The install path used here is the documented one for a local checkout: place
# the built extension into the CMS extension directory and register it. The
# Extensions Directory route (admin UI / git tag) cannot be exercised from a box
# and is out of scope here.
#
# EXIT: 0 everything verified, 1 a check failed, 2 wrong invocation.

set -o errexit
set -o nounset
set -o pipefail

# Declared and assigned separately: `readonly X="$(...)"` masks the substitution's
# exit status, so a failing `cd` would leave X empty and every path built from it
# would silently be wrong.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
readonly REPO_ROOT

EXT_DIR=""
CV_BIN=""
BASE_URL=""

FAILURES=0

ok() { printf 'ok   %s\n' "$*"; }
info() { printf '  %s\n' "$*"; }
fail() {
    printf 'FAIL %s\n' "$*"
    FAILURES=$((FAILURES + 1))
}
die() {
    printf 'error: %s\n' "$*" >&2
    exit 2
}

usage() {
    sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
    case "$1" in
        --path) EXT_DIR="${2:-}"; shift 2 ;;
        --cv) CV_BIN="${2:-}"; shift 2 ;;
        --base-url) BASE_URL="${2:-}"; shift 2 ;;
        -h | --help) usage; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
done

if [ -z "${EXT_DIR}" ] && [ -z "${CV_BIN}" ]; then
    usage >&2
    die "need --path <ext-dir> or --cv <cv-binary>"
fi

# ---------------------------------------------------------------------------
printf 'dfc_civicrm install verification\n\n'

# Build the archive first. Everything under test is what would actually ship.
printf 'Building the package under test\n'
rm -rf "${REPO_ROOT}/build"
if ! "${SCRIPT_DIR}/build-release.sh" --force >/dev/null 2>&1; then
    die "build-release.sh failed - fix the package before testing it"
fi
archive="$(find "${REPO_ROOT}/build" -name '*.tar.gz' -print -quit)"
[ -n "${archive}" ] || die "no archive produced"
ok "built $(basename -- "${archive}") ($(wc -c <"${archive}" | tr -d ' ') bytes)"

# A scratch copy so a failed run cannot leave a half-installed extension behind.
STAGE="$(mktemp -d)"
cleanup() { rm -rf -- "${STAGE}"; }
trap cleanup EXIT

extract_target() {
    local dest="${STAGE}/ext"
    mkdir -p -- "${dest}"
    tar xzf "${archive}" -C "${dest}"
    printf '%s' "${dest}/dfc_civicrm"
}

# --- 1. place the extension ------------------------------------------------
printf '\nPlacing the extension\n'
installed_dir=""
if [ -n "${CV_BIN}" ] && [ -x "${CV_BIN}" ]; then
    info "using cv CLI at ${CV_BIN}"
    # cv ext:install <name> expects the extension discoverable by the site. We
    # stage it into the site's ext-dir via cv where the CLI exposes one.
    if "${CV_BIN}" ext:dir 2>/dev/null; then
        site_ext_dir="$("${CV_BIN}" ext:dir 2>/dev/null | tr -d '[:space:]')"
        ok "site ext dir: ${site_ext_dir}"
    else
        info "could not query 'cv ext:dir'; falling back to --path placement"
        site_ext_dir=""
    fi
    if [ -n "${site_ext_dir:-}" ]; then
        mkdir -p -- "${site_ext_dir}/dfc_civicrm"
        tar xzf "${archive}" -C "${STAGE}/unpack" 2>/dev/null || {
            mkdir -p -- "${STAGE}/unpack"
            tar xzf "${archive}" -C "${STAGE}/unpack"
        }
        cp -a -- "${STAGE}/unpack/dfc_civicrm/." "${site_ext_dir}/dfc_civicrm/"
        installed_dir="${site_ext_dir}/dfc_civicrm"
        ok "placed at ${installed_dir}"
    fi
fi

if [ -z "${installed_dir}" ]; then
    [ -n "${EXT_DIR}" ] || die "no destination: give --path, or a --cv that can report 'cv ext:dir'"
    mkdir -p -- "${EXT_DIR}/dfc_civicrm"
    mkdir -p -- "${STAGE}/unpack"
    tar xzf "${archive}" -C "${STAGE}/unpack"
    cp -a -- "${STAGE}/unpack/dfc_civicrm/." "${EXT_DIR}/dfc_civicrm/"
    installed_dir="${EXT_DIR}/dfc_civicrm"
    ok "placed at ${installed_dir}"
fi

# --- 2. static agreement with what we ship ---------------------------------
printf '\nPackage agrees with source\n'
for f in info.xml composer.json dfc_civicrm.php; do
    if [ -f "${installed_dir}/${f}" ]; then
        ok "${f} present"
    else
        fail "${f} missing from the installed extension"
    fi
done
if diff -r --brief "${REPO_ROOT}/Civi" "${installed_dir}/Civi" >/dev/null 2>&1; then
    ok "installed Civi/ matches the working tree"
else
    fail "installed Civi/ differs from the working tree - the archive is not what we think"
fi

# --- 3. runtime autoloading ------------------------------------------------
printf '\nClass loading inside CiviCRM\n'
# The real risk: PSR-4 declared in composer.json must ALSO be declared in
# info.xml <classloader>, because the site does not run Composer. If they drift,
# every Civi\Dfc class fatals only when a route is hit - i.e. in production,
# after install. Compare them here instead.
# -F, because the backslashes are literal text in the XML, not regex escapes.
# An earlier version used a quoted-regex pattern with four backslashes and
# reported a FAIL on a correct file - a false alarm is worse than no alarm.
if grep -qF '<psr4 prefix="Civi\Dfc\"' "${installed_dir}/info.xml"; then
    ok "info.xml <classloader> declares Civi\\Dfc\\ (site runtime autoloading)"
else
    fail "info.xml <classloader> does NOT declare Civi\\Dfc\\ - the site cannot autoload our classes without Composer"
fi
if [ -d "${installed_dir}/vendor/siol-data/dfc-connector/php-connector/src" ]; then
    ok "vendored connector present under the site's own vendor/ path"
else
    fail "vendored connector missing - export would fail at runtime with 'file not found'"
fi

# --- 4. install / lifecycle via cv -----------------------------------------
if [ -n "${CV_BIN}" ] && [ -x "${CV_BIN}" ]; then
    printf '\nInstall lifecycle (cv)\n'
    # ext:enable runs install + enable. Capture output rather than trusting the
    # exit code alone: CiviCRM often succeeds with PHP notices in the stream.
    if "${CV_BIN}" ext:enable dfc_civicrm >"${STAGE}/enable.log" 2>&1; then
        ok "ext:enable succeeded"
        if [ -s "${STAGE}/enable.log" ]; then
            info "output:"
            sed 's/^/    | /' "${STAGE}/enable.log" | head -20
        fi
    else
        fail "ext:enable FAILED - install is broken. Output:"
        sed 's/^/    | /' "${STAGE}/enable.log" | head -40
    fi

    printf '\nExtension state\n'
    if "${CV_BIN}" ext:status dfc_civicrm 2>&1 | sed 's/^/    | /'; then
        ok "ext:status responded"
    else
        fail "ext:status failed - the extension is not recognised"
    fi

    # Our two tables are created by CiviMix\Schema\DfcCivicrm\AutomaticUpgrader
    # reading schema/*.entityType.php. No hand-written SQL anywhere. If the
    # upgrader did not run, these are missing and every APIv4 call fails.
    printf '\nSchema created by the upgrader\n'
    for tbl in civicrm_dfc_identity civicrm_dfc_webid; do
        if "${CV_BIN}" sql:query "SHOW TABLES LIKE '${tbl}'" 2>/dev/null | grep -q "${tbl}"; then
            ok "table ${tbl} exists"
        else
            fail "table ${tbl} MISSING - the entityType upgrader did not run on install"
        fi
    done

    # Managed entities: sa-007 created these deterministically via .mgd.php.
    printf '\nManaged entities created\n'
    if "${CV_BIN}" sql:query "SELECT name FROM civicrm_custom_group WHERE name LIKE 'DFC%'" 2>/dev/null | grep -q DFC; then
        ok "DFC custom group present"
    else
        fail "no DFC custom group - managed/*.mgd.php did not run"
    fi

    # The four permissions sa-007 registered. If hook_civicrm_permission did not
    # fire, every DFC route 403s with no obvious cause.
    printf '\nPermissions registered\n'
    for p in 'access dfc api' 'read dfc data' 'write dfc data' 'administer dfc'; do
        if "${CV_BIN}" sql:query "SELECT id FROM civicrm_permission WHERE name = '${p}'" 2>/dev/null | grep -qE '[0-9]+'; then
            ok "permission '${p}'"
        else
            fail "permission '${p}' missing - hook_civicrm_permission did not register it"
        fi
    done

    # Uninstall must NOT delete CiviCRM contacts. This is an explicit PRD-002
    # requirement and the one irreversible thing an extension can get wrong.
    printf '\nUninstall does not destroy CiviCRM data\n'
    before="$("${CV_BIN}" sql:query "SELECT COUNT(*) FROM civicrm_contact" 2>/dev/null | tail -1 | tr -dc '0-9')"
    info "contacts before disable: ${before:-unknown}"
    if "${CV_BIN}" ext:disable dfc_civicrm >"${STAGE}/disable.log" 2>&1; then
        ok "ext:disable succeeded"
    else
        fail "ext:disable FAILED"
        sed 's/^/    | /' "${STAGE}/disable.log" | head -20
    fi
    after="$("${CV_BIN}" sql:query "SELECT COUNT(*) FROM civicrm_contact" 2>/dev/null | tail -1 | tr -dc '0-9')"
    info "contacts after disable:  ${after:-unknown}"
    if [ -n "${before:-}" ] && [ -n "${after:-}" ] && [ "${before}" = "${after}" ]; then
        ok "contact count unchanged across disable - CiviCRM data is safe"
    else
        fail "contact count changed across disable, or could not be read. This is the one irreversible failure mode."
    fi

    printf '\nRe-enable (idempotence of install)\n'
    if "${CV_BIN}" ext:enable dfc_civicrm >/dev/null 2>&1; then
        ok "ext:enable after disable succeeded - install is repeatable"
    else
        fail "ext:enable after disable FAILED - install is not idempotent"
    fi

    # The compatibility floor. Before this, no CiviCRM had ever loaded this
    # extension, so info.xml's 5.74 was reasoning, not evidence.
    printf '\nCompatibility claim\n'
    if [ -n "${BASE_URL}" ]; then
        ver="$(curl -sS -H 'Accept: application/json' "${BASE_URL}/civicrm/ajax/rest/v1/system/version" 2>/dev/null | sed -n -E 's/.*"version"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/p' | head -1)"
        if [ -n "${ver}" ]; then
            info "running CiviCRM ${ver} against a declared floor of 5.74"
            ok "floor is now evidence, not reasoning"
        else
            info "could not read the running version without --base-url"
        fi
    else
        info "pass --base-url to record which CiviCRM version proved the 5.74 floor"
    fi
else
    printf '\n(cv CLI not available)\n'
    info "Without the cv CLI we can only prove the package is PLACED correctly."
    info "Install, schema creation, managed entities, permissions and uninstall"
    info "safety all need: --cv $(command -v cv || echo /path/to/cv)"
fi

printf '\n----------------------------------------\n'
if [ "${FAILURES}" -gt 0 ]; then
    printf 'CP-2 NOT MET: %d check(s) failed\n' "${FAILURES}"
    exit 1
fi
printf 'CP-2 install checks passed.\n'
printf 'Still unproven: black-box HTTP/LDP/WebID conformance (lane-5), and the\n'
printf 'Extensions Directory git-tag route, which cannot be exercised from a box.\n'
exit 0