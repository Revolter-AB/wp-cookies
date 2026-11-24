<?php
/**
 * GitHub release updater.
 *
 * The update check is the only outbound request this plugin makes. It does
 * nothing until a real repository URL is configured and it never shows notices.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Wires plugin-update-checker to GitHub releases.
 */
class Updater {

	/**
	 * Plugin-update-checker's Api::REQUIRE_RELEASE_ASSETS: no fallback to the source archive.
	 */
	const REQUIRE_RELEASE_ASSETS = 2;

	/**
	 * Update checker instance, if built.
	 *
	 * @var object|null
	 */
	private $checker = null;

	/**
	 * Build the update checker when a real repository is set.
	 */
	public function register(): void {
		$repo = (string) apply_filters( 'revolter_cookies_update_repo', REVOLTER_COOKIES_UPDATE_REPO );
		if ( '' === $repo || false !== strpos( $repo, 'REPLACE_ME' ) ) {
			return;
		}

		$factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		try {
			$this->checker = $factory::buildUpdateChecker( $repo, REVOLTER_COOKIES_FILE, 'revolter-cookies' );
			$api           = $this->checker->getVcsApi();
			if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
				// Only the built zip: GitHub's source archive has no vendor/ and would break the plugin.
				$api->enableReleaseAssets( '/revolter-cookies\.zip$/', self::REQUIRE_RELEASE_ASSETS );
			}
			// Updates come from published releases only, never from a bare tag or a branch.
			$this->checker->addFilter( 'vcs_update_detection_strategies', array( __CLASS__, 'releases_only' ) );
		} catch ( \Throwable $e ) {
			$this->checker = null;
		}
	}

	/**
	 * Keep only the latest-release strategy.
	 *
	 * @param array $strategies Strategy callbacks keyed by name.
	 * @return array
	 */
	public static function releases_only( $strategies ): array {
		return is_array( $strategies ) ? array_intersect_key( $strategies, array( 'latest_release' => true ) ) : array();
	}

	/**
	 * The update checker, or null when inactive.
	 *
	 * @return object|null
	 */
	public function checker() {
		return $this->checker;
	}
}
