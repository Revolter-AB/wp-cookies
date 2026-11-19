<?php
/**
 * Gutenberg blocks.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic blocks rendering the cookie declaration.
 */
class Blocks {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register the editor script and both blocks.
	 */
	public function register_blocks(): void {
		wp_register_script(
			'revolter-cookies-blocks',
			REVOLTER_COOKIES_URL . 'blocks/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			REVOLTER_COOKIES_VERSION,
			true
		);
		wp_set_script_translations( 'revolter-cookies-blocks', 'revolter-cookies', REVOLTER_COOKIES_PATH . 'languages' );

		register_block_type(
			REVOLTER_COOKIES_PATH . 'blocks/full-page',
			array( 'render_callback' => array( $this, 'render_full_page' ) )
		);
		register_block_type(
			REVOLTER_COOKIES_PATH . 'blocks/cookies-table',
			array( 'render_callback' => array( $this, 'render_table' ) )
		);
	}

	/**
	 * Full page block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_full_page( $attributes ): string {
		$attributes = (array) $attributes;
		$map        = array(
			'showWhat'    => 'what',
			'showHow'     => 'how',
			'showManage'  => 'manage',
			'showLegal'   => 'legal',
			'showRights'  => 'rights',
			'showConsent' => 'consent',
		);
		$sections   = array();
		foreach ( $map as $attribute => $key ) {
			$sections[ $key ] = ! isset( $attributes[ $attribute ] ) || rest_sanitize_boolean( $attributes[ $attribute ] );
		}
		return $this->wrap( Declaration::render_full_page( array( 'sections' => $sections ) ) );
	}

	/**
	 * Cookie table block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_table( $attributes ): string {
		$attributes = (array) $attributes;
		return $this->wrap( Declaration::render_table( array( 'settings_link' => isset( $attributes['showSettingsLink'] ) && rest_sanitize_boolean( $attributes['showSettingsLink'] ) ) ) );
	}

	/**
	 * Wrap output in the block wrapper so spacing and typography supports apply.
	 *
	 * @param string $html Already escaped declaration HTML.
	 * @return string
	 */
	private function wrap( string $html ): string {
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
