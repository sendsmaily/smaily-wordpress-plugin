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
# that mounts this checkout as the plugin (the local dev setup; WP_ENV_CLI_CONTAINER
# names another one); set WP_CLI_BIN to a wp-cli binary — e.g. the dev-vendor
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
	P=/var/www/html/wp-content/plugins/smaily-connect
	# The wp-env dev CLI container (not the -tests- one) that mounts THIS checkout
	# as the plugin: with a second wp-env running, the first `*-cli-1` found can be
	# another checkout's. WP_ENV_CLI_CONTAINER names it explicitly.
	CONTAINER="${WP_ENV_CLI_CONTAINER:-}"
	CANDIDATES=""
	if [ -z "$CONTAINER" ]; then
		ROOT="$( pwd -P )"
		CANDIDATES="$( docker ps --format '{{.Names}}' 2>/dev/null | grep -E 'wp-env.*-cli-1$' | grep -v tests || true )"
		for c in $CANDIDATES; do
			src="$( docker inspect "$c" --format "{{range .Mounts}}{{if eq .Destination \"$P\"}}{{.Source}}{{end}}{{end}}" 2>/dev/null || true )"
			if [ "$src" = "$ROOT" ]; then
				CONTAINER="$c"
				break
			fi
		done
	fi
	if [ -z "$CONTAINER" ]; then
		echo "!! No running wp-env CLI container mounts this checkout ($( pwd -P )) as the plugin." >&2
		echo "   Running wp-env CLI containers: $( printf '%s ' ${CANDIDATES:-none} )" >&2
		echo "   Start this checkout's env (npx @wordpress/env start), name the container in" >&2
		echo "   WP_ENV_CLI_CONTAINER, or point WP_CLI_BIN at a wp-cli binary (e.g. vendor/bin/wp)." >&2
		exit 1
	fi
	wpc() { docker exec "$CONTAINER" sh -c "cd $P && wp i18n $* --allow-root"; }
fi

# Entries (msgids, the header included) of a .po, and a copy of the committed
# catalogs to put back if the run strips them (see the check after make-json).
count_msgids() { grep -c '^msgid ' "$1" || true; }
po_before="$( count_msgids "languages/smaily-connect-${LANG}.po" )"
backup="$( mktemp -d )"
trap 'rm -rf "$backup"' EXIT
cp languages/smaily-connect.pot "languages/smaily-connect-${LANG}.po" "$backup/"

echo "1/6  Transpile admin/src TS/TSX -> _i18n-src (so make-pot can read the __() calls)"
rm -rf _i18n-src
node_modules/.bin/esbuild $( find admin/src -name '*.tsx' -o -name '*.ts' | grep -v '\.test\.' ) \
	--outdir=_i18n-src --outbase=admin/src --format=esm --loader:.ts=ts --loader:.tsx=tsx --log-level=error

echo "2/6  make-pot (PHP + blocks + transpiled admin JS)"
# --slug pins the header's bug-report address: make-pot otherwise derives the slug
# from the checkout directory name, which is `smaily-connect` only inside the
# container (the mounted plugin path) and whatever the clone is called on the host.
wpc make-pot . languages/smaily-connect.pot --domain=smaily-connect --slug=smaily-connect \
	--exclude=dist,tests,bin,node_modules,vendor,blocks/checkout-optin/build,blocks/landingpage/build,blocks/newsletter-signup/build,blocks/recommendations/build

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
# The help text is read into a variable first, never piped into `grep -q`: grep
# exits at the first match, the writer can then die of SIGPIPE, pipefail turns
# the test false, and make-json ran WITHOUT --no-purge (wp-cli 2.12.0 purges by
# default) — it deleted 601 of 862 .po entries while the script printed OK.
# A help text that cannot be read is a stop, never "no --purge flag".
if ! make_json_help="$( wpc make-json --help 2>&1 )" || [[ "$make_json_help" != *--use-map* ]]; then
	echo "!! could not read 'wp i18n make-json --help'; refusing to run make-json without knowing whether it purges the .po" >&2
	exit 1
fi
purge_flag=""
if [[ "$make_json_help" == *--purge* ]]; then
	purge_flag="--no-purge"
fi
wpc make-json "languages/smaily-connect-${LANG}.po" languages/ --use-map=languages/i18n-map.json ${purge_flag}
# Backstop: a purge (or any other step) that strips the catalog fails the run and
# puts the committed .pot/.po back. update-po legitimately drops strings removed
# from the source, so a small drop passes; more than 10% does not.
po_after="$( count_msgids "languages/smaily-connect-${LANG}.po" )"
if [ $(( ( po_before - po_after ) * 10 )) -gt "$po_before" ]; then
	cp "$backup/smaily-connect.pot" languages/smaily-connect.pot
	cp "$backup/smaily-connect-${LANG}.po" "languages/smaily-connect-${LANG}.po"
	echo "!! languages/smaily-connect-${LANG}.po lost more than 10% of its entries (${po_before} -> ${po_after} msgids)." >&2
	echo "   The .pot and .po are restored to what they were before this run; the .mo/.json built from them are not trustworthy — re-run the script." >&2
	exit 1
fi
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
# perl, not `sed -i`: GNU and BSD (macOS) sed disagree on the in-place flag. A source
# file may be .ts or .tsx; the transpiled .js does not say which, so ask the tree.
perl -pi -e 's#_i18n-src/([^\s:]+)\.js#-e "admin/src/$1.ts" ? "admin/src/$1.ts" : "admin/src/$1.tsx"#ge' \
	languages/smaily-connect.pot "languages/smaily-connect-${LANG}.po"
rm -rf _i18n-src languages/i18n-map.json
if grep -q '_i18n-src' languages/smaily-connect.pot "languages/smaily-connect-${LANG}.po"; then
	echo "!! _i18n-src references left in the .pot/.po" >&2
	exit 1
fi
if [ ! -s "$target" ]; then
	echo "!! admin catalog $target is missing or empty" >&2
	exit 1
fi

echo "OK — languages/*.mo + languages/*.json rebuilt (admin catalog: smaily-connect-${LANG}-${ADMIN_JSON_HASH}.json)."
