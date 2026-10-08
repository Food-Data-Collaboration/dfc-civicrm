#!/usr/bin/env bash
#
# preflight.sh - run BEFORE handing this extension to a CiviCRM box.
#
# Everything here can be checked without CiviCRM. It is deliberately split from
# verify-install.sh: this script proves the package is well-formed and complete,
# verify-install.sh proves CiviCRM accepts it. Keeping them apart means we never
# mistake "the archive is fine" for "the extension installs".
#
# Usage:  ./tools/preflight.sh [--strict]
#
#   --strict   fail on warnings as well as errors.
#
# Exit:  0 all checks passed, 1 something failed.

set -o errexit
set -o nounset
set -o pipefail

# Declared and assigned separately: `readonly X="$(...)"` masks the command
# substitution's exit status, so a failing `cd` would leave X empty and every
# later path check would silently test the wrong directory.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
readonly REPO_ROOT

STRICT=0
FAILURES=0
WARNINGS=0

for arg in "$@"; do
    case "${arg}" in
        --strict) STRICT=1 ;;
        -h | --help)
            sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            printf 'unknown argument: %s\n' "${arg}" >&2
            exit 2
            ;;
    esac
done

info() { printf '  %s\n' "$*"; }
ok() { printf 'ok  %s\n' "$*"; }
warn() {
    printf 'WARN %s\n' "$*"
    WARNINGS=$((WARNINGS + 1))
}
fail() {
    printf 'FAIL %s\n' "$*"
    FAILURES=$((FAILURES + 1))
}

# ---------------------------------------------------------------------------
printf 'dfc_civicrm preflight\n\n'

# --- 1. PHP -----------------------------------------------------------------
printf 'PHP\n'
if ! command -v php >/dev/null 2>&1; then
    fail "php not found"
else
    php_version="$(php -r 'echo PHP_VERSION;')"
    info "php ${php_version}"
    # info.xml declares <php_compatibility> from 8.1.
    if php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; then
        ok "php >= 8.1 (info.xml php_compatibility floor)"
    else
        fail "php ${php_version} is below the 8.1 floor declared in info.xml"
    fi
fi

# The extensions the unit suite and the connector actually need. civix/CiviCRM
# may want more (gd, soap) but nothing here requires them.
printf '\nPHP extensions\n'
for ext in dom json libxml mbstring openssl; do
    if php -m 2>/dev/null | grep -qix "${ext}"; then
        ok "ext-${ext}"
    else
        fail "ext-${ext} missing"
    fi
done
# Curl and PDO are needed at runtime for JWKS/OIDC fetches and APIv4.
for ext in curl pdo_mysql; do
    if php -m 2>/dev/null | grep -qix "${ext}"; then
        ok "ext-${ext}"
    else
        warn "ext-${ext} missing - OIDC discovery/JWKS fetch needs curl, APIv4 needs pdo_mysql"
    fi
done

# --- 2. info.xml -----------------------------------------------------------
printf '\ninfo.xml\n'
if [ ! -f "${REPO_ROOT}/info.xml" ]; then
    fail "info.xml missing - it must be at the repository ROOT for the Extensions Directory"
elif ! php -r 'exit(simplexml_load_file($argv[1]) ? 0 : 1);' "${REPO_ROOT}/info.xml"; then
    fail "info.xml is not well-formed XML"
