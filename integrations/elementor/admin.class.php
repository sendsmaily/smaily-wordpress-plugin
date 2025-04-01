<?php

namespace Smaily_WP_Connect\Integrations\Elementor;

class Admin {
	/**
	 * The name of the main plugin.
	 * This is the reference to construct widget names and categories.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * The name of the widget category grouping all Smaily widgets under the same category.
	 *
	 * @var string
	 */
	private $widget_category;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_name The name of the plugin.
	 */
	public function __construct( $plugin_name ) {
		$this->plugin_name = $plugin_name;
		$this->widget_category = $plugin_name . '-elementor-category';
	}

	/**
	 * Register hooks for the Elementor integration.
	 */
	public function register_hooks() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'add_elementor_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_newsletter_widget' ) );
	}

	/**
	 * Add a custom category to the Elementor widget panel.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager The Elementor elements manager instance.
	 */
	public function add_elementor_category( $elements_manager ) {
		$elements_manager->add_category(
			$this->widget_category,
			[
				'title' => __( 'Smaily', 'smaily-wp-connect' ),
				'icon'  => 'fa fa-envelope',
			]
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

		$widget_name = $this->plugin_name . '-elementor-newsletter-widget';
		$widgets_manager->register( new Newsletter_Widget( $widget_name, $this->widget_category ) );
	}
}
