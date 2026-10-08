<?php
/**
 * Unit: GdprHandler's cart-session coverage (PRO-1343) — the WP Privacy
 * exporter/eraser also surfaces/removes `smly_plus_cart_session` rows, not
 * just rec-engine data. The engine + order-meta paths already have
 * integration coverage (RecEngineGdprTest); this file isolates the new
 * cart-session logic with a fake CartSessionStore double, mirroring the
 * fake-store pattern in CartAbandonmentSweeperTest.
 *
 * @package Smaily\Connect\Tests\Unit\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Privacy\GdprHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class GdprHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( '_n' )->alias(
			static fn ( string $single, string $plural, int $number ): string => 1 === $number ? $single : $plural
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_export_includes_a_cart_session_row(): void {
		$store = $this->fake_store(
			array(
				array(
					'id'                   => 5,
					'cart_token'           => 'tok-1',
					'user_id'              => 0,
					'email'                => 'shopper@example.test',
					'first_name'           => 'Jane',
					'last_name'            => 'Doe',
					'cart_content'         => '[{"product_id":123,"variation_id":0,"quantity":2}]',
					'cart_updated'         => '2026-07-14 10:00:00',
					'reminder_enqueued_at' => null,
					'created_at'           => '2026-07-14 09:00:00',
				),
			)
		);

		$export = $this->handler( $store )->export( 'shopper@example.test' );
		$item   = $this->find_group_item( $export, 'Abandoned-cart session' );

		self::assertNotNull( $item, 'cart-session row surfaced under its own group label.' );
		self::assertSame( 'tok-1', $this->field( $item, 'cart_token' ) );
		self::assertSame( '[{"product_id":123,"variation_id":0,"quantity":2}]', $this->field( $item, 'cart_content' ) );
	}

	public function test_export_omits_the_id_column_and_empty_fields(): void {
		$store = $this->fake_store(
			array(
				array(
					'id'                   => 9,
					'cart_token'           => 'tok-2',
					'user_id'              => 0,
					'email'                => 'shopper2@example.test',
					'first_name'           => '',
					'last_name'            => '',
					'cart_content'         => '[]',
					'cart_updated'         => '2026-07-14 10:00:00',
					'reminder_enqueued_at' => null,
					'created_at'           => '2026-07-14 09:00:00',
				),
			)
		);

		$export = $this->handler( $store )->export( 'shopper2@example.test' );
		$item   = $this->find_group_item( $export, 'Abandoned-cart session' );
		self::assertNotNull( $item );

		$names = array_column( $item['data'], 'name' );
		self::assertNotContains( 'id', $names, 'the internal row id is not subject-access data.' );
		self::assertNotContains( 'first_name', $names, 'empty column omitted.' );
		self::assertNotContains( 'reminder_enqueued_at', $names, 'null column omitted.' );
	}

	public function test_export_resolves_the_wp_user_id_for_the_store_lookup(): void {
		Functions\when( 'get_user_by' )->alias(
			static fn ( string $field, $value ) => $field === 'email' && $value === 'shopper@example.test'
				? new class() extends \WP_User {
					public function __construct() {
						$this->ID         = 7;
						$this->user_email = 'Shopper@Example.test'; // Any letter case is the same address.
					}
				}
				: false
		);
		$store = $this->fake_store( array() );

		$this->handler( $store )->export( 'shopper@example.test' );

		self::assertSame(
			array(
				'email'   => 'shopper@example.test',
				'user_id' => 7,
			),
			$store->lookup_calls[0] ?? null
		);
	}

	public function test_export_uses_user_id_zero_when_no_wp_user_matches(): void {
		$store = $this->fake_store( array() );

		$this->handler( $store )->export( 'nobody@example.test' );

		self::assertSame(
			array(
				'email'   => 'nobody@example.test',
				'user_id' => 0,
			),
			$store->lookup_calls[0] ?? null
		);
	}

	public function test_erase_removes_cart_session_rows_and_reports_removed(): void {
		$store                = $this->fake_store( array() );
		$store->delete_return = 2;

		$result = $this->handler( $store )->erase( 'shopper@example.test' );

		self::assertTrue( $result['items_removed'] );
		self::assertSame(
			array(
				'email'   => 'shopper@example.test',
				'user_id' => 0,
			),
			$store->delete_calls[0] ?? null
		);
	}

	public function test_export_lists_queued_smaily_messages_without_their_payload(): void {
		$queue = $this->fake_queue(
			array(
				array(
					'id'         => 12,
					'event_type' => 'automation.abandoned_cart',
					'status'     => 'sent',
					'created_at' => '2026-09-01 08:00:00',
				),
			)
		);

		$export = $this->handler( $this->fake_store( array() ), $queue )->export( 'erase-me@example.test' );
		$item   = $this->find_group_item( $export, 'Queued Smaily message' );

		self::assertNotNull( $item );
		self::assertSame( 'automation.abandoned_cart', $this->field( $item, 'event_type' ) );
		self::assertSame( '2026-09-01 08:00:00', $this->field( $item, 'created_at' ) );
		self::assertSame( array( 'event_type', 'created_at' ), array_column( $item['data'], 'name' ) );
	}

	public function test_erase_reports_the_deleted_and_the_anonymised_queue_rows_apart(): void {
		// PRO-2383: two different outcomes — a queued message is gone, an
		// already-sent record stays in the Event Log with nothing personal in it.
		$queue = $this->fake_queue( array(), 2, 1 );

		$result = $this->handler( $this->fake_store( array() ), $queue )->erase( 'erase-me@example.test' );

		self::assertTrue( $result['items_removed'] );
		self::assertFalse( $result['items_retained'], 'What stays is anonymised, so nothing personal is retained.' );
		self::assertSame( array( 'erase-me@example.test' ), $queue->erase_calls );
		self::assertSame(
			array(
				'Removed 2 Smaily messages that were still queued for this address.',
				'Anonymised 1 already-sent Smaily record in the event log.',
			),
			$result['messages']
		);
	}

	public function test_erase_reports_false_when_nothing_was_removed_anywhere(): void {
		$store = $this->fake_store( array() );
		// delete_return defaults to 0; engine disconnected + no order-meta/user-merge stubs.

		$result = $this->handler( $store )->erase( 'nobody@example.test' );

		self::assertFalse( $result['items_removed'] );
	}

	public function test_erase_reports_the_deleted_ingest_queue_rows_as_removed(): void {
		// PRO-2384: the Campaign Intelligence queue's sent copies, deleted
		// whether or not the engine is connected.
		$ingest = $this->fake_ingest_queue( 2 );

		$result = $this->handler( $this->fake_store( array() ), null, array(), $ingest )->erase( 'erase-me@example.test' );

		self::assertTrue( $result['items_removed'] );
		self::assertSame( array( 'erase-me@example.test' ), $ingest->erase_calls );
	}

	public function test_erase_drops_the_waiting_updates_of_the_user_and_the_orders_billed_to_the_address(): void {
		// PRO-3906: the WP user with the address, and its orders, by entity id.
		Functions\when( 'get_user_by' )->alias(
			static fn ( string $field, $value ) => $field === 'email' && $value === 'erase-me@example.test'
				? new class() extends \WP_User {
					public function __construct() {
						$this->ID         = 7;
						$this->user_email = 'erase-me@example.test';
					}
				}
				: false
		);
		$ingest = $this->fake_ingest_queue( 0, 3 );
		$orders = array( $this->fake_order( 11, '11', array() ), $this->fake_order( 12, '12', array() ) );

		$result = $this->handler( $this->fake_store( array() ), null, $orders, $ingest )->erase( 'erase-me@example.test' );

		self::assertTrue( $result['items_removed'] );
		self::assertSame(
			array(
				array( 'customer.upsert', array( 7 ) ),
				array( 'order.upsert', array( 11, 12 ) ),
			),
			$ingest->unsent_calls
		);
	}

	public function test_a_wp_user_whose_address_differs_by_an_accent_is_not_the_requester(): void {
		// PRO-3986: get_user_by() matches accent-blind on the usual collations.
		Functions\when( 'get_user_by' )->alias(
			static fn ( string $field, $value ) => $field === 'email'
				? new class() extends \WP_User {
					public function __construct() {
						$this->ID         = 8;
						$this->user_email = 'jäne@example.com';
					}
				}
				: false
		);
		$store  = $this->fake_store( array() );
		$ingest = $this->fake_ingest_queue( 0, 1 );

		$this->handler( $store, null, array(), $ingest )->erase( 'jane@example.com' );

		self::assertSame( array(), $ingest->unsent_calls, 'The other person\'s waiting updates stay.' );
		self::assertSame( 0, $store->delete_calls[0]['user_id'] ?? null, 'The other person\'s cart is not matched by user id.' );
	}

	public function test_erase_drops_no_waiting_update_when_no_user_or_order_has_the_address(): void {
		$ingest = $this->fake_ingest_queue( 0, 5 );

		$result = $this->handler( $this->fake_store( array() ), null, array(), $ingest )->erase( 'nobody@example.test' );

		self::assertFalse( $result['items_removed'] );
		self::assertSame( array(), $ingest->unsent_calls );
	}

	public function test_export_states_the_newsletter_consent_kept_on_each_marked_order(): void {
		// PRO-3426: the block checkout keeps the tick as order meta (PRO-3406).
		$marked  = $this->fake_order( 101, '1001', array( HookHandler::ORDER_META_NEWSLETTER_OPTIN => '1' ) );
		$plain   = $this->fake_order( 102, '1002', array() );
		$handler = $this->handler( $this->fake_store( array() ), null, array( $marked, $plain ) );

		$export = $handler->export( 'shopper@example.test' );
		$items  = array_values(
			array_filter(
				$export['data'],
				static fn ( array $item ): bool => $item['group_label'] === 'Newsletter consent (order meta)'
			)
		);

		self::assertCount( 1, $items, 'One item per marked order; the unmarked order has none.' );
		self::assertSame( 'smaily-connect-rec-engine', $items[0]['group_id'], 'The plugin\'s existing export group.' );
		self::assertSame( 'smaily-connect-rec-engine-newsletter-optin-order-101', $items[0]['item_id'] );
		self::assertSame( '1001', $this->field( $items[0], 'Order' ) );
		self::assertSame( 'Yes', $this->field( $items[0], 'Newsletter consent given at checkout' ) );
		self::assertSame( array( 'shopper@example.test' ), $handler->order_lookups );
	}

	public function test_erase_removes_the_newsletter_consent_marker_and_leaves_unmarked_orders_alone(): void {
		$marked  = $this->fake_order( 201, '2001', array( HookHandler::ORDER_META_NEWSLETTER_OPTIN => '1' ) );
		$plain   = $this->fake_order( 202, '2002', array() );
		$handler = $this->handler( $this->fake_store( array() ), null, array( $marked, $plain ) );

		$result = $handler->erase( 'shopper@example.test' );

		self::assertTrue( $result['items_removed'], 'The eraser reports the marker as removed.' );
		self::assertSame( '', $marked->get_meta( HookHandler::ORDER_META_NEWSLETTER_OPTIN ) );
		self::assertSame( array( HookHandler::ORDER_META_NEWSLETTER_OPTIN ), $marked->deleted );
		self::assertSame( 1, $marked->saves );
		self::assertSame( array(), $plain->deleted, 'An order without the marker is untouched.' );
		self::assertSame( 0, $plain->saves );
		self::assertSame( array( 'shopper@example.test' ), $handler->order_lookups );
	}

	public function test_hpos_order_lookup_reads_wc_orders_in_every_status_and_any_letter_case(): void {
		// PRO-3908: no status filter, LOWER() on both sides. PRO-3986: then
		// compared as bytes, so an accent is a different address. The
		// integration site runs legacy storage, so this is the HPOS path's
		// only pin.
		self::assertSame(
			'SELECT id FROM wp_wc_orders WHERE CAST( LOWER( billing_email ) AS BINARY ) = CAST( LOWER( %s ) AS BINARY ) ORDER BY id ASC',
			GdprHandler::order_ids_sql( true, 'wp_' )
		);
	}

	public function test_legacy_order_lookup_reads_the_billing_email_meta_in_every_status_and_any_letter_case(): void {
		self::assertSame(
			"SELECT p.ID FROM wp_posts p INNER JOIN wp_postmeta m ON m.post_id = p.ID WHERE m.meta_key = '_billing_email' AND CAST( LOWER( m.meta_value ) AS BINARY ) = CAST( LOWER( %s ) AS BINARY ) ORDER BY p.ID ASC",
			GdprHandler::order_ids_sql( false, 'wp_' )
		);
	}

	// --- helpers -------------------------------------------------------

	/**
	 * An order double carrying the given meta; records deletes and saves.
	 *
	 * @param array<string, string> $meta
	 */
	private function fake_order( int $id, string $number, array $meta ): \WC_Order {
		return new class( $id, $number, $meta ) extends \WC_Order {
			private int $id;
			private string $number;

			/** @var array<string, string> */
			private array $meta;

			/** @var array<int, string> */
			public array $deleted = array();

			public int $saves = 0;

			/**
			 * @param array<string, string> $meta
			 */
			public function __construct( int $id, string $number, array $meta ) {
				$this->id     = $id;
				$this->number = $number;
				$this->meta   = $meta;
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_order_number(): string {
				return $this->number;
			}

			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				return $this->meta[ $key ] ?? '';
			}

			public function delete_meta_data( $key ): void {
				$this->deleted[] = $key;
				unset( $this->meta[ $key ] );
			}

			public function save() {
				++$this->saves;
				return $this->id;
			}
		};
	}


	/**
	 * @param \WC_Order[] $orders What the order lookup returns — the unit suite
	 *                            cannot define `wc_get_orders` (it would leak).
	 */
	private function handler( CartSessionStore $store, ?EventQueue $queue = null, array $orders = array(), ?IngestQueue $ingest = null ): GdprHandler {
		$settings = $this->createMock( RecEngineSettings::class );
		$settings->method( 'is_connected' )->willReturn( false );
		$settings->method( 'sending_allowed' )->willReturn( false );

		return new class(
			$settings,
			static function (): Client {
				throw new \RuntimeException( 'engine must not be called while disconnected' );
			},
			$store,
			$queue ?? $this->fake_queue(),
			$ingest ?? $this->fake_ingest_queue( 0 ),
			$orders
		) extends GdprHandler {
			/** @var \WC_Order[] */
			private array $orders;

			/** @var array<int, string> */
			public array $order_lookups = array();

			/**
			 * @param \WC_Order[] $orders
			 */
			public function __construct( RecEngineSettings $settings, callable $client_factory, CartSessionStore $cart_store, EventQueue $event_queue, IngestQueue $ingest_queue, array $orders ) {
				parent::__construct( $settings, $client_factory, $cart_store, $event_queue, $ingest_queue );
				$this->orders = $orders;
			}

			protected function orders_for( string $email ): array {
				$this->order_lookups[] = $email;
				return $this->orders;
			}
		};
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function fake_queue( array $rows = array(), int $removed = 0, int $redacted = 0 ): EventQueue {
		return new class( $rows, $removed, $redacted ) extends EventQueue {
			/** @var array<int, array<string, mixed>> */
			private array $rows;
			private int $removed;
			private int $redacted;

			/** @var array<int, string> */
			public array $lookup_calls = array();

			/** @var array<int, string> */
			public array $erase_calls = array();

			/**
			 * @param array<int, array<string, mixed>> $rows
			 */
			public function __construct( array $rows, int $removed, int $redacted ) {
				$this->rows     = $rows;
				$this->removed  = $removed;
				$this->redacted = $redacted;
			}

			public function rows_for_privacy_request( string $email ): array {
				$this->lookup_calls[] = $email;
				return $this->rows;
			}

			public function erase_for_privacy_request( string $email ): array {
				$this->erase_calls[] = $email;
				return array(
					'removed'  => $this->removed,
					'redacted' => $this->redacted,
				);
			}
		};
	}

	private function fake_ingest_queue( int $deleted, int $unsent = 0 ): IngestQueue {
		return new class( $deleted, $unsent ) extends IngestQueue {
			private int $deleted;
			private int $unsent;

			/** @var array<int, string> */
			public array $erase_calls = array();

			/** @var array<int, array{0: string, 1: array<int, int|string>}> */
			public array $unsent_calls = array();

			public function __construct( int $deleted, int $unsent ) {
				$this->deleted = $deleted;
				$this->unsent  = $unsent;
			}

			public function delete_for_privacy_request( string $email ): int {
				$this->erase_calls[] = $email;
				return $this->deleted;
			}

			public function delete_unsent( string $event_type, array $entity_ids ): int {
				$this->unsent_calls[] = array( $event_type, $entity_ids );
				return $this->unsent;
			}
		};
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function fake_store( array $rows ): CartSessionStore {
		return new class( $rows ) extends CartSessionStore {
			/** @var array<int, array<string, mixed>> */
			private array $rows;

			public int $delete_return = 0;

			/** @var array<int, array{email: string, user_id: int}> */
			public array $lookup_calls = array();

			/** @var array<int, array{email: string, user_id: int}> */
			public array $delete_calls = array();

			/**
			 * @param array<int, array<string, mixed>> $rows
			 */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			public function rows_for_privacy_request( string $email, int $user_id = 0 ): array {
				$this->lookup_calls[] = array(
					'email'   => $email,
					'user_id' => $user_id,
				);
				return $this->rows;
			}

			public function delete_rows_for_privacy_request( string $email, int $user_id = 0 ): int {
				$this->delete_calls[] = array(
					'email'   => $email,
					'user_id' => $user_id,
				);
				return $this->delete_return;
			}
		};
	}

	/**
	 * @param array{data: array<int, array<string, mixed>>, done: bool} $export
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_group_item( array $export, string $group_label ): ?array {
		foreach ( $export['data'] as $item ) {
			if ( $item['group_label'] === $group_label ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function field( array $item, string $name ): ?string {
		foreach ( $item['data'] as $pair ) {
			if ( $pair['name'] === $name ) {
				return $pair['value'];
			}
		}
		return null;
	}
}

// Shared shim pattern (see CartHookHandlerTest) — declared conditionally,
// another test file loaded earlier in the same process may already have it.
if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}

if ( ! class_exists( \WC_Order::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
class WC_Order {
	public function get_id(): int { return 0; }
	public function get_order_number() { return ''; }
	public function get_meta( $key = '', $single = true, $context = 'view' ) { return ''; }
	public function delete_meta_data( $key ): void {}
	public function save() { return 0; }
}
PHP
	);
}
