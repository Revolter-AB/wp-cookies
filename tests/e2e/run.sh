#!/usr/bin/env bash
# npm run e2e: set up wptests, run Playwright, and always restore wptests afterwards.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
RC_E2E_RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM"
export RC_E2E_RUN_ID
# teardown only restores the snapshot tagged with this run id, so a refused setup changes nothing.
trap 'bash "$HERE/teardown.sh"' EXIT
bash "$HERE/setup.sh" || exit 1
cd "$HERE/../.."
npx playwright test -c tests/e2e "$@"
