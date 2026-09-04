#!/usr/bin/env bash
#
# Shared smly_rec_* snapshot/restore guard (PRO-1240, factored out PRO-1256).
#
# The integration suite — and any live-walk that seeds/scrubs the engine
# connection — boots the DEV site's WordPress, so it can overwrite the dev
# site's `smly_rec_*` options with fixture values (the F3-53 / LESSONS §2.17
# failure mode that twice destroyed the real sandbox engine connection).
# This library holds the guard both bin/run-integration-tests.sh and the
# walk scripts use:
#
#   - snapshot: saves the dev site's `smly_rec_*` options to a durable file
#     OUTSIDE the repo (~/.local/state/smaily-connect/, mode 600 — it
#     contains the encrypted API key), never overwriting a good snapshot
#     with a fixture/production/empty one, and records whether a restore
#     should happen afterwards (a pending-decision file, so the decision
#     survives across processes — a walk's pre-step and exit-step are
#     separate invocations);
#   - restore: puts the options back secret-safely (JSON piped over STDIN
#     into `docker exec -i … wp eval-file bin/restore-smly-rec-options.php`,
#     never on a command line) and prints the restored tenant_name, warning
#     loudly on "MiuMjau" (production) or a fixture value. Snapshot/restore
#     problems never fail the guarded run.
#
# Two ways to use it:
#
#   1. Source it (bin/run-integration-tests.sh does), then:
#        SMLY_CLI_CONTAINER="<wp-env dev cli container>"
#        smly_snapshot_options            # before the risky work
#        trap smly_restore_options EXIT   # after it, even on failure
#
#   2. Execute it with a subcommand (walk scripts, via the Node wrapper
#      bin/lib-smly-snapshot.cjs — see guardSmlyRec()):
#        bash bin/lib-smly-snapshot.sh snapshot      # always exit 0
#        bash bin/lib-smly-snapshot.sh restore       # always exit 0
#        bash bin/lib-smly-snapshot.sh restore-only  # strict; see below
#
#   `restore-only` restores the dev connection straight from the durable
#   snapshot (ignoring any pending decision) — for recovering after a
#   hand-rolled phpunit run or a walk that scrubbed the connection. It is
#   the one STRICT mode: exit 3 when no usable (GOOD) snapshot exists,
#   exit 1 when the restore itself fails. Also reachable as
#   `bash bin/run-integration-tests.sh --restore-only`.
#

SMLY_STATE_DIR="${HOME}/.local/state/smaily-connect"
SMLY_SNAPSHOT_FILE="${SMLY_STATE_DIR}/smly_rec_snapshot.json"
SMLY_SNAPSHOT_PREV="${SMLY_SNAPSHOT_FILE%.json}.prev.json"
# Pending-decision file: contains the path to restore from after the guarded
# run, or is absent when no restore should happen (dev site was intentionally
# disconnected/empty). Written by smly_snapshot_options, consumed (deleted)
# by smly_restore_options.
SMLY_RESTORE_PENDING="${SMLY_STATE_DIR}/smly_rec_restore_pending"
SMLY_PLUGIN_PATH_IN_CONTAINER="/var/www/html/wp-content/plugins/smaily-connect"

# Pick `docker` directly when the current user is already in the docker
# group (CI runners, dev machines that have rebooted since `usermod -aG`)
# and fall back to `sg docker` when an interactive shell predates the
# group-add (Erkki's local machine). Lazy: probed on first use.
smly_docker_init() {
  if [[ -n "${SMLY_DOCKER_DIRECT:-}" ]]; then
    return 0
  fi
  if docker ps >/dev/null 2>&1; then
    SMLY_DOCKER_DIRECT=1
  else
    SMLY_DOCKER_DIRECT=0
  fi
}

# Run a docker command string either directly or through `sg docker`.
# STDIN/STDOUT pass through both paths, so callers may pipe into or out
# of this (the secret-safe restore relies on the STDIN passthrough).
smly_run_docker() {
  smly_docker_init
  if [[ "$SMLY_DOCKER_DIRECT" == "1" ]]; then
    bash -c "$1"
  else
    sg docker -c "$1"
  fi
}

# wp-env names its containers wp-env-<project-hash>-wordpress-1. The hash is
# derived from the project install path; find it dynamically rather than
# hard-coding so this works across machines. Prints the DEV cli container
# name (the tests-cli one is a separate WordPress with its own options);
# returns 1 when wp-env is not running.
smly_find_cli_container() {
  local candidates candidate
  candidates=$(smly_run_docker "docker ps --filter name=wp-env- --format {{.Names}}" 2>/dev/null || true)
  for candidate in $candidates; do
    # The docker filter only narrows to wp-env, so the list also carries the
    # mysql/cli sidecars: keep only the WordPress containers (or the name
    # rewrite below is a no-op and we hand back e.g. …-tests-mysql-1, which
    # has no php), and skip the tests-wordpress one — wp-env spawns a
    # separate instance for its own automated tests; we want the dev one.
    if [[ "$candidate" != *-wordpress-1 || "$candidate" == *-tests-wordpress-1 ]]; then
      continue
    fi
    # We exec into the cli sidecar (which shares the WordPress volume
    # mount) rather than the wordpress container itself, because the
    # cli image has wp-cli + composer pre-installed.
    printf '%s\n' "${candidate/-wordpress-1/-cli-1}"
    return 0
  done
  return 1
}

