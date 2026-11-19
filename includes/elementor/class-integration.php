<?php
/**
 * Elementor integration.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Revolter widget category and both widgets when Elementor is present.
 *
 * Nothing here references an Elementor class until Elementor has loaded, and the
 * widget files (which extend Elementor classes) are only autoloaded from callbacks
 * that Elementor itself fires.
 */
class Integration {

	/**
	 * Hook in now if Elementor is already loaded, otherwise as soon as it is.
	 */
	public function register(): void {
		if ( did_action( 'elementor/loaded' ) ) {
			$this->hook();
			return;
		}
		add_action( 'elementor/loaded', array( $this, 'hook' ) );
	}

	/**
	 * Attach to Elementor's registration hooks.
	 */
	public function hook(): void {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
	}

	/**
	 * Add the Revolter category.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 */
	public function register_category( $elements_manager ): void {
		$elements_manager->add_category(
			'revolter',
			array(
				'title' => 'Revolter',
				'icon'  => 'fa fa-plug',
			)
		);
	}

	/**
	 * Register both widgets.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 */
	public function register_widgets( $widgets_manager ): void {
		$widgets_manager->register( new Full_Page_Widget() );
		$widgets_manager->register( new Cookies_Table_Widget() );
	}

	/**
	 * Table style controls shared by both widgets. Must run inside register_controls().
	 *
	 * @param \Elementor\Widget_Base $widget Widget being configured.
	 */
	public static function add_table_style_controls( $widget ): void {
		$table = '{{WRAPPER}} .rc-table';
		$cells = '{{WRAPPER}} .rc-table td, {{WRAPPER}} .rc-table th';

		$widget->add_control(
			'table_header_background',
			array(
				'label'     => esc_html__( 'Table header background', 'revolter-cookies' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .rc-table th' => 'background-color: {{VALUE}};' ),
			)
		);
		$widget->add_control(
			'border_color',
			array(
				'label'     => esc_html__( 'Border colour', 'revolter-cookies' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					$table . ', ' . $cells => 'border-color: {{VALUE}};',
				),
			)
		);
		$widget->add_responsive_control(
			'cell_padding',
			array(
				'label'      => esc_html__( 'Cell padding', 'revolter-cookies' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array( $cells => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
	}
}
