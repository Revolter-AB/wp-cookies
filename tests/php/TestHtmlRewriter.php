<?php
/**
 * HTML rewriter tests.
 *
 * @package RevolterCookies
 */

// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- HTML fixtures, not enqueued assets.

use RevolterCookies\Blocking_Rules;
use RevolterCookies\Html_Rewriter;
use RevolterCookies\Library;
use RevolterCookies\Settings;

/**
 * Verifies server-side rewriting of scripts, iframes and embeds.
 */
class TestHtmlRewriter extends WP_UnitTestCase {

	/**
	 * Clean up the consent cookie.
	 */
	public function tear_down() {
		unset( $_COOKIE['rc_consent'] );
		parent::tear_down();
	}

	/**
	 * Build a rewriter from the real library.
	 *
	 * @param array $settings Setting overrides.
	 * @param array $domains  Reviewed domain rows.
	 * @return Html_Rewriter
	 */
	private function rewriter( array $settings = array(), array $domains = array() ): Html_Rewriter {
		return new Html_Rewriter( Blocking_Rules::build( Library::from_file(), array_merge( Settings::defaults(), $settings ), $domains ) );
	}

	/**
	 * Wrap a fragment in a document.
	 *
	 * @param string $body Body markup.
	 * @return string
	 */
	private function page( string $body ): string {
		return "<!DOCTYPE html>\n<html lang=\"sv\"><head><meta charset=\"utf-8\"><title>T</title></head><body>\n" . $body . "\n</body></html>\n";
	}

	/**
	 * External gtag is blocked.
	 */
	public function test_gtag_external_blocked() {
		$out = $this->rewriter()->rewrite( $this->page( '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>' ) );
		$this->assertStringContainsString( 'type="text/plain"', $out );
		$this->assertMatchesRegularExpression( '#data-rc-src="[^"]*gtag/js#', $out );
		$this->assertDoesNotMatchRegularExpression( '#\ssrc=#', $out );
		$this->assertStringContainsString( 'data-rc-category="statistics"', $out );
		$this->assertStringContainsString( 'data-rc-label="Google Analytics"', $out );
		$this->assertStringContainsString( 'async', $out );
	}

	/**
	 * Every JavaScript MIME type, including parameterised ones, is gated.
	 */
	public function test_all_javascript_types_blocked() {
		$types = array( 'text/ecmascript', 'application/ecmascript', 'application/x-javascript', 'text/javascript; charset=utf-8', ' TEXT/JavaScript ;charset=UTF-8', 'Module' );
		foreach ( $types as $type ) {
			$out = $this->rewriter()->rewrite( $this->page( '<script type="' . esc_attr( $type ) . '" src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>' ) );
			$this->assertStringContainsString( 'type="text/plain"', $out, $type );
			$this->assertStringContainsString( 'data-rc-category="statistics"', $out, $type );
		}
		$out = $this->rewriter()->rewrite( $this->page( '<script type="Module" src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>' ) );
		$this->assertStringContainsString( 'data-rc-type="module"', $out );
		$out = $this->rewriter()->rewrite( $this->page( '<script type="text/template" src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>' ) );
		$this->assertStringNotContainsString( 'data-rc-src', $out );
	}

	/**
	 * Inline gtag config is blocked.
	 */
	public function test_gtag_inline_config_blocked() {
		$inline = "<script>window.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\ngtag('js', new Date());\ngtag('config', 'G-ABC123');</script>";
		$out    = $this->rewriter()->rewrite( $this->page( $inline ) );
		$this->assertMatchesRegularExpression( '#<script [^>]*type="text/plain"[^>]*>window\.dataLayer#', $out );
		$this->assertStringContainsString( 'data-rc-category="statistics"', $out );
		$this->assertStringContainsString( "gtag('config', 'G-ABC123');</script>", $out );

		$ads = $this->rewriter()->rewrite( $this->page( "<script>gtag('config', 'AW-123');</script>" ) );
		$this->assertStringContainsString( 'data-rc-category="marketing"', $ads );
	}

