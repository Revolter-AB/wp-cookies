<?php
/**
 * Public REST route that logs consent choices.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * POST /revolter-cookies/v1/consent, plus the retention cron.
 *
 * Public and nonce-free on purpose: pages are cached, so visitors have no nonce.
 */
class Rest_Consent {

	const ROUTE     = '/revolter-cookies/v1/consent';
	const MAX_BYTES = 2048;
	const UUID_V4   = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
	const ACTIONS   = array( 'accept_all', 'reject_all', 'custom', 'withdraw' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'limit_body' ), 10, 3 );
		add_action( Installer::CRON_HOOK, array( $this, 'purge' ) );
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'revolter-cookies/v1',
			'/consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'         => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_id' ),
					),
					'categories' => array(
						'required'          => true,
						'type'              => 'array',
						'validate_callback' => array( $this, 'validate_categories' ),
						'sanitize_callback' => array( $this, 'sanitize_categories' ),
					),
					'version'    => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_version' ),
						'sanitize_callback' => 'absint',
					),
					'action'     => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_action' ),
					),
					'language'   => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_language' ),
					),
				),
			)
		);
	}

	/**
	 * Reject oversized bodies before any parameter validation runs.
	 *
	 * @param mixed            $result  Short-circuit value.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function limit_body( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || self::ROUTE !== $request->get_route() ) {
			return $result;
		}
		if ( strlen( (string) $request->get_body() ) > self::MAX_BYTES ) {
			return new \WP_Error( 'revolter_cookies_too_large', 'Request body too large.', array( 'status' => 413 ) );
		}
		return $result;
	}

	/**
	 * Validate the consent id.
	 *
	 * @param mixed $value Value.
	 */
	public function validate_id( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::UUID_V4, $value );
	}

	/**
	 * Categories: array of known categories including necessary.
	 *
	 * @param mixed $value Value.
	 */
	public function validate_categories( $value ): bool {
		if ( ! is_array( $value ) || array() === $value ) {
			return false;
		}
		foreach ( $value as $category ) {
			if ( ! is_string( $category ) || ! in_array( $category, Consent_Log::CATEGORIES, true ) ) {
				return false;
			}
		}
		return in_array( 'necessary', $value, true );
	}

	/**
	 * Dedupe and put categories in canonical order.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	public function sanitize_categories( $value ): array {
		return array_values( array_intersect( Consent_Log::CATEGORIES, (array) $value ) );
	}

	/**
	 * Policy version: integer >= 1.
	 *
	 * @param mixed $value Value.
	 */
	public function validate_version( $value ): bool {
		return is_int( $value ) && $value >= 1;
	}

	/**
	 * Action whitelist.
	 *
	 * @param mixed $value Value.
	 */
	public function validate_action( $value ): bool {
		return is_string( $value ) && in_array( $value, self::ACTIONS, true );
	}

	/**
	 * Two-letter language code.
	 *
	 * @param mixed $value Value.
	 */
	public function validate_language( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-z]{2}$/', $value );
	}

	/**
	 * Store the choice.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$id = ( new Consent_Log() )->insert(
			array(
				'consent_id'     => $request->get_param( 'id' ),
				'categories'     => $request->get_param( 'categories' ),
				'policy_version' => $request->get_param( 'version' ),
				'action'         => $request->get_param( 'action' ),
				'language'       => $request->get_param( 'language' ),
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'revolter_cookies_log_failed', 'Could not store consent.', array( 'status' => 500 ) );
		}
		return new \WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * Daily retention cleanup: 24 months.
	 */
	public function purge(): void {
		( new Consent_Log() )->purge_older_than( 24 );
	}
}
