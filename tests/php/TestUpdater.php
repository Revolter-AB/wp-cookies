<?php
/**
 * Updater tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Updater;

/**
 * Verifies the GitHub updater is inert until a real repository is set.
 */
class TestUpdater extends WP_UnitTestCase {

	/**
	 * Remove any filter added by a test.
	 */
	public function tear_down() {
		remove_all_filters( 'revolter_cookies_update_repo' );
		parent::tear_down();
	}

	/**
	 * The placeholder repository must register nothing.
	 */
	public function test_updater_noop_with_placeholder_repo() {
		add_filter(
			'revolter_cookies_update_repo',
			static function () {
				return 'https://github.com/REPLACE_ME/revolter-cookies/';
			}
		);
		$before = has_filter( 'site_transient_update_plugins' );
		$result = ( new Updater() )->register();

		$this->assertNull( $result );
		$this->assertSame( $before, has_filter( 'site_transient_update_plugins' ) );
		$this->assertNull( ( new Updater() )->checker() );
	}

	/**
	 * A real-looking repository builds the update checker.
	 */
	public function test_updater_builds_checker_with_real_repo() {
		add_filter(
			'revolter_cookies_update_repo',
			static function () {
				return 'https://github.com/example/revolter-cookies/';
			}
		);
		$updater = new Updater();
		$updater->register();

		$this->assertNotNull( $updater->checker() );
		$this->assertNotFalse( has_filter( 'site_transient_update_plugins' ) );
	}

	/**
	 * Updates come only from a release carrying the built zip, never from a tag, branch or source archive.
	 */
	public function test_updater_uses_release_assets_only() {
		add_filter(
			'revolter_cookies_update_repo',
			static function () {
				return 'https://github.com/example/revolter-cookies/';
			}
		);
		$updater = new Updater();
		$updater->register();
		$checker = $updater->checker();
		$this->assertNotNull( $checker );

		$api        = $checker->getVcsApi();
		$preference = new ReflectionProperty( $api, 'releaseAssetPreference' );
		$preference->setAccessible( true );
		$this->assertSame( Updater::REQUIRE_RELEASE_ASSETS, $preference->getValue( $api ) );

		$all = array(
			'latest_release' => 'a',
			'latest_tag'     => 'b',
			'branch'         => 'c',
		);
		$this->assertSame( array( 'latest_release' => 'a' ), apply_filters( $checker->getUniqueName( 'vcs_update_detection_strategies' ), $all ) );
		remove_all_filters( $checker->getUniqueName( 'vcs_update_detection_strategies' ) );
	}
}
