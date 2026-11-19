<?php
/**
 * WP Consent API integration.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin with the WP Consent API plugin when it is installed.
 *
 * The browser side (consent.js) calls wp_set_consent(); this side only declares the plugin and the opt-in model.
 */
class Wp_Consent_Api {

	/**
	 * Test-only presence override.
	 *
	 * @var bool|null
	 */
	private static $presence_override = null;

	/**
	 * Hook in when the API is present.
	 */
	public function register(): void {
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'wp_consent_api_registered_' . plugin_basename( REVOLTER_COOKIES_FILE ), '__return_true' );
		add_filter( 'wp_get_consent_type', array( $this, 'consent_type' ) );
	}

	/**
	 * Visitors opt in: nothing beyond necessary runs before consent.
	 *
	 * @param mixed $type Current type.
	 * @return string
	 */
	public function consent_type( $type ): string {
		unset( $type );
		return 'optin';
	}

	/**
	 * Whether the WP Consent API plugin is present.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		if ( null !== self::$presence_override ) {
			return self::$presence_override;
		}
		return function_exists( 'wp_set_consent' ) || class_exists( 'WP_CONSENT_API' );
	}

	/**
	 * Override the presence check.
	 *
	 * @internal Tests only.
	 *
	 * @param bool|null $present Presence, or null to clear.
	 */
	public static function set_presence_override( ?bool $present ): void {
		self::$presence_override = $present;
	}
}