# Classify a snapshot JSON file by its NON-SECRET fields. Prints one line:
#   GOOD|FIXTURE|PRODUCTION|DISCONNECTED|EMPTY tenant=<name> base=<url>
# Reads only tenant/base/connected — never touches the api_key value.
smly_classify_snapshot() {
  php -r '
    $rows = json_decode( (string) @file_get_contents( $argv[1] ), true );
    if ( ! is_array( $rows ) || $rows === array() ) { echo "EMPTY tenant= base="; exit; }
    $o = array();
    foreach ( $rows as $r ) {
      if ( is_array( $r ) && isset( $r["option_name"] ) ) {
        $o[ $r["option_name"] ] = (string) ( $r["option_value"] ?? "" );
      }
    }
    $tenant = $o["smly_rec_tenant_name"] ?? "";
    $base   = $o["smly_rec_engine_base_url"] ?? "";
    $conn   = $o["smly_rec_connected"] ?? "";
    $tail   = " tenant=" . $tenant . " base=" . $base;
    if ( $conn === "" || $conn === "0" )                { echo "DISCONNECTED" . $tail; exit; }
    if ( strpos( $base, ".test" ) !== false )           { echo "FIXTURE" . $tail; exit; }
    if ( $tenant === "MiuMjau" )                        { echo "PRODUCTION" . $tail; exit; }
    echo "GOOD" . $tail;
  ' "$1"
}

# Snapshot the dev site's smly_rec_* options and decide the restore source.
# Requires SMLY_CLI_CONTAINER. Never fails the caller (always returns 0).
smly_snapshot_options() {
  umask 077
  mkdir -p "$SMLY_STATE_DIR"
  # Fresh decision every run — a stale pending file from a crashed earlier
  # run must not leak into this one.
  rm -f "$SMLY_RESTORE_PENDING"

  if ! command -v php >/dev/null 2>&1; then
    echo "WARNING: no host php — cannot snapshot smly_rec_* options. The run" >&2
    echo "WARNING: may overwrite the dev site's engine connection (F3-53)." >&2
    return 0
  fi

  local tmp="${SMLY_STATE_DIR}/smly_rec_snapshot.tmp.json"
  if ! smly_run_docker "docker exec $SMLY_CLI_CONTAINER wp option list --search='smly_rec_*' --fields=option_name,option_value,autoload --format=json --allow-root" > "$tmp" 2>/dev/null; then
    echo "WARNING: could not read smly_rec_* options from $SMLY_CLI_CONTAINER — no snapshot taken." >&2
    rm -f "$tmp"
    return 0
  fi
  chmod 600 "$tmp"

  local state
  state=$(smly_classify_snapshot "$tmp")
  echo "smly_rec_* pre-run state: $state"

  case "$state" in
    GOOD*)
      # Rotate: keep the previous good snapshot as .prev.json.
      if [[ -f "$SMLY_SNAPSHOT_FILE" ]]; then
        mv -f "$SMLY_SNAPSHOT_FILE" "$SMLY_SNAPSHOT_PREV"
      fi
      mv -f "$tmp" "$SMLY_SNAPSHOT_FILE"
      chmod 600 "$SMLY_SNAPSHOT_FILE"
      printf '%s\n' "$SMLY_SNAPSHOT_FILE" > "$SMLY_RESTORE_PENDING"
      echo "Snapshotted dev-site smly_rec_* options to $SMLY_SNAPSHOT_FILE (mode 600)."
      ;;
    FIXTURE*|PRODUCTION*)
      # Never let a fixture (or a production-tenant!) state clobber a good
      # snapshot — a re-fixture.test snapshot is worse than none.
      rm -f "$tmp"
      echo "WARNING: dev site currently holds a ${state%% *} smly_rec_* state — NOT saved as snapshot." >&2
      if [[ -f "$SMLY_SNAPSHOT_FILE" ]] && [[ "$(smly_classify_snapshot "$SMLY_SNAPSHOT_FILE")" == GOOD* ]]; then
        printf '%s\n' "$SMLY_SNAPSHOT_FILE" > "$SMLY_RESTORE_PENDING"
        echo "A good previous snapshot exists — the dev site will be restored from it afterwards."
      else
        echo "WARNING: no good previous snapshot at $SMLY_SNAPSHOT_FILE — nothing to restore afterwards." >&2
        echo "WARNING: the dev site's engine connection will need a fresh SANDBOX setup token (CLAUDE.md live-walk note)." >&2
      fi
      ;;
    *)
      # DISCONNECTED / EMPTY: the dev site has no engine connection right
      # now — likely intentional. Don't auto-reconnect it from an old
      # snapshot, and don't clobber the good snapshot with this state.
      rm -f "$tmp"
      echo "Dev site has no engine connection (${state%% *}) — nothing to snapshot; no restore afterwards."
      if [[ -f "$SMLY_SNAPSHOT_FILE" ]]; then
        echo "(A previous snapshot exists at $SMLY_SNAPSHOT_FILE — restore it manually with 'bash bin/run-integration-tests.sh --restore-only' if wanted.)"
      fi
      ;;
  esac
  return 0
}

