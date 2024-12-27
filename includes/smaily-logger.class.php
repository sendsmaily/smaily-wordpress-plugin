<?php

/**
 * Logger class for smaily plugin logging
 */
class Smaily_Logger {

	/**
	 * The table where the logs are stored.
	 *
	 */
	public static $table_name = 'smaily_logs';

	public static function create_log_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;

		$table_name      = $wpdb->prefix . self::$table_name;
		$charset_collate = $wpdb->get_charset_collate();
		$query           = sprintf(
			"CREATE TABLE %s (
				log_id int NOT NULL AUTO_INCREMENT,
				log_level varchar(255) DEFAULT NULL,
				log_message varchar(255) DEFAULT NULL,
				log_service varchar(255) DEFAULT NULL,
				log_time DATETIME DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY (log_id)
				) %s;
			",
			$table_name,
			$charset_collate
		);

		return maybe_create_table( $table_name, $query );
	}

	public static function drop_log_tables() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::$table_name;
		$wpdb->query(
			$wpdb->prepare(
				'DROP TABLE IF EXISTS %s',
				$table_name
			)
		);
	}

	/**
	 * Log an informational message.
	 *
	 * @param string $message The message to log.
	 */
	public static function info( $message, $service = 'general' ) {
		self::log( $message, 'info', $service );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message The message to log.
	 */
	public static function warning( $message, $service = 'general' ) {
		self::log( $message, 'warning', $service );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message The message to log.
	 */
	public static function error( $message, $service = 'general' ) {
		self::log( $message, 'error', $service );
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
				( log_level, log_message, log_service, log_time )
				VALUES ( %s, %s, %s, %s )',
				$wpdb->prefix . self::$table_name,
				$level,
				$message,
				$service,
				current_time( 'mysql' )
			)
		);
	}
}
