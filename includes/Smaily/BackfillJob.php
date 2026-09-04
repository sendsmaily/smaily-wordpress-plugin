<?php
/**
 * Idempotent backfill of existing WordPress users to Smaily as contacts.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Support\ContactLanguageResolver;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

/**
 * Walks the WP user table in batches of 100, marking each user with a
 * _smaily_synced_at meta on successful upsert so re-runs only touch
 * users that have never been synced or whose last sync is older than the
 * "freshness window" (default 7 days).
 *
 * Lifecycle (driven by Action Scheduler — sub-PR 5 wires the recurring
 * tick into the Settings "Start backfill" button + the daily contact-sync
 * cron):
 *
 *   1. start() seeds a smly_plus_backfill_job row with total_count and
 *      status='running' — or, when the sync audience is empty, closes it as
 *      'completed' on the spot, because a walk could not sync anyone (PRO-1715).
 *   2. process_batch() handles up to $batch_size users, then either
 *      schedules the next iteration (when the cursor hasn't reached
 *      total_count) or marks status='completed'.
 *   3. Failures bump the row's error_message and leave status='failed'
 *      so the UI surfaces a "Retry" affordance.
 *
 * The Smaily API call itself is delegated to a Client instance supplied
 * via constructor injection so tests don't need wp_remote_post mocks.
 *
 * Not final: REST endpoint + Bootstrap tests subclass with anonymous
 * doubles to short-circuit start() / process_batch() without exercising
 * the WP user-table read path. Same rationale as Smaily\Client.
 *
 * Implements BackfillJobInterface so it shares the REST endpoint + AS tick with
 * the rec-engine backfills (3.5); its process_batch() keeps its own optional
 * $batch_size, compatible with the no-arg interface method.
 */
class BackfillJob implements BackfillJobInterface {

	public const BACKFILL_TARGET = 'smaily';
	public const BACKFILL_TYPE   = 'contacts';

	public const TABLE_SUFFIX = 'smly_plus_backfill_job';
	public const META_KEY     = '_smaily_synced_at';

	/**
	 * Default freshness window — users synced within this many seconds are
	 * skipped on re-run. 7 days, expressed as a literal so the file is
	 * loadable without WordPress's DAY_IN_SECONDS constant being available
	 * (e.g. from unit tests).
	 */
	private const DEFAULT_FRESHNESS_SECONDS = 7 * 86400;

	private Client $client;

	private int $freshness_seconds;

	private ?SubscriberPayloadBuilder $builder = null;

	private ?ContactLanguageResolver $language_resolver = null;

	private ?ContactAudience $audience = null;

	public function __construct( Client $client, int $freshness_seconds = self::DEFAULT_FRESHNESS_SECONDS ) {
		$this->client            = $client;
		$this->freshness_seconds = $freshness_seconds;
	}

