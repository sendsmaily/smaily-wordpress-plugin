<?php
/**
 * WordPress Filesystem API does not provide a good functionality to append content to files. We use file_put_contents to append to the debug log.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 */


/**
 * Logger class for smaily plugin logging
 */
class Smaily_Logger {

	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * Folder where logs are stored.
	 *
	 * @var string
	 */
	const FOLDER_NAME = 'smaily_uploads';

	/**
	 * Filename where logs are stored.
	 */
	const FILE_NAME = 'log.txt';

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
	public function info( $message ) {
		self::log( $message, self::LEVEL_INFO, $this->service );
	}

	/**
	 * Log a warning message.
	 *
	 * @param mixed $message
	 * @return void
	 */
	public function warning( $message ) {
		self::log( $message, self::LEVEL_WARNING, $this->service );
	}

	/**
	 * Log an error message.
	 *
	 * @param mixed $message
	 * @return void
	 */
	public function error( $message ) {
		self::log( $message, self::LEVEL_ERROR, $this->service );
	}

	/**
	 * Create folders required for storing log messages.
	 *
	 */
	public static function create_log_folder() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;

		$upload_dir        = wp_upload_dir();
		$smaily_upload_dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . self::FOLDER_NAME;

		wp_mkdir_p( $smaily_upload_dir );

		$htacces_path = $smaily_upload_dir . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! $wp_filesystem->exists( $htacces_path ) ) {
			$wp_filesystem->put_contents( $htacces_path, 'deny from all' );
		}
	}

	/**
	 * Delete log folder.
	 *
	 * @return void
	 */
	public static function delete_log_folder() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;

		$upload_dir        = wp_upload_dir();
		$smaily_upload_dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . self::FOLDER_NAME;

		$wp_filesystem->rmdir( $smaily_upload_dir, true );
	}

	/**
	 * Log a message.
	 *
	 * @param string $message The message to log.
	 * @param string $level The log level (e.g., 'info', 'warning', 'error').
	 * @param string $service The service that logged the message.
	 */
	private static function log( $message, $level, $service ) {
		$message = sprintf( '[%s] [%s] : %s : %s', current_time( 'mysql' ), $service, strtoupper( $level ), $message );

		$upload_dir        = wp_upload_dir();
		$smaily_upload_dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . self::FOLDER_NAME;
		$file              = $smaily_upload_dir . DIRECTORY_SEPARATOR . self::FILE_NAME;

		file_put_contents( $file, $message . PHP_EOL, FILE_APPEND );
	}
}
