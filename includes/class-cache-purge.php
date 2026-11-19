<?php
/**
 * Full-page cache purging.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Purges common full-page caches when blocking rules, categories or banner settings change,
 * so cached pages never keep serving markup built from old rules.
 */
class Cache_Purge {

	/**
	 * Settings that never reach visitors; changing only these does not purge.
	 */
	const INTERNAL_KEYS = array( 'last_scan', 'rescan_recommended' );

	/**
	 * Whether a purge is already queued for the end of this request.
	 *
	 * @var bool
	 */
	private $queued = false;

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'revolter_cookies_rules_changed', array( $this, 'rules_changed' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_updated' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'settings_added' ), 10, 2 );
	}

	/**
	 * Drop derived data now and purge page caches once, at the end of the request.
	 */
	public function rules_changed(): void {
		Item_Repository::flush_frontend_cache();
		if ( $this->queued ) {
			return;
		}
		$this->queued = true;
		add_action( 'shutdown', array( __CLASS__, 'purge' ) );
	}

	/**
	 * Fire the rules-changed action when a visitor-facing setting changed.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $value     New value.
	 */
	public function settings_updated( $old_value, $value ): void {
		$old = self::visible( $old_value );
		$new = self::visible( $value );
		if ( $old !== $new ) {
			/** This action is documented in includes/admin/class-overview-page.php */
			do_action( 'revolter_cookies_rules_changed' );
		}
	}

	/**
	 * The option was created.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 */
	public function settings_added( $option, $value ): void {
		$this->settings_updated( array(), $value );
	}

	/**
	 * Visitor-facing part of the settings, keys sorted so order does not count as a change.
	 *
	 * @param mixed $value Option value.
	 * @return array
	 */
	private static function visible( $value ): array {
		$value = is_array( $value ) ? array_diff_key( $value, array_flip( self::INTERNAL_KEYS ) ) : array();
		ksort( $value );
		return $value;
	}

	/**
	 * Best-effort purge of the full-page caches of common caching plugins. Never fatal.
	 */
	public static function purge(): void {
		try {
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			if ( has_action( 'litespeed_purge_all' ) ) {
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
			}
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
			}
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
			}
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
			}
			if ( has_action( 'breeze_clear_all_cache' ) ) {
				do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's own hook.
			}
			if ( function_exists( 'wpfc_clear_all_cache' ) ) {
				wpfc_clear_all_cache( true );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