	/**
	 * The jQuery and own scripts are byte-identical.
	 */
	public function test_jquery_and_own_scripts_untouched() {
		$html = $this->page(
			'<script src="https://example.org/wp-includes/js/jquery/jquery.min.js?ver=3.7.1" id="jquery-core-js"></script>' . "\n"
			. '<script id="theme-js-extra">var theme = {"ajax":"https:\/\/example.org\/wp-admin\/admin-ajax.php"};</script>' . "\n"
			. '<SCRIPT  type="text/javascript" src="/wp-content/themes/x/app.js"></SCRIPT>'
		);
		$this->assertSame( $html, $this->rewriter()->rewrite( $html ) );
	}

	/**
	 * JSON-LD and other non-JS types are untouched, even if they mention trackers.
	 */
	public function test_json_ld_untouched() {
		$html = $this->page(
			'<script type="application/ld+json">{"@context":"https://schema.org","url":"https://www.googletagmanager.com/gtag/js"}</script>'
			. '<script type="text/template">gtag(\'config\', \'G-1\')</script>'
		);
		$this->assertSame( $html, $this->rewriter()->rewrite( $html ) );
	}

	/**
	 * The data-rc-ignore attribute opts out.
	 */
	public function test_data_rc_ignore_untouched() {
		$html = $this->page( '<script data-rc-ignore src="https://www.googletagmanager.com/gtag/js?id=G-1"></script><script data-rc-ignore>gtag(\'config\',\'G-1\');</script>' );
		$this->assertSame( $html, $this->rewriter()->rewrite( $html ) );
	}

	/**
	 * Developer markup with data-rc-category is blocked with that category.
	 */
	public function test_developer_category_attribute_blocked() {
		$out = $this->rewriter()->rewrite( $this->page( '<script data-rc-category="preferences" src="/wp-content/themes/x/chat.js"></script>' ) );
		$this->assertStringContainsString( 'type="text/plain"', $out );
		$this->assertStringContainsString( 'data-rc-src="/wp-content/themes/x/chat.js"', $out );
		$this->assertStringContainsString( 'data-rc-category="preferences"', $out );

		$upper = $this->rewriter()->rewrite( $this->page( '<script data-rc-category="Marketing" src="/wp-content/themes/x/ads.js"></script>' ) );
		$this->assertStringContainsString( 'data-rc-category="marketing"', $upper );
		$this->assertStringContainsString( 'type="text/plain"', $upper );
	}

	/**
	 * Necessary, unclassified and unknown developer values fall through to normal matching.
	 */
	public function test_developer_category_other_values_fall_through() {
		foreach ( array( 'necessary', 'unclassified', 'bogus' ) as $value ) {
			$out = $this->rewriter()->rewrite( $this->page( '<script data-rc-category="' . $value . '" src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' ) );
			$this->assertStringContainsString( 'type="text/plain"', $out, $value );
			$this->assertStringContainsString( 'data-rc-category="statistics"', $out, $value );

			$own = $this->page( '<script data-rc-category="' . $value . '" src="/wp-content/themes/x/own.js"></script>' );
			$this->assertSame( $own, $this->rewriter()->rewrite( $own ), $value );
		}
	}

	/**
	 * Developer markup with data-rc-category on an iframe is blocked with that category.
	 */
	public function test_developer_category_on_iframe() {
		$out = $this->rewriter()->rewrite( $this->page( '<iframe data-rc-category="Statistics" src="https://www.example-widget.com/embed/1"></iframe>' ) );
		$this->assertStringContainsString( 'data-rc-src="https://www.example-widget.com/embed/1"', $out );
		$this->assertDoesNotMatchRegularExpression( '#\ssrc=#', $out );
		$this->assertStringContainsString( 'data-rc-category="statistics"', $out );
		$this->assertStringContainsString( 'data-rc-label="example-widget.com"', $out );
		$this->assertStringContainsString( 'class="rc-blocked-embed"', $out );

		$labelled = $this->rewriter()->rewrite( $this->page( '<iframe data-rc-category="preferences" data-rc-label="Chat" src="/chat/frame"></iframe>' ) );
		$this->assertStringContainsString( 'data-rc-label="Chat"', $labelled );
		$this->assertStringContainsString( 'data-rc-src="/chat/frame"', $labelled );

		$plain = $this->page( '<iframe data-rc-category="unclassified" src="https://www.example-widget.com/embed/1"></iframe>' );
		$this->assertSame( $plain, $this->rewriter()->rewrite( $plain ) );
	}

