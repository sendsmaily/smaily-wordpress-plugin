#!/usr/bin/env bash
#
# Verify a packaged plugin ZIP is actually shippable.
#
# The release ZIP has always been assembled by a multi-step build (vite admin +
# both storefront bundles, wp-scripts blocks, the i18n catalogs, a prod-only
# vendor tree) whose steps are easy to forget one at a time — each omission
# produces a ZIP that installs but is silently broken. This script is the gate:
# it asserts the required build outputs are present, the development-only
# material is absent, no shipped bundle points at a stripped source map, and the
# version is consistent (and matches an expected one when given).
#
# Usage:  bash bin/verify-release-zip.sh smaily-connect.zip [expected-version]
#
# Runs in CI (.github/workflows/release.yml) and locally against any ZIP.
set -uo pipefail

ZIP="${1:-}"
EXPECTED="${2:-}"

if [ -z "$ZIP" ]; then
	echo "usage: bash bin/verify-release-zip.sh <zip> [expected-version]" >&2
	exit 2
fi
if [ ! -f "$ZIP" ]; then
	echo "!! not a file: $ZIP" >&2
	exit 2
fi

ROOT=smaily-connect
FAILURES=0

fail() {
	echo "FAIL  $*"
	FAILURES=$(( FAILURES + 1 ))
}
pass() {
	echo "ok    $*"
}

LIST="$( mktemp )"
trap 'rm -f "$LIST"' EXIT
unzip -Z1 "$ZIP" > "$LIST" || { echo "!! cannot list $ZIP" >&2; exit 2; }

echo "== $ZIP ($( wc -l < "$LIST" | tr -d ' ' ) entries)"

# --- 1. Archive root -------------------------------------------------------
# release.sh copies /tmp/smaily-connect into the SVN trunk, so the archive must
# unpack into exactly that directory.
if grep -qv "^${ROOT}/" "$LIST"; then
	fail "archive root is not ${ROOT}/ — stray entries: $( grep -v "^${ROOT}/" "$LIST" | head -3 | tr '\n' ' ' )"
else
	pass "archive root is ${ROOT}/"
fi

# --- 2. Required build outputs --------------------------------------------
require() {
	if grep -qx "${ROOT}/$1" "$LIST"; then
		pass "present: $1"
	else
		fail "missing: $1"
	fi
}
require_glob() {
	if grep -qE "^${ROOT}/$1$" "$LIST"; then
		pass "present: $1"
	else
		fail "missing: $1"
	fi
}

require smaily-connect.php
require readme.txt
require build-hash.txt
require vendor/autoload.php
require dist/admin/admin.js
require dist/admin/admin.css
require dist/public/js/sc-runtime.js
require dist/public/js/sc-landing.js
require languages/smaily-connect-et.mo
# The admin bundle's script translations: WordPress requests this exact name
# (md5 of "dist/admin/admin.js"), so a generically-named catalog is not loaded.
require languages/smaily-connect-et-464ceaab21588225a35cae9f83dfa47d.json
for block in checkout-optin landingpage newsletter-signup; do
	require_glob "blocks/${block}/build/.+"
done

# --- 3. Development-only material must stay out ----------------------------
forbid() {
	local label="$1" pattern="$2"
	local hits
	hits="$( grep -E "^${ROOT}/${pattern}" "$LIST" | head -3 | tr '\n' ' ' )"
	if [ -n "$hits" ]; then
		fail "shipped ${label}: ${hits}"
	else
		pass "absent: ${label}"
	fi
}

forbid "tests"          'tests/'
forbid "docs"           'docs/'
forbid "admin sources"  'admin/src/'
forbid "node_modules"   '.*node_modules/'
forbid "TypeScript"     '.*\.tsx?$'
forbid "source maps"    '.*\.map$'
forbid "bin scripts"    'bin/'
forbid "CI config"      '\.github/'
# Dev composer packages — the ZIP must carry the --no-dev vendor tree only.
for pkg in phpunit phpstan wp-cli squizlabs brain yoast php-stubs wp-coding-standards phpcompatibility sebastian; do
	forbid "vendor/${pkg}" "vendor/${pkg}/"
done
forbid "vendor/bin"     'vendor/bin/'

# --- 4. No bundle points at a stripped source map --------------------------
for bundle in dist/admin/admin.js dist/public/js/sc-runtime.js dist/public/js/sc-landing.js; do
	if grep -qx "${ROOT}/${bundle}" "$LIST"; then
		n="$( unzip -p "$ZIP" "${ROOT}/${bundle}" | grep -c sourceMappingURL )"
		if [ "$n" -eq 0 ]; then
			pass "no sourceMappingURL trailer: ${bundle}"
		else
			fail "sourceMappingURL trailer left in ${bundle} (${n})"
		fi
	fi
done

# --- 5. Version consistency ------------------------------------------------
header_version="$( unzip -p "$ZIP" "${ROOT}/smaily-connect.php" 2>/dev/null \
	| sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -1 )"
const_version="$( unzip -p "$ZIP" "${ROOT}/smaily-connect.php" 2>/dev/null \
	| sed -n "s/^define( 'SMAILY_CONNECT_VERSION', '\([^']*\)'.*/\1/p" | head -1 )"
stable_tag="$( unzip -p "$ZIP" "${ROOT}/readme.txt" 2>/dev/null \
	| sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -1 )"

if [ -z "$header_version" ]; then
	fail "cannot read the plugin header Version"
elif [ "$header_version" != "$const_version" ] || [ "$header_version" != "$stable_tag" ]; then
	fail "version mismatch inside the ZIP: header=${header_version} SMAILY_CONNECT_VERSION=${const_version} readme.txt Stable tag=${stable_tag}"
else
	pass "version is ${header_version} (header, constant, Stable tag agree)"
fi

if [ -n "$EXPECTED" ]; then
	if [ "$header_version" = "$EXPECTED" ]; then
		pass "version matches the expected ${EXPECTED}"
	else
		fail "expected version ${EXPECTED}, ZIP carries ${header_version}"
	fi
fi

echo
if [ "$FAILURES" -gt 0 ]; then
	echo "VERIFY FAILED — ${FAILURES} problem(s); this ZIP must not be published."
	exit 1
fi
echo "VERIFY OK — $ZIP is shippable."
