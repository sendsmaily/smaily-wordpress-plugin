#!/usr/bin/env bash
#
# Reproducible i18n build for the React admin bundle.
#
# Why this exists: `wp i18n make-pot` cannot parse .tsx (the bundled WP-CLI uses a
# PHP ES parser that chokes on TypeScript), so the admin strings are extracted from
# an esbuild transpile of admin/src/. And `make-json` hashes its output to its own
# scheme, while WordPress loads the script-translation JSON by md5() of the script's
# path relative to the plugin dir (`dist/admin/admin.js` → 464ceaab…), so the
# combined catalog is renamed to the name WordPress actually requests.
#
# Inputs (committed):  languages/smaily-connect-et.po (msgids + translations)
# Outputs (gitignored, shipped in the ZIP): languages/*.mo, languages/*.json
#
# Requires wp-cli. By default the wp-cli steps run inside the wp-env CLI container
# (the local dev setup); set WP_CLI_BIN to a wp-cli binary — e.g. the dev-vendor
# one, WP_CLI_BIN=vendor/bin/wp — to run them on the host instead, which is what
# CI does (no Docker there). Run from the plugin root:  bash bin/build-i18n.sh
set -euo pipefail
cd "$( dirname "$0" )/.."

# WordPress loads the admin JS translations from this fixed name (md5 of the script
# path relative to the plugin dir; the path never changes, so the hash is stable).
ADMIN_JSON_HASH="464ceaab21588225a35cae9f83dfa47d"
LANG=et

WP_CLI_BIN="${WP_CLI_BIN:-}"
if [ -n "$WP_CLI_BIN" ]; then
	wpc() { "$WP_CLI_BIN" i18n "$@" --allow-root; }
else
	# Locate the wp-env dev CLI container (not the -tests- one).
	CONTAINER="$( docker ps --format '{{.Names}}' 2>/dev/null | grep -E 'wp-env.*-cli-1$' | grep -v tests | head -1 )"
	if [ -z "$CONTAINER" ]; then
		echo "!! wp-env CLI container not found. Start it with: npx @wordpress/env start" >&2
		echo "   (or point WP_CLI_BIN at a wp-cli binary, e.g. WP_CLI_BIN=vendor/bin/wp)" >&2
		exit 1
	fi
	P=/var/www/html/wp-content/plugins/smaily-connect
	wpc() { docker exec "$CONTAINER" sh -c "cd $P && wp i18n $* --allow-root"; }
fi

echo "1/6  Transpile admin/src TS/TSX -> _i18n-src (so make-pot can read the __() calls)"
rm -rf _i18n-src
node_modules/.bin/esbuild $( find admin/src -name '*.tsx' -o -name '*.ts' | grep -v '\.test\.' ) \
	--outdir=_i18n-src --outbase=admin/src --format=esm --loader:.ts=ts --loader:.tsx=tsx --log-level=error

echo "2/6  make-pot (PHP + blocks + transpiled admin JS)"
# --slug pins the header's bug-report address: make-pot otherwise derives the slug
# from the checkout directory name, which is `smaily-connect` only inside the
# container (the mounted plugin path) and whatever the clone is called on the host.
wpc make-pot . languages/smaily-connect.pot --domain=smaily-connect --slug=smaily-connect \
	--exclude=dist,tests,bin,node_modules,vendor,blocks/checkout-optin/build,blocks/landingpage/build,blocks/newsletter-signup/build

echo "3/6  update-po (merge new strings into ${LANG}.po, preserving existing translations)"
wpc update-po languages/smaily-connect.pot "languages/smaily-connect-${LANG}.po"

echo "4/6  make-mo (PHP translations)"
wpc make-mo languages/ languages/

echo "5/6  make-json with use-map (collapse admin sources into one catalog) + rename to WP's name"
# Drop stale catalogs first so the "most strings" pick below can't latch onto a
# previous run's already-renamed file (make-json --no-purge regenerates them all).
rm -f languages/smaily-connect-${LANG}-*.json
python3 - <<'PY'
import json, glob
json.dump({ p: "dist/admin/admin.js" for p in sorted(glob.glob('_i18n-src/**/*.js', recursive=True)) },
          open('languages/i18n-map.json', 'w'))
PY
# `--purge` (which we must switch off, or make-json strips the JS strings out of
# the committed .po) only exists in older i18n-command releases; newer ones
# dropped purging altogether and reject the flag. Pass it only when understood.
purge_flag=""
if wpc make-json --help 2>/dev/null | grep -q -- '--purge'; then
	purge_flag="--no-purge"
fi
wpc make-json "languages/smaily-connect-${LANG}.po" languages/ --use-map=languages/i18n-map.json ${purge_flag}
# The combined admin catalog is the .po-derived JSON with the most strings; rename it
# to the name WordPress requests for dist/admin/admin.js.
combined="$( for f in languages/smaily-connect-${LANG}-*.json; do
		n=$( python3 -c "import json,sys;print(len(json.load(open(sys.argv[1]))['locale_data']['messages']))" "$f" )
		echo "$n $f"
	done | sort -rn | head -1 | awk '{print $2}' )"
target="languages/smaily-connect-${LANG}-${ADMIN_JSON_HASH}.json"
# Newer i18n-command releases already name the catalog after the MAPPED path, so
# --use-map lands it on WP's name and there is nothing to rename.
[ "$combined" = "$target" ] || mv "$combined" "$target"

echo "6/6  Tidy references + drop transient build inputs"
sed -i -E 's#_i18n-src/(.*)\.js#admin/src/\1.tsx#g' languages/smaily-connect.pot "languages/smaily-connect-${LANG}.po" || true
rm -rf _i18n-src languages/i18n-map.json

echo "OK — languages/*.mo + languages/*.json rebuilt (admin catalog: smaily-connect-${LANG}-${ADMIN_JSON_HASH}.json)."
