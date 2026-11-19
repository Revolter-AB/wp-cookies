#!/usr/bin/env bash
# Prepares the local wptests site for the e2e suite.
# Every run takes a fresh snapshot of the plugin state, tagged with this run's id
# (RC_E2E_RUN_ID, generated when unset). teardown.sh restores only the snapshot of
# its own run. If fixtures from an interrupted run are still on the site, setup
# refuses: the site then holds test data, and only a person can tell whether the
# old snapshot or the current state is the one to keep (see teardown.sh --help).
set -euo pipefail
cd "$(dirname "$0")"
. ./env.sh

RC_E2E_RUN_ID="${RC_E2E_RUN_ID:-$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM}"
export RC_E2E_RUN_ID

# Prove WP-CLI reaches the site and its database before anything is decided: with the
# database down, fixture_present would report "clean" and the state dir would be lost.
if ! PREFIX="$(wp db prefix)" || [ -z "$PREFIX" ] || ! wp option get siteurl >/dev/null; then
	echo "e2e setup: WP-CLI cannot reach the database of $RC_WP_PATH; nothing changed." >&2
	exit 2
fi

if fixture_present; then
	echo "e2e setup refused: fixtures from an earlier, interrupted run are still on $RC_WP_PATH." >&2
	if [ -f "$RC_E2E_STATE_DIR/snapshot.meta" ]; then
		echo "  Snapshot of that run: created $(state_value created), run id $(state_value run_id)." >&2
	else
		echo "  No snapshot of that run exists." >&2
	fi
	echo "  Recover with ONE of:" >&2
	echo "    bash tests/e2e/teardown.sh --recover   # restore that run's snapshot (discards plugin changes made since it)" >&2
	echo "    bash tests/e2e/teardown.sh --discard   # remove fixtures only and keep the current plugin data" >&2
	exit 2
fi

# No fixture on the site, so any leftover state dir is stale (the site is already
# clean): drop it and snapshot the current state.
rm -rf "$RC_E2E_STATE_DIR"
mkdir -p "$RC_E2E_STATE_DIR"
chmod 700 "$RC_E2E_STATE_DIR"

# 1. Snapshot.
if wp option get WPLANG >/dev/null 2>&1; then
	wp option get WPLANG > "$RC_E2E_STATE_DIR/wplang.txt"
fi
if ! wp option get revolter_cookies_settings --format=json > "$RC_E2E_STATE_DIR/settings.json" 2>/dev/null; then
	rm -f "$RC_E2E_STATE_DIR/settings.json"
fi
wp db export "$RC_E2E_STATE_DIR/tables.sql" --tables="${PREFIX}revolter_cookies_items,${PREFIX}revolter_cookies_consent_log" --quiet
printf 'run_id=%s\ncreated=%s\n' "$RC_E2E_RUN_ID" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$RC_E2E_STATE_DIR/snapshot.meta"

# 2. Fixture mu-plugin.
MU="$RC_WP_PATH/wp-content/mu-plugins"
mkdir -p "$MU"
cp fixtures/rc-e2e-fixture.php "$MU/rc-e2e-fixture.php"

# 3. Fixture posts (POSTS, not pages: the site's Elementor page template hides page content).

new_post() { # slug title content date
	wp post create --post_type=post --post_status=publish --post_name="$1" --post_title="$2" \
		--post_content="$3" --post_date="$4" --porcelain
}
mark() { wp post meta update "$1" _rc_e2e 1 --quiet; }

POLICY_ID="$(new_post cookie-policy 'Cookie policy (e2e)' '[revolter_cookies_full_page]' '2001-01-01 10:00:00')"
mark "$POLICY_ID"
PRIVACY_ID="$(new_post privacy 'Privacy (e2e)' '[revolter_cookies_table settings_link="yes"]' '2001-01-01 10:01:00')"
mark "$PRIVACY_ID"

TABLE_ID="$(new_post elementor-table 'Elementor table (e2e)' '' '2001-01-01 10:02:00')"
mark "$TABLE_ID"
wp post meta update "$TABLE_ID" _elementor_edit_mode builder --quiet
wp post meta update "$TABLE_ID" _elementor_template_type wp-post --quiet
wp post meta update "$TABLE_ID" _elementor_data '[{"id":"a1e2e01","elType":"section","settings":{},"elements":[{"id":"a1e2e02","elType":"column","settings":{"_column_size":100},"elements":[{"id":"a1e2e03","elType":"widget","widgetType":"rc-cookies-table","settings":{},"elements":[]}]}]}]' --quiet

VIDEO_ID="$(new_post elementor-video 'Elementor video (e2e)' '' '2001-01-01 10:03:00')"
mark "$VIDEO_ID"
wp post meta update "$VIDEO_ID" _elementor_edit_mode builder --quiet
wp post meta update "$VIDEO_ID" _elementor_template_type wp-post --quiet
wp post meta update "$VIDEO_ID" _elementor_data '[{"id":"b1e2e01","elType":"section","settings":{},"elements":[{"id":"b1e2e02","elType":"column","settings":{"_column_size":100},"elements":[{"id":"b1e2e03","elType":"widget","widgetType":"video","settings":{"youtube_url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ"},"elements":[]}]}]}]' --quiet

# Newest post, so the scanner (latest 3 posts) visits it.
E2E_ID="$(new_post rc-e2e 'Tracker fixture (e2e)' '<p>Fixture post.</p>' "$(date -u '+%Y-%m-%d %H:%M:%S')")"
mark "$E2E_ID"

# 4. Policy page link: set explicitly (detection searches pages only).
wp option patch update revolter_cookies_settings policy_page_id "$POLICY_ID" --quiet 2>/dev/null \
	|| wp option patch insert revolter_cookies_settings policy_page_id "$POLICY_ID" --quiet
wp transient delete revolter_cookies_policy_page --quiet || true

# 5. Admin user with a fresh generated password, stored outside the repo.
# Created fresh each run (setup refuses when one exists), so no scan session carries over.
PASS="$(openssl rand -hex 16)"
wp user create rc-e2e-admin rc-e2e-admin@example.invalid --role=administrator --user_pass="$PASS" --quiet
umask 077
cat > "$RC_E2E_STATE_DIR/fixture.json" <<JSON
{"user":"rc-e2e-admin","password":"$PASS","posts":{"e2e":$E2E_ID,"policy":$POLICY_ID,"privacy":$PRIVACY_ID,"table":$TABLE_ID,"video":$VIDEO_ID}}
JSON
echo "e2e setup done (run $RC_E2E_RUN_ID). Tear down with: RC_E2E_RUN_ID=$RC_E2E_RUN_ID bash tests/e2e/teardown.sh"
