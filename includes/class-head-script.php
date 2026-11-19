<?php
/**
 * Inline head script.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the consent core, rules, consent version, scan mode (scan pages only), Consent Mode defaults and guard as one inline script.
 */
class Head_Script {

	const CONSENT_DEFAULT = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n"
		. "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'denied',personalization_storage:'denied',security_storage:'granted',wait_for_update:500});\n"
		. "gtag('set','ads_data_redaction',true);";

	/**
	 * Returns the Blocking_Rules, built on first use.
	 *
	 * @var callable
	 */
	private $rules_factory;

	/**
	 * Constructor.
	 *
	 * @param callable $rules_factory Returns Blocking_Rules.
	 */
	public function __construct( callable $rules_factory ) {
		$this->rules_factory = $rules_factory;
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_script' ), 1 );
	}

	/**
	 * Print on front-end pages.
	 */
	public function print_script(): void {
		if ( ! Context::is_frontend_page() ) {
			return;
		}
		echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Local JS files and JSON encoded with HEX flags.
	}

	/**
	 * The script element. Same for every visitor; only scan-mode requests (never cached) differ.
	 *
	 * @return string
	 */
	public function render(): string {
		$rules = call_user_func( $this->rules_factory );
		$js    = $rules instanceof Blocking_Rules ? $rules->for_js() : array();
		$json  = wp_json_encode( $js, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		$parts = array();
		$core  = self::asset( 'consent-core.js' );
		if ( '' !== $core ) {
			$parts[] = $core;
		}
		$parts[]   = 'window.RevolterCookiesRules = ' . ( is_string( $json ) ? $json : '[]' ) . ';';
		$parts[]   = 'window.RevolterCookiesVersion = ' . (int) Settings::get( 'consent_version' ) . ';';
		$scan_mode = Context::scan_mode();
		if ( null !== $scan_mode ) {
			// Scan pages are never cached, so this per-request line is safe.
			$parts[] = "window.RevolterCookiesScanMode='" . ( 'accepted' === $scan_mode ? 'accepted' : 'none' ) . "';";
		}
		$parts[] = self::CONSENT_DEFAULT;
		$guard   = self::asset( 'guard.js' );
		if ( '' !== $guard ) {
			$parts[] = $guard;
		}
		return '<script ' . Banner::OPTIMIZER_OPT_OUTS . '>' . "\n" . implode( "\n", $parts ) . "\n</script>\n";
	}

	/**
	 * Contents of a bundled JS file, '' if missing.
	 *
	 * @param string $file File name in assets/js.
	 * @return string
	 */
	private static function asset( string $file ): string {
		$path = REVOLTER_COOKIES_PATH . 'assets/js/' . $file;
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		return is_string( $contents ) ? trim( $contents ) : '';
	}
}
