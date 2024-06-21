<?php

/**
 * Define all the logic related to plugin lifecycle
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_Lifecycle
{

	/**
	 * Callback for plugin activation hook.
	 *
	 */
	public function activate()
	{
		if (class_exists('WooCommerce')) {
			$this->create_woocommerce_tables();
			$this->set_scheduled_actions();
		}

		$this->run_migrations();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Schedule custom actions if they are not already scheduled.
	 *
	 * This method sets up the following scheduled actions:
	 * - Syncing customers daily.
	 * - Tracking abandoned cart statuses every 15 minutes.
	 * - Sending abandoned cart emails every 15 minutes.
	 * Additionally, it flushes the rewrite rules.
	 */
	private function set_scheduled_actions()
	{

		// Check if the daily sync action is already scheduled.
		if (!wp_next_scheduled('smaily_cron_sync_contacts')) {
			// Add Cron job to sync customers.
			wp_schedule_event(time(), 'daily', 'smaily_cron_sync_contacts');
		}

		// Check if the abandoned cart status action is already scheduled.
		if (!wp_next_scheduled('smaily_cron_abandoned_carts_status')) {
			// Schedule event to track abandoned statuses.
			wp_schedule_event(time(), 'smaily_15_minutes', 'smaily_cron_abandoned_carts_status');
		}

		// Check if the abandoned cart email action is already scheduled.
		if (!wp_next_scheduled('smaily_cron_abandoned_carts_email')) {
			// Schedule event to send emails.
			wp_schedule_event(time(), 'smaily_15_minutes', 'smaily_cron_abandoned_carts_email');
		}
	}

	/**
	 * Checks if additional featured plugins are being activated, creates tables if needed and schedules events
	 *
	 */
	public function check_for_dependency($plugin, $network)
	{
		if ($plugin == 'woocommerce/woocommerce.php') {
			$this->create_woocommerce_tables();
			$this->set_scheduled_actions();
		}
	}

	/**
	 * Check if woocommerce related tables have been created, if not create 
	 */
	private function create_woocommerce_tables()
	{
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Create smaily_abandoned_cart table if it does not exist.
		$abandoned_table_name = $wpdb->prefix . 'smaily_abandoned_carts';

		// Check if the table already exists.
		if ($wpdb->get_var("SHOW TABLES LIKE '$abandoned_table_name'") != $abandoned_table_name) {
			$abandoned = "CREATE TABLE $abandoned_table_name (
				customer_id int(11) NOT NULL,
				cart_updated datetime DEFAULT '0000-00-00 00:00:00',
				cart_content longtext DEFAULT NULL,
				cart_status varchar(255) DEFAULT NULL,
				cart_abandoned_time datetime DEFAULT '0000-00-00 00:00:00',
				mail_sent tinyint(1) DEFAULT NULL,
				mail_sent_time datetime DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (customer_id)
			) $charset_collate;";
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($abandoned);
		}
	}


	/**
	 * Callback for plugin deactivation hook.
	 *
	 */
	public function deactivate()
	{
		// Flush rewrite rules.
		flush_rewrite_rules();
		// Stop Cron.
		wp_clear_scheduled_hook('smaily_cron_sync_contacts');
		wp_clear_scheduled_hook('smaily_cron_abandoned_carts_email');
		wp_clear_scheduled_hook('smaily_cron_abandoned_carts_status');
	}

	/**
	 * Callback for plugin uninstall hook.
	 *
	 */
	public function uninstall()
	{
		global $wpdb;

		// Delete Smaily plugin abandoned cart table.
		$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}smaily_abandoned_carts");

		delete_option('smaily_form_options');
		delete_option('smaily_woocommerce_settings');
		delete_option('smaily_db_version');
		delete_transient('smaily_plugin_updated');
	}

	/**
	 * Callback for plugins_loaded hook.
	 *
	 * Start migrations if plugin was updated.
	 *
	 */
	public function update()
	{
		if (get_transient('smaily_plugin_updated') !== true) {
			return;
		}
		$this->run_migrations();
		delete_transient('smaily_plugin_updated');
	}

	/**
	 * Callback for upgrader_process_complete hook.
	 *
	 * Check if our plugin was updated, make a transient option if so.
	 * This alows us to trigger a DB upgrade script if necessary.
	 *
	 * @param Plugin_Upgrader $upgrader_object Instance of WP_Upgrader.
	 * @param array           $options         Array of bulk item update data.
	 */
	public function check_for_update($upgrader_object, $options)
	{
		$smaily_basename = plugin_basename(SMAILY_PLUGIN_FILE);

		$plugin_was_updated = $options['action'] === 'update' && $options['type'] === 'plugin';
		if (!isset($options['plugins']) || !$plugin_was_updated) {
			return;
		}

		// $options['plugins'] is string during single update, array if multiple plugins updated.
		$updated_plugins = (array) $options['plugins'];

		foreach ($updated_plugins as $plugin_basename) {
			if ($smaily_basename === $plugin_basename) {
				return set_transient('smaily_plugin_updated', true);
			}
		}
	}

	/**
	 * Get plugin's DB version, run any migrations the database requires.
	 * Update DB version with current plugin version.
	 *
	 *
	 * @access private
	 */
	private function run_migrations()
	{
		$plugin_version = SMAILY_PLUGIN_VERSION;
		$db_version     = get_option('smaily_db_version', '0.0.0');

		if ($plugin_version === $db_version) {
			return;
		}

		$migrations = array();

		foreach ($migrations as $migration_version => $migration_file) {
			// Database is up-to-date with plugin version.
			if (version_compare($db_version, $migration_version, '>=')) {
				continue;
			}

			$migration_file = SMAILY_PLUGIN_PATH . 'migrations/' . $migration_file;
			if (!file_exists($migration_file)) {
				continue;
			}

			$upgrade = null;
			require_once $migration_file;
			if (is_callable($upgrade)) {
				$upgrade();
			}
		}

		// Migrations finished.
		update_option('smaily_db_version', $plugin_version);
	}
}
