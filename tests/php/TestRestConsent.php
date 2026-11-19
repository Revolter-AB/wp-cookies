<?php
/**
 * Consent REST endpoint tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Consent_Log;
use RevolterCookies\Installer;

/**
 * Verifies the public consent logging route.
 */
class TestRestConsent extends WP_UnitTestCase {

	const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

	/**
	 * Register routes and clear the table.
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . Installer::log_table() );
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		( new \RevolterCookies\Rest_Consent() )->register();
		rest_get_server();
	}

	/**
	 * Dispatch a raw JSON body.
	 *
	 * @param array|string $payload Payload or raw body.
	 * @return WP_REST_Response
	 */
	private function post( $payload ) {
		$request = new WP_REST_Request( 'POST', '/revolter-cookies/v1/consent' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( is_string( $payload ) ? $payload : wp_json_encode( $payload ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Valid payload.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function payload( array $over = array() ): array {
		return array_merge(
			array(
				'id'         => self::ID,
				'categories' => array( 'necessary', 'statistics' ),
				'version'    => 1,
				'action'     => 'custom',
				'language'   => 'sv',
			),
			$over
		);
	}

	/**
	 * Valid post inserts a row.
	 */
	public function test_valid_post_inserts_row() {
		$res = $this->post( $this->payload() );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( array( 'ok' => true ), $res->get_data() );
		$rows = ( new Consent_Log() )->latest( 5 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'necessary,statistics', $rows[0]['categories'] );
		$this->assertSame( 'custom', $rows[0]['action'] );
	}

	/**
	 * Categories are stored in canonical order.
	 */
	public function test_categories_stored_in_canonical_order() {
		$this->post( $this->payload( array( 'categories' => array( 'marketing', 'necessary', 'preferences', 'marketing' ) ) ) );
		$rows = ( new Consent_Log() )->latest( 5 );
		$this->assertSame( 'necessary,preferences,marketing', $rows[0]['categories'] );
	}

	/**
	 * Bad uuid.
	 */
	public function test_invalid_uuid_400() {
		$this->assertSame( 400, $this->post( $this->payload( array( 'id' => 'not-a-uuid' ) ) )->get_status() );
		$this->assertSame( 400, $this->post( $this->payload( array( 'id' => 'aaaaaaaa-aaaa-1aaa-8aaa-aaaaaaaaaaaa' ) ) )->get_status() );
	}

	/**
	 * Unknown category.
	 */
	public function test_unknown_category_400() {
		$this->assertSame( 400, $this->post( $this->payload( array( 'categories' => array( 'necessary', 'bogus' ) ) ) )->get_status() );
		$this->assertSame( 400, $this->post( $this->payload( array( 'categories' => array( 'necessary', 'unclassified' ) ) ) )->get_status() );
		$this->assertSame( 400, $this->post( $this->payload( array( 'categories' => 'necessary' ) ) )->get_status() );
	}

	/**
	 * Necessary is mandatory.
	 */
	public function test_missing_necessary_400() {
		$this->assertSame( 400, $this->post( $this->payload( array( 'categories' => array( 'statistics' ) ) ) )->get_status() );
	}

	/**
	 * Other fields are validated.
	 */
	public function test_invalid_fields_400() {
		$this->assertSame( 400, $this->post( $this->payload( array( 'version' => 0 ) ) )->get_status() );
		$this->assertSame( 400, $this->post( $this->payload( array( 'version' => 'x' ) ) )->get_status() );
		$this->assertSame( 400, $this->post( $this->payload( array( 'action' => 'hack' ) ) )->get_status() );
		$this->assertSame( 400, $this->post( $this->payload( array( 'language' => 'swe' ) ) )->get_status() );
		$missing = $this->payload();
		unset( $missing['id'] );
		$this->assertSame( 400, $this->post( $missing )->get_status() );
		$this->assertCount( 0, ( new Consent_Log() )->latest( 5 ) );
	}

	/**
	 * Oversized body.
	 */
	public function test_oversized_body_413() {
		$res = $this->post( $this->payload( array( 'pad' => str_repeat( 'x', 2100 ) ) ) );
		$this->assertSame( 413, $res->get_status() );
		$this->assertCount( 0, ( new Consent_Log() )->latest( 5 ) );
	}

	/**
	 * Works anonymously without a nonce.
	 */
	public function test_works_for_anonymous_without_nonce() {
		wp_set_current_user( 0 );
		$this->assertSame( 201, $this->post( $this->payload() )->get_status() );
	}
}
