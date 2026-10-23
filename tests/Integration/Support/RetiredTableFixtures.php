<?php
/**
 * Test-support helper — recreates the two tables schema migration 012 drops
 * (CON-182), with the DDL the versions that created them used.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- test fixture DDL on the plugin's own retired tables.

/**
 * The plugin no longer creates either table, so a test of the upgrade path
 * builds them the way an older install has them: the legacy cart table as
 * 1.x / 2.0.0's Lifecycle::create_woocommerce_tables() made it, the visitor
 * table as schema migration 005 made it.
 */
final class RetiredTableFixtures {

	public const LEGACY_CART_TABLE = 'smaily_connect_abandoned_carts';

	public const VISITOR_TABLE = 'smly_rec_visitor';

	public const CART_TRACKER_TABLE = 'smly_plus_cart_session';

	private const HIDDEN_CART_TRACKER_TABLE = 'smly_plus_cart_session_hidden';

	public static function create_legacy_cart_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::LEGACY_CART_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				customer_id int(11) NOT NULL,
				cart_updated datetime DEFAULT '0000-00-00 00:00:00',
				cart_content longtext DEFAULT NULL,
				cart_status varchar(255) DEFAULT NULL,
				cart_abandoned_time datetime DEFAULT '0000-00-00 00:00:00',
				mail_sent tinyint(1) DEFAULT NULL,
				mail_sent_time datetime DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (customer_id)
			) {$wpdb->get_charset_collate()}"
		);
	}

	public static function create_visitor_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::VISITOR_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				visitor_id CHAR(36) NOT NULL,
				email VARCHAR(255) DEFAULT NULL,
				identified_at DATETIME DEFAULT NULL,
				identified_source VARCHAR(32) DEFAULT NULL,
				first_seen_at DATETIME NOT NULL,
				last_seen_at DATETIME NOT NULL,
				PRIMARY KEY  (visitor_id),
				KEY idx_email (email)
			) {$wpdb->get_charset_collate()}"
		);
	}

	public static function drop_both(): void {
		global $wpdb;
		foreach ( array( self::LEGACY_CART_TABLE, self::VISITOR_TABLE ) as $suffix ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$suffix}" );
		}
	}

	/**
	 * Move the cart tracker (`smly_plus_cart_session`) out of the way, so a
	 * test sees a store whose tracker table is missing (CON-207). Its rows
	 * are kept; restore_cart_tracker() puts it back.
	 */
	public static function hide_cart_tracker(): void {
		global $wpdb;
		$wpdb->query( "RENAME TABLE {$wpdb->prefix}" . self::CART_TRACKER_TABLE . " TO {$wpdb->prefix}" . self::HIDDEN_CART_TRACKER_TABLE );
	}

	/**
	 * Undo hide_cart_tracker(). A no-op when the tracker was not hidden.
	 */
	public static function restore_cart_tracker(): void {
		global $wpdb;
		if ( ! self::exists( self::HIDDEN_CART_TRACKER_TABLE ) ) {
			return;
		}
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::CART_TRACKER_TABLE );
		$wpdb->query( "RENAME TABLE {$wpdb->prefix}" . self::HIDDEN_CART_TRACKER_TABLE . " TO {$wpdb->prefix}" . self::CART_TRACKER_TABLE );
	}

	public static function exists( string $suffix ): bool {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * One legacy cart row in the 2.0.0 writer's shape (serialized WC cart).
	 */
	public static function seed_legacy_cart( int $customer_id, string $cart_updated, ?int $mail_sent = null ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::LEGACY_CART_TABLE,
			array(
				'customer_id'  => $customer_id,
				'cart_updated' => $cart_updated,
				'cart_content' => serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the legacy writer's shape.
					array(
						'k1' => array(
							'product_id'   => 11,
							'variation_id' => 0,
							'quantity'     => 1,
						),
					)
				),
				'cart_status'  => 'abandoned',
				'mail_sent'    => $mail_sent,
			)
		);
	}
}