	/**
	 * WooCommerce order attribution scripts wait for marketing consent; core WooCommerce scripts do not.
	 */
	public function test_woocommerce_order_attribution_blocked() {
		$base = 'https://shop.example/wp-content/plugins/woocommerce/assets/js/';
		$out  = $this->rewriter()->rewrite(
			$this->page(
				'<script src="' . $base . 'sourcebuster/sourcebuster.min.js?ver=9.0" id="sourcebuster-js-js"></script>'
				. '<script src="' . $base . 'frontend/order-attribution.min.js?ver=9.0" id="wc-order-attribution-js"></script>'
				. '<script src="' . $base . 'frontend/woocommerce.min.js?ver=9.0" id="woocommerce-js"></script>'
				. '<script src="' . $base . 'frontend/cart-fragments.min.js?ver=9.0" id="wc-cart-fragments-js"></script>'
			)
		);
		$this->assertSame( 2, substr_count( $out, 'type="text/plain"' ) );
		$this->assertSame( 2, substr_count( $out, 'data-rc-category="marketing"' ) );
		$this->assertStringContainsString( 'data-rc-src="' . $base . 'sourcebuster/sourcebuster.min.js?ver=9.0"', $out );
		$this->assertStringContainsString( 'data-rc-src="' . $base . 'frontend/order-attribution.min.js?ver=9.0"', $out );
		$this->assertStringContainsString( 'data-rc-label="WooCommerce Order Attribution"', $out );
		$this->assertStringContainsString( '<script src="' . $base . 'frontend/woocommerce.min.js?ver=9.0" id="woocommerce-js"></script>', $out );
		$this->assertStringContainsString( '<script src="' . $base . 'frontend/cart-fragments.min.js?ver=9.0" id="wc-cart-fragments-js"></script>', $out );
	}

	/**
	 * Module type is kept in data-rc-type.
	 */
	public function test_module_type_preserved_in_data_attr() {
		$out = $this->rewriter()->rewrite( $this->page( '<script type="module" src="https://static.hotjar.com/c/hotjar.js"></script>' ) );
		$this->assertStringContainsString( 'type="text/plain"', $out );
		$this->assertStringContainsString( 'data-rc-type="module"', $out );
		$this->assertStringContainsString( 'data-rc-category="statistics"', $out );
	}

	/**
	 * YouTube iframes are blocked with label.
	 */
	public function test_youtube_iframe_blocked_with_label() {
		$out = $this->rewriter()->rewrite( $this->page( '<iframe class="embed" width="560" src="https://www.youtube.com/embed/abc?rel=0&amp;x=1" allowfullscreen></iframe>' ) );
		$this->assertStringContainsString( 'data-rc-src="https://www.youtube.com/embed/abc?rel=0&amp;x=1"', $out );
		$this->assertDoesNotMatchRegularExpression( '#\ssrc=#', $out );
		$this->assertStringContainsString( 'data-rc-category="marketing"', $out );
		$this->assertStringContainsString( 'data-rc-label="YouTube"', $out );
		$this->assertStringContainsString( 'class="embed rc-blocked-embed"', $out );
		$this->assertStringContainsString( 'allowfullscreen', $out );
	}

