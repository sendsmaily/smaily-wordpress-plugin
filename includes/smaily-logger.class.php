<?php
/**
 * We use file operations outside WP to make directories in recursive mode and appending logs to file end instead of writing empty files.
 * 
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 */

/**
 * Logger class for smaily plugin logging
 */
class Smaily_Logger
{

    private const LOG_DIR = SMAILY_PLUGIN_PATH . 'logs';

    private const LOG_FILE = self::LOG_DIR . '/debug.log';

    private const HTACCESS_FILE = self::LOG_DIR . '/.htaccess';

    /**
     * Log a message to the WordPress debug log.
     *
     * @param string $message The message to log.
     * @param string $level The log level (e.g., 'info', 'warning', 'error').
     */
    public static function log($message, $level = 'info')
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return;
        }

        global $wp_filesystem;
        WP_Filesystem();

        $log_message = sprintf('[%s] %s: %s', current_time('mysql'), strtoupper($level), $message);

        // Check if the logs directory exists, if not create it.
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0755, true);

            // Create .htaccess file to prevent direct access.
            $htaccess_content = "Deny from all";
            file_put_contents(self::HTACCESS_FILE, $htaccess_content);
        }

        // Write the log message to the log file.
        file_put_contents(self::LOG_FILE, $log_message . PHP_EOL, FILE_APPEND);
}

    /**
     * Log an informational message.
     *
     * @param string $message The message to log.
     */
    public static function info($message)
    {
        self::log($message, 'info');
    }

    /**
     * Log a warning message.
     *
     * @param string $message The message to log.
     */
    public static function warning($message)
    {
        self::log($message, 'warning');
    }

    /**
     * Log an error message.
     *
     * @param string $message The message to log.
     */
    public static function error($message)
    {
        self::log($message, 'error');
    }
}
