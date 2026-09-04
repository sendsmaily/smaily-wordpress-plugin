<?php
/**
 * Integration: the one-time legacy abandoned-cart drain (PRO-1195).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Migration\LegacyCartDrain;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

/**
 * What this pins (upgrade continuity — Erkki's definition-of-done):
 *
 *   - in-flight legacy rows (`mail_sent IS NULL`) land in the new tracker
 *     with the ORIGINAL cart_updated, identity and a scalar item shape —
 *     zero carts lost on a plain plugin update;
 *   - POISON legacy rows (the F3-53 classes: string items, unserializable
 *     content) are skipped with a log, never a crash — treated as wire
 *     input; a string-items cart still drains (items skipped item-level,
 *     the legacy hardening's semantics);
 *   - the drain is READ-ONLY on the legacy table (rows + mail_sent
 *     untouched — safe rollback) and one-time (option stamp);
 *   - a drained recent cart gets its reminder through the NEW pipeline;
 *     a stale one expires under the F3-37 backlog guard without emailing.
 */
final class LegacyCartDrainTest extends TestCase {

	private const LEGACY_TABLE = 'smaily_connect_abandoned_carts';

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset(); // Also clears the smly_plus_cart_legacy_drained stamp.
		$this->ensure_legacy_table();
		$this->wipe_legacy();
		$this->wipe_tracker();
	}

	protected function tearDown(): void {
		$this->wipe_legacy();
		$this->wipe_tracker();
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();
		parent::tearDown();
	}

	public function test_drain_migrates_in_flight_rows_and_survives_poison(): void {
		$fresh_user   = $this->make_user( 'drain-fresh' );
		$poison_user  = $this->make_user( 'drain-string-items' );
		$garbage_user = $this->make_user( 'drain-garbage' );
		$sent_user    = $this->make_user( 'drain-already-sent' );

		$fresh_ts = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// A healthy in-flight cart.
		$this->seed_legacy_cart(
			$fresh_user,
			$fresh_ts,
			serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- seeding the legacy writer's shape.
				array(
					'k1' => array(
						'product_id'   => 11,
						'variation_id' => 0,
						'quantity'     => 2,
					),
				)
			)
		);
		// F3-53 poison (a): items are bare strings — the cart itself drains,
		// items are skipped item-level.
		$this->seed_legacy_cart(
			$poison_user,
			$fresh_ts,
			serialize( array( 'k1' => 'i-am-a-string-not-a-cart-item' ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- deliberate poison shape.
		);
		// F3-53 poison (b): cart_content that never unserializes.
		$this->seed_legacy_cart( $garbage_user, $fresh_ts, 'not-serialized-garbage' );
		// Already-sent row: NOT in-flight, must not drain.
		$this->seed_legacy_cart( $sent_user, $fresh_ts, serialize( array() ), 1 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$stats = ( new LegacyCartDrain() )->maybe_run();

		self::assertNotNull( $stats );
		self::assertSame( 2, $stats['drained'], 'The healthy cart AND the string-items cart drain (items skipped item-level).' );
		self::assertSame( 1, $stats['poison'], 'Unserializable content is counted, logged, skipped — never a crash.' );

		// The healthy row landed with identity + original timestamp + scalar shape.
		$row = $this->tracker_row_for_token( (string) $fresh_user );
		self::assertNotNull( $row );
		self::assertSame( get_userdata( $fresh_user )->user_email, $row['email'] );
		self::assertSame( $fresh_ts, $row['cart_updated'], 'The ORIGINAL cart_updated carries over so cutoff + backlog-guard semantics hold.' );
		self::assertSame(
			array(
				array(
					'product_id'   => 11,
					'variation_id' => 0,
					'quantity'     => 2,
				),
			),
			json_decode( (string) $row['cart_content'], true )
		);

		// String-items cart drained with an empty item list (still emailable).
		$poison_row = $this->tracker_row_for_token( (string) $poison_user );
		self::assertNotNull( $poison_row );
		self::assertSame( array(), json_decode( (string) $poison_row['cart_content'], true ) );

		self::assertNull( $this->tracker_row_for_token( (string) $garbage_user ) );
		self::assertNull( $this->tracker_row_for_token( (string) $sent_user ) );

		// READ-ONLY on the legacy table: every row still there, mail_sent untouched.
		self::assertSame( 4, $this->legacy_count() );
		self::assertNull( $this->legacy_mail_sent( $fresh_user ) );
		self::assertNull( $this->legacy_mail_sent( $garbage_user ) );

		// One-time: the second run is a stamped no-op.
		self::assertNull( ( new LegacyCartDrain() )->maybe_run() );
	}

	public function test_drained_recent_cart_reminds_and_stale_cart_expires_under_the_guard(): void {
		update_option( 'smly_plus_setup_completed', true );
		update_option(
			'smaily_connect_abandoned_cart_status',
			array(
				'enabled'          => true,
				'autoresponder_id' => 88,
			)
		);
		update_option( 'smaily_connect_abandoned_cart_cutoff', 10 );

		$recent_user = $this->make_user( 'drain-recent' );
		$stale_user  = $this->make_user( 'drain-stale' );

		$item = serialize( array( 'k1' => array( 'product_id' => 11, 'quantity' => 1 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->seed_legacy_cart( $recent_user, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), $item );
		$this->seed_legacy_cart( $stale_user, gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ), $item );

		( new LegacyCartDrain() )->maybe_run();
		do_action( 'smly_plus_abandoned_cart' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$entities = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT entity_id FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s",
				CartFlusher::EVENT_TYPE
			)
		);

		self::assertCount( 1, $entities, 'Exactly the recent drained cart earns a reminder; the stale one expires (F3-37).' );
		self::assertNull( $this->tracker_row_for_token( (string) $stale_user ), 'The stale drained row is expired without emailing.' );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * The tests environment never runs the legacy activation, so create the
	 * legacy cart table through the REAL Lifecycle DDL (reflection — no
	 * schema duplication).
	 */
	private function ensure_legacy_table(): void {
		$lifecycle = new \Smaily_Connect\Includes\Lifecycle();
		$method    = new \ReflectionMethod( $lifecycle, 'create_woocommerce_tables' );
		$method->setAccessible( true );
		$method->invoke( $lifecycle );
	}

	private function make_user( string $slug ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_drain_' . $slug . '_' . wp_generate_password( 6, false ),
				'user_email' => $slug . '-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;
		return $user_id;
	}

	private function seed_legacy_cart( int $customer_id, string $cart_updated, string $cart_content, ?int $mail_sent = null ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$wpdb->prefix . self::LEGACY_TABLE,
			array(
				'customer_id'         => $customer_id,
				'cart_updated'        => $cart_updated,
				'cart_content'        => $cart_content,
				'cart_status'         => 'abandoned',
				'cart_abandoned_time' => gmdate( 'Y-m-d H:i:s' ),
				'mail_sent'           => $mail_sent,
			)
		);
		self::assertNotFalse( $inserted, 'Legacy cart seed failed: ' . $wpdb->last_error );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function tracker_row_for_token( string $token ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smly_plus_cart_session WHERE cart_token = %s", $token ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	private function legacy_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::LEGACY_TABLE );
	}

	private function legacy_mail_sent( int $customer_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT mail_sent FROM {$wpdb->prefix}" . self::LEGACY_TABLE . ' WHERE customer_id = %d',
				$customer_id
			)
		);
	}

	private function wipe_legacy(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . self::LEGACY_TABLE );
	}

	private function wipe_tracker(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smly_plus_cart_session" );
	}
}
