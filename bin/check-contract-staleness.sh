#!/usr/bin/env bash
#
# bin/check-contract-staleness.sh — fail when the vendored
# docs/RECENGINE_API_CONTRACT.md is stale vs the engine repo's main branch.
# (PRO-1250; Decision A on PRO-1247: vendored byte-identical copies stay, but
# drift must never go unnoticed again.)
#
# Usage:
#   bin/check-contract-staleness.sh [engine-contract-path-or-url]
#
# Engine source resolution (first match wins):
#   1. $1 — a local file path (e.g. inside a smaily-recommendations checkout)
#      or an http(s) URL to the raw contract file.
#   2. $ENGINE_CONTRACT_READ_TOKEN — fetch the contract from
#      github.com/erkkimarkus/smaily-recommendations@main via the GitHub
#      contents API. The engine repo is PRIVATE, so this is the CI path; the
#      token needs contents:read on that repo. NEVER printed.
#   3. $ENGINE_CHECKOUT (default: /home/erkki/Allalaadimised/smaily.app/re) —
#      the local dev engine checkout, if present (local fallback). NB: a local
#      checkout can itself lag origin/main — CI uses the API path for a reason.
#
# Exit codes (the workflow relies on the MESSAGES being distinct, not just red):
#   0 — in sync (byte-identical with engine main)
#   1 — CONTRACT COPY STALE — local copy differs from the engine's main branch
#   2 — CANNOT CHECK — no engine source configured or fetch failed (this is a
#       configuration problem, NOT staleness)

set -u

ENGINE_REPO="erkkimarkus/smaily-recommendations"
ENGINE_BRANCH="main"
CONTRACT_REL="docs/RECENGINE_API_CONTRACT.md"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCAL_FILE="${REPO_ROOT}/${CONTRACT_REL}"
DEFAULT_ENGINE_CHECKOUT="${ENGINE_CHECKOUT:-/home/erkki/Allalaadimised/smaily.app/re}"
API_BASE="https://api.github.com/repos/${ENGINE_REPO}"

fail_config() {
	echo "CANNOT CHECK contract staleness: $1" >&2
	echo "This is a configuration/fetch problem, NOT a stale contract." >&2
	echo "In CI: add the repo secret ENGINE_CONTRACT_READ_TOKEN (fine-grained PAT," >&2
	echo "contents:read on ${ENGINE_REPO}). Locally: pass a path/URL argument or" >&2
	echo "set ENGINE_CHECKOUT to a smaily-recommendations checkout." >&2
	exit 2
}

if [ ! -f "${LOCAL_FILE}" ]; then
	fail_config "local ${CONTRACT_REL} not found at ${LOCAL_FILE}"
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT
ENGINE_FILE="${TMP_DIR}/engine-contract.md"
ENGINE_SHA="(unknown)"
ENGINE_SOURCE=""

# Extract the first 40-hex "sha" value from a GitHub API JSON response.
first_sha() {
	sed -n 's/.*"sha": *"\([0-9a-f]\{40\}\)".*/\1/p' | head -n 1
}

fetch_via_api() {
	# Token is read from the environment and sent only as a header — never echoed.
	if ! curl -fsSL \
		-H "Authorization: Bearer ${ENGINE_CONTRACT_READ_TOKEN}" \
		-H "Accept: application/vnd.github.raw+json" \
		"${API_BASE}/contents/${CONTRACT_REL}?ref=${ENGINE_BRANCH}" \
		-o "${ENGINE_FILE}"; then
		fail_config "GitHub contents API fetch failed for ${ENGINE_REPO}@${ENGINE_BRANCH}:${CONTRACT_REL} (bad/expired token? repo/path moved?)"
	fi
	ENGINE_SHA="$(curl -fsSL \
		-H "Authorization: Bearer ${ENGINE_CONTRACT_READ_TOKEN}" \
		-H "Accept: application/vnd.github+json" \
		"${API_BASE}/commits?path=${CONTRACT_REL}&sha=${ENGINE_BRANCH}&per_page=1" \
		| first_sha)"
	[ -n "${ENGINE_SHA}" ] || ENGINE_SHA="(unknown)"
	ENGINE_SOURCE="GitHub API ${ENGINE_REPO}@${ENGINE_BRANCH}"
}

use_local_path() {
	local src="$1"
	cp "${src}" "${ENGINE_FILE}"
	local src_dir
	src_dir="$(cd "$(dirname "${src}")" && pwd)"
	if git -C "${src_dir}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
		ENGINE_SHA="$(git -C "${src_dir}" log -1 --format=%H -- "$(basename "${src}")" 2>/dev/null || true)"
		[ -n "${ENGINE_SHA}" ] || ENGINE_SHA="(unknown)"
	fi
	ENGINE_SOURCE="local file ${src}"
}

if [ "$#" -ge 1 ] && [ -n "$1" ]; then
	case "$1" in
		http://*|https://*)
			auth_args=()
			if [ -n "${ENGINE_CONTRACT_READ_TOKEN:-}" ]; then
				auth_args=(-H "Authorization: Bearer ${ENGINE_CONTRACT_READ_TOKEN}")
			fi
			if ! curl -fsSL "${auth_args[@]}" "$1" -o "${ENGINE_FILE}"; then
				fail_config "fetch of $1 failed"
			fi
			ENGINE_SOURCE="URL $1"
			;;
		*)
			[ -f "$1" ] || fail_config "engine contract file not found at $1"
			use_local_path "$1"
			;;
	esac
elif [ -n "${ENGINE_CONTRACT_READ_TOKEN:-}" ]; then
	fetch_via_api
elif [ -f "${DEFAULT_ENGINE_CHECKOUT}/${CONTRACT_REL}" ]; then
	use_local_path "${DEFAULT_ENGINE_CHECKOUT}/${CONTRACT_REL}"
else
	fail_config "no engine source: ENGINE_CONTRACT_READ_TOKEN is not set and no local engine checkout at ${DEFAULT_ENGINE_CHECKOUT}"
fi

LOCAL_MD5="$(md5sum "${LOCAL_FILE}" | awk '{print $1}')"
ENGINE_MD5="$(md5sum "${ENGINE_FILE}" | awk '{print $1}')"

if [ "${LOCAL_MD5}" = "${ENGINE_MD5}" ]; then
	echo "OK: ${CONTRACT_REL} is byte-identical with engine ${ENGINE_BRANCH} (md5 ${LOCAL_MD5}, engine source: ${ENGINE_SOURCE}, engine commit ${ENGINE_SHA})."
	exit 0
fi

echo "CONTRACT COPY STALE — sync from engine@${ENGINE_SHA}" >&2
echo "  local  ${CONTRACT_REL}: md5 ${LOCAL_MD5}" >&2
echo "  engine (${ENGINE_SOURCE}): md5 ${ENGINE_MD5}, commit ${ENGINE_SHA}" >&2
echo "Sync per CLAUDE.md CC-8 (byte-identical + mock + code follow-through in the same pass)." >&2
exit 1