# Low-level restore from an explicit snapshot file. Requires
# SMLY_CLI_CONTAINER. Returns 1 when the in-container restore fails.
smly_do_restore() {
  local src="$1" out tenant
  echo ""
  echo "Restoring dev-site smly_rec_* options from $src ..."
  # Secret-safe: the snapshot JSON travels over STDIN into the container —
  # never on a command line, never in process args, never echoed.
  if ! out=$(smly_run_docker "docker exec -i $SMLY_CLI_CONTAINER wp eval-file $SMLY_PLUGIN_PATH_IN_CONTAINER/bin/restore-smly-rec-options.php --allow-root" < "$src" 2>&1); then
    echo "WARNING: smly_rec_* restore FAILED — the dev site may be left with fixture values:" >&2
    echo "$out" >&2
    return 1
  fi
  echo "$out"

  tenant=$(printf '%s\n' "$out" | sed -n 's/^tenant_name=//p' | head -1)
  if [[ "$tenant" == "MiuMjau" ]]; then
    echo "" >&2
    echo "!!! WARNING: restored tenant_name is 'MiuMjau' — that is the pilot's PRODUCTION" >&2
    echo "!!! tenant. The dev wp-env must NEVER point at production. Reconnect to the" >&2
    echo "!!! 'Smaily Connect test' sandbox before any send/walk. (CLAUDE.md live-walk note.)" >&2
  elif [[ -z "$tenant" ]] || printf '%s' "$tenant" | grep -qi 'fixture'; then
    echo "" >&2
    echo "!!! WARNING: restored tenant_name looks like a fixture/empty value ('$tenant')." >&2
    echo "!!! The dev site's engine connection is NOT healthy — check the snapshot." >&2
  else
    echo "Restore verified: tenant_name='$tenant'."
  fi
  return 0
}

# Post-run restore: consumes the pending-decision file. Runs from EXIT
# traps, so it must never change the caller's exit code — every step is
# failure-tolerant (always returns 0).
smly_restore_options() {
  local src=""
  if [[ -f "$SMLY_RESTORE_PENDING" ]]; then
    src=$(cat "$SMLY_RESTORE_PENDING" 2>/dev/null || true)
    rm -f "$SMLY_RESTORE_PENDING"
  fi
  if [[ -z "$src" || ! -f "$src" ]]; then
    return 0
  fi
  smly_do_restore "$src" || true
  return 0
}

# STRICT restore straight from the durable snapshot (--restore-only): for
# recovering the dev connection after a hand-rolled run or a walk that
# scrubbed it. Exit 3 when no usable snapshot exists; the restore result
# code otherwise.
smly_restore_from_durable_snapshot() {
  if [[ ! -f "$SMLY_SNAPSHOT_FILE" ]]; then
    echo "ERROR: no snapshot at $SMLY_SNAPSHOT_FILE — nothing to restore." >&2
    echo "Run the integration wrapper once while the dev site is connected to create one," >&2
    echo "or reconnect with a fresh SANDBOX setup token (CLAUDE.md live-walk note)." >&2
    return 3
  fi
  local state
  state=$(smly_classify_snapshot "$SMLY_SNAPSHOT_FILE")
  case "$state" in
    GOOD*) ;;
    *)
      echo "ERROR: snapshot at $SMLY_SNAPSHOT_FILE is not usable — ${state} — refusing to restore it." >&2
      echo "Reconnect the dev site to the 'Smaily Connect test' sandbox with a fresh setup token instead." >&2
      return 3
      ;;
  esac
  smly_do_restore "$SMLY_SNAPSHOT_FILE"
}

# ---------------------------------------------------------------------------
# CLI dispatcher — only when executed, not when sourced.
# ---------------------------------------------------------------------------
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  set -euo pipefail

  sub="${1:-}"
  case "$sub" in
    snapshot|restore|restore-only) ;;
    *)
      echo "Usage: bash bin/lib-smly-snapshot.sh {snapshot|restore|restore-only}" >&2
      exit 64
      ;;
  esac

  if ! SMLY_CLI_CONTAINER=$(smly_find_cli_container); then
    if [[ "$sub" == "restore-only" ]]; then
      echo "wp-env does not appear to be running. Start it first:" >&2
      echo "  npx @wordpress/env start" >&2
      exit 2
    fi
    # Guard modes never fail the guarded run.
    echo "WARNING: wp-env not running — smly_rec_* $sub step skipped." >&2
    exit 0
  fi

  case "$sub" in
    snapshot)     smly_snapshot_options ;;
    restore)      smly_restore_options ;;
    restore-only) smly_restore_from_durable_snapshot ;;
  esac
fi
