<?php

/**
 * Logger class for smaily plugin logging
 */
class Smaily_Logger
{
    /**
     * Log a message to the WordPress debug log.
     *
     * @param string $message The message to log.
     * @param string $level The log level (e.g., 'info', 'warning', 'error').
     */
    public static function log($message, $level = 'info')
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = sprintf('[%s] %s: %s', current_time('mysql'), strtoupper($level), $message);

            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                $log_dir = SMAILY_PLUGIN_PATH . 'logs';
                $log_file = $log_dir . '/debug.log';
                $htaccess_file = $log_dir . '/.htaccess';

                // Check if the logs directory exists, if not create it.
                if (!is_dir($log_dir)) {
                    mkdir($log_dir, 0755, true);

                    // Create .htaccess file to prevent direct access.
                    $htaccess_content = "Deny from all";
                    file_put_contents($htaccess_file, $htaccess_content);
                }

                // Write the log message to the log file.
                file_put_contents($log_file, $log_message . PHP_EOL, FILE_APPEND);
            }
        }
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
