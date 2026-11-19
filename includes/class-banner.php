<?php
/**
 * Consent banner and preferences modal.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the banner markup, enqueues its assets and prints the browser config.
 *
 * Everything here is the same for every visitor, so full-page caches stay correct.
 */
class Banner {

	const DEFAULT_ACCENT = '#2F4BC7';

	/**
	 * Dark text for light accents.
	 */
	const INK_DARK = '#1c1f1a';

	/**
	 * Light text for dark accents.
	 */
	const INK_LIGHT = '#ffffff';

	/**
	 * Categories a visitor can switch on, in canonical order.
	 */
	const CONSENT_CATEGORIES = array( 'preferences', 'statistics', 'marketing' );

	/**
	 * Attributes that keep the blocker and JS optimisers (Cloudflare Rocket Loader, LiteSpeed,
	 * Autoptimize, WP Rocket and others) from deferring, combining or minifying our scripts.
	 */
	const OPTIMIZER_OPT_OUTS = 'data-rc-ignore data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-no-minify="1" nowprocket';

	/**
	 * Ids of our own script tags.
	 */
	const OWN_SCRIPT_IDS = array( 'rc-consent-js', 'rc-consent-js-before', 'rc-scan-agent-js' );

	/**
	 * Handles of our own scripts.
	 */
	const OWN_HANDLES = array( 'rc-consent', 'rc-scan-agent' );

