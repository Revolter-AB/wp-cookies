<?php
/**
 * Admin-only REST endpoints for the scanner.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * POST /revolter-cookies/v1/scan/{start,page,server,finish}.
 *
 * Authentication is WordPress's cookie + wp_rest nonce; the permission callback checks manage_options.
 */
class Rest_Scan {

	const MAX_ITEMS  = 500;
	const MAX_LENGTH = 2048;

	/**
	 * Scanner.
	 *
	 * @var Scanner
	 */
	private $scanner;

	/**
	 * Constructor.
	 *
	 * @param Scanner $scanner Scanner.
	 */
	public function __construct( Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$routes = array(
			'start'  => 'start',
			'page'   => 'page',
			'server' => 'server',
			'finish' => 'finish',
		);
		foreach ( $routes as $path => $method ) {
			register_rest_route(
				'revolter-cookies/v1',
				'/scan/' . $path,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $method ),
					'permission_callback' => array( $this, 'can_scan' ),
				)
			);
		}
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public function can_scan(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Start or resume.
	 *
	 * @return \WP_REST_Response
	 */
	public function start(): \WP_REST_Response {
		return new \WP_REST_Response( $this->scanner->start( get_current_user_id() ) );
	}

	/**
	 * Record a page report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function page( \WP_REST_Request $request ) {
		$url = $this->checked_url( $request );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$mode   = $request->get_param( 'mode' );
		$status = $request->get_param( 'status' );
		if ( ! in_array( $mode, array( 'none', 'accepted' ), true ) ) {
			return $this->bad( 'Invalid mode.' );
		}
		if ( ! in_array( $status, array( 'ok', 'failed' ), true ) ) {
			return $this->bad( 'Invalid status.' );
		}
		$resources = $this->resources( $request->get_param( 'resources' ) );
		$this->scanner->add_page_result(
			get_current_user_id(),
			array(
				'url'       => $url,
				'mode'      => $mode,
				'status'    => $status,
				'cookies'   => $this->strings( $request->get_param( 'cookies' ) ),
				'storage'   => $this->strings( $request->get_param( 'storage' ) ),
				'resources' => $resources['urls'],
				'embeds'    => $resources['embeds'],
			)
		);
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Fetch the server-side cookies of an own URL.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function server( \WP_REST_Request $request ) {
		$url = $this->checked_url( $request );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$cookies = $this->scanner->server_cookies( $url );
		$this->scanner->add_server_cookies( get_current_user_id(), $url, $cookies );
		return new \WP_REST_Response( array( 'cookies' => $cookies ) );
	}

	/**
	 * Finish the scan.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function finish() {
		$summary = $this->scanner->finish( get_current_user_id() );
		if ( null === $summary ) {
			return new \WP_Error( 'revolter_cookies_no_scan', 'No scan in progress.', array( 'status' => 409 ) );
		}
		return new \WP_REST_Response( $summary );
	}

	/**
	 * The request URL, if it is on this site and in the session list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error
	 */
	private function checked_url( \WP_REST_Request $request ) {
		$url = $request->get_param( 'url' );
		if ( ! is_string( $url ) || strlen( $url ) > self::MAX_LENGTH || ! Scanner::is_site_url( $url ) ) {
			return $this->bad( 'Invalid URL.' );
		}
		$session = $this->scanner->session( get_current_user_id() );
		if ( null === $session ) {
			return new \WP_Error( 'revolter_cookies_no_scan', 'No scan in progress.', array( 'status' => 409 ) );
		}
		if ( ! in_array( $url, $session['urls'], true ) ) {
			return $this->bad( 'URL is not part of this scan.' );
		}
		return $url;
	}

	/**
	 * Resources from a page report: URL strings, or array( url, type ) from the scan agent.
	 *
	 * Every URL is kept for service inference. Scripts and iframes are also listed as embeds,
	 * the only resources that can become an unknown-domain item. A plain string counts as an embed.
	 *
	 * @param mixed $value Raw value.
	 * @return array{urls: string[], embeds: string[]}
	 */
	private function resources( $value ): array {
		$urls   = array();
		$embeds = array();
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		foreach ( array_slice( $value, 0, self::MAX_ITEMS ) as $item ) {
			$type = 'script';
			if ( is_array( $item ) ) {
				$type = isset( $item['type'] ) && is_string( $item['type'] ) ? strtolower( $item['type'] ) : '';
				$item = isset( $item['url'] ) ? $item['url'] : '';
			}
			if ( ! is_string( $item ) ) {
				continue;
			}
			$url = esc_url_raw( substr( $item, 0, self::MAX_LENGTH ) );
			if ( '' === $url ) {
				continue;
			}
			$urls[ $url ] = true;
			if ( in_array( $type, array( 'script', 'iframe', 'subdocument' ), true ) ) {
				$embeds[ $url ] = true;
			}
		}
		return array(
			'urls'   => array_keys( $urls ),
			'embeds' => array_keys( $embeds ),
		);
	}

	/**
	 * Capped, sanitised list of strings.
	 *
	 * @param mixed $value    Raw value.
	 * @param bool  $sanitize Apply sanitize_text_field (names); false for URLs, which get esc_url_raw by the caller.
	 * @return string[]
	 */
	private function strings( $value, bool $sanitize = true ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $value, 0, self::MAX_ITEMS ) as $item ) {
			if ( is_string( $item ) || is_numeric( $item ) ) {
				$item  = substr( (string) $item, 0, self::MAX_LENGTH );
				$out[] = $sanitize ? sanitize_text_field( $item ) : $item;
			}
		}
		return $out;
	}

	/**
	 * A 400 error.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private function bad( string $message ): \WP_Error {
		return new \WP_Error( 'revolter_cookies_bad_request', $message, array( 'status' => 400 ) );
	}
}
