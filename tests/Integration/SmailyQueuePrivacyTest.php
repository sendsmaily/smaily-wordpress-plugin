<?php
/**
 * Integration: the WP personal-data eraser/exporter over the Smaily event
 * queue (PRO-2383), against the real MariaDB table.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Privacy\GdprHandler;
use Smaily\Connect\REST\EventsEndpoint;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\TransactionalResend;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use WP_REST_Request;

/**
 * What this pins (PRO-2383): the queue used to be invisible to an erasure
 * request — a queued message still went out to an address the shopper asked
 * us to forget, and the F3-44 exchange kept the address on every already-sent
 * row until the janitor's retention window pruned it.
 *
 * The split Erkki chose: a row that could still SEND is deleted, a row that
 * already did is redacted in place so the Event Log keeps its history. The
 * rows are found by the PRO-1723 `contact_key` where there is one, and by a
 * payload match where there is not — a row enqueued before migration 011, and
 * a transactional row, whose recipient rides `to` and which therefore never
 * gets a key at all.
 *
 * The engine is deliberately left disconnected here: this is the Smaily-side
 * queue, independent of the rec-engine (RecEngineGdprTest owns that half).
 */
final class SmailyQueuePrivacyTest extends TestCase {

	private const SUBJECT   = 'erase-me@example.test';
	private const BYSTANDER = 'keep-me@example.test';

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		// EnvScrub clears the schema-version pointer with the smly_% sweep;
		// re-running activation re-applies migrations idempotently (incl. 011,
		// which adds the contact_key column this looks rows up by).
		Activation::run();
	}

	public function test_erasure_deletes_the_sendable_rows_and_reports_how_many(): void {
		$pending = $this->enqueue_for( self::SUBJECT, 'contact.sync' );
		$failed  = $this->enqueue_for( self::SUBJECT, 'automation.welcome' );
		$this->set_status( $failed, EventQueue::STATUS_FAILED );

		$result = $this->run_eraser( self::SUBJECT );

		self::assertTrue( $result['items_removed'] );
		self::assertNull( $this->row( $pending ), 'A message still queued for the subject is gone.' );
		self::assertNull( $this->row( $failed ), 'So is a failed one — the Event Log Retry could still send it.' );
		self::assertContains(
			'Removed 2 Smaily messages that were still queued for this address.',
			$result['messages']
		);
	}

	public function test_a_sent_row_survives_the_erasure_carrying_nothing_personal(): void {
		// The abandoned-cart reminder is the fullest payload the queue holds:
		// the address, the shopper's name, and the PRO-1680 product matrix.
		$id = $this->enqueue_for(
			self::SUBJECT,
			'automation.abandoned_cart',
			array(
				'fields' => array(
					'is_abandoned_cart' => 'true',
					'first_name'        => 'Given',
					'last_name'         => 'Family',
					'product_name_1'    => 'Mystery Box',
					'product_price_1'   => '12.00',
				),
			)
		);
		$this->set_status( $id, EventQueue::STATUS_SENT );
		$this->set_exchange(
			$id,
			(string) wp_json_encode( array( 'addresses' => array( array( 'email' => self::SUBJECT ) ) ) ),
			(string) wp_json_encode(
				array(
					'http'    => 200,
					'outcome' => 'sent',
					'error'   => 'none for ' . self::SUBJECT,
				)
			)
		);

		$result = $this->run_eraser( self::SUBJECT );

		$row = $this->row( $id );
		self::assertNotNull( $row, 'The row stays — it is the record that the store emailed this person.' );
		self::assertSame( EventQueue::STATUS_SENT, $row['status'] );
		self::assertSame( 'automation.abandoned_cart', $row['event_type'], 'What happened is kept.' );
		self::assertNotSame( '', (string) $row['created_at'], 'And when.' );
		self::assertNull( $row['contact_key'], 'The contact key is cleared.' );

		foreach ( array( 'payload', 'sent_payload', 'last_response' ) as $column ) {
			$stored = (string) $row[ $column ];
			self::assertStringNotContainsString( self::SUBJECT, $stored, $column . ' carries no address.' );
			self::assertStringNotContainsString( 'Given', $stored, $column . ' carries no name.' );
			self::assertStringNotContainsString( 'Mystery Box', $stored, $column . ' carries no cart detail.' );
		}

		// The exchange's own routing scalars stay, so the row still renders as
		// the delivered reminder it was.
		self::assertStringContainsString( '"outcome":"sent"', (string) $row['last_response'] );
		self::assertContains(
			'Anonymised 1 already-sent Smaily record in the event log.',
			$result['messages']
		);
	}

	public function test_a_redacted_row_still_lists_in_the_event_log(): void {
		$id = $this->enqueue_for( self::SUBJECT, 'contact.sync' );
		$this->set_status( $id, EventQueue::STATUS_SENT );

		$this->run_eraser( self::SUBJECT );

		$req = new WP_REST_Request( 'GET', '/smaily-connect/v1/events' );
		$req->set_param( 'source', 'smaily' );
		$data = $this->events_endpoint()->list_events( $req )->get_data();

		$ids = array_column( $data['events'], 'id' );
		self::assertContains( $id, $ids, 'The Event Log still shows the anonymised row.' );

		$detail = new WP_REST_Request( 'GET', '/smaily-connect/v1/events/detail' );
		$detail->set_param( 'source', 'smaily' );
		$detail->set_param( 'id', $id );
		$body = $this->events_endpoint()->detail( $detail )->get_data();

		self::assertSame( 'contact.sync', $body['event']['event_type'] );
		self::assertStringNotContainsString( self::SUBJECT, (string) $body['payload'] );
	}

	public function test_rows_without_a_contact_key_are_matched_on_their_payload(): void {
		// Two of them: a row enqueued before migration 011, and a transactional
		// row, whose recipient is `to` — enqueue() only keys on `email`, so a
		// transactional row never carries a contact_key at all.
		$legacy = $this->insert_raw(
			'contact.sync',
			(string) wp_json_encode( array( 'email' => self::SUBJECT ) ),
			EventQueue::STATUS_PENDING
		);
		$order  = $this->insert_raw(
			'transactional.order_confirmation',
			(string) wp_json_encode(
				array(
					'to'          => self::SUBJECT,
					'workflow_id' => 'wf-7',
					'account_key' => 'main',
					'to_status'   => 'completed',
					'context'     => array(
						'first_name'   => 'Given',
						'last_name'    => 'Family',
						'order_number' => '58922',
					),
				)
			),
			EventQueue::STATUS_SENT
		);

		$this->run_eraser( self::SUBJECT );

		self::assertNull( $this->row( $legacy ), 'A pre-011 pending row is found and deleted.' );

		$row = $this->row( $order );
		self::assertNotNull( $row );
		self::assertStringNotContainsString( self::SUBJECT, (string) $row['payload'] );
		self::assertStringNotContainsString( 'Given', (string) $row['payload'] );
		self::assertStringNotContainsString( '58922', (string) $row['payload'] );
		// The routing scalars the "Send again" guard reads are not personal.
		self::assertStringContainsString( '"to_status":"completed"', (string) $row['payload'] );
	}

	public function test_another_contacts_rows_are_untouched(): void {
		$pending = $this->enqueue_for( self::BYSTANDER, 'contact.sync' );
		$sent    = $this->enqueue_for( self::BYSTANDER, 'automation.welcome' );
		$this->set_status( $sent, EventQueue::STATUS_SENT );
		$before  = array( $this->row( $pending ), $this->row( $sent ) );

		$this->run_eraser( self::SUBJECT );

		self::assertEquals( $before, array( $this->row( $pending ), $this->row( $sent ) ) );
	}

	public function test_erasing_a_contact_with_no_queue_rows_reports_nothing(): void {
		$this->enqueue_for( self::BYSTANDER, 'contact.sync' );

		$result = $this->run_eraser( self::SUBJECT );

		self::assertSame( array(), $result['messages'] );
	}

	public function test_the_exporter_lists_this_contacts_rows_and_no_one_elses(): void {
		$mine = $this->enqueue_for( self::SUBJECT, 'automation.abandoned_cart' );
		$this->enqueue_for( self::BYSTANDER, 'contact.sync' );

		$export = $this->run_exporter( self::SUBJECT );

		$items = array();
		foreach ( $export['data'] as $item ) {
			if ( $item['group_label'] === 'Queued Smaily message' ) {
				$items[] = $item;
			}
		}

		self::assertCount( 1, $items, 'Only the subject\'s own row.' );
		self::assertSame(
			array( 'event_type', 'created_at' ),
			array_column( $items[0]['data'], 'name' ),
			'What was queued and when — never the payload.'
		);
		self::assertSame( 'automation.abandoned_cart', $items[0]['data'][0]['value'] );
		self::assertStringEndsWith( 'event-queue-' . $mine, (string) $items[0]['item_id'] );
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

	/**
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	private function run_exporter( string $email ): array {
		$this->handler()->register();

		/** @var array<string, array<string, mixed>> $exporters */
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		self::assertArrayHasKey( 'smaily-connect-rec-engine', $exporters );

		return call_user_func( $exporters['smaily-connect-rec-engine']['callback'], $email, 1 );
	}

	private function handler(): GdprHandler {
		$settings = new RecEngineSettings();

		return new GdprHandler(
			$settings,
			static function () use ( $settings ): Client {
				throw new \RuntimeException( 'engine must not be called while disconnected' );
			},
			new CartSessionStore(),
			new EventQueue()
		);
	}

	private function events_endpoint(): EventsEndpoint {
		return new EventsEndpoint(
			static function (): TransactionalResend {
				return Bootstrap::instance()->transactional_resend();
			}
		);
	}

	/**
	 * @param array<string, mixed> $extra Merged into the enqueued payload.
	 */
	private function enqueue_for( string $email, string $event_type, array $extra = array() ): int {
		$id = ( new EventQueue() )->enqueue(
			$event_type,
			'privacy-test',
			array_merge( array( 'email' => $email ), $extra )
		);
		self::assertIsInt( $id );

		return $id;
	}

	/**
	 * A row written straight to the table, so it carries NO contact_key —
	 * what a pre-migration-011 row and every transactional row look like.
	 */
	private function insert_raw( string $event_type, string $payload, string $status ): int {
		global $wpdb;

		$wpdb->insert(
			$this->table(),
			array(
				'event_type' => $event_type,
				'entity_id'  => 'privacy-test',
				'payload'    => $payload,
				'created_at' => current_time( 'mysql', true ),
				'status'     => $status,
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function set_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	private function set_exchange( int $id, string $sent_payload, string $last_response ): void {
		( new EventQueue() )->store_exchange( $id, $sent_payload, $last_response );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function row( int $id ): ?array {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . EventQueue::TABLE_SUFFIX;
	}
}
