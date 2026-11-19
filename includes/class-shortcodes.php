<?php
/**
 * Declaration shortcodes.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * [revolter_cookies_full_page] and [revolter_cookies_table].
 */
class Shortcodes {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_shortcode( 'revolter_cookies_full_page', array( $this, 'full_page' ) );
		add_shortcode( 'revolter_cookies_table', array( $this, 'table' ) );
		// A page that just received the full-page shortcode must be found right away.
		add_action( 'save_post_page', array( __CLASS__, 'forget_policy_page' ) );
		add_action( 'deleted_post', array( __CLASS__, 'forget_policy_page' ) );
		add_action( 'trashed_post', array( __CLASS__, 'forget_policy_page' ) );
	}

	/**
	 * Drop the cached policy page id.
	 */
	public static function forget_policy_page(): void {
		delete_transient( Policy_Page::TRANSIENT );
	}

	/**
	 * Full cookie policy page.
	 *
	 * @param array|string $atts Attributes; hide is a comma list of section keys.
	 * @return string
	 */
	public function full_page( $atts ): string {
		$atts     = shortcode_atts( array( 'hide' => '' ), (array) $atts, 'revolter_cookies_full_page' );
		$sections = array();
		foreach ( explode( ',', (string) $atts['hide'] ) as $key ) {
			$key = strtolower( trim( $key ) );
			if ( in_array( $key, Declaration::SECTIONS, true ) ) {
				$sections[ $key ] = false;
			}
		}
		return Declaration::render_full_page( array( 'sections' => $sections ) );
	}

	/**
	 * Declaration table.
	 *
	 * @param array|string $atts Attributes; settings_link yes/no.
	 * @return string
	 */
	public function table( $atts ): string {
		$atts = shortcode_atts( array( 'settings_link' => 'no' ), (array) $atts, 'revolter_cookies_table' );
		return Declaration::render_table(
			array( 'settings_link' => in_array( strtolower( (string) $atts['settings_link'] ), array( 'yes', 'true', '1', 'on' ), true ) )
		);
	}
}
