<?php
/**
 * Drains the rec-engine ingest queue's customer rows to POST
 * /api/v1/ingest/customers.
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

use Smaily\Connect\Settings\RecEngineSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler callback for `smly_rec_flush_customers`. The D6 batch
 * machinery (split, invariant, retry policy) lives in AbstractD6Flusher; this
 * subclass supplies the four customer-specific bits: the event type, the batch
 * cap (100), the Client call, and how a row becomes a wire object.
 *
 * Each customer.upsert row is keyed on the WP user id (entity_id): the flusher
 * loads the WP_User FRESH and builds it, so the engine gets current state. A
 * user deleted since enqueue is a terminal skip. (There is no customer.delete
 * here — user erasure is the GDPR flow, a later sub-PR.)
 *
 * Kept separate from the order/catalog flushers (its own AS hook/group) so the
 * retry cycles are independent; the shared D6 logic is inherited, not copied.
 *
 * Not final: tests subclass to stub get_user().
 */
class CustomerFlusher extends AbstractD6Flusher {

	/** Queue event type for a customer upsert (register / update / checkout). */
	public const EVENT_CUSTOMER_UPSERT = 'customer.upsert';

	/** Action Scheduler hook + group, separate from catalog/order cycles. */
	public const FLUSH_HOOK = 'smly_rec_flush_customers';
	public const AS_GROUP   = 'smaily-rec-customers';

	/** Spec-conservative batch ceiling (engine accepts 1..100). */
	public const DEFAULT_BATCH_SIZE = 100;

	private CustomerPayloadBuilder $builder;

	/**
	 * @param callable(): Client $client_factory Builds a rec-engine Client from the stored config.
	 */
	public function __construct(
		IngestQueue $queue,
		CustomerPayloadBuilder $builder,
		RecEngineSettings $settings,
		callable $client_factory
	) {
		parent::__construct( $queue, $settings, $client_factory );
		$this->builder = $builder;
	}

	protected function event_types(): array {
		return array( self::EVENT_CUSTOMER_UPSERT );
	}

	protected function batch_size(): int {
		return self::DEFAULT_BATCH_SIZE;
	}

	protected function endpoint_label(): string {
		return 'customers';
	}

	protected function send( array $batch ): array {
		return ( $this->client_factory )()->ingest_customers( $batch );
	}

	protected function row_to_object( array $row ): ?array {
		$user = $this->get_user( (int) ( $row['entity_id'] ?? 0 ) );
		if ( $user === null ) {
			return null;
		}
		return $this->builder->build( $user, (string) ( $row['event_uuid'] ?? '' ) );
	}

	/**
	 * Load a WP user by id. Protected so tests can stub the lookup.
	 */
	protected function get_user( int $user_id ): ?\WP_User {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return null;
		}
		$user = get_userdata( $user_id );
		return $user instanceof \WP_User ? $user : null;
	}
}
