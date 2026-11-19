<?php
/**
 * Scanner backend: page list, per-user scan session, finishing.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Drives a scan that runs in the admin's browser.
 */
class Scanner {

	/**
	 * Post types never sampled: pages (listed in full), attachments and builder template libraries.
	 */
	const SKIPPED_POST_TYPES = array( 'page', 'attachment', 'elementor_library', 'et_pb_layout', 'fl-builder-template', 'ct_template', 'brizy_template' );

	const SESSION_TTL = 1800;

	const SESSION_PREFIX = 'revolter_cookies_scan_';

	/**
	 * Autoloaded counter stored in every session. Bumping it ends all sessions at once, including
	 * those kept in a persistent object cache, where the options-table sweep cannot see them.
	 */
	const SESSION_EPOCH = 'revolter_cookies_scan_epoch';
	const MAX_REDIRECTS = 3;

	/**
	 * Hook the rescan recommendations.
	 */
	public function register(): void {
		// Before the Blocker (-1000), while headers can still be sent.
		add_action( 'template_redirect', array( __NAMESPACE__ . '\\Context', 'maybe_start_scan_mode' ), -2000 );
		add_action( 'activated_plugin', array( $this, 'on_plugin_change' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_change' ) );
		add_action( 'switch_theme', array( $this, 'recommend_rescan' ) );
		add_action( 'upgrader_process_complete', array( $this, 'recommend_rescan' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_admin_script' ) );
	}

	/**
	 * Register (not enqueue) the admin scan runner with its localized data, for administrators.
	 *
	 * The Overview page enqueues the 'rc-admin-scan' handle; the data is in window.RevolterCookiesScanL10n.
	 */
	public static function register_admin_script(): void {
		if ( ! current_user_can( 'manage_options' ) || wp_script_is( 'rc-admin-scan', 'registered' ) ) {
			return;
		}
		wp_register_script( 'rc-admin-scan', REVOLTER_COOKIES_URL . 'assets/js/admin-scan.js', array(), REVOLTER_COOKIES_VERSION, true );
		wp_localize_script( 'rc-admin-scan', 'RevolterCookiesScanL10n', self::admin_script_data() );
	}

	/**
	 * REST root, nonce and UI strings for admin-scan.js.
	 *
	 * @return array{rest: string, nonce: string, strings: array<string, string>}
	 */
	public static function admin_script_data(): array {
		return array(
			'rest'    => rest_url( 'revolter-cookies/v1/' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'strings' => array(
				/* translators: 1: page number, 2: number of pages, 3: phase, e.g. "checking without consent". */
				'progress'      => __( 'Scanning page %1$d of %2$d (%3$s)', 'revolter-cookies' ),
				'phaseNone'     => __( 'checking without consent', 'revolter-cookies' ),
				'phaseAccepted' => __( 'checking with consent', 'revolter-cookies' ),
				'phaseServer'   => __( 'checking cookies set by the server', 'revolter-cookies' ),
				'finishing'     => __( 'Saving the results…', 'revolter-cookies' ),
				/* translators: %s: page URL. */
				'pageFailed'    => __( 'Could not load %s. It is recorded as failed.', 'revolter-cookies' ),
				/* translators: %s: page URL. */
				'saveFailed'    => __( 'Could not save the result for %s.', 'revolter-cookies' ),
				'startFailed'   => __( 'The scan could not be started.', 'revolter-cookies' ),
				'finishFailed'  => __( 'The scan could not be finished.', 'revolter-cookies' ),
				/* translators: 1: pages scanned, 2: pages that failed, 3: cookies declared, 4: cookies that need review. */
				'summary'       => __( 'Scan complete. Pages scanned: %1$d (failed: %2$d). Cookies declared: %3$d. Needs review: %4$d.', 'revolter-cookies' ),
				'allFailed'     => __( 'No page could be scanned, so the cookie list was not changed. Check that the site loads for logged-in administrators and try again.', 'revolter-cookies' ),
			),
		);
	}

	/**
	 * Plugin (de)activation, ignoring this plugin itself.
	 *
	 * @param string $plugin Plugin basename.
	 */
	public function on_plugin_change( $plugin = '' ): void {
		if ( plugin_basename( REVOLTER_COOKIES_FILE ) === $plugin ) {
			return;
		}
		$this->recommend_rescan();
	}

	/**
	 * Flag that the cookie list may be stale.
	 */
	public function recommend_rescan(): void {
		Settings::set( 'rescan_recommended', true );
	}

	/**
	 * Absolute, deduplicated URLs to scan.
	 *
	 * @param int $cap Maximum number of URLs.
	 * @return string[]
	 */
	public function page_list( int $cap = 50 ): array {
		$urls = array();
		$add  = static function ( $url ) use ( &$urls, $cap ) {
			if ( is_string( $url ) && '' !== $url && count( $urls ) < $cap && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
		};

		$add( home_url( '/' ) );
		foreach ( $this->home_translations() as $url ) {
			$add( $url );
		}
		$add( Policy_Page::url() );

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => $cap,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $pages as $id ) {
			$add( get_permalink( $id ) );
			foreach ( $this->post_translations( (int) $id ) as $url ) {
				$add( $url );
			}
		}

		foreach ( $this->sampled_post_types() as $type ) {
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 3,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				$add( get_permalink( $id ) );
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'number'     => 1,
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			if ( is_array( $terms ) && $terms ) {
				$link = get_term_link( $terms[0] );
				$add( is_wp_error( $link ) ? '' : $link );
			}
		}

		return array_slice( $urls, 0, $cap );
	}

	/**
	 * Public post types to sample a few recent entries from. Pages and attachments are left out
	 * (pages are listed in full), and so are page builders' template libraries, which are not
	 * pages visitors see on their own.
	 *
	 * @return string[]
	 */
	private function sampled_post_types(): array {
		$types = array();
		foreach ( get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			)
		) as $type ) {
			if ( ! in_array( $type, self::SKIPPED_POST_TYPES, true ) ) {
				$types[] = (string) $type;
			}
		}
		/**
		 * Filters the post types the scanner samples (the latest three published entries of each).
		 *
		 * @param string[] $types Post type names.
		 */
		$types = apply_filters( 'revolter_cookies_scan_post_types', $types );
		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ), 'post_type_exists' ) ) : array();
	}

