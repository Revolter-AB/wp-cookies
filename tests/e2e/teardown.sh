#!/usr/bin/env bash
# Restores wptests after an e2e run.
#   RC_E2E_RUN_ID=<id> teardown.sh   restore the snapshot of run <id>, then remove fixtures (run.sh does this)
#   teardown.sh --recover            after an interrupted run: restore its snapshot whatever its id
#   teardown.sh --discard            after an interrupted run: remove fixtures only, keep current plugin data
# A snapshot from another run is never restored without --recover.
set -uo pipefail
SELF="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
cd "$(dirname "$0")"
. ./env.sh

MODE="run"
case "${1:-}" in
	--recover) MODE="recover" ;;
	--discard) MODE="discard" ;;
	--help|-h) sed -n '2,6p' "$SELF"; exit 0 ;;
	"") ;;
	*) echo "unknown option: $1" >&2; exit 64 ;;
esac

restore_snapshot() {
	if [ -f "$RC_E2E_STATE_DIR/wplang.txt" ]; then
		wp option update WPLANG "$(cat "$RC_E2E_STATE_DIR/wplang.txt")" --quiet
	else
		wp option delete WPLANG --quiet 2>/dev/null || true
	fi
	if [ -f "$RC_E2E_STATE_DIR/settings.json" ]; then
		wp option update revolter_cookies_settings --format=json < "$RC_E2E_STATE_DIR/settings.json" --quiet
	else
		wp option delete revolter_cookies_settings --quiet 2>/dev/null || true
	fi
	wp db import "$RC_E2E_STATE_DIR/tables.sql" --quiet
	wp transient delete revolter_cookies_policy_page --quiet 2>/dev/null || true
	# The tables were replaced behind the plugin's back, so drop data derived from them.
	wp option delete revolter_cookies_frontend_cache --quiet 2>/dev/null || true
	wp option delete revolter_cookies_frontend_gen --quiet 2>/dev/null || true
}

HAVE_SNAPSHOT=0
[ -f "$RC_E2E_STATE_DIR/snapshot.meta" ] && [ -f "$RC_E2E_STATE_DIR/tables.sql" ] && HAVE_SNAPSHOT=1
SNAP_ID="$(state_value run_id)"

case "$MODE" in
	run)
		if [ "$HAVE_SNAPSHOT" != 1 ] || [ -z "${RC_E2E_RUN_ID:-}" ] || [ "$SNAP_ID" != "$RC_E2E_RUN_ID" ]; then
			echo "e2e teardown: no snapshot for run '${RC_E2E_RUN_ID:-<unset>}' (snapshot on disk: '${SNAP_ID:-none}'); nothing changed." >&2
			echo "  For an interrupted run use --recover or --discard (see --help)." >&2
			exit 3
		fi
		# Restore first, fixtures second: a teardown that dies midway still leaves fixtures,
		# so the next setup refuses instead of snapshotting half-restored data.
		restore_snapshot
		remove_fixtures
		rm -rf "$RC_E2E_STATE_DIR"
		echo "e2e teardown done: wptests restored (run $SNAP_ID)"
		;;
	recover)
		if [ "$HAVE_SNAPSHOT" != 1 ]; then
			echo "e2e teardown --recover: no snapshot on disk; use --discard." >&2
			exit 3
		fi
		restore_snapshot
		remove_fixtures
		echo "e2e teardown --recover: restored snapshot of run $SNAP_ID (created $(state_value created))"
		rm -rf "$RC_E2E_STATE_DIR"
		;;
	discard)
		remove_fixtures
		rm -rf "$RC_E2E_STATE_DIR"
		echo "e2e teardown --discard: fixtures removed, plugin data left as it is"
		;;
esac