else
    ok "info.xml parses and is at the repository root"

    key="$(php -r '$x=simplexml_load_file($argv[1]); echo (string)$x["key"];' "${REPO_ROOT}/info.xml")"
    # CRM_Extension_Info::parse() reads key and file off the root element, and
    # CiviCRM resolves the extension from <ext-dir>/<key>/<file>.php - so the
    # directory name MUST equal the key or nothing resolves.
    if [ "${key}" = "$(basename -- "${REPO_ROOT}")" ]; then
        ok "key '${key}' matches the directory name"
    else
        fail "key '${key}' does not match directory '$(basename -- "${REPO_ROOT}")' - CiviCRM will not resolve the extension"
    fi

    file_attr="$(php -r '$x=simplexml_load_file($argv[1]); echo (string)$x->file;' "${REPO_ROOT}/info.xml")"
    if [ -f "${REPO_ROOT}/${file_attr}.php" ]; then
        ok "<file>${file_attr}</file>.php present at the extension root"
    else
        fail "<file>${file_attr}</file> does not exist at the extension root"
    fi

    stage="$(php -r '$x=simplexml_load_file($argv[1]); echo (string)$x->develStage;' "${REPO_ROOT}/info.xml")"
    info "develStage=${stage}"
    if [ "${stage}" = "stable" ]; then
        ok "stable - eligible for the Extensions Directory"
    else
        warn "develStage is '${stage}'. Alpha/beta releases are NOT listed in the Extensions Directory. Expected until CP-7."
    fi

    # The declared CiviCRM floor is the most consequential untested claim in the
    # repository - it gates installation and nothing has installed yet.
    floor="$(php -r '$x=simplexml_load_file($argv[1]); $n=$x->xpath("//compatibility/ver"); echo $n ? (string)$n[0] : "";' "${REPO_ROOT}/info.xml")"
    if [ -n "${floor}" ]; then
        info "<compatibility><ver>${floor}</ver> - UNVERIFIED. No CiviCRM instance has installed this."
        warn "compatibility floor ${floor} is derived from mixin versions, not tested. verify-install.sh must prove it."
    else
        fail "no <compatibility><ver> declared - the Extensions Directory requires one"
    fi

    # Mixins are the load-bearing part of the classloader and route wiring.
    mixins="$(php -r '$x=simplexml_load_file($argv[1]); foreach ($x->mixins->mixin as $m) { echo (string)$m, PHP_EOL; }' "${REPO_ROOT}/info.xml")"
    for m in ${mixins}; do
        info "mixin ${m}"
    done

    # ---- PHP support must be declared, tested, and agreed --------------------
    #
    # <php_compatibility> is a hard allow-list, NOT a floor. CiviCRM's docs give
    # <compatibility> (CiviCRM versions) forward-compatibility semantics and
    # explicitly do NOT give it to <php_compatibility>: "Each <ver> child element
    # should only contain a single compatible version of PHP" and "It is not
    # currently possible to specify a 'maximum compatible version'."
    #
    # So a version missing from that list is a version this extension refuses to
    # install on. 8.4 was silently acting as a ceiling until 2026-10-08, because
    # the reference info.xml on docs.civicrm.org itself lists only 8.1-8.4.
    #
    # Three checks, because each file alone has lied before:
    #   1. the declared list is non-empty and starts at the composer floor
    #   2. the CI matrix is exactly the declared list
    #   3. composer.json's constraint does not claim a higher floor
    php_compat="$(php -r '$x=simplexml_load_file($argv[1]); $n=$x->php_compatibility->ver; if ($n) { foreach ($n as $v) { echo (string)$v, PHP_EOL; } }' "${REPO_ROOT}/info.xml")"
    if [ -z "${php_compat}" ]; then
        warn "info.xml declares no <php_compatibility>. CiviCRM introduced the element in 5.81, so on an older core the list is simply ignored."
    else
        declared_php="$(printf '%s\n' "${php_compat}" | sort -V | tr '\n' ' ')"
        info "<php_compatibility>: ${declared_php}"
        highest_declared="$(printf '%s\n' "${php_compat}" | sort -V | tail -n 1)"

        composer_floor="$(php -r '
            $d = json_decode((string) file_get_contents($argv[1]), true);
            $c = $d["require"]["php"] ?? "";
            if (preg_match("/(\d+)\.(\d+)/", $c, $m)) { echo $m[1] . "." . $m[2]; }
        ' "${REPO_ROOT}/composer.json")"

        if [ -n "${composer_floor}" ]; then
            lowest_declared="$(printf '%s\n' "${php_compat}" | sort -V | head -n 1)"
            if [ "$(printf '%s\n%s\n' "${composer_floor}" "${lowest_declared}" | sort -V | head -n 1)" = "${composer_floor}" ]; then
                ok "composer.json floor (${composer_floor}) agrees with the declared list (${lowest_declared})"
            else
                fail "composer.json requires php ${composer_floor} but info.xml's lowest declared version is ${lowest_declared}. Composer would install on a version the extension then refuses."
            fi
        fi

        # The CI matrix is parsed from the YAML rather than duplicated, so this
        # check cannot itself drift out of date.
        ci_php=""
        if [ -f "${REPO_ROOT}/.github/workflows/ci.yml" ]; then
            ci_php="$(awk '
                /^[[:space:]]*php:[[:space:]]*$/ { inphp = 1; next }
                inphp && /^[[:space:]]*-[[:space:]]*.8\./ {
                    gsub(/[ '"'"'-]/, "", $0); print; next
                }
                inphp { inphp = 0 }
            ' "${REPO_ROOT}/.github/workflows/ci.yml" | sort -V | uniq | tr '\n' ' ')"
        fi

        if [ -z "${ci_php}" ]; then
            fail "could not read a php matrix from .github/workflows/ci.yml - cannot verify that declared support is actually tested"
        elif [ "${ci_php}" = "${declared_php}" ]; then
            ok "CI matrix tests exactly the declared PHP versions"
        else
            fail "PHP support is declared but not tested, or vice versa.
       info.xml declares: ${declared_php}
       CI matrix runs:   ${ci_php}
     Every declared version must be tested, and every tested version declared -
     a version in only one of the two is either an untested claim or untested code."
        fi

        if [ -n "${highest_declared}" ]; then
            info "highest declared: ${highest_declared} (a hard ceiling - not a floor)"
        fi
    fi
fi

# --- 3. composer + dependency ---------------------------------------------
printf '\nDependency\n'
if [ ! -f "${REPO_ROOT}/composer.json" ]; then
    fail "composer.json missing"
else
    ok "composer.json present"
fi

# composer.lock is deliberately NOT committed (BLK-017): this extension ships as
# a CiviCRM package, not a Composer library, so the lock belongs to whoever
# builds. What matters is that the INSTALLED tree is what gets vendored.
if [ -f "${REPO_ROOT}/composer.lock" ]; then
    info "composer.lock present locally (expected - it is ignored, not committed)"
else
    info "composer.lock absent (expected - ignored by policy)"
fi
git -C "${REPO_ROOT}" ls-files --error-unmatch composer.lock >/dev/null 2>&1 && \
    fail "composer.lock is TRACKED, contradicting the BLK-017 decision"

if [ -d "${REPO_ROOT}/vendor/siol-data/dfc-connector" ]; then
    ok "siol-data/dfc-connector installed"
else
    fail "siol-data/dfc-connector not installed - run: composer install"
fi

# The vendored subset is chosen because Connector.php reads contexts/ and
# vocabularies/ off disk at runtime. Losing either breaks export with a
# "file not found" rather than an obvious missing-vendor error.
for d in src contexts vocabularies; do
    if [ -d "${REPO_ROOT}/vendor/siol-data/dfc-connector/php-connector/${d}" ]; then
        ok "connector php-connector/${d} present"
    else
        fail "connector php-connector/${d} missing - build-release.sh refuses to ship an archive without it"
    fi
done
if [ -f "${REPO_ROOT}/vendor/siol-data/dfc-connector/php-connector/LICENSE" ]; then
    ok "connector LICENSE present (MIT, ships alongside our AGPL code)"
else
    fail "connector LICENSE missing"
fi

# --- 4. lint + tests -------------------------------------------------------
printf '\nStatic checks\n'
if [ -x "${REPO_ROOT}/vendor/bin/phpunit" ]; then
    if "${REPO_ROOT}/vendor/bin/phpunit" --testsuite unit >/dev/null 2>&1; then
        ok "unit suite passes"
    else
        fail "unit suite FAILS - do not ship a red suite"
    fi
else
    fail "phpunit not installed"
fi

lint_out="$(find "${REPO_ROOT}/Civi" "${REPO_ROOT}/tests" -name '*.php' -exec php -l {} \; 2>&1 | grep -v '^No syntax errors' || true)"
if [ -z "${lint_out}" ]; then
    ok "all PHP lints clean"
else
    fail "PHP lint errors: ${lint_out}"
fi

# ---- CoversClass targets must resolve --------------------------------------
#
# PHPUnit only evaluates #[CoversClass] when a coverage driver is loaded, so a
# dangling reference is invisible to a plain `phpunit` run - which is how
# `ValidationRun::class` went unqualified in a test whose own namespace was
# Civi\Dfc\Test\Validation, resolving to a class that has never existed. The
# suite passed; only the coverage job failed. Reflection resolves every target
# here with no driver needed, so this catches it locally.
dangling="$(
    cd "${REPO_ROOT}" || exit 1
    php -r '
        require "vendor/autoload.php";
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . "/tests", FilesystemIterator::SKIP_DOTS)
        );
        $found = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== "php") {
                continue;
            }
            $path = $file->getPathname();
            $before = get_declared_classes();
            require_once $path;
            $after = array_diff(get_declared_classes(), $before);
            foreach ($after as $class) {
                try {
                    $r = new ReflectionClass($class);
                } catch (Throwable) {
                    continue;
                }
                if (strpos($class, "Civi\\Dfc\\Test\\") !== 0 || strpos($class, "TestCase") !== false) {
                    continue;
                }
                foreach ($r->getAttributes(PHPUnit\Framework\Attributes\CoversClass::class) as $attr) {
                    $target = $attr->getArguments()[0];
                    if (!class_exists($target) && !interface_exists($target) && !enum_exists($target)) {
                        $found[] = sprintf("%s: CoversClass -> %s does not exist", basename($path), $target);
                    }
                }
            }
        }
        echo implode("\n", array_unique($found));
    ' 2>/dev/null || true
)"
if [ -z "${dangling}" ]; then
    ok "every CoversClass target resolves (checked by reflection, no coverage driver needed)"
