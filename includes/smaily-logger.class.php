<?php

/**
 * Logger class for Smaily plugin logging.
 */
class Smaily_Logger {

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
		self::log( $message, self::LEVEL_INFO, $this->service );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string|array|object $message
	 * @return void
	 */
	public function warning( $message ) {
		self::log( $message, self::LEVEL_WARNING, $this->service );
	}

	/**
	 * Log an error message.
	 *
	 * @param string|array|object $message
	 * @return void
	 */
	public function error( $message ) {
		self::log( $message, self::LEVEL_ERROR, $this->service );
	}

	/**
	 * Log a message.
	 *
	 * @param string|array|object $message The message to log.
	 * @param string $level The log level (e.g., 'info', 'warning', 'error').
	 * @param string $service The service that logged the message.
	 */
	private static function log( $message, $level, $service ) {
		if ( empty( $message ) || empty( $service ) || empty( $service ) ) {
			return;
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			$message = wp_json_encode( $message );
		}

		$message = sprintf( 'PHP %s: Smaily-%s: %s', strtoupper( $level ), $service, $message );

		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $message );
	}
}
