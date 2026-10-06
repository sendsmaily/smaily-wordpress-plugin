<?php
/**
 * Minimal stand-ins for the Elementor Pro classes the Smaily form action
 * extends and reads (PRO-3806). There is no licensed Elementor Pro in the test
 * environments; these carry only the surface the action uses.
 *
 * @package Smaily\Connect\Tests
 */

// phpcs:disable

namespace ElementorPro\Modules\Forms\Classes {
	if ( ! class_exists( Action_Base::class ) ) {
		abstract class Action_Base {
			abstract public function get_name();
			abstract public function get_label();
			abstract public function run( $record, $ajax_handler );
			abstract public function register_settings_section( $widget );
			abstract public function on_export( $element );
		}
	}
}

namespace Elementor {
	if ( ! class_exists( Controls_Manager::class ) ) {
		class Controls_Manager {
			const TEXT     = 'text';
			const SELECT   = 'select';
			const REPEATER = 'repeater';
		}
	}
}