	/**
	 * Patterns WP Rocket must neither delay nor combine.
	 */
	const ROCKET_EXCLUSIONS = array( 'revolter-cookies/assets/js/', 'rc-consent', 'RevolterCookiesConfig' );

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'wp_footer', array( $this, 'print_banner' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'wp_script_attributes', array( $this, 'mark_own_script' ) );
		add_filter( 'wp_inline_script_attributes', array( $this, 'mark_own_script' ) );
		add_filter( 'script_loader_tag', array( $this, 'mark_own_tag' ), 10, 2 );
		add_filter( 'rocket_delay_js_exclusions', array( $this, 'rocket_exclusions' ) );
		add_filter( 'rocket_exclude_js', array( $this, 'rocket_exclusions' ) );
	}

	/**
	 * Enqueue the consent script, its config and the styles on front-end pages.
	 *
	 * Scan mode still loads them (consent.js activates everything in 'accepted') and adds the scan agent in the head.
	 */
	public function enqueue(): void {
		if ( ! Context::is_frontend_page() ) {
			return;
		}
		wp_enqueue_style( 'rc-consent', REVOLTER_COOKIES_URL . 'assets/css/consent.css', array(), REVOLTER_COOKIES_VERSION );
		wp_add_inline_style( 'rc-consent', '.rc-blocked-embed,.rc-blocked-widget{' . self::accent_vars() . '}' );

		wp_enqueue_script( 'rc-consent', REVOLTER_COOKIES_URL . 'assets/js/consent.js', array(), REVOLTER_COOKIES_VERSION, true );
		$json = wp_json_encode( self::config(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		wp_add_inline_script( 'rc-consent', 'window.RevolterCookiesConfig=' . ( is_string( $json ) ? $json : '{}' ) . ';', 'before' );

		if ( null !== Context::scan_mode() ) {
			// In the head, so it strips the scan token from the address bar before other scripts run.
			wp_enqueue_script( 'rc-scan-agent', REVOLTER_COOKIES_URL . 'assets/js/scan-agent.js', array(), REVOLTER_COOKIES_VERSION, false );
		}
	}

	/**
	 * Keep the blocker away from our own script tags.
	 *
	 * @param array $attributes Script tag attributes.
	 * @return array
	 */
	public function mark_own_script( $attributes ) {
		if ( is_array( $attributes ) && isset( $attributes['id'] ) && in_array( $attributes['id'], self::OWN_SCRIPT_IDS, true ) ) {
			$attributes['data-rc-ignore']   = true;
			$attributes['data-cfasync']     = 'false';
			$attributes['data-no-optimize'] = '1';
			$attributes['data-no-defer']    = '1';
			$attributes['data-no-minify']   = '1';
			$attributes['nowprocket']       = true;
		}
		return $attributes;
	}

	/**
	 * Add the opt-out attributes to our script tags (and their inline config) when a theme or
	 * plugin printed them without the attribute filters.
	 *
	 * @param string $tag    Script HTML, inline before/after scripts included.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function mark_own_tag( $tag, $handle ) {
		if ( ! is_string( $tag ) || ! in_array( $handle, self::OWN_HANDLES, true ) ) {
			return $tag;
		}
		$result = preg_replace_callback(
			'#<script\b([^>]*)>#i',
			static function ( $m ) {
				return false !== stripos( $m[1], 'data-cfasync' ) ? $m[0] : '<script ' . self::OPTIMIZER_OPT_OUTS . $m[1] . '>';
			},
			$tag
		);
		return is_string( $result ) ? $result : $tag;
	}

	/**
	 * Keep WP Rocket from delaying or combining our scripts.
	 *
	 * @param mixed $patterns Excluded patterns.
	 * @return mixed
	 */
	public function rocket_exclusions( $patterns ) {
		if ( ! is_array( $patterns ) ) {
			return $patterns;
		}
		return array_values( array_unique( array_merge( $patterns, self::ROCKET_EXCLUSIONS ) ) );
	}

	/**
	 * Print the markup in the footer of front-end pages, except in scan mode.
	 */
	public function print_banner(): void {
		if ( ! Context::is_frontend_page() || null !== Context::scan_mode() ) {
			return;
		}
		echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes every value.
	}

	/**
	 * The banner, modal and settings button markup.
	 *
	 * @return string
	 */
	public function render(): string {
		$texts       = Texts::banner();
		$policy_url  = Policy_Page::url();
		$policy_html = esc_html( $texts['text'] );
		if ( '' !== $policy_url ) {
			$policy_html .= ' ' . sprintf( '<a class="rc-link" href="%s">%s</a>', esc_url( $policy_url ), esc_html( $texts['policy_link'] ) );
		}

		$rows = array();
		foreach ( self::categories() as $category ) {
			$key = $category['key'];
			// A category can be in use only through a blocked domain, with no cookie found yet:
			// say nothing rather than "0 cookies".
			$count  = $category['count'] > 0 ? Texts::category_count( $category['count'] ) : '';
			$rows[] = array(
				'key'         => $key,
				'label'       => Texts::category_label( $key ),
				'description' => Texts::category_description( $key ),
				'count'       => 'necessary' === $key ? trim( $texts['always_on'] . ( '' !== $count ? ' · ' . $count : '' ) ) : $count,
				'locked'      => 'necessary' === $key,
			);
		}

		$rc_data = array(
			'texts'       => $texts,
			'style'       => self::accent_vars(),
			'policy_html' => wp_kses(
				$policy_html,
				array(
					'a' => array(
						'class' => true,
						'href'  => true,
					),
				)
			),
			'rows'        => $rows,
			'position'    => self::position(),
			'credit_html' => self::credit_html( $texts['credit'] ),
		);

		ob_start();
		include REVOLTER_COOKIES_PATH . 'templates/banner.php';
		return (string) ob_get_clean();
	}

	/**
	 * Config for consent.js. No per-visitor data (scanMode is set only on uncached scan requests).
	 *
	 * @return array
	 */
	public static function config(): array {
		$placeholders = array();
		foreach ( self::CONSENT_CATEGORIES as $category ) {
			// consent.js replaces {service} with the blocked service's name.
			$placeholders[ $category ] = Texts::placeholder( '{service}', $category );
		}
		$labels = array();
		foreach ( Item_Repository::CATEGORIES as $category ) {
			$labels[ $category ] = Texts::category_label( $category );
		}

		$data         = ( new Item_Repository() )->frontend_data();
		$cookie_names = $data['cookie_names'];
		$api_class    = __NAMESPACE__ . '\\Wp_Consent_Api';

		return array(
			'version'      => (int) Settings::get( 'consent_version' ),
			'rest'         => rest_url( 'revolter-cookies/v1/consent' ),
			'policyUrl'    => Policy_Page::url(),
			'lang'         => Language::current(),
			'categories'   => self::categories(),
			'cookieNames'  => $cookie_names ? $cookie_names : new \stdClass(),
			'strings'      => array(
				'placeholder'    => $placeholders,
				'consentBox'     => Texts::consent_box(),
				'categoryLabels' => $labels,
			),
			'position'     => self::position(),
			'wpConsentApi' => class_exists( $api_class ) && call_user_func( array( $api_class, 'active' ) ),
			// Only non-null on scan requests, which are never cached.
			'scanMode'     => Context::scan_mode(),
		);
	}

	/**
	 * Categories shown to visitors: necessary always, then those in use. Never unclassified.
	 *
	 * @return array<int, array{key:string, count:int}>
	 */
	public static function categories(): array {
		$in_use = Settings::get( 'categories_in_use' );
		$in_use = is_array( $in_use ) ? $in_use : array();
		$data   = ( new Item_Repository() )->frontend_data();
		$counts = $data['counts'];
		$out    = array(
			array(
				'key'   => 'necessary',
				'count' => isset( $counts['necessary'] ) ? $counts['necessary'] : 0,
			),
		);
		foreach ( self::CONSENT_CATEGORIES as $category ) {
			if ( in_array( $category, $in_use, true ) ) {
				$out[] = array(
					'key'   => $category,
					'count' => isset( $counts[ $category ] ) ? $counts[ $category ] : 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Settings button side.
	 *
	 * @return string left or right.
	 */
	private static function position(): string {
		return 'right' === Settings::get( 'button_position' ) ? 'right' : 'left';
	}

	/**
	 * Sanitised accent colour, default when invalid.
	 *
	 * @return string
	 */
	public static function accent(): string {
		$color = Settings::get( 'accent_color' );
		$color = is_string( $color ) ? sanitize_hex_color( $color ) : null;
		return $color ? $color : self::DEFAULT_ACCENT;
	}

	/**
	 * CSS custom properties for the accent, a readable text colour on it, and a readable accent for text on white.
	 *
	 * @return string
	 */
	public static function accent_vars(): string {
		$accent = self::accent();
		return '--rc-accent:' . $accent . ';--rc-accent-ink:' . self::ink( $accent ) . ';--rc-accent-text:' . self::accent_text( $accent );
	}

	/**
	 * The accent when it is readable as text on white (WCAG contrast 4.5:1), else a darker shade of it,
	 * else near-black.
	 *
	 * @param string $hex #rgb or #rrggbb.
	 * @return string
	 */
	public static function accent_text( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return self::INK_DARK;
		}
		$rgb = array_map( 'hexdec', str_split( $hex, 2 ) );
		for ( $step = 0; $step < 12; $step++ ) {
			$candidate = vsprintf( '%02x%02x%02x', $rgb );
			if ( 1.05 / ( self::luminance( $candidate ) + 0.05 ) >= 4.5 ) {
				return '#' . ( 0 === $step ? $hex : $candidate );
			}
			foreach ( $rgb as $i => $channel ) {
				$rgb[ $i ] = (int) floor( $channel * 0.85 );
			}
		}
		return self::INK_DARK;
	}

	/**
	 * White or near-black, whichever contrasts more with the colour (WCAG relative luminance).
	 *
	 * @param string $hex #rgb or #rrggbb.
	 * @return string
	 */
	public static function ink( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return self::INK_LIGHT;
		}
		$luminance = self::luminance( $hex );
		$dark      = self::luminance( ltrim( self::INK_DARK, '#' ) );
		$on_light  = 1.05 / ( $luminance + 0.05 );
		$on_dark   = ( $luminance + 0.05 ) / ( $dark + 0.05 );
		return $on_light >= $on_dark ? self::INK_LIGHT : self::INK_DARK;
	}

	/**
	 * Relative luminance of a six-digit hex colour.
	 *
	 * @param string $hex rrggbb.
	 * @return float
	 */
	private static function luminance( string $hex ): float {
		$channels = array();
		foreach ( str_split( $hex, 2 ) as $pair ) {
			$c          = hexdec( $pair ) / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Credit row, or '' when the filter turns it off.
	 *
	 * @param string $credit Credit text.
	 * @return string
	 */
	private static function credit_html( string $credit ): string {
		/**
		 * Whether to show the "Made with care by Revolter" credit row.
		 *
		 * @param bool $show Default true.
		 */
		if ( ! apply_filters( 'revolter_cookies_show_credit', true ) ) {
			return '';
		}
		return '<div class="rc-credit"><a href="https://revolter.se" rel="noopener">' . esc_html( $credit )
			. ' <svg class="rc-credit-logo" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1390.74 383.79">'
			. self::logo_paths() . '</svg><span class="rc-sr">Revolter</span></a></div>';
	}

	/**
	 * Path elements of the wordmark, read from the bundled SVG.
	 *
	 * @return string
	 */
	private static function logo_paths(): string {
		$path = REVOLTER_COOKIES_PATH . 'assets/img/revolter-logo.svg';
		$svg  = is_readable( $path ) ? file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( ! is_string( $svg ) || ! preg_match_all( '/<path\s[^>]*\/>/', $svg, $matches ) ) {
			return '';
		}
		return implode( '', $matches[0] );
	}
}
