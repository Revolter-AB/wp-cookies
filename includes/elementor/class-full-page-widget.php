<?php
/**
 * Elementor widget: full cookie policy page.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies\Elementor;

use RevolterCookies\Declaration;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the complete cookie declaration.
 */
class Full_Page_Widget extends \Elementor\Widget_Base {

	/**
	 * Section toggle control => declaration section key.
	 */
	const SECTIONS = array(
		'show_what'    => 'what',
		'show_how'     => 'how',
		'show_manage'  => 'manage',
		'show_legal'   => 'legal',
		'show_rights'  => 'rights',
		'show_consent' => 'consent',
	);

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'rc-full-page';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return esc_html__( 'Cookie policy (full page)', 'revolter-cookies' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-lock-user';
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
		return array( 'cookie', 'cookies', 'consent', 'gdpr', 'policy', 'revolter' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => esc_html__( 'Sections', 'revolter-cookies' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);
		$labels = array(
			'show_what'    => esc_html__( 'What are cookies', 'revolter-cookies' ),
			'show_how'     => esc_html__( 'How we use cookies', 'revolter-cookies' ),
			'show_manage'  => esc_html__( 'Managing cookies', 'revolter-cookies' ),
			'show_legal'   => esc_html__( 'Legal basis', 'revolter-cookies' ),
			'show_rights'  => esc_html__( 'Your rights', 'revolter-cookies' ),
			'show_consent' => esc_html__( 'Your consent', 'revolter-cookies' ),
		);
		foreach ( $labels as $id => $label ) {
			$this->add_control(
				$id,
				array(
					'label'        => $label,
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'label_on'     => esc_html__( 'Show', 'revolter-cookies' ),
					'label_off'    => esc_html__( 'Hide', 'revolter-cookies' ),
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);
		}
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => esc_html__( 'Style', 'revolter-cookies' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$headings = '{{WRAPPER}} .rc-decl h2, {{WRAPPER}} .rc-group-title';
		$body     = '{{WRAPPER}} .rc-decl p, {{WRAPPER}} .rc-table td';

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'heading_typography',
				'label'    => esc_html__( 'Heading typography', 'revolter-cookies' ),
				'selector' => $headings,
			)
		);
		$this->add_control(
			'heading_color',
			array(
				'label'     => esc_html__( 'Heading colour', 'revolter-cookies' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $headings => 'color: {{VALUE}};' ),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'body_typography',
				'label'    => esc_html__( 'Body typography', 'revolter-cookies' ),
				'selector' => $body,
			)
		);
		$this->add_control(
			'text_color',
			array(
				'label'     => esc_html__( 'Text colour', 'revolter-cookies' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $body => 'color: {{VALUE}};' ),
			)
		);
		Integration::add_table_style_controls( $this );
		$this->add_responsive_control(
			'section_gap',
			array(
				'label'      => esc_html__( 'Section gap', 'revolter-cookies' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 120,
					),
					'em' => array(
						'min' => 0,
						'max' => 8,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .rc-decl' => '--rc-gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Output.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$sections = array();
		foreach ( self::SECTIONS as $control => $key ) {
			$sections[ $key ] = isset( $settings[ $control ] ) && 'yes' === $settings[ $control ];
		}
		echo Declaration::render_full_page( array( 'sections' => $sections ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Declaration returns already-escaped HTML.
	}
}
