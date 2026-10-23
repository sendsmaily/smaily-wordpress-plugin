<?php
/**
 * Integration: schema migration 012 drops the legacy abandoned-cart table and
 * the unused smly_rec_visitor table (CON-182).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Constants;
use Smaily\Connect\DB\Migrator;
use Smaily\Connect\Privacy\GdprHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RetiredTableFixtures;

/**
 * What this pins:
 *
 *   - a fresh install creates neither table, and the schema version reaches
 *     the highest migration file;
 *   - an update from a store that still has both tables drops them through
 *     the real upgrade entry point (admin_init → UpgradeLock → Activation),
 *     after draining the legacy carts a 1.x / 2.0.0 store never drained;
 *   - a store that drained on an earlier version is not drained twice;
 *   - a second run of the migration is a clean no-op;
 *   - the personal-data exporter and eraser answer the same for a customer
 *     before and after the drop (neither ever read either table).
 */
final class RetiredTablesMigrationTest extends TestCase {

	private const DRAINED_OPTION = 'smly_plus_cart_legacy_drained';

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
	}

	protected function tearDown(): void {
		global $wpdb;
		RetiredTableFixtures::drop_both();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smly_plus_cart_session" );
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();
		// Leave a fully migrated, version-stamped state for the rest of the
		// suite — the same cache-independent re-run BackwardCompatTest uses.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Constants::OPTION_SCHEMA_VERSION ) );
		wp_cache_flush();
		Activation::run();
		parent::tearDown();
	}

	public function test_a_fresh_install_creates_neither_table(): void {
		global $wpdb;
		RetiredTableFixtures::drop_both();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Constants::OPTION_SCHEMA_VERSION ) );
		wp_cache_flush();

		Activation::run();

		self::assertFalse( RetiredTableFixtures::exists( RetiredTableFixtures::LEGACY_CART_TABLE ), 'A fresh install must not create the legacy abandoned-cart table.' );
		self::assertFalse( RetiredTableFixtures::exists( RetiredTableFixtures::VISITOR_TABLE ), 'A fresh install must not create smly_rec_visitor.' );
		self::assertSame( $this->highest_migration(), (int) get_option( Constants::OPTION_SCHEMA_VERSION ) );
		self::assertSame( 12, $this->highest_migration(), 'Migration 012 is the drop.' );
	}

	public function test_an_update_drains_the_legacy_carts_then_drops_both_tables(): void {
		$this->arrange_store_before_migration_012();
		$user = $this->make_user( 'never-drained' );
		RetiredTableFixtures::seed_legacy_cart( $user, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		Bootstrap::instance()->maybe_run_upgrade();

		self::assertFalse( RetiredTableFixtures::exists( RetiredTableFixtures::LEGACY_CART_TABLE ), 'The update drops the legacy abandoned-cart table.' );
		self::assertFalse( RetiredTableFixtures::exists( RetiredTableFixtures::VISITOR_TABLE ), 'The update drops smly_rec_visitor.' );
		self::assertSame( 12, (int) get_option( Constants::OPTION_SCHEMA_VERSION ) );
		self::assertNotNull( $this->tracker_row( (string) $user ), 'The in-flight legacy cart was drained into the tracker before the drop.' );
		self::assertFalse( get_option( self::DRAINED_OPTION ), 'The drain stamp is removed with the table it guarded.' );
	}

	public function test_a_store_that_already_drained_is_not_drained_again(): void {
		$this->arrange_store_before_migration_012();
		update_option( self::DRAINED_OPTION, '2026-09-04 00:00:00', false );
		$user = $this->make_user( 'already-drained' );
		RetiredTableFixtures::seed_legacy_cart( $user, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		Bootstrap::instance()->maybe_run_upgrade();

		self::assertFalse( RetiredTableFixtures::exists( RetiredTableFixtures::LEGACY_CART_TABLE ) );
		self::assertNull( $this->tracker_row( (string) $user ), 'A drained store keeps its earlier drain — no second copy of an old cart.' );
	}

	public function test_a_second_run_is_a_clean_no_op(): void {
		global $wpdb;
		$this->arrange_store_before_migration_012();
		( new Migrator() )->migrate();

		update_option( Constants::OPTION_SCHEMA_VERSION, 11, false );
		$applied = ( new Migrator() )->migrate();

		self::assertSame( array( 12 ), $applied );
		self::assertSame( '', $wpdb->last_error );
		self::assertFalse( RetiredTableFixtures::exists( RetiredTableFixtures::LEGACY_CART_TABLE ) );
		self::assertFalse( RetiredTableFixtures::exists( RetiredTableFixtures::VISITOR_TABLE ) );
		self::assertSame( 12, (int) get_option( Constants::OPTION_SCHEMA_VERSION ) );
	}

	public function test_the_personal_data_export_is_the_same_before_and_after_the_drop(): void {
		$this->arrange_store_before_migration_012();
		update_option( self::DRAINED_OPTION, '2026-09-04 00:00:00', false );
		$user  = $this->make_user( 'gdpr-export' );
		$email = (string) get_userdata( $user )->user_email;
		RetiredTableFixtures::seed_legacy_cart( $user, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->seed_tracker_row( $user, $email );

		$before = $this->handler()->export( $email );
		Bootstrap::instance()->maybe_run_upgrade();
		$after = $this->handler()->export( $email );

		self::assertFalse( RetiredTableFixtures::exists( RetiredTableFixtures::LEGACY_CART_TABLE ) );
		self::assertNotEmpty( $before['data'], 'The customer has exportable data (the tracker row).' );
		self::assertSame( $before, $after );
	}

	public function test_the_personal_data_erasure_is_the_same_before_and_after_the_drop(): void {
		$this->arrange_store_before_migration_012();
		update_option( self::DRAINED_OPTION, '2026-09-04 00:00:00', false );
		$user  = $this->make_user( 'gdpr-erase' );
		$email = (string) get_userdata( $user )->user_email;
		RetiredTableFixtures::seed_legacy_cart( $user, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$this->seed_tracker_row( $user, $email );
		$before = $this->handler()->erase( $email );

		$this->seed_tracker_row( $user, $email );
		Bootstrap::instance()->maybe_run_upgrade();
		$after = $this->handler()->erase( $email );

		self::assertFalse( RetiredTableFixtures::exists( RetiredTableFixtures::LEGACY_CART_TABLE ) );
		self::assertTrue( $before['items_removed'], 'The eraser removed the tracker row.' );
		self::assertSame( $before, $after );
		self::assertNull( $this->tracker_row( 'gdpr-token' ) );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * A store on schema version 11 that still has both tables, with a
	 * trailing version stamp — what a file-overwrite update leaves behind.
	 */
	private function arrange_store_before_migration_012(): void {
		RetiredTableFixtures::create_legacy_cart_table();
		RetiredTableFixtures::create_visitor_table();
		update_option( Constants::OPTION_SCHEMA_VERSION, 11, false );
		update_option( Activation::OPTION_PLUGIN_VERSION, '0.0.0-pre' );
	}

	private function highest_migration(): int {
		$highest = 0;
		foreach ( (array) glob( SMAILY_CONNECT_PLUGIN_PATH . 'migrations/*' ) as $file ) {
			if ( preg_match( '/^(\d+)-.+\.(sql|php)$/', basename( (string) $file ), $m ) === 1 ) {
				$highest = max( $highest, (int) $m[1] );
			}
		}
		return $highest;
	}

	private function make_user( string $slug ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_con182_' . $slug . '_' . wp_generate_password( 6, false ),
				'user_email' => $slug . '-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;
		return $user_id;
	}

	private function seed_tracker_row( int $user_id, string $email ): void {
		( new CartSessionStore() )->upsert(
			'gdpr-token',
			$user_id,
			$email,
			'Ada',
			'Lovelace',
			array(
				array(
					'product_id'   => 11,
					'variation_id' => 0,
					'quantity'     => 1,
				),
			),
			'2026-10-20 10:00:00'
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function tracker_row( string $token ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smly_plus_cart_session WHERE cart_token = %s", $token ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	private function handler(): GdprHandler {
		$settings = new RecEngineSettings();
		return new GdprHandler(
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			},
			new CartSessionStore(),
			new EventQueue(),
			new IngestQueue()
		);
	}
}