else
    fail "CoversClass targets that do not resolve. These pass a plain phpunit run because
   PHPUnit only evaluates the attribute when a coverage driver is loaded, so
   only the coverage job would ever notice.
${dangling}"
fi

# --- 5. packaging ----------------------------------------------------------
printf '\nPackaging\n'
if [ ! -x "${SCRIPT_DIR}/build-release.sh" ]; then
    fail "tools/build-release.sh missing or not executable"
elif ! bash -n "${SCRIPT_DIR}/build-release.sh" 2>/dev/null; then
    fail "tools/build-release.sh is not valid bash"
else
    ok "build-release.sh is valid bash"
fi
if [ -x "${SCRIPT_DIR}/check-release-metadata.sh" ]; then
    if "${SCRIPT_DIR}/check-release-metadata.sh" >/dev/null 2>&1; then
        ok "release metadata is consistent (info.xml <-> CHANGELOG)"
    else
        fail "release metadata drift - run tools/check-release-metadata.sh for detail"
    fi
fi

if [ -z "$(git -C "${REPO_ROOT}" status --porcelain 2>/dev/null)" ]; then
    ok "working tree clean"
else
    warn "working tree dirty - a release built now is not reproducible from any tag"
fi

# --- 6. security -----------------------------------------------------------
printf '\nSecurity\n'
# The single most likely way to leak a client secret into a release is to commit
# it and let build-release.sh stage it. Check what is tracked, not the working
# tree.
leaked=0
while IFS= read -r f; do
    case "$(basename -- "${f}")" in
        .oidc-auth | .env | *.pem | id_rsa | id_ed25519)
            fail "credential-shaped file is TRACKED: ${f}"
            leaked=1
            ;;
    esac
done < <(git -C "${REPO_ROOT}" ls-files)
[ "${leaked}" -eq 0 ] && ok "no tracked credential files"

printf '\n----------------------------------------\n'
if [ "${FAILURES}" -gt 0 ]; then
    printf 'FAILURES: %d   WARNINGS: %d\n' "${FAILURES}" "${WARNINGS}"
    exit 1
fi
printf 'PASSED with %d warning(s)\n' "${WARNINGS}"
if [ "${WARNINGS}" -gt 0 ] && [ "${STRICT}" -eq 1 ]; then
    exit 1
fi
exit 0