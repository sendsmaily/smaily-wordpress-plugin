#!/usr/bin/env bash
#
# Drives the integration phpunit suite inside the wp-env cli container.
# Integration tests boot real WordPress (wp-load.php) and need the
# wp-env MariaDB + PHP runtime; running phpunit on the host would fail
# to require wp-load.php.
#
# The suite boots the DEV site's WordPress (the port-8888 site in the
# `…-cli-1` container), so a full run overwrites the dev site's
# `smly_rec_*` options with fixture values (`re-fixture.test`, fixture
# tenant "MiuMjau") — the F3-53 / LESSONS §2.17 failure mode that twice
# destroyed the real sandbox engine connection. This wrapper therefore
# (PRO-1240, guard logic shared via bin/lib-smly-snapshot.sh since
# PRO-1256):
#   1. snapshots the dev site's `smly_rec_*` options to a durable file
#      OUTSIDE the repo (~/.local/state/smaily-connect/, mode 600 — it
#      contains the encrypted API key) before the suite, never
#      overwriting a good snapshot with a fixture/empty one;
#   2. after the suite (even on failure — EXIT trap), restores the
#      options secret-safely (JSON piped over STDIN into
#      `docker exec -i … wp eval-file bin/restore-smly-rec-options.php`,
#      never on a command line) and prints the restored tenant_name,
#      warning loudly on "MiuMjau" (production) or a fixture value.
#
# Usage:
#   bash bin/run-integration-tests.sh [--filter Pattern]
#   bash bin/run-integration-tests.sh --restore-only
#
# --restore-only skips the suite entirely and restores the dev site's
# smly_rec_* connection from the durable snapshot — for recovering after
# a hand-rolled phpunit run or a walk that scrubbed the connection
# (PRO-1256). Prints only non-secret fields (tenant_name, base URL,
# connected, row counts).
#
# Exit codes (suite's own exit code is preserved):
#   0 — all tests passed (or --restore-only restored successfully)
#   1 — at least one test failed (or the --restore-only restore failed)
#   2 — wp-env not running (start it with `npx @wordpress/env start`)
#   3 — --restore-only found no usable snapshot
#

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Shared smly_rec_* snapshot/restore guard + docker helpers (PRO-1240/1256).
# shellcheck source=bin/lib-smly-snapshot.sh
source "${PROJECT_DIR}/bin/lib-smly-snapshot.sh"

PLUGIN_PATH_IN_CONTAINER="$SMLY_PLUGIN_PATH_IN_CONTAINER"

if ! CONTAINER_NAME=$(smly_find_cli_container); then
  echo "wp-env does not appear to be running. Start it first:" >&2
  echo "  npx @wordpress/env start" >&2
  exit 2
fi
SMLY_CLI_CONTAINER="$CONTAINER_NAME"

# ---------------------------------------------------------------------------
# --restore-only: restore the dev connection from the durable snapshot
# without running the suite (PRO-1256).
# ---------------------------------------------------------------------------
if [[ "${1:-}" == "--restore-only" ]]; then
  rc=0
  smly_restore_from_durable_snapshot || rc=$?
  exit "$rc"
fi

smly_snapshot_options
trap smly_restore_options EXIT

echo "Running integration suite inside container: $CONTAINER_NAME"

# We pass through any extra CLI args (e.g. --filter) so a developer
# can iterate on a single test class without re-running the whole
# suite. Each arg is single-quoted into the sg-docker command string
# so the host shell doesn't interpret regex chars (e.g. "|") as
# pipeline separators.
QUOTED_ARGS=""
for arg in "$@"; do
  # Escape single quotes inside the arg before wrapping.
  escaped=${arg//\'/\'\\\'\'}
  QUOTED_ARGS="${QUOTED_ARGS} '${escaped}'"
done

# -d memory_limit: WP 7.0 core is heavier than 6.9 — the suite exhausted
# the container's 128M default during in-process REST dispatch when the
# baseline moved to WP 7.0 (2026-06-11). 512M gives ample headroom.
CMD="docker exec \"$CONTAINER_NAME\" \
  php -d memory_limit=512M \"$PLUGIN_PATH_IN_CONTAINER/vendor/bin/phpunit\" \
    --configuration \"$PLUGIN_PATH_IN_CONTAINER/phpunit.integration.xml.dist\" \
    ${QUOTED_ARGS}"

# No `exec` here (it would skip the EXIT trap and the restore): run the
# suite, remember its exit code, let the trap restore, then exit with it.
suite_rc=0
smly_run_docker "$CMD" || suite_rc=$?

exit "$suite_rc"