	/**
	 * Elementor video widgets are marked.
	 */
	public function test_elementor_video_widget_marked() {
		$settings = esc_attr(
			wp_json_encode(
				array(
					'youtube_url' => 'https://www.youtube.com/watch?v=abc',
					'video_type'  => 'youtube',
				)
			)
		);
		$vimeo    = esc_attr( wp_json_encode( array( 'video_type' => 'vimeo' ) ) );
		$hosted   = esc_attr( wp_json_encode( array( 'video_type' => 'hosted' ) ) );
		$out      = $this->rewriter()->rewrite(
			$this->page(
				'<div class="elementor-element elementor-widget elementor-widget-video" data-settings="' . $settings . '" data-widget_type="video.default"></div>'
				. '<div class="elementor-widget-video" data-settings="' . $vimeo . '"></div>'
				. '<div class="elementor-widget-video" id="hosted" data-settings="' . $hosted . '"></div>'
			)
		);
		$this->assertStringContainsString( 'elementor-widget-video rc-blocked-widget" data-settings', $out );
		$this->assertStringContainsString( 'data-rc-label="YouTube"', $out );
		$this->assertStringContainsString( 'data-rc-label="Vimeo"', $out );
		$this->assertSame( 2, substr_count( $out, 'data-rc-category="marketing"' ) );
		$this->assertStringContainsString( '<div class="elementor-widget-video" id="hosted"', $out );
	}

	/**
	 * GTM noscript is removed, others are kept.
	 */
	public function test_noscript_gtm_removed_other_noscript_kept() {
		$gtm  = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABC" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
		$keep = '<NOSCRIPT><img src="/wp-content/uploads/logo.png" alt=""></NOSCRIPT>';
		$out  = $this->rewriter()->rewrite( $this->page( $gtm . "\n" . $keep ) );
		$this->assertStringNotContainsString( 'ns.html', $out );
		$this->assertStringContainsString( $keep, $out );
	}

	/**
	 * GTM category setting is respected by the rewriter.
	 */
	public function test_gtm_category_setting_respected() {
		$html = $this->page( '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':new Date().getTime(),event:\'gtm.js\'});})(window,document,\'script\',\'dataLayer\',\'GTM-ABC\');</script>' );
		$this->assertStringContainsString( 'data-rc-category="statistics"', $this->rewriter()->rewrite( $html ) );
		$this->assertStringContainsString( 'data-rc-category="marketing"', $this->rewriter( array( 'gtm_category' => 'marketing' ) )->rewrite( $html ) );
	}

	/**
	 * Manual rules block scripts.
	 */
	public function test_manual_rule_blocks_script() {
		$rules = array(
			array(
				'pattern'  => 'widget.chatvendor.example/loader.js',
				'category' => 'preferences',
			),
		);
		$out   = $this->rewriter( array( 'manual_rules' => $rules ) )->rewrite( $this->page( '<script src="https://widget.chatvendor.example/loader.js"></script>' ) );
		$this->assertStringContainsString( 'data-rc-category="preferences"', $out );
		$this->assertStringContainsString( 'data-rc-label="widget.chatvendor.example/loader.js"', $out );
	}

	/**
	 * Reviewed domains block iframes.
	 */
	public function test_reviewed_domain_blocks_iframe() {
		$domains = array(
			array(
				'name'     => 'booking.example',
				'category' => 'marketing',
				'status'   => 'declared',
			),
		);
		$out     = $this->rewriter( array(), $domains )->rewrite( $this->page( '<iframe src="https://app.booking.example/widget"></iframe>' ) );
		$this->assertStringContainsString( 'data-rc-src="https://app.booking.example/widget"', $out );
		$this->assertStringContainsString( 'data-rc-category="marketing"', $out );
		$this->assertStringContainsString( 'rc-blocked-embed', $out );
	}

	/**
	 * No per-visitor output.
	 */
	public function test_rewrite_identical_with_and_without_consent_cookie() {
		$html = $this->page(
			'<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
			. "<script>gtag('config','G-1');</script>"
			. '<iframe src="https://www.youtube.com/embed/x"></iframe>'
		);
		unset( $_COOKIE['rc_consent'] );
		$without               = $this->rewriter()->rewrite( $html );
		$_COOKIE['rc_consent'] = rawurlencode(
			wp_json_encode(
				array(
					'v'          => 1,
					'ts'         => time(),
					'categories' => array( 'necessary', 'preferences', 'statistics', 'marketing', 'unclassified' ),
				)
			)
		);
		$with                  = $this->rewriter()->rewrite( $html );
		$this->assertSame( $without, $with );
		$this->assertStringContainsString( 'type="text/plain"', $with );
	}
}
