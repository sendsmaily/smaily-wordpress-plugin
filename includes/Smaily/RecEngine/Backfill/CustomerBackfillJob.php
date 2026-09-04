<?php
/**
 * Backfill every registered WordPress user into the rec-engine as a customer.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

/**
 * The cursor walks the wp_users table by ascending ID. The set MUST match what
 * the live CustomerHookHandler enqueues — the A-filter (F3-20): EVERY
 * registered user, with NO role filter (not just the `customer` role) and no
 * email filter. The handler covers users that register/update after connection;
 * this backfill covers the ones already there. Because the A-filter is the
 * ABSENCE of a predicate ("all users"), consistency means this enumerator adds
 * no `WHERE role=...` / `WHERE email<>''` either — both sides are unfiltered, so
 * neither can send a different cohort than the other. (An email-less user is
 * enqueued here exactly as the handler enqueues it; the engine rejects it
 * per-item the same way — consistent, not silently dropped.)
 */
class CustomerBackfillJob extends AbstractBackfillJob {

	public function __construct( IngestQueue $queue, CustomerFlusher $flusher ) {
		parent::__construct( $queue, $flusher );
	}

	public function job_type(): string {
		return 'customers';
	}

	protected function batch_size(): int {
		return 100;
	}

	protected function count_total(): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @return int[]
	 */
	protected function fetch_ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		// No role filter — the A-filter (F3-20) is every registered user.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
				$after_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids );
	}

	protected function enqueue_record( int $entity_id ): void {
		// Mirrors CustomerHookHandler::enqueue_upsert — the same event type +
		// flush hook/group so backfill rows drain through CustomerFlusher.
		$this->queue->enqueue(
			CustomerFlusher::EVENT_CUSTOMER_UPSERT,
			(string) $entity_id,
			array(),
			null,
			CustomerFlusher::FLUSH_HOOK,
			CustomerFlusher::AS_GROUP
		);
	}
}
