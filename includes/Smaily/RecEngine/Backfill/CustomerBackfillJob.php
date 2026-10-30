<?php
/**
 * Backfill the registered WordPress users in the engine's customer scope.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Backfill;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerScope;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

/**
 * The cursor walks the wp_users table by ascending ID, over the users in the
 * engine's customer scope only (contract v1.20.0 §4, CON-246): the users the
 * plugin's own contact sync puts into Smaily, plus every user with a sale
 * order inside the history horizon. The scope is part of the page query
 * (CustomerScope::where_sql() — a semi-join against the active order table),
 * so the walk reads no out-of-scope account and the progress total counts the
 * same set. The live CustomerHookHandler applies the same rule per user
 * (CustomerScope::includes_user()), so the import and the live sync never send
 * a different cohort. Still no role filter: a role is not part of the rule.
 * (An email-less user in scope is enqueued and the engine rejects it per
 * item — consistent, not silently dropped.)
 */
class CustomerBackfillJob extends AbstractBackfillJob {

	private CustomerScope $scope;

	public function __construct( IngestQueue $queue, CustomerFlusher $flusher, CustomerScope $scope ) {
		parent::__construct( $queue, $flusher );
		$this->scope = $scope;
	}

	public function job_type(): string {
		return 'customers';
	}

	protected function batch_size(): int {
		return 100;
	}

	/** The users in scope — the Customers panel's count and the progress total. */
	protected function count_total(): int {
		return self::count_in_scope( $this->scope );
	}

	/**
	 * How many users the customer import sends: the same WHERE as its walk.
	 * Static so the admin's "N customers to import" (EnvDetector) reads the
	 * one number the import itself counts.
	 */
	public static function count_in_scope( CustomerScope $scope ): int {
		global $wpdb;
		list( $where, $args ) = $scope->where_sql( 'u.ID', time() );
		$sql                  = "SELECT COUNT(*) FROM {$wpdb->users} u WHERE {$where}";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @return int[]
	 */
	protected function fetch_ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		list( $where, $args ) = $this->scope->where_sql( 'u.ID', time() );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT u.ID FROM {$wpdb->users} u WHERE u.ID > %d AND {$where} ORDER BY u.ID ASC LIMIT %d",
				array_merge( array( $after_id ), $args, array( $limit ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
