<?php
/**
 * Activation, schema and upgrades.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables and schedules cron.
 */
class Installer {

	const DB_VERSION = 1;
	const DB_OPTION  = 'revolter_cookies_db_version';
	const CRON_HOOK  = 'revolter_cookies_daily';

	/**
	 * Items table name.
	 */
	public static function items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'revolter_cookies_items';
	}

	/**
	 * Consent log table name.
	 */
	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'revolter_cookies_consent_log';
	}

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::install_schema();
		Conflicts::on_activation();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation hook: stop the daily cron and drop unfinished scan sessions. Data stays until uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		Scanner::clear_sessions();
	}

	/**
	 * Run schema upgrades when the stored version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}
		self::install_schema();
	}

	/**
	 * Create or update tables and record the version.
	 */
	private static function install_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$items   = self::items_table();
		$log     = self::log_table();

		dbDelta(
			"CREATE TABLE {$items} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(10) NOT NULL,
			name VARCHAR(191) NOT NULL,
			service_id VARCHAR(64) NULL,
			provider VARCHAR(191) NOT NULL DEFAULT '',
			category VARCHAR(20) NOT NULL DEFAULT 'unclassified',
			status VARCHAR(20) NOT NULL DEFAULT 'needs_review',
			purpose_override LONGTEXT NULL,
			duration VARCHAR(32) NULL,
			third_party TINYINT(1) NOT NULL DEFAULT 0,
			source VARCHAR(10) NOT NULL DEFAULT 'scan',
			pages LONGTEXT NULL,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			missed_scans TINYINT NOT NULL DEFAULT 0,
			pre_consent TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY type_name (type,name)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			consent_id CHAR(36) NOT NULL,
			categories VARCHAR(100) NOT NULL DEFAULT '',
			policy_version INT NOT NULL DEFAULT 1,
			action VARCHAR(16) NOT NULL DEFAULT '',
			language VARCHAR(10) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY consent_id (consent_id),
			KEY created_at (created_at)
			) {$charset};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION );
	}
}
