# Shared paths and helpers for setup.sh, teardown.sh and run.sh. Sourced, not executed.
RC_WP_PATH="${RC_WP_PATH:-$HOME/Development/WordPress/wptests}"
TMP_BASE="${TMPDIR:-/tmp}"
RC_E2E_STATE_DIR="${RC_E2E_STATE_DIR:-${TMP_BASE%/}/rc-e2e-state}"
RC_MU_FILE="$RC_WP_PATH/wp-content/mu-plugins/rc-e2e-fixture.php"
export RC_WP_PATH RC_E2E_STATE_DIR

# Elementor Pro is skipped: it is not needed here and prints PHP 8.4 deprecations.
wp() { command wp --path="$RC_WP_PATH" --skip-plugins=elementor-pro "$@"; }

# True when any e2e fixture is on the site (a run that has not been torn down).
fixture_present() {
	[ -f "$RC_MU_FILE" ] && return 0
	wp user get rc-e2e-admin --field=ID >/dev/null 2>&1 && return 0
	[ -n "$(wp post list --post_type=post --post_status=any --meta_key=_rc_e2e --field=ID)" ] && return 0
	return 1
}

# Remove fixtures only. The one plugin setting touched is policy_page_id, and only when
# it still points at a fixture post: it goes back to the snapshot's value, else 0.
remove_fixtures() {
	local ids current orig
	ids=" $(wp post list --post_type=post --post_status=any --meta_key=_rc_e2e --field=ID | tr '\n' ' ') "
	current="$(wp option pluck revolter_cookies_settings policy_page_id 2>/dev/null || true)"
	if [ -n "$current" ] && [ "${ids#* $current }" != "$ids" ]; then
		orig=0
		if [ -f "$RC_E2E_STATE_DIR/settings.json" ]; then
			orig="$(php -r '$s=json_decode(file_get_contents($argv[1]),true); echo (int)($s["policy_page_id"]??0);' "$RC_E2E_STATE_DIR/settings.json")"
		fi
		wp option patch update revolter_cookies_settings policy_page_id "$orig" --quiet
	fi
	rm -f "$RC_MU_FILE"
	# Only removed when empty, so a site's own mu-plugins are never touched.
	rmdir "$RC_WP_PATH/wp-content/mu-plugins" 2>/dev/null || true
	local id
	for id in $(wp post list --post_type=post --post_status=any --meta_key=_rc_e2e --field=ID); do
		wp post delete "$id" --force --quiet
	done
	if id="$(wp user get rc-e2e-admin --field=ID 2>/dev/null)"; then
		wp transient delete "revolter_cookies_scan_${id}" --quiet 2>/dev/null || true
		wp user delete rc-e2e-admin --yes --quiet
	fi
}

state_value() { # key
	sed -n "s/^$1=//p" "$RC_E2E_STATE_DIR/snapshot.meta" 2>/dev/null
}
