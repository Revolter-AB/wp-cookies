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
				$api->enableReleaseAssets( '/revolter-cookies\.zip$/' );
			}
		} catch ( \Throwable $e ) {
			$this->checker = null;
		}
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
