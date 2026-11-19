<?php
/**
 * Conflict detection tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Admin\Menu;
use RevolterCookies\Conflicts;
use RevolterCookies\Settings;

/**
 * Verifies other consent plugins and scanned CMPs raise the notice.
 */
class TestConflicts extends WP_UnitTestCase {

	/**
	 * Active plugin list used by the filter.
	 *
	 * @var string[]
	 */
	private $active = array();

	/**
	 * Reset state.
	 */
	public function set_up() {
		parent::set_up();
		$this->active = array();
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		delete_option( Settings::OPTION );
		delete_transient( Conflicts::TRANSIENT );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Filter callback.
	 *
	 * @return string[]
	 */
	public function filter_active() {
		return $this->active;
	}

	/**
	 * Render the notice for a screen.
	 *
	 * @param string $screen Screen id.
	 * @return string
	 */
	private function notice_on( $screen ) {
		set_current_screen( $screen );
		ob_start();
		( new Conflicts() )->render_notice();
		return ob_get_clean();
	}

	/**
	 * Active Cookiebot plugin is found.
	 */
	public function test_detects_active_cookiebot() {
		$this->active = array( 'hello/hello.php', 'cookiebot/cookiebot.php' );
		$this->assertSame( array( 'Cookiebot' ), Conflicts::detect() );
	}

	/**
	 * Scan-detected CMPs are merged and de-duplicated.
	 */
	public function test_detects_scan_cmp() {
		$this->active = array( 'cookiebot/cookiebot.php' );
		Settings::set( 'last_scan', array( 'cmp_detected' => array( 'Usercentrics', 'Cookiebot' ) ) );
		$this->assertSame( array( 'Cookiebot', 'Usercentrics' ), Conflicts::detect() );
	}

	/**
	 * Notice shows on plugins and own pages only.
	 */
	public function test_notice_only_on_plugins_and_own_pages() {
		$this->active = array( 'cookiebot/cookiebot.php', 'complianz-gdpr/complianz-gpdr.php' );
		( new Menu() )->add_menu();

		$out = $this->notice_on( 'plugins' );
		$this->assertStringContainsString( 'notice notice-error', $out );
		$this->assertStringContainsString( '<strong>Revolter Cookies conflicts with Cookiebot and Complianz.</strong>', $out );
		$this->assertStringNotContainsString( 'is-dismissible', $out );

		$ids = Menu::screen_ids();
		$this->assertNotEmpty( $ids );
		foreach ( $ids as $id ) {
			$this->assertStringContainsString( 'notice-error', $this->notice_on( $id ), $id );
		}

		$this->assertSame( '', $this->notice_on( 'dashboard' ) );
		$this->assertSame( '', $this->notice_on( 'options-general' ) );
	}

	/**
	 * Nothing without a conflict.
	 */
	public function test_no_notice_without_conflict() {
		$this->assertSame( array(), Conflicts::detect() );
		$this->assertSame( '', $this->notice_on( 'plugins' ) );
	}

	/**
	 * Users without capability see nothing.
	 */
	public function test_notice_hidden_without_capability() {
		$this->active = array( 'cookiebot/cookiebot.php' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', $this->notice_on( 'plugins' ) );
	}

	/**
	 * Activation sets the transient only with a conflict; showing clears it.
	 */
	public function test_activation_transient() {
		Conflicts::on_activation();
		$this->assertFalse( get_transient( Conflicts::TRANSIENT ) );

		$this->active = array( 'cookie-notice/cookie-notice.php' );
		Conflicts::on_activation();
		$this->assertNotFalse( get_transient( Conflicts::TRANSIENT ) );

		$this->assertStringContainsString( 'Cookie Notice', $this->notice_on( 'plugins' ) );
		$this->assertFalse( get_transient( Conflicts::TRANSIENT ) );
	}
}
