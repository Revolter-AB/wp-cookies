<?php
/**
 * Settings store.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Static accessor for the single settings option.
 */
class Settings {

	const OPTION = 'revolter_cookies_settings';

	/**
	 * Default values, computed at call time.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'company_name'       => get_bloginfo( 'name' ),
			'org_number'         => '',
			'contact_email'      => get_option( 'admin_email' ),
			'policy_page_id'     => 0,
			'accent_color'       => '#2F4BC7',
			'button_position'    => 'left',
			'gtm_category'       => 'statistics',
			'manual_rules'       => array(),
			'consent_version'    => 1,
			'categories_in_use'  => array( 'necessary' ),
			'last_scan'          => array(),
			'rescan_recommended' => false,
		);
	}

	/**
	 * All settings, stored values merged over defaults.
	 *
	 * @return array
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = self::defaults();
		return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Setting key.
	 * @throws InvalidArgumentException For unknown keys.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		if ( ! array_key_exists( $key, $all ) ) {
			throw new InvalidArgumentException( 'Unknown setting: ' . esc_html( $key ) );
		}
		return $all[ $key ];
	}

	/**
	 * Store one setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 * @throws InvalidArgumentException For unknown keys.
	 */
	public static function set( string $key, $value ): void {
		if ( ! array_key_exists( $key, self::defaults() ) ) {
			throw new InvalidArgumentException( 'Unknown setting: ' . esc_html( $key ) );
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored[ $key ] = $value;
		update_option( self::OPTION, $stored );
	}
}
