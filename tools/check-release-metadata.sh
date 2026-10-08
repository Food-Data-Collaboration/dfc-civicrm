#!/usr/bin/env bash
#
# check-release-metadata.sh — the drift gate.
#
# ============================================================================
# WHAT THIS CHECKS, AND WHY THESE FOUR THINGS AND NOTHING ELSE
# ============================================================================
# This project has no code generator. `api-coverage-manifest.yaml` is still a
# scaffold that claims no coverage, so there is no generated tree to diff
# against. Inventing a "check generated files are up to date" step would be
# theatre.
#
# The real drift risks in this repository, in order of how quietly they would
# bite:
#
#   1. info.xml <version> vs CHANGELOG.md.
#      CiviCRM's extension directory keys releases off the version in the
#      manifest, and takes the release date from <releaseDate>. If the manifest
#      moves without the changelog, administrators get a release with no notes;
#      if the changelog moves without the manifest, the notes describe a version
#      nobody can install.
#
#   2. info.xml <version> vs the git tag.
#      Same reason one step later: the directory publishes a *tag*, and a tag
#      that disagrees with the manifest publishes the wrong bytes as the
#      version. Only checked when --require-tag is passed.
#
#   3. info.xml <releaseDate> vs the day the tag is pushed.
#      "At minimum you'll need to make two changes in order for the release to
#      be published as the latest release: increment the version number; and
#      update the release date" (docs.civicrm.org/dev/en/latest/extensions/
#      publish/). A stale release date is the single most common reason a
#      release does not appear as "latest". Only checked with --require-tag.
#
#   4. composer.json <version>.
#      It must be ABSENT. Packagist derives a version from the git tag, and a
#      hand-written version field is ignored at best and contradictory at
#      worst. This is the one manifest field this script asserts is missing.
#
# The composer.json <-> composer.lock drift check lives in .github/workflows/
# ci.yml, not here, because it needs Composer and belongs in the job that
# installs dependencies.
#
# ============================================================================
# USAGE
# ============================================================================
#   tools/check-release-metadata.sh                     # versions agree
#   tools/check-release-metadata.sh --require-tag 0.2.0  # ...and match a tag
#   tools/check-release-metadata.sh --release-date 2026-10-02
#                                                        # date is today
#
# Exit status: 0 all checks passed, 1 a check failed, 2 the script could not
# read the files it needs (which is a failure too, never a pass).
#
# ============================================================================
# WHY grep/sed AND NOT xmllint, php, python OR yq
# ============================================================================
# This has to run on a bare CI runner, a maintainer's laptop and inside a git
# hook, without asking anyone to install a tool. info.xml is a flat document:
# every field this script wants is a unique single-line element under the root
# <extension> element. The patterns below are anchored and fail loudly when
# they match zero or more than one line, so a refactor of info.xml shows up as
# "could not find exactly one <version>" rather than as a silently empty
# version string.
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

readonly INFO_XML="${REPO_ROOT}/info.xml"
readonly CHANGELOG="${REPO_ROOT}/CHANGELOG.md"
readonly COMPOSER_JSON="${REPO_ROOT}/composer.json"

# SemVer 2.0.0 without a leading v. Pre-release and build metadata are allowed
# because CiviCRM alpha/beta releases are tagged that way, but the extension is
# currently <develStage>alpha so nothing pre-release has shipped yet.
readonly SEMVER_RE='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'
readonly DATE_RE='^[0-9]{4}-[0-9]{2}-[0-9]{2}$'

FAILURES=0

# ---------------------------------------------------------------------------
# Reporting
# ---------------------------------------------------------------------------

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    FAILURES=$((FAILURES + 1))
}

pass() {
    printf 'ok:   %s\n' "$*"
}

die() {
    printf '%s: %s\n' "${SCRIPT_NAME}" "$*" >&2
    exit 2
}

# ---------------------------------------------------------------------------
# Extraction
#
# read_single_element <element-name>
#
# Prints the value of a single-line element. Fails (exit 2) when the element is
# missing or appears more than once: both mean info.xml changed shape and this
# script's assumptions are stale, which is worth knowing immediately.
# ---------------------------------------------------------------------------

