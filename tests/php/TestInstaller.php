<?php
/**
 * Installer tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Installer;
use RevolterCookies\Item_Repository;

/**
 * Verifies activation, schema and upgrade routine.
 */
class TestInstaller extends WP_UnitTestCase {

	/**
	 * Activation creates both tables.
	 */
	public function test_activate_creates_tables() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		Installer::activate();
		$this->assertSame( Installer::items_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::items_table() ) ) );
		$this->assertSame( Installer::log_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::log_table() ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Activation schedules the daily cron.
	 */
	public function test_activate_schedules_daily_cron() {
		wp_clear_scheduled_hook( 'revolter_cookies_daily' );
		Installer::activate();
		$this->assertNotFalse( wp_next_scheduled( 'revolter_cookies_daily' ) );
	}

	/**
	 * Upgrade can run repeatedly.
	 */
	public function test_maybe_upgrade_is_idempotent() {
		delete_option( 'revolter_cookies_db_version' );
		Installer::maybe_upgrade();
		Installer::maybe_upgrade();
		$this->assertSame( 1, (int) get_option( 'revolter_cookies_db_version' ) );
	}

	/**
	 * Deactivation stops the cron and drops scan sessions; activation schedules the cron again.
	 */
	public function test_deactivate_unschedules_cron_and_clears_sessions() {
		Installer::activate();
		set_transient( 'revolter_cookies_scan_1', array( 'urls' => array() ), 600 );
		set_transient( 'revolter_cookies_scan_22', array( 'urls' => array() ), 600 );
		set_transient( 'revolter_cookies_policy_page', 5 );
		Installer::deactivate();
		$this->assertFalse( wp_next_scheduled( Installer::CRON_HOOK ) );
		$this->assertFalse( get_transient( 'revolter_cookies_scan_1' ) );
		$this->assertFalse( get_transient( 'revolter_cookies_scan_22' ) );
		$this->assertSame( 5, get_transient( 'revolter_cookies_policy_page' ) );
		Installer::activate();
		$this->assertNotFalse( wp_next_scheduled( Installer::CRON_HOOK ) );
	}

	/**
	 * Uninstall removes the update checker's state and cron too.
	 */
	public function test_uninstall_removes_update_checker_state() {
		update_site_option( 'external_updates-revolter-cookies', array( 'x' => 1 ) );
		update_option( Item_Repository::FRONTEND_CACHE, array( 'x' => 1 ), false );
		wp_schedule_event( time() + 60, 'daily', 'puc_cron_check_updates-revolter-cookies' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'revolter-cookies/revolter-cookies.php' );
		}
		include REVOLTER_COOKIES_PATH . 'uninstall.php';
		$this->assertFalse( get_site_option( 'external_updates-revolter-cookies' ) );
		$this->assertFalse( get_option( Item_Repository::FRONTEND_CACHE ) );
		$this->assertFalse( wp_next_scheduled( 'puc_cron_check_updates-revolter-cookies' ) );
		Installer::activate();
	}
}
