<?php

namespace Smaily_WP_Connect\Integrations\Elementor;

use Elementor\Widget_Base;

class Newsletter_Widget extends Widget_Base {
	
	/**
	 * The name of the widget.
	 *
	 * @var string
	 */
	private $widget_name;

	/**
	 * The category of the widget.
	 *
	 * @var string
	 */
	private $widget_category;

	/**
	 * Constructor.
	 *
	 * @param string $widget_name The name of the widget.
	 * @param string $widget_category The category of the widget.
	 */
	public function __construct( $widget_name, $widget_category ) {
		parent::__construct();

		$this->widget_name     = $widget_name;
		$this->widget_category = $widget_category;
	}

	/**
	 * Get the programmatic name of the widget.
	 *
	 * @return string The name of the widget.
	 */
	public function get_name() {
		return $this->widget_name;
	}

	/**
	 * Get the visible title of the widget.
	 *
	 * @return string The title of the widget.
	 */
	public function get_title() {
		return __( 'Smaily Newsletter', 'smaily-wp-connect' );
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
		return 'eicon-mail';
	}

	/**
	 * Get the categories of the widget.
	 *
	 * @return array The categories of the widget.
	 */
	public function get_categories() {
		return array( $this->widget_category );
	}

	/**
	 * Get the search keywords for the widget.
	 *
	 * @return array The keywords for the widget.
	 */
	public function get_keywords() {
		return array(
			__( 'newsletter', 'smaily-wp-connect' ),
			__( 'email', 'smaily-wp-connect' ),
			__( 'subscribe', 'smaily-wp-connect' ),
			__( 'form', 'smaily-wp-connect' ),
			__( 'smaily', 'smaily-wp-connect' ),
		);
	}

	/**
	 * Render the frontend output of the widget.
	 *
	 * @return void
	 */
	protected function render() {
		?>
		<p> Hello World </p>
		<?php
	}

	/**
	 * Render the preview output in the editor.
	 *
	 * @return void
	 */
	protected function content_template() {
		?>
		<p> Hello World </p>
		<?php
	}
}
