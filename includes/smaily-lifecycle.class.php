<?php

/**
 * Define all the logic related to plugin activation and upgrade logic.
 *
 * @since      1.0.0
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_Lifecycle
{

	/**
	 * Callback for plugin activation hook.
	 *
	 * @since 1.0.0
	 */
	public function activate()
	{
		$this->run_migrations();
	}

	/**
	 * Callback for plugin deactivation hook.
	 *
	 * @since 1.0.0
	 */
	public function deactivate()
	{
		// ... 
	}

	/**
	 * Callback for plugins_loaded hook.
	 *
	 * Start migrations if plugin was updated.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since  1.0.0
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
