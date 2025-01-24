<?php

/**
 * Logger class for smaily plugin logging
 */
class Smaily_Logger {

	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * Table name where logs are stored without the prefix.
	 *
	 * @var string
	 */
	const TABLE_NAME = 'smaily_logs';

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
	 * @param string $message
	 * @return void
	 */
	public function log_info( $message ) {
		self::log( $message, self::LEVEL_INFO, $this->service );
	}

	/**
	 * Log a warning message.
	 *
	 * @param mixed $message
	 * @return void
	 */
	public function log_warning( $message ) {
		self::log( $message, self::LEVEL_WARNING, $this->service );
	}

	/**
	 * Log an error message.
	 *
	 * @param mixed $message
	 * @return void
	 */
	public function log_error( $message ) {
		self::log( $message, self::LEVEL_ERROR, $this->service );
	}

	/**
	 * Log an informational message.
	 *
	 * @param string $message The message to log.
	 */
	public static function info( $message, $service = 'general' ) {
		self::log( $message, self::LEVEL_INFO, $service );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message The message to log.
	 */
	public static function warning( $message, $service = 'general' ) {
		self::log( $message, self::LEVEL_WARNING, $service );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message The message to log.
	 */
	public static function error( $message, $service = 'general' ) {
		self::log( $message, self::LEVEL_ERROR, $service );
	}

	public static function get_log_messages() {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY log_id DESC',
				$wpdb->prefix . self::TABLE_NAME
			),
			'ARRAY_A'
		);
	}

	/**
	 * Create tables required for storing log messages.
	 *
	 * @return bool
	 */
	public static function create_log_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();
		$query           = sprintf(
			'CREATE TABLE %s (
				log_id int NOT NULL AUTO_INCREMENT,
				log_level varchar(255) DEFAULT NULL,
				log_message TEXT DEFAULT NULL,
				log_service varchar(255) DEFAULT NULL,
				log_time DATETIME DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (log_id)
				) %s;
			',
			$table_name,
			$charset_collate
		);

		return maybe_create_table( $table_name, $query );
	}

	/**
	 * Drop log tables.
	 *
	 * @return void
	 */
	public static function drop_log_tables() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->query(
			$wpdb->prepare(
				'DROP TABLE IF EXISTS %s',
				$table_name
			)
		);
	}

	/**
	 * Log a message.
	 *
	 * @param string $message The message to log.
	 * @param string $level The log level (e.g., 'info', 'warning', 'error').
	 * @param string $service The service that logged the message.
	 */
	private static function log( $message, $level, $service ) {
		if ( empty( $message ) || empty( $level ) || empty( $service ) ) {
			// Throw error?
			return;
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i
				( log_level, log_message, log_service )
				VALUES ( %s, %s, %s )',
				$wpdb->prefix . self::TABLE_NAME,
				$level,
				$message,
				$service
			)
		);
	}
}
