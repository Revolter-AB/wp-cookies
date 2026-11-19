<?php
/**
 * Detects other consent plugins and scanned CMPs.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

use RevolterCookies\Admin\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Loud, non-dismissible notice when another consent solution is present.
 */
class Conflicts {

	const TRANSIENT = 'revolter_cookies_conflict_on_activation';

	/**
	 * Plugin directory slug => label.
	 */
	const SLUGS = array(
		'cookiebot'                   => 'Cookiebot',
		'usercentrics-cmp'            => 'Usercentrics',
		'usercentrics'                => 'Usercentrics',
		'cookie-law-info'             => 'CookieYes / GDPR Cookie Consent',
		'complianz-gdpr'              => 'Complianz',
		'complianz-gdpr-premium'      => 'Complianz',
		'cookie-notice'               => 'Cookie Notice',
		'borlabs-cookie'              => 'Borlabs Cookie',
		'real-cookie-banner'          => 'Real Cookie Banner',
		'real-cookie-banner-pro'      => 'Real Cookie Banner',
		'iubenda-cookie-law-solution' => 'iubenda',
		'gdpr-cookie-compliance'      => 'Moove GDPR Cookie Compliance',
		'uk-cookie-consent'           => 'Termly',
	);

	/**
	 * Hook the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Labels of conflicting plugins and scanned CMPs, de-duplicated, stable order.
	 *
	 * @return string[]
	 */
	public static function detect(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$labels = array();
		foreach ( $active as $plugin ) {
			if ( ! is_string( $plugin ) ) {
				continue;
			}
			$dir = strstr( $plugin, '/', true );
			if ( false !== $dir && isset( self::SLUGS[ $dir ] ) ) {
				$labels[] = self::SLUGS[ $dir ];
			}
		}

		$scan = Settings::get( 'last_scan' );
		if ( is_array( $scan ) && ! empty( $scan['cmp_detected'] ) ) {
			foreach ( (array) $scan['cmp_detected'] as $label ) {
				if ( is_string( $label ) && '' !== $label ) {
					$labels[] = $label;
				}
			}
		}

		return array_values( array_unique( $labels ) );
	}

	/**
	 * Remember a conflict at activation so the next plugins.php load shows it.
	 */
	public static function on_activation(): void {
		if ( array() !== self::detect() ) {
			set_transient( self::TRANSIENT, 1, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Print the notice on plugins.php and this plugin's pages.
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) && ! in_array( $screen->id, Menu::screen_ids(), true ) ) {
			return;
		}

		$labels = self::detect();
		if ( array() === $labels ) {
			return;
		}
		$list = wp_sprintf_l( '%l', array_map( 'esc_html', $labels ) );

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			sprintf(
				/* translators: %s: list of plugin names. */
				esc_html__( 'Revolter Cookies conflicts with %s.', 'revolter-cookies' ),
				$list // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Labels are escaped above.
			),
			sprintf(
				/* translators: %s: list of plugin names. */
				esc_html__( 'Two cookie plugins will show two banners and block scripts unpredictably. Deactivate and delete %s, then run a new scan.', 'revolter-cookies' ),
				$list // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Labels are escaped above.
			)
		);

		delete_transient( self::TRANSIENT );
	}
}
