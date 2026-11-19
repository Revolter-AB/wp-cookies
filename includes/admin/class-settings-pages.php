<?php
/**
 * Banner and company settings pages.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies\Admin;

use RevolterCookies\Banner;
use RevolterCookies\Policy_Page;
use RevolterCookies\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings API pages for the one stored option.
 */
class Settings_Pages {

	const GROUP_BANNER  = 'revolter_cookies_banner';
	const GROUP_COMPANY = 'revolter_cookies_company';

	const MAX_PATTERN = 200;

	/**
	 * Form keys each settings group may post. Internal keys (consent version, scan state) are never taken from a form.
	 */
	const GROUP_KEYS = array(
		self::GROUP_BANNER  => array( 'accent_color', 'button_position', 'policy_page_id', 'gtm_category', 'manual_rules' ),
		self::GROUP_COMPANY => array( 'company_name', 'org_number', 'contact_email' ),
	);

	/**
	 * Hook settings registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the option in both settings groups.
	 *
	 * Both groups share one sanitiser that merges over the stored option, so a page saves only the
	 * keys it posted and everything else (scan results, consent version) survives. The same static
	 * callback is used for both, so WordPress adds the filter once.
	 */
	public function register_settings(): void {
		foreach ( array( self::GROUP_BANNER, self::GROUP_COMPANY ) as $group ) {
			register_setting(
				$group,
				Settings::OPTION,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( __CLASS__, 'sanitize' ),
					'default'           => array(),
					'show_in_rest'      => false,
				)
			);
		}
	}

	/**
	 * Parse the manual rules textarea: one "pattern | category" per line.
	 *
	 * @param string $text Raw text.
	 * @return array[] Rows of array( pattern, category ).
	 */
	public static function parse_manual_rules( string $text ): array {
		$rules = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$pos = strrpos( $line, '|' );
			if ( false === $pos ) {
				continue;
			}
			$pattern  = sanitize_text_field( substr( $line, 0, $pos ) );
			$category = sanitize_key( substr( $line, $pos + 1 ) );
			if ( '' === $pattern || ! in_array( $category, Banner::CONSENT_CATEGORIES, true ) ) {
				continue;
			}
			$rules[] = array(
				'pattern'  => substr( $pattern, 0, self::MAX_PATTERN ),
				'category' => $category,
			);
		}
		return $rules;
	}

	/**
	 * Validate manual rules already in array form.
	 *
	 * @param array $rows Rows.
	 * @return array[]
	 */
	private static function clean_rule_rows( array $rows ): array {
		$lines = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['pattern'], $row['category'] ) && is_scalar( $row['pattern'] ) && is_scalar( $row['category'] ) ) {
				$lines[] = str_replace( array( "\r", "\n", '|' ), ' ', (string) $row['pattern'] ) . '|' . $row['category'];
			}
		}
		return self::parse_manual_rules( implode( "\n", $lines ) );
	}

	/**
	 * Sanitise the option value. Keys that are not posted are kept from the stored option.
	 *
	 * @param mixed $input Incoming value.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$stored = get_option( Settings::OPTION, array() );
		$out    = is_array( $stored ) ? $stored : array();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$defaults = Settings::defaults();
		$group    = self::posted_group();
		if ( null !== $group ) {
			$input = array_intersect_key( $input, array_flip( self::GROUP_KEYS[ $group ] ) );
		}
		foreach ( $input as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		if ( array_key_exists( 'accent_color', $input ) ) {
			$color               = is_string( $input['accent_color'] ) ? sanitize_hex_color( trim( $input['accent_color'] ) ) : null;
			$out['accent_color'] = $color ? $color : $defaults['accent_color'];
		}
		if ( array_key_exists( 'button_position', $input ) ) {
			$out['button_position'] = in_array( $input['button_position'], array( 'left', 'right' ), true ) ? $input['button_position'] : $defaults['button_position'];
		}
		if ( array_key_exists( 'policy_page_id', $input ) ) {
			$out['policy_page_id'] = absint( $input['policy_page_id'] );
		}
		if ( array_key_exists( 'gtm_category', $input ) ) {
			$out['gtm_category'] = in_array( $input['gtm_category'], array( 'preferences', 'statistics', 'marketing' ), true ) ? $input['gtm_category'] : $defaults['gtm_category'];
		}
		if ( array_key_exists( 'manual_rules', $input ) ) {
			$rules               = $input['manual_rules'];
			$out['manual_rules'] = is_string( $rules ) ? self::parse_manual_rules( $rules ) : ( is_array( $rules ) ? self::clean_rule_rows( $rules ) : array() );
		}
		foreach ( array( 'company_name', 'org_number' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = is_scalar( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : '';
			}
		}
		if ( array_key_exists( 'contact_email', $input ) ) {
			$out['contact_email'] = is_string( $input['contact_email'] ) ? sanitize_email( $input['contact_email'] ) : '';
		}
		return $out;
	}

	/**
	 * Settings group submitted through options.php, or null when the option is saved from code.
	 *
	 * @return string|null
	 */
	private static function posted_group(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the nonce before saving.
		$page = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';
		return isset( self::GROUP_KEYS[ $page ] ) ? $page : null;
	}

	/**
	 * Manual rules back to textarea lines.
	 *
	 * @param mixed $rules Stored rules.
	 * @return string
	 */
	public static function rules_to_text( $rules ): string {
		$lines = array();
		foreach ( is_array( $rules ) ? $rules : array() as $row ) {
			if ( is_array( $row ) && isset( $row['pattern'], $row['category'] ) ) {
				$lines[] = $row['pattern'] . ' | ' . $row['category'];
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Banner page.
	 */
	public function render_banner(): void {
		$detected = Policy_Page::detect();
		Menu::render(
			'banner',
			array(
				'group'    => self::GROUP_BANNER,
				'option'   => Settings::OPTION,
				'settings' => Settings::all(),
				'detected' => $detected ? get_the_title( $detected ) : '',
				'rules'    => self::rules_to_text( Settings::get( 'manual_rules' ) ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by options.php.
				'updated'  => isset( $_GET['settings-updated'] ),
				'gtm_cats' => array( 'preferences', 'statistics', 'marketing' ),
			)
		);
	}

	/**
	 * Company details page.
	 */
	public function render_company(): void {
		Menu::render(
			'company',
			array(
				'group'    => self::GROUP_COMPANY,
				'option'   => Settings::OPTION,
				'settings' => Settings::all(),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by options.php.
				'updated'  => isset( $_GET['settings-updated'] ),
			)
		);
	}
}
