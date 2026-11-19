<?php
/**
 * Request context.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Answers what kind of request is being served.
 */
class Context {

	/**
	 * Test-only scan mode override.
	 *
	 * @var string|null
	 */
	private static $scan_mode_override = null;

	/**
	 * Whether the scan mode has been resolved this request.
	 *
	 * @var bool
	 */
	private static $scan_mode_resolved = false;

	/**
	 * Resolved scan mode.
	 *
	 * @var string|null
	 */
	private static $scan_mode_cache = null;

	/**
	 * Whether this request renders a normal front-end HTML page.
	 *
	 * @return bool
	 */
	public static function is_frontend_page(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || is_embed() || is_customize_preview() ) {
			return false;
		}
		if ( self::is_builder_preview() ) {
			return false;
		}
		// No request-header checks (e.g. Accept: JSON): full-page caches do not vary on them.
		// Real JSON responses are caught by the Content-Type check in Blocker::filter_output().
		if ( '' !== (string) get_query_var( 'sitemap' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Page-builder preview, only for logged-in editors so anonymous query params cannot skip blocking.
	 *
	 * @return bool
	 */
	private static function is_builder_preview(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Presence checks only.
		if ( ! isset( $_GET['elementor-preview'] ) && ! isset( $_GET['et_fb'] ) ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/**
	 * Scan mode for this request: 'none', 'accepted' or null.
	 *
	 * Non-null only for a logged-in administrator whose scan session token matches the URL.
	 * Resolved once per request; a live scan request is never cacheable.
	 *
	 * @return string|null
	 */
	public static function scan_mode(): ?string {
		if ( null !== self::$scan_mode_override ) {
			return self::$scan_mode_override;
		}
		if ( ! self::$scan_mode_resolved ) {
			self::$scan_mode_resolved = true;
			self::$scan_mode_cache    = self::resolve_scan_mode();
			if ( null !== self::$scan_mode_cache ) {
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Cache plugins' contract.
				}
				nocache_headers(); // No-op once headers are sent, hence the early template_redirect call.
				do_action( 'revolter_cookies_scan_mode_started', self::$scan_mode_cache );
			}
		}
		return self::$scan_mode_cache;
	}

	/**
	 * Resolve scan mode early so cache headers can still be sent, and keep the token out of Referer.
	 */
	public static function maybe_start_scan_mode(): void {
		if ( null !== self::scan_mode() && ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/**
	 * Validate the scan query arguments against the current user's session.
	 *
	 * @return string|null
	 */
	private static function resolve_scan_mode(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The session token is the credential.
		if ( ! isset( $_GET['rc_scan'], $_GET['rc_scan_mode'] ) ) {
			return null;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['rc_scan'] ) );
		$mode  = sanitize_text_field( wp_unslash( $_GET['rc_scan_mode'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $mode, array( 'none', 'accepted' ), true ) || '' === $token ) {
			return null;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		$session = ( new Scanner() )->session( get_current_user_id() );
		if ( null === $session || ! hash_equals( (string) $session['token'], $token ) ) {
			return null;
		}
		return $mode;
	}

	/**
	 * Forget the memoised scan mode.
	 *
	 * @internal Tests only.
	 */
	public static function reset_scan_mode_cache(): void {
		self::$scan_mode_resolved = false;
		self::$scan_mode_cache    = null;
	}

	/**
	 * Override the scan mode.
	 *
	 * @internal Tests only.
	 *
	 * @param string|null $mode Mode, or null to clear.
	 */
	public static function set_scan_mode_override( ?string $mode ): void {
		self::$scan_mode_override = $mode;
	}
}
