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
use Smaily\Connect\Tests\Integration\Support\EventsEndpointFactory;
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

	private function endpoint(): EventsEndpoint {
		return EventsEndpointFactory::create();
	}

	private function list( array $params = array() ): array {
		$req = new WP_REST_Request( 'GET', '/smaily-connect/v1/events' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $this->endpoint()->list_events( $req )->get_data();
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

	/**
	 * PRO-4098: rows skipped for good are stored `sent` with a `skipped`
	 * outcome — the D6 flushers' terminal skip (rec queue), the Smaily
	 * flushers' terminal skip (a note, no reason) and a transactional skip
	 * (a reason). Seeds one of each in the shapes the writers store.
	 */
	private function seed_skipped_rows(): void {
		global $wpdb;
		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$this->rec_table(),
			array(
				'event_type'    => 'order.upsert',
				'entity_id'     => '43',
				'event_uuid'    => wp_generate_uuid4(),
				'payload'       => '[]',
				'created_at'    => $now,
				'attempts'      => 1,
				'max_attempts'  => 5,
				'status'        => 'sent',
				'last_response' => '{"outcome":"skipped","note":"row not in a sendable state (entity gone, no longer a confirmed sale, or no product lines) — nothing was POSTed"}',
			)
		);
		$wpdb->insert(
			$this->smaily_table(),
			array(
				'event_type'    => 'contact.sync',
				'entity_id'     => 'e@f.test',
				'payload'       => '[]',
				'created_at'    => $now,
				'attempts'      => 1,
				'status'        => 'sent',
				'last_response' => '{"outcome":"skipped","note":"nothing was POSTed"}',
			)
		);
		( new EventQueue() )->record_skipped( 'transactional.order_confirmation', '44', 'no_workflow' );
	}

	public function test_sent_filter_leaves_out_skipped_rows_from_both_queues(): void {
		$this->seed_skipped_rows();

		$data = $this->list( array( 'status' => 'sent' ) );

		// Only the two rows setUp sent — their last_response is NULL, which
		// must not drop them from the filter.
		self::assertSame( 2, $data['total'] );
		self::assertEqualsCanonicalizing(
			array( 'SKU-1', 'a@b.test' ),
			array_column( $data['events'], 'entity_id' )
		);
	}

	/**
	 * CON-2: an abandoned-cart reminder withdrawn before it went out is
	 * stored `sent` with a `cancelled` outcome (PRO-2372) — nothing was
	 * sent, so the Sent filter leaves it out like a skipped row. Written by
	 * the real withdrawal path, so the filter is pinned to the stored shape.
	 */
	public function test_sent_filter_leaves_out_a_withdrawn_cart_reminder(): void {
		$queue = new EventQueue();
		$id    = $queue->enqueue_scheduled( 'automation.abandoned_cart', 'g@h.test', array( 'email' => 'g@h.test' ), current_time( 'mysql', true ) );
		self::assertNotNull( $id );
		$queue->withdraw_pending_for( 'automation.abandoned_cart', 'g@h.test' );

		$all = $this->list( array( 'source' => 'smaily' ) );
		$row = current( array_filter( $all['events'], static fn ( $e ) => (int) $e['id'] === $id ) );
		self::assertIsArray( $row, 'The withdrawn reminder is still listed under All statuses.' );
		self::assertSame( 'sent', $row['status'], 'The withdrawal is terminal, never retried.' );
		self::assertTrue( $row['cancelled'] );

		$data = $this->list( array( 'status' => 'sent' ) );

		self::assertNotContains( $id, array_map( 'intval', array_column( $data['events'], 'id' ) ) );
		self::assertSame( 2, $data['total'], 'Only the two rows setUp really sent.' );
	}

	public function test_list_marks_every_skipped_row_as_skipped(): void {
		$this->seed_skipped_rows();

		$skipped = array();
		foreach ( $this->list()['events'] as $event ) {
			$skipped[ $event['source'] . ':' . $event['entity_id'] ] = $event['skipped'];
		}

		ksort( $skipped, SORT_STRING );
		self::assertSame(
			array(
				'rec_engine:42'    => false,
				'rec_engine:43'    => true,
				'rec_engine:SKU-1' => false,
				'smaily:44'        => true,
				'smaily:a@b.test'  => false,
				'smaily:c@d.test'  => false,
				'smaily:e@f.test'  => true,
			),
			$skipped
		);
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
		$detail = $this->endpoint()->detail( $req )->get_data();

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
		$data = $this->endpoint()->retry( $req )->get_data();

		self::assertSame( 1, $data['reset'] );
		// phpcs:disable WordPress.DB
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $this->rec_table() . ' WHERE id = %d', $id ) );
		// phpcs:enable WordPress.DB
		self::assertSame( 'pending', $status, 'the failed row is revived to pending' );
	}

	public function test_retry_all_revives_failed_in_both_queues(): void {
		// setUp seeds one failed row in each queue.
		$req  = new WP_REST_Request( 'POST', '/smaily-connect/v1/events/retry' );
		$data = $this->endpoint()->retry( $req )->get_data();

		self::assertSame( 2, $data['reset'], 'one failed row revived in each queue' );
		self::assertSame( 0, $this->list( array( 'status' => 'failed' ) )['total'], 'no failed rows remain' );
	}

	public function test_retry_single_requires_a_source(): void {
		$req = new WP_REST_Request( 'POST', '/smaily-connect/v1/events/retry' );
		$req->set_param( 'id', 123 );
		$resp = $this->endpoint()->retry( $req );

		self::assertSame( 400, $resp->get_status() );
	}
}
