<?php
/**
 * Uninstall: remove tables, options, transients and cron.
 *
 * @package RevolterCookies
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}revolter_cookies_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}revolter_cookies_consent_log" );

delete_option( 'revolter_cookies_settings' );
delete_option( 'revolter_cookies_db_version' );
delete_option( 'revolter_cookies_frontend_cache' );
delete_option( 'revolter_cookies_frontend_gen' );
delete_option( 'revolter_cookies_scan_epoch' );

// Update checker state.
delete_site_option( 'external_updates-revolter-cookies' );
delete_option( 'external_updates-revolter-cookies' );

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_revolter_cookies_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_revolter_cookies_' ) . '%'
	)
);

wp_clear_scheduled_hook( 'revolter_cookies_daily' );
wp_clear_scheduled_hook( 'puc_cron_check_updates-revolter-cookies' );