	/**
	 * Home page URLs in the other languages.
	 *
	 * @return string[]
	 */
	private function home_translations(): array {
		$out = array();
		foreach ( $this->other_language_codes() as $code ) {
			if ( 'wpml' === Language::source() ) {
				$out[] = (string) apply_filters( 'wpml_permalink', home_url( '/' ), $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
			} elseif ( 'polylang' === Language::source() && function_exists( 'pll_home_url' ) ) {
				$out[] = (string) pll_home_url( $code );
			}
		}
		return $out;
	}

	/**
	 * Permalinks of a page's translations.
	 *
	 * @param int $id Page id.
	 * @return string[]
	 */
	private function post_translations( int $id ): array {
		$out = array();
		foreach ( $this->other_language_codes() as $code ) {
			$translated = 0;
			if ( 'wpml' === Language::source() ) {
				$translated = (int) apply_filters( 'wpml_object_id', $id, 'page', false, $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
			} elseif ( 'polylang' === Language::source() && function_exists( 'pll_get_post' ) ) {
				$translated = (int) pll_get_post( $id, $code );
			}
			if ( $translated > 0 && $translated !== $id && 'publish' === get_post_status( $translated ) ) {
				$out[] = (string) get_permalink( $translated );
			}
		}
		return $out;
	}

	/**
	 * Active language codes other than the current one, only with WPML or Polylang.
	 *
	 * @return string[]
	 */
	private function other_language_codes(): array {
		if ( ! in_array( Language::source(), array( 'wpml', 'polylang' ), true ) ) {
			return array();
		}
		$current = Language::current_code();
		$codes   = array();
		if ( 'wpml' === Language::source() ) {
			$active = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
			$codes  = is_array( $active ) ? array_keys( $active ) : array();
		} elseif ( function_exists( 'pll_languages_list' ) ) {
			$codes = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		}
		return array_values( array_diff( array_map( 'strval', $codes ), array( $current ) ) );
	}

	/**
	 * Transient key for a user.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function key( int $user_id ): string {
		return self::SESSION_PREFIX . $user_id;
	}

	/**
	 * Delete every user's scan session.
	 */
	public static function clear_sessions(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::SESSION_PREFIX ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
		update_option( self::SESSION_EPOCH, self::epoch() + 1, true );
	}

	/**
	 * Current session epoch.
	 *
	 * @return int
	 */
	private static function epoch(): int {
		return (int) get_option( self::SESSION_EPOCH, 0 );
	}

	/**
	 * The user's active scan session.
	 *
	 * @param int $user_id User id.
	 * @return array|null
	 */
	public function session( int $user_id ): ?array {
		$session = get_transient( self::key( $user_id ) );
		if ( ! is_array( $session ) || ! isset( $session['token'], $session['urls'] ) ) {
			return null;
		}
		return self::epoch() === (int) ( isset( $session['epoch'] ) ? $session['epoch'] : 0 ) ? $session : null;
	}

	/**
	 * Create or resume the user's session.
	 *
	 * @param int $user_id User id.
	 * @return array{token: string, urls: string[], resumed: bool, done: string[]}
	 */
	public function start( int $user_id ): array {
		$session = $this->session( $user_id );
		$resumed = null !== $session;
		if ( ! $resumed ) {
			$session = array(
				'token'    => wp_generate_password( 32, false ),
				'urls'     => $this->page_list(),
				'results'  => array(),
				'reported' => array(),
				'started'  => time(),
				'epoch'    => self::epoch(),
			);
			$this->save( $user_id, $session );
		}
		return array(
			'token'   => $session['token'],
			'urls'    => $session['urls'],
			'resumed' => $resumed,
			'done'    => self::done_urls( $session ),
		);
	}

	/**
	 * Listed URLs reported in both modes, in list order.
	 *
	 * @param array $session Session.
	 * @return string[]
	 */
	private static function done_urls( array $session ): array {
		$reported = isset( $session['reported'] ) && is_array( $session['reported'] ) ? $session['reported'] : array();
		$done     = array();
		foreach ( $session['urls'] as $url ) {
			if ( ! empty( $reported[ $url ]['none'] ) && ! empty( $reported[ $url ]['accepted'] ) ) {
				$done[] = $url;
			}
		}
		return $done;
	}

	/**
	 * Store a session and refresh its lifetime.
	 *
	 * @param int   $user_id User id.
	 * @param array $session Session.
	 */
	private function save( int $user_id, array $session ): void {
		set_transient( self::key( $user_id ), $session, self::SESSION_TTL );
	}

	/**
	 * Blank per-URL result.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	private static function blank( string $url ): array {
		return array(
			'url'              => $url,
			'status'           => 'ok',
			'cookies_none'     => array(),
			'cookies_accepted' => array(),
			'storage'          => array(),
			'resources'        => array(),
			'embeds'           => array(),
			'server_cookies'   => array(),
		);
	}

	/**
	 * Whether the URL belongs to the session.
	 *
	 * @param array  $session Session.
	 * @param string $url     URL.
	 * @return bool
	 */
	private static function listed( array $session, string $url ): bool {
		return in_array( $url, $session['urls'], true );
	}

	/**
	 * Merge one page report: cookies by mode, storage, resources and embeds united, failed wins.
	 *
	 * @param int   $user_id User id.
	 * @param array $page    url, mode, cookies, storage, resources, embeds, status.
	 * @return bool False when there is no session or the URL is not part of it.
	 */
	public function add_page_result( int $user_id, array $page ): bool {
		$session = $this->session( $user_id );
		$url     = isset( $page['url'] ) ? (string) $page['url'] : '';
		if ( null === $session || ! self::listed( $session, $url ) ) {
			return false;
		}
		$kind   = isset( $page['mode'] ) && 'accepted' === $page['mode'] ? 'accepted' : 'none';
		$mode   = 'cookies_' . $kind;
		$result = isset( $session['results'][ $url ] ) ? $session['results'][ $url ] : self::blank( $url );

		$result[ $mode ] = self::union( $result[ $mode ], self::list_of( $page, 'cookies' ) );
		foreach ( array( 'storage', 'resources', 'embeds' ) as $key ) {
			$result[ $key ] = self::union( isset( $result[ $key ] ) ? $result[ $key ] : array(), self::list_of( $page, $key ) );
		}
		if ( isset( $page['status'] ) && 'ok' !== $page['status'] ) {
			$result['status'] = 'failed';
		}

		$session['results'][ $url ]           = $result;
		$session['reported'][ $url ][ $kind ] = true;
		$this->save( $user_id, $session );
		return true;
	}

	/**
	 * Merge server-side cookie names for a URL.
	 *
	 * @param int      $user_id User id.
	 * @param string   $url     URL.
	 * @param string[] $names   Cookie names.
	 * @return bool
	 */
	public function add_server_cookies( int $user_id, string $url, array $names ): bool {
		$session = $this->session( $user_id );
		if ( null === $session || ! self::listed( $session, $url ) ) {
			return false;
		}
		$result                     = isset( $session['results'][ $url ] ) ? $session['results'][ $url ] : self::blank( $url );
		$result['server_cookies']   = self::union( $result['server_cookies'], array_map( 'strval', $names ) );
		$session['results'][ $url ] = $result;
		$this->save( $user_id, $session );
		return true;
	}

	/**
	 * Union of two lists, capped.
	 *
	 * @param string[] $a First.
	 * @param string[] $b Second.
	 * @return string[]
	 */
	private static function union( array $a, array $b ): array {
		return array_slice( array_values( array_unique( array_merge( $a, $b ) ) ), 0, Rest_Scan::MAX_ITEMS );
	}

	/**
	 * Strings from an array key.
	 *
	 * @param array  $page Page.
	 * @param string $key  Key.
	 * @return string[]
	 */
	private static function list_of( array $page, string $key ): array {
		return isset( $page[ $key ] ) && is_array( $page[ $key ] ) ? array_values( array_filter( array_map( 'strval', $page[ $key ] ), 'strlen' ) ) : array();
	}

	/**
	 * Whether a URL is on this site's host (www twin included).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_site_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || ! is_string( $site ) ) {
			return false;
		}
		$strip = static function ( string $h ): string {
			$h = strtolower( $h );
			return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
		};
		$port  = static function ( string $u ): int {
			$p = wp_parse_url( $u, PHP_URL_PORT );
			return in_array( $p, array( null, 80, 443 ), true ) ? 0 : (int) $p;
		};
		return $strip( $host ) === $strip( $site )
			&& $port( $url ) === $port( home_url() )
			&& in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true );
	}

	/**
	 * Cookie names the server sets for a URL. Own host only, redirects followed by hand while they stay on it.
	 *
	 * @param string $url URL.
	 * @return string[]
	 */
	public function server_cookies( string $url ): array {
		$names = array();
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			if ( ! self::is_site_url( $url ) ) {
				break;
			}
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 0, // Followed by hand so every hop is checked against the site host.
					'sslverify'   => apply_filters( 'revolter_cookies_scan_sslverify', 'local' !== wp_get_environment_type() ),
				)
			);
			if ( is_wp_error( $response ) ) {
				break;
			}
			foreach ( wp_remote_retrieve_cookies( $response ) as $cookie ) {
				if ( isset( $cookie->name ) && '' !== (string) $cookie->name ) {
					$names[] = (string) $cookie->name;
				}
			}
			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( $code < 300 || $code >= 400 || ! is_string( $location ) || '' === $location ) {
				break;
			}
			$url = \WP_Http::make_absolute_url( $location, $url );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Process and persist the scan, then drop the session.
	 *
	 * A listed URL that was never reported counts as failed. When no page succeeded the declaration
	 * is kept (the processor handles that) and the rescan recommendation is left untouched.
	 *
	 * @param int $user_id User id.
	 * @return array|null Summary, or null without a session.
	 */
	public function finish( int $user_id ): ?array {
		$session = $this->session( $user_id );
		if ( null === $session ) {
			return null;
		}
		$pages  = array();
		$failed = 0;
		foreach ( $session['urls'] as $url ) {
			$page = isset( $session['results'][ $url ] ) ? $session['results'][ $url ] : array(
				'url'    => $url,
				'status' => 'failed',
			);
			if ( 'ok' !== $page['status'] ) {
				++$failed;
			}
			$pages[] = $page;
		}
		$total      = count( $pages );
		$all_failed = $failed === $total;

		$repository = new Item_Repository();
		$processor  = new Scan_Processor(
			Library::from_file(),
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			(string) Settings::get( 'company_name' )
		);
		$result     = $processor->process( array( 'pages' => $pages ), $repository->all() );
		( new Scan_Service( $repository ) )->apply( $result, $total, $failed, ! $all_failed );
		delete_transient( self::key( $user_id ) );

		$counts = $repository->counts();
		return array(
			'pages'        => $total,
			'failed'       => $failed,
			'all_failed'   => $all_failed,
			'declared'     => $counts['declared'],
			'needs_review' => $counts['needs_review'],
			'leaks'        => count( $result['leaks'] ),
		);
	}
}
