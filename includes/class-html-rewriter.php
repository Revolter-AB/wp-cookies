<?php
/**
 * HTML rewriter.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Makes matched scripts, iframes and embeds inert until consent.
 */
class Html_Rewriter {

	const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript', 'application/x-javascript', 'module' );

	const VIDEO_LABELS = array(
		'youtube'     => 'YouTube',
		'vimeo'       => 'Vimeo',
		'dailymotion' => 'Dailymotion',
	);

	/**
	 * Rules.
	 *
	 * @var Blocking_Rules
	 */
	private $rules;

	/**
	 * Constructor.
	 *
	 * @param Blocking_Rules $rules Rules.
	 */
	public function __construct( Blocking_Rules $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Rewrite an HTML document. Bytes outside changed tags are kept as-is.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function rewrite( string $html ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();
			if ( 'SCRIPT' === $tag ) {
				$this->script( $processor );
			} elseif ( 'IFRAME' === $tag ) {
				$this->iframe( $processor );
			} elseif ( 'DIV' === $tag && $processor->has_class( 'elementor-widget-video' ) ) {
				$this->video_widget( $processor );
			}
		}
		return $this->strip_noscripts( $processor->get_updated_html() );
	}

	/**
	 * Handle a SCRIPT tag.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor at a SCRIPT.
	 */
	private function script( \WP_HTML_Tag_Processor $p ): void {
		if ( null !== $p->get_attribute( 'data-rc-ignore' ) ) {
			return;
		}
		$type = $p->get_attribute( 'type' );
		// Lowercase MIME type without parameters, e.g. "text/javascript; charset=utf-8" becomes "text/javascript".
		$type = is_string( $type ) ? trim( explode( ';', strtolower( $type ), 2 )[0] ) : '';
		if ( ! in_array( $type, self::JS_TYPES, true ) ) {
			return;
		}
		$src = $p->get_attribute( 'src' );
		$src = is_string( $src ) ? $src : null;

		$developer = self::developer_category( $p );
		if ( null !== $developer ) {
			$label = $p->get_attribute( 'data-rc-label' );
			$this->block_script( $p, $type, $src, $developer, is_string( $label ) ? $label : '' );
			return;
		}

		$inline = (string) $p->get_modifiable_text();
		$match  = $this->rules->match_script( $src, $inline );
		if ( null !== $match ) {
			$this->block_script( $p, $type, $src, $match['category'], $match['label'] );
		}
	}

	/**
	 * Make a SCRIPT inert.
	 *
	 * @param \WP_HTML_Tag_Processor $p        Processor.
	 * @param string                 $type     Original lowercase type.
	 * @param string|null            $src      Original src.
	 * @param string                 $category Category.
	 * @param string                 $label    Label.
	 */
	private function block_script( \WP_HTML_Tag_Processor $p, string $type, ?string $src, string $category, string $label ): void {
		$p->set_attribute( 'type', 'text/plain' );
		if ( 'module' === $type ) {
			$p->set_attribute( 'data-rc-type', 'module' );
		}
		if ( null !== $src ) {
			$p->remove_attribute( 'src' );
			$p->set_attribute( 'data-rc-src', $src );
		}
		$p->set_attribute( 'data-rc-category', $category );
		$p->set_attribute( 'data-rc-label', $label );
	}

	/**
	 * Handle an IFRAME tag.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor at an IFRAME.
	 */
	private function iframe( \WP_HTML_Tag_Processor $p ): void {
		if ( null !== $p->get_attribute( 'data-rc-ignore' ) ) {
			return;
		}
		$src = $p->get_attribute( 'src' );
		if ( ! is_string( $src ) || '' === $src ) {
			return;
		}
		$developer = self::developer_category( $p );
		if ( null !== $developer ) {
			$label = $p->get_attribute( 'data-rc-label' );
			$label = is_string( $label ) && '' !== trim( $label ) ? $label : self::host_label( $src );
			$match = array(
				'category' => $developer,
				'label'    => $label,
			);
		} else {
			$match = $this->rules->match_iframe( $src );
		}
		if ( null === $match ) {
			return;
		}
		$p->remove_attribute( 'src' );
		$p->set_attribute( 'data-rc-src', $src );
		$p->set_attribute( 'data-rc-category', $match['category'] );
		$p->set_attribute( 'data-rc-label', $match['label'] );
		$p->add_class( 'rc-blocked-embed' );
	}

	/**
	 * Category a developer set with data-rc-category, if it is one that waits for consent.
	 *
	 * Matching is case-insensitive. Any other value (necessary, unclassified, unknown)
	 * returns null so the tag goes through normal matching.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor at a SCRIPT or IFRAME.
	 * @return string|null
	 */
	private static function developer_category( \WP_HTML_Tag_Processor $p ): ?string {
		$value = $p->get_attribute( 'data-rc-category' );
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		return in_array( $value, Banner::CONSENT_CATEGORIES, true ) ? $value : null;
	}

	/**
	 * Host of a URL for use as an embed label, or the URL itself when it has no host.
	 *
	 * @param string $src URL.
	 * @return string
	 */
	private static function host_label( string $src ): string {
		$url  = 0 === strpos( trim( $src ), '//' ) ? 'https:' . trim( $src ) : trim( $src );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? (string) preg_replace( '#^www\.#i', '', strtolower( $host ) ) : $src;
	}

	/**
	 * Mark an Elementor video widget that embeds a third-party player.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor at the widget DIV.
	 */
	private function video_widget( \WP_HTML_Tag_Processor $p ): void {
		$raw = $p->get_attribute( 'data-settings' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return;
		}
		$settings = json_decode( $raw, true );
		if ( ! is_array( $settings ) ) {
			$settings = json_decode( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ), true );
		}
		if ( ! is_array( $settings ) || ! isset( $settings['video_type'] ) || ! is_string( $settings['video_type'] ) ) {
			return;
		}
		$type = strtolower( $settings['video_type'] );
		if ( ! isset( self::VIDEO_LABELS[ $type ] ) ) {
			return;
		}
		$p->set_attribute( 'data-rc-category', 'marketing' );
		$p->set_attribute( 'data-rc-label', self::VIDEO_LABELS[ $type ] );
		$p->add_class( 'rc-blocked-widget' );
	}

	/**
	 * Remove noscript blocks that contain a blocking signature.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function strip_noscripts( string $html ): string {
		if ( false === stripos( $html, '<noscript' ) ) {
			return $html;
		}
		$signatures = $this->rules->signatures();
		if ( ! $signatures ) {
			return $html;
		}
		$quoted = array();
		foreach ( $signatures as $signature ) {
			$quoted[] = preg_quote( $signature, '#' );
		}
		$any    = '#' . implode( '|', $quoted ) . '#i';
		$result = preg_replace_callback(
			'#<noscript\b[^>]*>.*?</noscript\s*>#is',
			static function ( $m ) use ( $any ) {
				return preg_match( $any, $m[0] ) ? '' : $m[0];
			},
			$html
		);
		return is_string( $result ) ? $result : $html;
	}
}
