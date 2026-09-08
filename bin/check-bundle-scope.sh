#!/usr/bin/env bash
#
# Prove a built JS bundle is safe to load as a CLASSIC <script>: it is wrapped
# in an IIFE and leaks nothing into the global scope.
#
# Why this exists (PRO-2391, MiuMjau, 2026-09-08): up to 3.12.0 the storefront
# bundle was Vite `es`-format output with no wrapper, so its minified top-level
# `const m=…,_=/^vt_…/` became GLOBAL LEXICAL bindings. A top-level `const` in
# a classic script shadows the same-named `window` property for every script
# loaded after it — `underscore.min.js` still set `window._`, but the bare `_`
# that `wp-util` reads (`wp.template = _.memoize(…)`) resolved to our RegExp:
# `_.memoize is not a function`, and WooCommerce's variation form (which
# depends on wp-util) never enabled add-to-cart on any variable product.
#
# Three checks per file:
#   1. static  — the first statement is an IIFE (`(function(){` / `!function(`),
#                no top-level `import`/`export` (would not load as a classic
#                script at all);
#   2. dynamic — the file is evaluated in a fresh jsdom window (a `node:vm`
#                context); afterwards the window must hold
#                NO new global properties AND no global lexical binding named
#                `_` (the exact MiuMjau reproduction: `typeof _` must be
#                "undefined" from a SECOND script in the same context);
#   3. the same vm run must not have thrown while instantiating the script (a
#                syntax error would be a different way of shipping nothing).
#
# Usage: bash bin/check-bundle-scope.sh <bundle.js> [<bundle.js> …]
# Runs after every `npm run build:admin` and inside bin/verify-release-zip.sh.
set -uo pipefail

if [ "$#" -eq 0 ]; then
	echo "usage: bash bin/check-bundle-scope.sh <bundle.js> [...]" >&2
	exit 2
fi

FAILURES=0
fail() { echo "FAIL  $*"; FAILURES=$(( FAILURES + 1 )); }
pass() { echo "ok    $*"; }

for file in "$@"; do
	if [ ! -f "$file" ]; then
		fail "not a file: $file"
		continue
	fi

	# 1. Static shape. Skip leading comments/blank lines, then look at the first
	#    real characters.
	head_stmt="$( sed -e 's#^[[:space:]]*//.*##' "$file" | tr -d '\n' | sed -e 's#^/\*[^*]*\*/##' -e 's/^[[:space:]]*//' | head -c 40 )"
	case "$head_stmt" in
		"(function("*|"!function("*|"(()=>"*|"(function "*)
			pass "IIFE wrapper: $file" ;;
		*)
			fail "not an IIFE — starts with: ${head_stmt}… ($file)" ;;
	esac
	if grep -qE '^(import|export) ' "$file"; then
		fail "top-level import/export — would not load as a classic script ($file)"
	else
		pass "no top-level import/export: $file"
	fi

	# 2 + 3. Dynamic: run it, then ask the same context what leaked.
	if node - "$file" <<'NODE'
const fs = require('node:fs');
const vm = require('node:vm');
const { JSDOM } = require('jsdom'); // vitest devDependency — present wherever the build runs
const file = process.argv[2];
const code = fs.readFileSync(file, 'utf8');

// A real (jsdom) window: the bundles boot themselves against window/document
// and early-return when their boot blob is absent, exactly like on a page
// that does not print it.
const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', {
  url: 'https://example.test/',
  runScripts: 'outside-only',
});
const ctx = dom.getInternalVMContext();
const before = new Set(Object.getOwnPropertyNames(dom.window));

let threw = null;
try {
  new vm.Script(code, { filename: file }).runInContext(ctx);
} catch (e) {
  threw = e;
}
if (threw) {
  console.log(`FAIL  the bundle threw while loading: ${threw && threw.message} (${file})`);
  process.exit(1);
}

const leakedProps = Object.getOwnPropertyNames(dom.window).filter((k) => !before.has(k));
// A SECOND script in the same context sees the first script's global lexical
// bindings — exactly how wp-util saw our `_`. Probe the names Underscore and
// jQuery/WordPress hand out as globals, plus the single-letter set a minifier
// reaches for first.
const probes = ['_', '$', 'jQuery', 'wp', 'wc', 'lodash', ...'abcdefghijklmnopqrstuvwxyz'.split(''), ...'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')];
const leakedLexical = [];
for (const name of probes) {
  let t;
  try {
    t = vm.runInContext(`typeof ${name}`, ctx);
  } catch (e) {
    t = 'TDZ'; // a `const` in its temporal dead zone still shadows the name
  }
  if (t !== 'undefined' && !before.has(name)) leakedLexical.push(`${name} (${t})`);
}

let ok = true;
if (leakedProps.length) {
  ok = false;
  console.log(`FAIL  leaked global properties: ${leakedProps.join(', ')} (${file})`);
}
if (leakedLexical.length) {
  ok = false;
  console.log(`FAIL  leaked global lexical bindings: ${leakedLexical.join(', ')} (${file})`);
}
if (ok) console.log(`ok    no globals leak; typeof _ stays "undefined" after load: ${file}`);
process.exit(ok ? 0 : 1);
NODE
	then :; else FAILURES=$(( FAILURES + 1 )); fi
done

echo
if [ "$FAILURES" -gt 0 ]; then
	echo "BUNDLE SCOPE CHECK FAILED — ${FAILURES} problem(s); these bundles must not ship."
	exit 1
fi
echo "BUNDLE SCOPE CHECK OK"
