<?php
/**
 * Integration: EventsEndpoint (3.10.0) — the Event Log read-model unions both
 * durable queues, filters by source/status/type, paginates, and counts the
 * 24h failures for the banner. Proves the UNION + filters against a real DB.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\BackfillEndpoint;
use Smaily\Connect\REST\EventsEndpoint;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use WP_REST_Request;

final class RecEngineEventsTest extends TestCase {

	private function rec_table(): string {
		global $wpdb;
		return $wpdb->prefix . IngestQueue::TABLE_SUFFIX;
	}

	private function smaily_table(): string {
		global $wpdb;
		return $wpdb->prefix . EventQueue::TABLE_SUFFIX;
	}

	protected function setUp(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB
		$wpdb->query( 'DELETE FROM ' . $this->rec_table() );
		$wpdb->query( 'DELETE FROM ' . $this->smaily_table() );
		// phpcs:enable WordPress.DB

		$now = current_time( 'mysql', true );

		// Two rec-queue rows: one sent, one failed.
		$wpdb->insert(
			$this->rec_table(),
			array(
				'event_type'   => 'catalog.upsert',
				'entity_id'    => 'SKU-1',
				'event_uuid'   => wp_generate_uuid4(),
				'payload'      => '{"sku":"SKU-1"}',
				'created_at'   => $now,
				'attempts'     => 1,
				'max_attempts' => 5,
				'status'       => 'sent',
			)
		);
		$wpdb->insert(
			$this->rec_table(),
			array(
				'event_type'    => 'order.upsert',
				'entity_id'     => '42',
				'event_uuid'    => wp_generate_uuid4(),
				'payload'       => '{"order_id":42}',
				'created_at'    => $now,
				'attempts'      => 5,
				'max_attempts'  => 5,
				'last_error'    => 'http_503 service_unavailable',
				'status'        => 'failed',
				// F3-44: the send-time exchange the flusher records.
				'sent_payload'  => '{"external_order_id":"42","status":"completed"}',
				'last_response' => '{"http":503,"outcome":"http_error","error_code":"service_unavailable"}',
			)
		);

		// Two Smaily-queue rows: one sent, one failed. (No event_uuid/max_attempts.)
		$wpdb->insert(
			$this->smaily_table(),
			array(
				'event_type' => 'contact.sync',
				'entity_id'  => 'a@b.test',
				'payload'    => '{"email":"a@b.test"}',
				'created_at' => $now,
				'attempts'   => 1,
				'status'     => 'sent',
			)
		);
		$wpdb->insert(
			$this->smaily_table(),
			array(
				'event_type' => 'automation.welcome',
				'entity_id'  => 'c@d.test',
				'payload'    => '{"email":"c@d.test"}',
				'created_at' => $now,
				'attempts'   => 2,
				'last_error' => 'http_400 bad_request',
				'status'     => 'failed',
			)
		);
	}

	private function list( array $params = array() ): array {
		$req = new WP_REST_Request( 'GET', '/smaily-connect/v1/events' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return ( new EventsEndpoint() )->list_events( $req )->get_data();
	}

	public function test_unions_both_queues_with_a_common_projection(): void {
		$data = $this->list();

		self::assertSame( 4, $data['total'] );
		self::assertCount( 4, $data['events'] );

		$sources = array_column( $data['events'], 'source' );
		self::assertContains( 'rec_engine', $sources );
		self::assertContains( 'smaily', $sources );

		// The Smaily queue has no max_attempts column → projected NULL.
		$smaily = array_values( array_filter( $data['events'], static fn ( $e ) => $e['source'] === 'smaily' ) )[0];
		self::assertNull( $smaily['max_attempts'] );
		// The rec queue carries its cap.
		$rec = array_values( array_filter( $data['events'], static fn ( $e ) => $e['source'] === 'rec_engine' ) )[0];
		self::assertSame( 5, $rec['max_attempts'] );
	}

	public function test_filters_by_source(): void {
		$data = $this->list( array( 'source' => 'rec_engine' ) );

		self::assertSame( 2, $data['total'] );
		foreach ( $data['events'] as $e ) {
			self::assertSame( 'rec_engine', $e['source'] );
		}
	}

	public function test_filters_by_status_across_both_queues(): void {
		$data = $this->list( array( 'status' => 'failed' ) );

		// One failed row in each queue.
		self::assertSame( 2, $data['total'] );
		$types = array_column( $data['events'], 'event_type' );
		self::assertContains( 'order.upsert', $types );
		self::assertContains( 'automation.welcome', $types );
	}

	public function test_failed_24h_counts_both_queues(): void {
		$data = $this->list();
		self::assertSame( 2, $data['failed_24h'] );
	}

	public function test_detail_returns_the_full_payload(): void {
		// Grab the rec failed row's id from the list, then fetch its detail.
		$list = $this->list( array( 'source' => 'rec_engine', 'status' => 'failed' ) );
		$id   = (int) $list['events'][0]['id'];

		$req = new WP_REST_Request( 'GET', '/smaily-connect/v1/events/detail' );
		$req->set_param( 'source', 'rec_engine' );
		$req->set_param( 'id', $id );
		$detail = ( new EventsEndpoint() )->detail( $req )->get_data();

		self::assertSame( 'order.upsert', $detail['event']['event_type'] );
		self::assertStringContainsString( 'order_id', $detail['payload'] );
		self::assertSame( 'http_503 service_unavailable', $detail['event']['last_error'] );
		// F3-44: the send-time exchange comes back so "Details" can show what was
		// sent + the engine reply (migration 007 added the columns).
		self::assertStringContainsString( 'external_order_id', $detail['sent_payload'] );
		self::assertStringContainsString( '"outcome":"http_error"', $detail['last_response'] );
	}

	public function test_pagination_caps_per_page(): void {
		$data = $this->list( array( 'per_page' => 1, 'page' => 1 ) );

		self::assertSame( 4, $data['total'] );
		self::assertCount( 1, $data['events'] );
		self::assertSame( 1, $data['per_page'] );
	}

	/**
	 * The 3.10.0 backfill-progress fix: status() reports engine-confirmed sent +
	 * terminal failed from the queue (not the walked count), so the panel can't
	 * read "N/N" while rows silently failed. setUp seeded one FAILED order.upsert;
	 * here we add a SENT one and assert the counts surface.
	 */
	public function test_backfill_status_surfaces_engine_confirmed_sent_and_failed(): void {
		global $wpdb;

		$wpdb->insert(
			$this->rec_table(),
			array(
				'event_type'   => 'order.upsert',
				'entity_id'    => '99',
				'event_uuid'   => wp_generate_uuid4(),
				'payload'      => '{"order_id":99}',
				'created_at'   => current_time( 'mysql', true ),
				'attempts'     => 1,
				'max_attempts' => 5,
				'status'       => 'sent',
			)
		);

		$backfill_table = $wpdb->prefix . BackfillEndpoint::TABLE_SUFFIX;
		// started_at an hour ago so all of this test's queue rows fall in-window.
		$started = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		// phpcs:disable WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$backfill_table} WHERE job_type = %s", 'orders' ) );
		// phpcs:enable WordPress.DB
		$wpdb->insert(
			$backfill_table,
			array(
				'job_type'        => 'orders',
				'target'          => 'rec_engine',
				'status'          => 'running',
				'total_count'     => 2,
				'processed_count' => 2,
				'started_at'      => $started,
			)
		);

		$req = new WP_REST_Request( 'GET', '/smaily-connect/v1/backfill/status' );
		$req->set_param( 'job_type', 'orders' );
		$data = ( new BackfillEndpoint( static fn ( string $t ) => null ) )->status( $req )->get_data();

		self::assertSame( 1, $data['sent'], 'one order.upsert is sent' );
		self::assertSame( 1, $data['failed'], 'one order.upsert is terminally failed' );
	}

	// --- 3.10.1 recovery: /events/retry + reset_failed -----------------------

	public function test_retry_single_revives_one_failed_row(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB
		$id = (int) $wpdb->get_var( 'SELECT id FROM ' . $this->rec_table() . " WHERE status = 'failed' LIMIT 1" );
		// phpcs:enable WordPress.DB

		$req = new WP_REST_Request( 'POST', '/smaily-connect/v1/events/retry' );
		$req->set_param( 'source', 'rec_engine' );
		$req->set_param( 'id', $id );
		$data = ( new EventsEndpoint() )->retry( $req )->get_data();

		self::assertSame( 1, $data['reset'] );
		// phpcs:disable WordPress.DB
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $this->rec_table() . ' WHERE id = %d', $id ) );
		// phpcs:enable WordPress.DB
		self::assertSame( 'pending', $status, 'the failed row is revived to pending' );
	}

	public function test_retry_all_revives_failed_in_both_queues(): void {
		// setUp seeds one failed row in each queue.
		$req  = new WP_REST_Request( 'POST', '/smaily-connect/v1/events/retry' );
		$data = ( new EventsEndpoint() )->retry( $req )->get_data();

		self::assertSame( 2, $data['reset'], 'one failed row revived in each queue' );
		self::assertSame( 0, $this->list( array( 'status' => 'failed' ) )['total'], 'no failed rows remain' );
	}

	public function test_retry_single_requires_a_source(): void {
		$req = new WP_REST_Request( 'POST', '/smaily-connect/v1/events/retry' );
		$req->set_param( 'id', 123 );
		$resp = ( new EventsEndpoint() )->retry( $req );

		self::assertSame( 400, $resp->get_status() );
	}
}
