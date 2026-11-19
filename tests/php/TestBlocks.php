<?php
/**
 * Gutenberg block tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Settings;

/**
 * Verifies block registration and server-side rendering.
 */
class TestBlocks extends WP_UnitTestCase {

	/**
	 * Fixed settings.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Settings::OPTION, array() );
		Settings::set( 'company_name', 'Acme AB' );
	}

	/**
	 * Both blocks are registered with their editor script.
	 */
	public function test_blocks_registered() {
		$registry = WP_Block_Type_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( 'revolter-cookies/full-page' ) );
		$this->assertTrue( $registry->is_registered( 'revolter-cookies/cookies-table' ) );
		$this->assertTrue( wp_script_is( 'revolter-cookies-blocks', 'registered' ) );
		$block = $registry->get_registered( 'revolter-cookies/full-page' );
		$this->assertSame( array( 'revolter-cookies-blocks' ), $block->editor_script_handles );
	}

	/**
	 * Toggles hide sections.
	 */
	public function test_full_page_block_render_respects_toggles() {
		$all = render_block(
			array(
				'blockName'    => 'revolter-cookies/full-page',
				'attrs'        => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( 'rc-section-legal', $all );
		$this->assertStringContainsString( 'rc-section-what', $all );

		$some = render_block(
			array(
				'blockName'    => 'revolter-cookies/full-page',
				'attrs'        => array( 'showLegal' => false ),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringNotContainsString( 'rc-section-legal', $some );
		$this->assertStringContainsString( 'rc-section-what', $some );
		$this->assertStringContainsString( 'wp-block-revolter-cookies-full-page', $some );
	}

	/**
	 * A string "false" attribute counts as false.
	 */
	public function test_string_false_attribute_hides_section() {
		$html = render_block(
			array(
				'blockName'    => 'revolter-cookies/full-page',
				'attrs'        => array( 'showLegal' => 'false' ),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringNotContainsString( 'rc-section-legal', $html );
	}

	/**
	 * Table block renders the table and optional settings link.
	 */
	public function test_table_block_render() {
		$block = array(
			'blockName'    => 'revolter-cookies/cookies-table',
			'attrs'        => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		$html  = render_block( $block );
		$this->assertStringContainsString( 'wp-block-revolter-cookies-cookies-table', $html );
		$this->assertStringContainsString( 'rc-decl', $html );
		$this->assertStringNotContainsString( 'rc-open-settings', $html );

		$block['attrs'] = array( 'showSettingsLink' => true );
		$this->assertStringContainsString( 'rc-open-settings', render_block( $block ) );
	}
}