read_single_element() {
    local element="$1"
    local count
    local located

    # Count first, without line numbers, so the count cannot be polluted by
    # grep -n's own output. Then locate for the error message only.
    count="$(grep -c -E "^[[:space:]]*<${element}>([^<]*)</${element}>[[:space:]]*$" "${INFO_XML}" || true)"

    if [ "${count}" -eq 0 ]; then
        die "no <${element}> element found in ${INFO_XML}. Either it was removed or it is no longer a single-line element with no attributes; update ${SCRIPT_NAME}."
    fi

    if [ "${count}" -gt 1 ]; then
        located="$(grep -n -E "^[[:space:]]*<${element}>([^<]*)</${element}>[[:space:]]*$" "${INFO_XML}" | cut -d: -f1 | paste -sd, - || true)"
        die "found ${count} <${element}> elements in ${INFO_XML} (lines ${located}); expected exactly one."
    fi

    # No -n here: the value must be the value, with nothing prepended.
    grep -o -E "^[[:space:]]*<${element}>([^<]*)</${element}>[[:space:]]*$" "${INFO_XML}" \
        | sed -E "s/^[[:space:]]*<${element}>([^<]*)<\/${element}>[[:space:]]*\$/\1/"
}

require_file() {
    if [ ! -f "$1" ]; then
        die "required file not found: $1"
    fi
}

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------

REQUIRE_TAG=""
RELEASE_DATE=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --require-tag)
            [ "$#" -ge 2 ] || die "--require-tag needs a value"
            REQUIRE_TAG="$2"
            shift 2
            ;;
        --release-date)
            [ "$#" -ge 2 ] || die "--release-date needs a value (YYYY-MM-DD)"
            RELEASE_DATE="$2"
            shift 2
            ;;
        -h|--help)
            sed -n '2,60p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            die "unknown argument: $1 (try --help)"
            ;;
    esac
done

# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

require_file "${INFO_XML}"
require_file "${CHANGELOG}"
require_file "${COMPOSER_JSON}"

VERSION="$(read_single_element version)"
RELEASE_DATE_IN_XML="$(read_single_element releaseDate)"
DEVEL_STAGE="$(read_single_element develStage)"
EXTENSION_KEY="$(grep -o -E '<extension[[:space:]][^>]*key="[^"]+"' "${INFO_XML}" | sed -E 's/.*key="([^"]+)".*/\1/' || true)"
EXTENSION_FILE="$(read_single_element file)"

if [ -z "${EXTENSION_KEY}" ]; then
    die "could not read key=\"...\" off the root <extension> element in ${INFO_XML}"
fi

# 0. The extension key names the DIRECTORY CiviCRM installs into, because
# CRM_Extension_Info::parse() reads it and CiviCRM resolves the extension from
# <ext-dir>/<key>/<file>.php. This is the one thing that silently "works" in
# development and fails on install.
#
# Note what is deliberately NOT asserted: that the key equals the git
# repository name. Those are different namespaces. This repository is
# "dfc-civicrm" on GitHub (hyphen, lowercase, the only form GitHub allows)
# while the key is "dfc_civicrm" (underscore), and CiviCRM installs by key
# regardless of what the remote is called. An earlier version of this check
# compared the key against the checkout directory, which passed locally because
# the working copy happened to be named dfc_civicrm/ and failed on the GitHub
# runner, where actions/checkout names the directory after the repository.
#
# What does have to hold is that the key names a directory we can actually
# produce and that it is consistent with <file>, since both are read off the
# same root element.
if [ "${EXTENSION_KEY}" != "${EXTENSION_FILE}" ]; then
    fail "info.xml key is '${EXTENSION_KEY}' but <file> is '${EXTENSION_FILE}'. Both name the install directory <ext-dir>/<key>/ and must be equal."
else
    pass "extension key and <file> agree, so <ext-dir>/${EXTENSION_KEY}/ is well-formed (${EXTENSION_KEY})"
fi

if [ ! -f "${REPO_ROOT}/${EXTENSION_KEY}.php" ]; then
    fail "info.xml key is '${EXTENSION_KEY}' but ${REPO_ROOT}/${EXTENSION_KEY}.php does not exist. CiviCRM loads <ext-dir>/<key>/<file>.php and would fatal on a missing entry point."
else
    pass "extension entry point exists (${EXTENSION_KEY}.php)"
fi



# 1. info.xml <version> is SemVer.
if printf '%s' "${VERSION}" | grep -q -E "${SEMVER_RE}"; then
    pass "info.xml <version> is SemVer (${VERSION})"
else
    fail "info.xml <version> is '${VERSION}', which is not SemVer. x.y forms lose Composer support, so use x.y.z."
fi

# 2. info.xml <releaseDate> is a real calendar date.
if printf '%s' "${RELEASE_DATE_IN_XML}" | grep -q -E "${DATE_RE}"; then
    pass "info.xml <releaseDate> looks like a date (${RELEASE_DATE_IN_XML})"
