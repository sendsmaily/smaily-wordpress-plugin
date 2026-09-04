<?php

namespace Smaily_Connect\Includes;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/woocommerce/cart.class.php';

use Smaily_Connect\Integrations\WooCommerce\Cart;

class Lifecycle {
	/**
	 * Service name.
	 * @var string
	 */
	const SERVICE = 'lifecycle';

	/**
	 * List of migrations.
	 * @var array<string,string> Version and file path.
	 */
	const MIGRATIONS = array(
		'1.3.0' => 'upgrade-1-3-0.php',
	);

	/**
	 * Logger.
	 * @var Logger
	 */
	private $logger;

	public function __construct() {
		$this->logger = new Logger( self::SERVICE );
	}

	/**
	 * Register hooks for the plugin lifecycle.
	 *
	 * @return void
	 */
	public function register_hooks() {
		register_activation_hook( SMAILY_CONNECT_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( SMAILY_CONNECT_PLUGIN_FILE, array( $this, 'deactivate' ) );
		register_uninstall_hook( SMAILY_CONNECT_PLUGIN_FILE, array( __CLASS__, 'uninstall' ) );
		add_action( 'init', array( $this, 'set_locale' ) );
		add_action( 'plugins_loaded', array( $this, 'update' ) );
		add_action( 'upgrader_process_complete', array( $this, 'check_for_update' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'check_for_dependency' ), 10, 2 );
	}

	/**
	 * Callback for plugin activation hook.
	 *
	 */
	public function activate() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->create_woocommerce_tables();

			// Set to flush rewrite rules during init action if not yet flushed.
			update_option( 'smaily_connect_flush_rewrite_rules', true );
		}

		$this->run_migrations();
		$this->logger->info( 'Plugin activated' );
	}

	/*
	 * NOTE (F3-53): this class used to (re)schedule the legacy WP-Cron events
	 * (smaily_connect_cron_sync_subscribers / _abandoned_carts_status /
	 * _abandoned_carts_email) here and in check_for_dependency(). Scheduling
	 * is owned by Action Scheduler now (Bootstrap's smly_plus_* recurring
	 * actions bridge to the same hook names); the WP-Cron re-arm ran AFTER
	 * WPCronAuditor's activation-time clear whenever WooCommerce was
	 * (re)activated, resurrecting a duplicate scheduler — including the daily
	 * legacy subscriber mass-send (the F3-47 language-clobber path). The
	 * deactivate() clears below stay, as defense against residue.
	 */

	/**
	 * Checks if additional featured plugins are being activated, creates tables if needed
	 *
	 */
	public function check_for_dependency( $plugin, $network ) {
		if ( $plugin === 'woocommerce/woocommerce.php' ) {
			$this->create_woocommerce_tables();
			update_option( 'smaily_connect_flush_rewrite_rules', true );
		}
	}

	/**
	 * Check if woocommerce related tables have been created, if not create
	 */
	private function create_woocommerce_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Create abandoned_cart table if it does not exist.
		$abandoned_table_name = $wpdb->prefix . Cart::ABANDONED_CART_TABLE_NAME;
		$query                = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $abandoned_table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Check if the table already exists.
		if ( $query !== $abandoned_table_name ) {
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
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $abandoned );
		}
	}

	/**
	 * Callback for plugin deactivation hook.
	 *
	 */
	public function deactivate() {
		// Flush rewrite rules.
		flush_rewrite_rules();
		// Stop Cron.
		wp_clear_scheduled_hook( 'smaily_connect_cron_sync_subscribers' );
		wp_clear_scheduled_hook( 'smaily_connect_cron_abandoned_carts_email' );
		wp_clear_scheduled_hook( 'smaily_connect_cron_abandoned_carts_status' );
		$this->logger->info( 'Plugin deactivated' );
	}

	/**
	 * Callback for plugin uninstall hook.
	 *
	 */
	public static function uninstall() {
		global $wpdb;

		// Delete Smaily plugin abandoned cart table.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name is a plugin constant
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . Cart::ABANDONED_CART_TABLE_NAME . '`' );

		Options::delete_all_options();

		delete_transient( 'smaily_connect_plugin_updated' );
	}

	/**
	 * Callback for plugins_loaded hook.
	 *
	 * Start migrations if plugin was updated.
	 *
	 */
	public function update() {
		if ( (bool) get_transient( 'smaily_connect_plugin_updated' ) !== true ) {
			return;
		}
		$this->run_migrations();
		delete_transient( 'smaily_connect_plugin_updated' );
	}

	/**
	 * Callback for upgrader_process_complete hook.
	 *
	 * Check if our plugin was updated, make a transient option if so.
	 * This allows us to trigger a DB upgrade script if necessary.
	 *
	 * @param \Plugin_Upgrader $upgrader_object Instance of WP_Upgrader.
	 * @param array           $options         Array of bulk item update data.
	 */
	public function check_for_update( $upgrader_object, $options ) {
		$smaily_basename = plugin_basename( SMAILY_CONNECT_PLUGIN_FILE );

		$plugin_was_updated = $options['action'] === 'update' && $options['type'] === 'plugin';
		if ( ! isset( $options['plugins'] ) || ! $plugin_was_updated ) {
			return;
		}

		// $options['plugins'] is string during single update, array if multiple plugins updated.
		$updated_plugins = (array) $options['plugins'];

		foreach ( $updated_plugins as $plugin_basename ) {
			if ( $smaily_basename === $plugin_basename ) {
				return set_transient( 'smaily_connect_plugin_updated', true );
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
	private function run_migrations() {
		$plugin_version = SMAILY_CONNECT_PLUGIN_VERSION;
		$db_version     = get_option( Options::DATABASE_VERSION_OPTION, '0.0.0' );

		if ( $plugin_version === $db_version ) {
			return;
		}

		foreach ( self::MIGRATIONS as $migration_version => $migration_file ) {
			// Database is up-to-date with plugin version.
			if ( version_compare( $db_version, $migration_version, '>=' ) ) {
				continue;
			}

			$migration_file = SMAILY_CONNECT_PLUGIN_PATH . 'migrations/' . $migration_file;
			if ( ! file_exists( $migration_file ) ) {
				continue;
			}

			/** @var callable|null $upgrade */
			$upgrade = null;
			require_once $migration_file;
			if ( is_callable( $upgrade ) ) {
				$upgrade();
			}
		}

		// Migrations finished.
		update_option( Options::DATABASE_VERSION_OPTION, $plugin_version );
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Smaily_I18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 */
	public function set_locale() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- explicit load retained for the private-ZIP distribution; redundant-but-harmless on wp.org (WP 4.6+ auto-loads).
		load_plugin_textdomain(
			'smaily-connect',
			false,
			plugin_basename( SMAILY_CONNECT_PLUGIN_PATH ) . '/languages/'
		);
	}
}
