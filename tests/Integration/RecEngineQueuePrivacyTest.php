<?php
/**
 * Integration: the WP personal-data eraser over the Campaign Intelligence
 * ingest queue (PRO-2384), against the real MariaDB table.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\Privacy\GdprHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\QueueRowFixture;

/**
 * What this pins (PRO-2384): `smly_rec_event_queue` rows enqueue an empty
 * payload, but the F3-44 copy of what was sent (`sent_payload`) carries a
 * customer update's email and an order's customer fields. The table has no
 * contact key, and the engine-side erasure never reaches it, so before this
 * only the janitor's 30/90-day retention removed that copy.
 *
 * The eraser now DELETES every row whose stored copy carries the address as
 * a whole JSON string — raw, or as wp_json_encode() writes it (a non-ASCII
 * address is stored as `\uXXXX` escapes, the PRO-2448 trap). The quotes bound
 * the match, so a longer address that ends in the subject's is not touched.
 *
 * The engine is left disconnected: this table is local, and its erasure does
 * not depend on the connection (RecEngineGdprTest owns the engine half).
 */
final class RecEngineQueuePrivacyTest extends TestCase {

	private const SUBJECT   = 'jane@example.com';
	private const NON_ASCII = 'jäne@example.com';
	private const SIMILAR   = 'xjane@example.com';
	private const BYSTANDER = 'keep-me@example.com';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		Activation::run();
	}

	public function test_erasure_deletes_the_rows_whose_sent_copy_carries_the_address(): void {
		$customer = $this->sent_customer_row( self::SUBJECT );
		$order    = $this->sent_order_row( self::SUBJECT );

		$result = $this->run_eraser( self::SUBJECT );

		self::assertTrue( $result['items_removed'] );
		self::assertNull( $this->row( $customer ), 'The customer update sent for the subject is gone.' );
		self::assertNull( $this->row( $order ), 'So is the order, with its customer fields.' );
		self::assertSame( 0, $this->rows_containing( self::SUBJECT ), 'No copy of the address is left in the table.' );
		self::assertSame( 0, $this->rows_containing( 'Family' ), 'Nor the customer fields that rode with it.' );
	}

	public function test_a_non_ascii_address_stored_json_escaped_is_erased_too(): void {
		$customer = $this->sent_customer_row( self::NON_ASCII );
		$order    = $this->sent_order_row( self::NON_ASCII );

		// The precondition PRO-2448 turns on: the stored copy does NOT carry
		// the address as typed, only its JSON escape.
		$stored = (string) $this->row( $customer )['sent_payload'];
		self::assertStringNotContainsString( self::NON_ASCII, $stored );
		self::assertStringContainsString( 'j' . chr( 92 ) . 'u00e4ne@example.com', $stored );

		$result = $this->run_eraser( self::NON_ASCII );

		self::assertTrue( $result['items_removed'] );
		self::assertNull( $this->row( $customer ) );
		self::assertNull( $this->row( $order ) );
	}

	public function test_the_address_is_matched_whatever_case_the_request_uses(): void {
		// The payload builders lowercase the address; the erasure request
		// carries it as the account or the requester typed it.
		$customer = $this->sent_customer_row( self::SUBJECT );

		$this->run_eraser( 'Jane@Example.COM' );

		self::assertNull( $this->row( $customer ) );
	}

	public function test_other_rows_are_untouched(): void {
		$similar   = $this->sent_customer_row( self::SIMILAR );
		$bystander = $this->sent_order_row( self::BYSTANDER );
		// The plain ASCII neighbour of the non-ASCII address: an accent-blind
		// collation would call the two equal.
		$plain     = $this->sent_customer_row( self::SUBJECT );
		$catalog   = $this->enqueue( 'catalog.upsert', '77' );
		$this->queue()->store_exchange(
			$catalog,
			(string) wp_json_encode(
				array(
					'sku'   => 'woo-77',
					'title' => 'Mystery Box',
				)
			),
			(string) wp_json_encode(
				array(
					'http'    => 200,
					'outcome' => 'accepted',
				)
			)
		);
		$before = array_map( array( $this, 'row' ), array( $similar, $bystander, $plain, $catalog ) );

		$result = $this->run_eraser( self::NON_ASCII );

		self::assertFalse( $result['items_removed'], 'Nothing in the table carries the requested address.' );
		self::assertEquals( $before, array_map( array( $this, 'row' ), array( $similar, $bystander, $plain, $catalog ) ) );

		$this->run_eraser( 'jane@example.com' );

		self::assertNull( $this->row( $plain ) );
		self::assertNotNull( $this->row( $similar ), 'A longer address ending in the subject\'s is not the subject.' );
		self::assertNotNull( $this->row( $bystander ) );
		self::assertNotNull( $this->row( $catalog ) );
	}

	// --- helpers --------------------------------------------------------

	/**
	 * Run the eraser exactly as WordPress does: through the callback the
	 * plugin registers on `wp_privacy_personal_data_erasers`.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	private function run_eraser( string $email ): array {
		$this->handler()->register();

		/** @var array<string, array<string, mixed>> $erasers */
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		self::assertArrayHasKey( 'smaily-connect-rec-engine', $erasers );

		return call_user_func( $erasers['smaily-connect-rec-engine']['callback'], $email, 1 );
	}

	private function handler(): GdprHandler {
		$settings = new RecEngineSettings();

		return new GdprHandler(
			$settings,
			static function (): Client {
				throw new \RuntimeException( 'engine must not be called while disconnected' );
			},
			new CartSessionStore(),
			new EventQueue(),
			$this->queue()
		);
	}

	/**
	 * A customer.upsert row as CustomerFlusher leaves it after a send: an
	 * empty payload, and the object it POSTed stored as `sent_payload`
	 * through the same wp_json_encode() the flusher uses.
	 */
	private function sent_customer_row( string $email ): int {
		$id = $this->enqueue( CustomerFlusher::EVENT_CUSTOMER_UPSERT, '12' );
		$this->queue()->mark_sent( $id );
		$this->queue()->store_exchange(
			$id,
			(string) wp_json_encode(
				array(
					'event_id'   => wp_generate_uuid4(),
					'email'      => strtolower( $email ),
					'first_name' => 'Given',
					'last_name'  => 'Family',
				)
			),
			(string) wp_json_encode(
				array(
					'http'    => 200,
					'outcome' => 'accepted',
				)
			)
		);

		return $id;
	}

	/** An order.upsert row whose send failed — the copy is kept all the same. */
	private function sent_order_row( string $email ): int {
		$id = $this->enqueue( OrderFlusher::EVENT_ORDER_UPSERT, '58922' );
		$this->queue()->mark_failed( $id, 'http_422 validation_failed' );
		$this->queue()->store_exchange(
			$id,
			(string) wp_json_encode(
				array(
					'event_id'          => wp_generate_uuid4(),
					'external_order_id' => '58922',
					'customer_email'    => strtolower( $email ),
					'customer'          => array(
						'first_name' => 'Given',
						'last_name'  => 'Family',
					),
					'total_amount'      => 12.0,
				)
			),
			(string) wp_json_encode(
				array(
					'http'    => 422,
					'outcome' => 'http_error',
				)
			)
		);

		return $id;
	}

	private function enqueue( string $event_type, string $entity_id ): int {
		$id = $this->queue()->enqueue( $event_type, $entity_id, array() );
		self::assertIsInt( $id );

		return $id;
	}

	private function rows_containing( string $needle ): int {
		global $wpdb;
		$table = QueueRowFixture::table( IngestQueue::TABLE_SUFFIX );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE CONCAT_WS( ' ', payload, sent_payload, last_response ) LIKE %s", '%' . $wpdb->esc_like( $needle ) . '%' ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function row( int $id ): ?array {
		return QueueRowFixture::row( IngestQueue::TABLE_SUFFIX, $id );
	}

	private function queue(): IngestQueue {
		return new IngestQueue();
	}
}
