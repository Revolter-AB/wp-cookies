<?php
/**
 * Elementor widget: cookie table.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies\Elementor;

use RevolterCookies\Declaration;

defined( 'ABSPATH' ) || exit;

/**
 * Renders only the cookie declaration table.
 */
class Cookies_Table_Widget extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'rc-cookies-table';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return esc_html__( 'Cookie table', 'revolter-cookies' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-table';
	}

	/**
	 * Panel categories.
	 */
	public function get_categories() {
		return array( 'revolter' );
	}

	/**
	 * Search keywords.
	 */
	public function get_keywords() {
		return array( 'cookie', 'cookies', 'table', 'declaration', 'revolter' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => esc_html__( 'Table', 'revolter-cookies' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'show_settings_link',
			array(
				'label'        => esc_html__( 'Show settings link', 'revolter-cookies' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'revolter-cookies' ),
				'label_off'    => esc_html__( 'Hide', 'revolter-cookies' ),
				'return_value' => 'yes',
				'default'      => '',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => esc_html__( 'Style', 'revolter-cookies' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'heading_typography',
				'label'    => esc_html__( 'Heading typography', 'revolter-cookies' ),
				'selector' => '{{WRAPPER}} .rc-decl h2, {{WRAPPER}} .rc-group-title',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'body_typography',
				'label'    => esc_html__( 'Body typography', 'revolter-cookies' ),
				'selector' => '{{WRAPPER}} .rc-decl p, {{WRAPPER}} .rc-table td',
			)
		);
		$this->add_control(
			'text_color',
			array(
				'label'     => esc_html__( 'Text colour', 'revolter-cookies' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .rc-decl p, {{WRAPPER}} .rc-table td' => 'color: {{VALUE}};' ),
			)
		);
		Integration::add_table_style_controls( $this );
		$this->end_controls_section();
	}

	/**
	 * Output.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		echo Declaration::render_table( array( 'settings_link' => isset( $settings['show_settings_link'] ) && 'yes' === $settings['show_settings_link'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Declaration returns already-escaped HTML.
	}
}
