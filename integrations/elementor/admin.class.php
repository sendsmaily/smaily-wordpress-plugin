<?php

namespace Smaily_Connect\Integrations\Elementor;

class Admin {
	const WIDGET_CATEGORY = SMAILY_CONNECT_PLUGIN_NAME . '-elementor-category';

	/**
	 * Register hooks for the Elementor integration.
	 */
	public function register_hooks() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'add_elementor_category' ) );
		add_action( 'elementor/frontend/after_register_styles', array( $this, 'register_frontend_styles' ) );
		add_action( 'elementor/frontend/after_enqueue_styles', array( $this, 'enqueue_frontend_styles' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_newsletter_widget' ) );
	}

	/**
	 * Add a custom category to the Elementor widget panel.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager The Elementor elements manager instance.
	 */
	public function add_elementor_category( $elements_manager ) {
		$elements_manager->add_category(
			self::WIDGET_CATEGORY,
			array(
				'title' => __( 'Smaily', 'smaily-connect' ),
				'icon'  => 'fa fa-envelope',
			)
		);
	}

	/**
	 * Register the newsletter widget with Elementor.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager The Elementor widget manager instance.
	 */
	public function register_newsletter_widget( $widgets_manager ) {
		if ( ! class_exists( 'Elementor\Widget_Base' ) ) {
			return;
		}

		require_once __DIR__ . '/newsletter-widget.class.php';

		$widgets_manager->register( new Newsletter_Widget() );
	}

	/**
	 * Register frontend styles for elementor widgets.
	 */
	public function register_frontend_styles() {
		wp_register_style(
			'smaily-connect-elementor-newsletter-widget',
			SMAILY_CONNECT_PLUGIN_URL . '/integrations/elementor/assets/css/newsletter-widget.css',
			array(),
			SMAILY_CONNECT_PLUGIN_VERSION
		);
	}

	/**
	 * Enqueue frontend styles for elementor widgets.
	 */
	public function enqueue_frontend_styles() {
		wp_enqueue_style( 'smaily-connect-elementor-newsletter-widget' );
	}
}
