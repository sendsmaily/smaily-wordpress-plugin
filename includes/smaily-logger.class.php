<?php
/**
 * Logger class for Smaily plugin logging.
 */

namespace Smaily_Connect\Includes;

class Logger {
	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * The service using the logger.
	 *
	 * @var string
	 */
	protected $service;

	/**
	 * Constructor for instance-based logging.
	 * @param string $service
	 */
	public function __construct( $service ) {
		$this->service = $service;
	}

	/**
	 * Log an info message.
	 *
	 * @param string|array|object $message
	 * @return void
	 */
	public function info( $message ) {
		$this->log( $message, self::LEVEL_INFO );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string|array|object $message
	 * @return void
	 */
	public function warning( $message ) {
		$this->log( $message, self::LEVEL_WARNING );
	}

	/**
	 * Log an error message.
	 *
	 * @param string|array|object $message
	 * @return void
	 */
	public function error( $message ) {
		$this->log( $message, self::LEVEL_ERROR );
	}

	/**
	 * Log a message.
	 *
	 * @param string|array|object $message The message to log.
	 * @param string $level The log level (e.g., 'info', 'warning', 'error').
	 */
	private function log( $message, $level ) {
		if ( empty( $message ) || empty( $level ) ) {
			return;
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			$message = wp_json_encode( $message );
		}

		$message = sprintf( 'PHP %s: Smaily-%s: %s', strtoupper( $level ), $this->service, $message );

		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $message );
	}
}
