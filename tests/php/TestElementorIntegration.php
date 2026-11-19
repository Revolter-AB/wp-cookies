<?php
/**
 * Elementor integration tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Elementor\Integration;

/**
 * Verifies the Elementor integration stays inert without Elementor and registers widgets with it.
 */
class TestElementorIntegration extends WP_UnitTestCase {

	/**
	 * Without Elementor, register() hooks nothing now and never touches Elementor classes.
	 */
	public function test_integration_noop_without_elementor() {
		if ( did_action( 'elementor/loaded' ) ) {
			$this->markTestSkipped( 'Elementor is loaded in this environment.' );
		}
		$integration = new Integration();
		$integration->register();
		$this->assertFalse( has_action( 'elementor/widgets/register', array( $integration, 'register_widgets' ) ) );
		$this->assertFalse( has_action( 'elementor/elements/categories_registered', array( $integration, 'register_category' ) ) );
		$this->assertSame( 10, has_action( 'elementor/loaded', array( $integration, 'hook' ) ) );
		$this->assertFalse( class_exists( 'RevolterCookies\Elementor\Full_Page_Widget', false ) );
	}

	/**
	 * With Elementor loaded, both widgets and the category are registered.
	 */
	public function test_widgets_register_when_elementor_loaded() {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			$this->markTestSkipped( 'Elementor is not loaded (set RC_ELEMENTOR_PATH).' );
		}
		$widgets = \Elementor\Plugin::instance()->widgets_manager->get_widget_types();
		$this->assertArrayHasKey( 'rc-full-page', $widgets );
		$this->assertArrayHasKey( 'rc-cookies-table', $widgets );
		$categories = \Elementor\Plugin::instance()->elements_manager->get_categories();
		$this->assertArrayHasKey( 'revolter', $categories );
	}
}
