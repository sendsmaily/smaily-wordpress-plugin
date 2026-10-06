<?php
/**
 * Minimal stand-in for the Contact Form 7 submission class the Smaily CF7
 * integration reads (PRO-3824). Contact Form 7 is not installed in the test
 * environments; this carries only the surface `Public_Base::submit()` uses.
 *
 * @package Smaily\Connect\Tests
 */

// phpcs:disable

if ( ! class_exists( 'WPCF7_Submission' ) ) {
	class WPCF7_Submission {
		/** @var WPCF7_Submission|null */
		public static $instance;

		/** @var string */
		public $status = 'mail_sent';

		/** @var array<string, mixed> */
		public $posted_data = array();

		public static function get_instance() {
			return self::$instance;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_posted_data() {
			return $this->posted_data;
		}
	}
}
