<?php

namespace Smaily_Connect\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Plugin;
use Elementor\Widget_Base;
use Smaily_Connect\Blocks\Landing_Page\Integration;

class Landingpage_Widget extends Widget_Base {
	/**
	 * Get the programmatic name of the widget.
	 *
	 * @return string The name of the widget.
	 */
	public function get_name() {
		return SMAILY_CONNECT_PLUGIN_NAME . '-elementor-landingpage-widget';
	}

	/**
	 * Get the visible title of the widget.
	 *
	 * @return string The title of the widget.
	 */
	public function get_title() {
		return __( 'Smaily Landing Page', 'smaily-connect' );
	}

	/**
	 * Get the icon of the widget. Elementor or Font Awesome icons can be used.
	 * See:
	 * https://elementor.github.io/elementor-icons/
	 * https://fontawesome.com/
	 *
	 * @return string The icon of the widget.
	 */
	public function get_icon() {
		return 'eicon-frame-expand';
	}

	/**
	 * Get the categories of the widget.
	 *
	 * @return array The categories of the widget.
	 */
	public function get_categories() {
		return array( Admin::WIDGET_CATEGORY );
	}

	/**
	 * Get the search keywords for the widget.
	 *
	 * @return array The keywords for the widget.
	 */
	public function get_keywords() {
		return array(
			__( 'landing page', 'smaily-connect' ),
			__( 'embed', 'smaily-connect' ),
			__( 'smaily', 'smaily-connect' ),
		);
	}

	/**
	 * Get the stylesheets the widget needs.
	 *
	 * The widget renders the landing-page block's markup, so it needs the
	 * block's stylesheet; naming it here lets Elementor load it only on pages
	 * that actually carry the widget. The handle is registered on `init` by
	 * register_block_type(), well before Elementor resolves widget deps.
	 *
	 * @return array The style handles of the widget.
	 */
	public function get_style_depends() {
		return array( 'smaily-landingpage-block-style' );
	}

	/**
	 * Whether the element returns dynamic content.
	 * Set to determine whether to cache the element output or not.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return false;
	}

	/**
	 * Register the controls for the widget.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'landing_page',
			array(
				'label' => __( 'Landing Page', 'smaily-connect' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'landing_page_url',
			array(
				'label'       => __( 'Landing Page URL', 'smaily-connect' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Copy the address of a landing page of your own Smaily account. Any other address shows nothing.', 'smaily-connect' ),
				'placeholder' => 'https://youraccount.sendsmaily.net/landing-pages/…/html/',
			)
		);

		$this->add_control(
			'height',
			array(
				'label'       => __( 'Height', 'smaily-connect' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => (string) Integration::DEFAULT_HEIGHT,
				'description' => __( 'In pixels, or as a percentage — for example 600 or 100%.', 'smaily-connect' ),
			)
		);

		$this->add_control(
			'width',
			array(
				'label'       => __( 'Width', 'smaily-connect' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => (string) Integration::DEFAULT_WIDTH,
				'description' => __( 'In pixels, or as a percentage — for example 500 or 100%.', 'smaily-connect' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the frontend output of the widget.
	 *
	 * The embed comes from the landing-page block's own renderer, so the
	 * widget, the shortcode and the block cannot drift apart (PRO-2440).
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$embed = Integration::render_embed(
			$settings['landing_page_url'] ?? '',
			$settings['height'] ?? '',
			$settings['width'] ?? ''
		);

		if ( '' === $embed ) {
			// Nothing to show a visitor; in the editor, say why.
			if ( Plugin::$instance->editor->is_edit_mode() ) {
				?>
				<div class="error">
					<p>
						<?php echo esc_html__( 'Add the address of a landing page of your connected Smaily account.', 'smaily-connect' ); ?>
					</p>
				</div>
				<?php
			}
			return;
		}

		// Built and escaped by the block's own renderer.
		echo $embed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
