<?php

/**
 * Defines the template generating and rendering functionality of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_Template {


	/**
	 * Template name.
	 *
	 * @access   protected
	 * @var      string    $_template    Filename of template.
	 */
	protected $_template = '';

	/**
	 * Template variables.
	 *
	 * @access   protected
	 * @var      array    $_vars    Variables assigned to template.
	 */
	protected $_vars = array();

	/**
	 * Constructor.
	 *
	 * @param string $file Input file.
	 */
	public function __construct( $file = null ) {
		$this->_template = $file;
	}

	/**
	 * Render partial html.
	 *
	 *
	 * @param  string $template Template.
	 * @param  array  $params Template params.
	 * @return string
	 */
	public function partial( $template, $params = array() ) {
		$template = new self( $template );
		$template->assign( $params );
		return $template->render();
	}

	/**
	 * Render template.
	 *
	 *
	 * @return string|bool
	 * @throws Exception Exeption.
	 */
	public function render() {
		$file_name = SMAILY_PLUGIN_PATH . $this->_template;
		// Check for template file.
		if ( empty( $this->_template ) || ! file_exists( $file_name ) || ! is_readable( $file_name ) ) {
			throw new Exception( 'Could not find template "' . esc_html( $file_name ) . '"! Please check for file existence.' );
			return false;
		}

		// Output template.
		ob_start();
		include $file_name;
		$output = ob_get_contents();
		ob_end_clean();
		return $output;
	}

	/**
	 * Dispatch template.
	 *
	 *
	 */
	public function dispatch() {
		// Template tiself is responsible for excaping values.
		// phpcs:ignore  WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render();
	}

	/**
	 * Assign template variables.
	 *
	 *
	 * @param  string|array $name Name.
	 * @param  object       $value [optional].
	 * @return Smaily_Template
	 */
	public function assign( $name, $value = null ) {
		if ( is_array( $name ) && ! empty( $name ) && empty( $value ) ) {
			foreach ( $name as $key => $value ) {
				$this->_vars[ $key ] = $value;
			}
		} elseif ( is_string( $name ) && ! empty( $name ) && ! empty( $value ) ) {
			$this->_vars[ $name ] = $value;
		}

		return $this;
	}

	/**
	 * Get language code currently in use.
	 *
	 *
	 * @return string $lang Language code.
	 */
	private function get_language_code() {
		// Language code if using WPML.
		$lang = '';
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			$lang = ICL_LANGUAGE_CODE;
			// Language code if using polylang.
		} elseif ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
		} else {
			$lang = get_locale();
			if ( strlen( $lang ) > 0 ) {
				// Remove any value past underscore if exists.
				$lang = explode( '_', $lang )[0];
			}
		}
		return $lang;
	}

	/**
	 * Get all assigned variables.
	 *
	 *
	 * @return array
	 */
	public function get_vars() {
		return $this->_vars;
	}

	/**
	 * Return assigned var value.
	 *
	 *
	 * @param  string $name Name.
	 * @return string|object|void
	 */
	public function __get( $name ) {
		if ( ! is_string( $name ) || empty( $name ) || ! isset( $this->_vars[ $name ] ) ) {
			return '';
		}

		return $this->_vars[ $name ];
	}

	/**
	 * Assign single param.
	 *
	 *
	 * @param  string $name Name.
	 * @param  object $value Value.
	 */
	public function __set( $name, $value ) {
		$this->_vars[ $name ] = $value;
	}
}