	/**
	 * Initialise (or reset) the backfill state row.
	 *
	 * Side-effect: clears every `_smaily_synced_at` user_meta so the next
	 * tick processes the full user list, not just the cohort outside the
	 * freshness window. Without this a second merchant-initiated "Start
	 * backfill" would mark itself "completed" after skipping every user
	 * as still-fresh — the actual POST /api/contact.php calls Erkki was
	 * waiting for never ran, so data never reached Smaily. The recurring
	 * background cron still benefits from the freshness optimization;
	 * only the merchant-driven start path resets it.
	 *
	 * @return int The backfill_job row id.
	 */
	public function start( bool $reset_freshness = true ): int {
		global $wpdb;

		$counts = count_users();
		$total  = (int) $counts['total_users'];

		$table = $this->table_name();

		// PRO-1715: with an empty audience the walk has nothing to POST, so the
		// run is recorded as finished right here rather than as 'running' with
		// an Action Scheduler tick as its only way out — on a quiet store that
		// tick can be minutes away, and until then the merchant watched a
		// progress spinner that never moved and cancelled by hand. Every user
		// is accounted for (each one would have been audience-skipped), so the
		// run completes at 100% with zero contacts synced.
		$nothing_to_sync = $this->has_empty_audience();

		// $reset_freshness=false is the daily-refresh path (F3-48.3): keep the
		// _smaily_synced_at markers so the walk re-syncs only users outside the
		// freshness window, not everyone. The merchant-driven "Start backfill"
		// keeps the default (true) — a deliberate full re-sync. A run that syncs
		// no-one has nothing to re-sync either, so it leaves the markers alone.
		//
		// Defensive against unit-test fakes that don't seed $wpdb->usermeta
		// (BackfillJobTest passes an anonymous stand-in). In production $wpdb
		// is always the WP-bootstrapped instance.
		if ( $reset_freshness && ! $nothing_to_sync && $wpdb instanceof \wpdb ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$cleared = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
					self::META_KEY
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			\Smaily\Connect\Support\DebugLog::write(
				sprintf(
					'[smaily-connect backfill.start] cleared %d freshness markers',
					is_numeric( $cleared ) ? (int) $cleared : 0
				)
			);
		}

		// Diagnostic logging — sub-PR 2.H.6. Erkki's staging shows AS
		// ticks completing with zero work; these lines let us see in
		// debug.log which decision branch fires. Drop these after the
		// pilot stabilises (Phase 4).
		\Smaily\Connect\Support\DebugLog::write(
			sprintf(
				'[smaily-connect backfill.start] total_users=%d table=%s',
				$total,
				$table
			)
		);

		// Table name interpolation: MySQL forbids parameterising it,
		// $table is composed from controlled values ($wpdb->prefix + a
		// private const). Real arguments are bound via %s / %d placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (job_type, target, status, total_count, processed_count, synced_count, started_at) VALUES (%s, %s, %s, %d, %d, 0, %s) ON DUPLICATE KEY UPDATE status = VALUES(status), total_count = VALUES(total_count), processed_count = 0, synced_count = 0, cursor_value = NULL, started_at = VALUES(started_at), completed_at = NULL, error_message = NULL",
				self::BACKFILL_TYPE,
				self::BACKFILL_TARGET,
				'running',
				$total,
				0,
				current_time( 'mysql', true )
			)
		);

		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE job_type = %s AND target = %s",
				self::BACKFILL_TYPE,
				self::BACKFILL_TARGET
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$last_error = isset( $wpdb->last_error ) && (string) $wpdb->last_error !== ''
			? (string) $wpdb->last_error
			: '(none)';
		\Smaily\Connect\Support\DebugLog::write(
			sprintf(
				'[smaily-connect backfill.start] row_id=%d wpdb_last_error=%s nothing_to_sync=%s',
				$id,
				$last_error,
				$nothing_to_sync ? 'yes' : 'no'
			)
		);

		if ( $nothing_to_sync ) {
			$this->mark_nothing_to_sync( $id, $total );
		}

		return $id;
	}

	/**
	 * True when the contact-sync mode's audience is empty — the switch is off,
	 * the mode syncs no accounts, or nobody has opted in — so a walk cannot
	 * produce a single contact (PRO-1715). Shared by start() and the daily
	 * refresh so neither leaves work scheduled that has nothing to do.
	 */
	public function has_empty_audience(): bool {
		return $this->audience()->count_audience() === 0;
	}

	/**
	 * Close the freshly-seeded row as a finished run that synced no-one. Written
	 * as a second statement rather than folded into the INSERT because
	 * $wpdb->prepare() turns a null %s into an empty string, which a DATETIME
	 * NULL column will not take.
	 */
	private function mark_nothing_to_sync( int $job_id, int $total ): void {
		global $wpdb;

		$wpdb->update(
			$this->table_name(),
			array(
				// Every user is accounted for: with an empty audience each one
				// would have been skipped, so the walk is done before it starts.
				'processed_count' => $total,
				'synced_count'    => 0,
				'status'          => 'completed',
				'completed_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $job_id ),
			array( '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Whether the daily refresh (F3-48.3) should (re)start a walk now. False
	 * while a walk is already draining — restarting would reset its cursor — and
	 * for one freshness window after the last completion, so each contact is
	 * re-synced about once per window instead of re-walked every daily tick.
	 * True when no walk has ever run.
	 */
	public function should_start_refresh(): bool {
		$state = $this->current_state();
		if ( $state === null ) {
			return true;
		}

		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( $status === 'running' ) {
			return false;
		}

		if ( $status === 'completed' && ! empty( $state['completed_at'] ) ) {
			$completed = strtotime( (string) $state['completed_at'] . ' UTC' );
			if ( $completed !== false && ( time() - $completed ) < $this->freshness_seconds ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string, mixed>|null Backfill state row (status + completed_at), or null.
	 */
	private function current_state(): ?array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return null;
		}

		$table = $this->table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, completed_at FROM {$table} WHERE job_type = %s AND target = %s",
				self::BACKFILL_TYPE,
				self::BACKFILL_TARGET
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Process the next chunk of users.
	 *
	 * Picks up where the previous batch left off via the row's cursor field
	 * (stored as the last seen user_id), syncs each user whose
	 * _smaily_synced_at meta is missing or stale, and updates
	 * processed_count + cursor.
	 *
	 * @return array{processed: int, remaining: int, completed: bool}
	 */
	public function process_batch( int $batch_size = 100 ): array {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$state = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, cursor_value, processed_count, synced_count, total_count FROM {$table} WHERE job_type = %s AND target = %s",
				self::BACKFILL_TYPE,
				self::BACKFILL_TARGET
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $state ) ) {
			\Smaily\Connect\Support\DebugLog::write( '[smaily-connect backfill.batch] state row missing — start() was never run or insert failed' );
			return array(
				'processed' => 0,
				'remaining' => 0,
				'completed' => true,
			);
		}

		$after    = isset( $state['cursor_value'] ) ? (int) $state['cursor_value'] : 0;
		$user_ids = $this->fetch_user_ids_after( $after, $batch_size );
		$users    = $this->hydrate_users( $user_ids );
		$synced   = 0;

		\Smaily\Connect\Support\DebugLog::write(
			sprintf(
				'[smaily-connect backfill.batch] state_id=%s status=%s processed=%s/%s cursor_after=%d fetched_users=%d',
				isset( $state['id'] ) ? (string) $state['id'] : '?',
				isset( $state['status'] ) ? (string) $state['status'] : '?',
				isset( $state['processed_count'] ) ? (string) $state['processed_count'] : '?',
				isset( $state['total_count'] ) ? (string) $state['total_count'] : '?',
				$after,
				count( $user_ids )
			)
		);

		$fresh_skips    = 0;
		$audience_skips = 0;
		foreach ( $users as $user ) {
			// F3-48: only sync the mode's audience (consent → opted-in only;
			// legitimate interest → all; checkout-only → none). Walked-past but
			// not POSTed, like a fresh-skip — the cursor still advances.
			if ( ! $this->audience()->should_sync_user( $user ) ) {
				++$audience_skips;
				continue;
			}

			if ( $this->is_fresh( (int) $user->ID ) ) {
				++$fresh_skips;
				continue;
			}

			$payload = $this->build_subscriber_payload( $user );
			try {
				$this->client->upsert_subscribers( array( $payload ) );
				update_user_meta( (int) $user->ID, self::META_KEY, time() );
				++$synced;
			} catch ( ApiException $e ) {
				\Smaily\Connect\Support\DebugLog::write(
					sprintf(
						'[smaily-connect backfill.batch] user_id=%d upsert_failed: %s',
						(int) $user->ID,
						$e->getMessage()
					)
				);
				$this->record_error( (int) $state['id'], $e->getMessage() );
				break;
			}
		}

		\Smaily\Connect\Support\DebugLog::write(
			sprintf(
				'[smaily-connect backfill.batch] synced=%d fresh_skipped=%d audience_skipped=%d',
				$synced,
				$fresh_skips,
				$audience_skips
			)
		);

		// Rows WALKED is the page the cursor query returned, not the users that
		// hydrated — so a user deleted between the two can't stall the cursor.
		$processed = (int) $state['processed_count'] + count( $user_ids );
		// F3-55: the cumulative "contacts synced" the UI shows — audience
		// members handled (POSTed now + already-fresh). processed_count keeps
		// counting rows WALKED (drives percent/ETA); the two diverge exactly
		// by the audience skips, which is the number Prike read as "30k
		// contacts go to Smaily".
		$synced_total = ( isset( $state['synced_count'] ) ? (int) $state['synced_count'] : 0 ) + $synced + $fresh_skips;
		$cursor       = empty( $user_ids ) ? $after : (int) end( $user_ids );
		$completed    = count( $user_ids ) < $batch_size;

		$wpdb->update(
			$table,
			array(
				'processed_count' => $processed,
				'synced_count'    => $synced_total,
				'cursor_value'    => (string) $cursor,
				'status'          => $completed ? 'completed' : 'running',
				'completed_at'    => $completed ? current_time( 'mysql', true ) : null,
			),
			array( 'id' => (int) $state['id'] ),
			array( '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'processed' => $synced,
			'remaining' => max( 0, (int) $state['total_count'] - $processed ),
			'completed' => $completed,
		);
	}

	/**
	 * The next page of user ids after the cursor — the same `WHERE ID > cursor
	 * ORDER BY ID ASC LIMIT n` walk the rec-engine backfills use
	 * (CustomerBackfillJob::fetch_ids_after).
	 *
	 * PRO-1769: this used to ask get_users() for the FIRST $limit users every
	 * time and prune `ID > cursor` in PHP, so the second tick re-read page one,
	 * filtered every row away, and the empty page marked the whole job
	 * 'completed' — a store with more than one page of users imported only its
	 * first 100 while reporting success.
	 *
	 * @return int[]
	 */
	private function fetch_user_ids_after( int $after_id, int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
				$after_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * @param int[] $user_ids
	 *
	 * @return \WP_User[]
	 */
	private function hydrate_users( array $user_ids ): array {
		if ( $user_ids === array() ) {
			return array();
		}

		return get_users(
			array(
				'fields'  => 'all',
				'include' => $user_ids,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
	}

	private function is_fresh( int $user_id ): bool {
		$synced = (int) get_user_meta( $user_id, self::META_KEY, true );
		if ( $synced <= 0 ) {
			return false;
		}

		return ( time() - $synced ) < $this->freshness_seconds;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_subscriber_payload( \WP_User $user ): array {
		$payload = $this->payload_builder()->build( $user );

		// Language is resolved here (not in SubscriberPayloadBuilder) so the
		// single ContactLanguageResolver source — not get_user_locale — drives
		// both live-sync and this backfill. This is what makes the backfill the
		// corrective mass re-sync for a store whose contacts drifted to the WP
		// site locale (F3-47): each user is re-sent with the right language.
		// Omit on empty — absent leaves Smaily's value intact, empty wipes.
		$language = $this->language_resolver()->for_user( $user );
		if ( $language !== '' ) {
			$payload['language'] = $language;
		}

		return $payload;
	}

	private function payload_builder(): SubscriberPayloadBuilder {
		if ( $this->builder === null ) {
			$this->builder = new SubscriberPayloadBuilder();
		}
		return $this->builder;
	}

	private function language_resolver(): ContactLanguageResolver {
		if ( $this->language_resolver === null ) {
			$this->language_resolver = new ContactLanguageResolver();
		}
		return $this->language_resolver;
	}

	private function audience(): ContactAudience {
		if ( $this->audience === null ) {
			$this->audience = new ContactAudience();
		}
		return $this->audience;
	}

	private function record_error( int $job_id, string $message ): void {
		global $wpdb;
		$wpdb->update(
			$this->table_name(),
			array(
				'status'        => 'failed',
				'error_message' => $message,
			),
			array( 'id' => $job_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