else
    fail "info.xml <releaseDate> is '${RELEASE_DATE_IN_XML}', expected YYYY-MM-DD."
fi

# 3. CHANGELOG.md has a heading for exactly this version and date.
#    Keep a Changelog: "## [<version>] - <YYYY-MM-DD>".
if grep -q -E "^## \[${VERSION}\] - ${RELEASE_DATE_IN_XML}\$" "${CHANGELOG}"; then
    pass "CHANGELOG.md has '## [${VERSION}] - ${RELEASE_DATE_IN_XML}'"
else
    fail "CHANGELOG.md has no heading '## [${VERSION}] - ${RELEASE_DATE_IN_XML}'. info.xml says version ${VERSION} released ${RELEASE_DATE_IN_XML}. Add the section, or fix the manifest."
    # Show what is actually there, so the failure is actionable.
    printf '      headings currently in %s:\n' "${CHANGELOG}" >&2
    grep -n -E '^## ' "${CHANGELOG}" >&2 || printf '      (none)\n' >&2
fi

# 4. composer.json must NOT declare a version.
if grep -q -E '^[[:space:]]*"version"[[:space:]]*:' "${COMPOSER_JSON}"; then
    fail "composer.json declares a \"version\". Packagist derives the version from the git tag for this project type; remove the field so there is one source of truth."
else
    pass "composer.json declares no version (the git tag is the source of truth)"
fi

# 5. Optional: the tag this release is being cut from.
if [ -n "${REQUIRE_TAG}" ]; then
    TAG="${REQUIRE_TAG}"

    # A leading "v" is REJECTED rather than stripped.
    #
    # CiviCRM's publish documentation gives `git tag -a 1.2.0` - a bare version,
    # no prefix - and the release workflow's tag trigger is
    # '[0-9]*.[0-9]*.[0-9]*', which a "v"-prefixed tag does not match.
    #
    # So tolerating one here would be actively harmful: this script would pass a
    # tag that the release workflow would then never fire on. The failure would
    # surface as a tag that exists and produced no release, which is a confusing
    # way to learn a rule.
    if printf '%s' "${TAG}" | grep -q -E "${SEMVER_RE}"; then
        pass "tag '${TAG}' is a bare SemVer string"
    else
        if printf '%s' "${TAG}" | grep -q -E "^v${SEMVER_RE#^}"; then
            fail "tag '${TAG}' carries a leading 'v'. CiviCRM's publish docs tag a bare version ('git tag -a 1.2.0') and the release workflow triggers on '[0-9]*.[0-9]*.[0-9]*', so a 'v'-prefixed tag would never fire a release. Drop the prefix."
        else
            fail "tag '${TAG}' is not a bare SemVer version. Use '1.2.0' (docs.civicrm.org/dev/en/latest/extensions/publish/), not a branch name and not a 'v'-prefixed ref."
        fi
    fi

    if [ "${TAG}" != "${VERSION}" ]; then
        fail "git tag '${TAG}' does not match info.xml <version> '${VERSION}'. CiviCRM publishes the TAG, so the two must be byte-identical."
    else
        pass "tag '${TAG}' matches info.xml <version>"
    fi
fi

# 6. Optional: <releaseDate> must be the day the release is actually cut.
if [ -n "${RELEASE_DATE}" ]; then
    if [ "${RELEASE_DATE_IN_XML}" != "${RELEASE_DATE}" ]; then
        fail "info.xml <releaseDate> is '${RELEASE_DATE_IN_XML}' but the release is being cut on '${RELEASE_DATE}'. Update <releaseDate> in info.xml, or the Extensions Directory will not show this as the latest release."
    else
        pass "<releaseDate> matches the release date (${RELEASE_DATE})"
    fi
fi

# 7. Informational, never a failure: an alpha/beta stage is not listed in the
#    Extensions Directory. Loud, because it is the sort of thing that gets
#    discovered after a launch announcement.
if [ "${DEVEL_STAGE}" != "stable" ]; then
    printf 'note: info.xml <develStage> is "%s". CiviCRM will not list a non-stable release in the Extensions Directory, so this cannot be installed in-app. Expected while CP-7 is unsigned.\n' \
        "${DEVEL_STAGE}"
fi

# ---------------------------------------------------------------------------
# Verdict
# ---------------------------------------------------------------------------

printf '\n'
if [ "${FAILURES}" -ne 0 ]; then
    printf '%s: %d check(s) failed.\n' "${SCRIPT_NAME}" "${FAILURES}" >&2
    exit 1
fi

printf '%s: all release metadata checks passed.\n' "${SCRIPT_NAME}"
exit 0