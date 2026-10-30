<?php
/**
 * Which registered users the engine gets as customers (contract v1.20.0 §4).
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only lookups against the order tables; every value is $wpdb->prepare()d, table and column names come from OrderBackfillJob::table_spec().

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;

/**
 * A registered user is in scope when (a) the plugin's own contact sync puts
 * them into Smaily (ContactAudience — the audience the contact import and its
 * "about N will be synced" estimate use, `sync_enabled()` included), or (b)
 * they have a sale order (status NOT IN the F3-42 denylist, CC-9 single
 * source) placed inside the history horizon (`config.history.horizon_months`,
 * default 60). Every other user is left out — e.g., in the consent mode, an
 * admin account that never bought and never subscribed (CON-246).
 *
 * One rule, two forms: includes_user() for the live sync (one user, on a WP
 * hook) and where_sql() for the customer import's page query (the whole users
 * table). Both read the horizon from the stored setup-exchange config
 * (HistoryConfig::stored) — never a ping on a hook — and the buyer test from
 * the active order table (OrderBackfillJob::table_spec(): HPOS keys the
 * customer in `wc_orders.customer_id`, legacy in `_customer_user` postmeta).
 *
 * Not final: tests subclass it to fake the order lookup.
 */
class CustomerScope {

	private RecEngineSettings $settings;

	/** @var callable(): ContactAudience */
	private $audience_factory;

	/**
	 * @param callable(): ContactAudience|null $audience_factory Builds the contact
	 *        audience per check, so a sync-mode change applies to the next user
	 *        and is never memoised for the life of a request.
	 */
	public function __construct( RecEngineSettings $settings, ?callable $audience_factory = null ) {
		$this->settings         = $settings;
		$this->audience_factory = $audience_factory ?? static function (): ContactAudience {
			return new ContactAudience();
		};
	}

	/** Should the engine get this registered user as a customer? */
	public function includes_user( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		if ( ( $this->audience_factory )()->should_sync_user( $user ) ) {
			return true;
		}

		return $this->has_sale_order_since( $user_id, $this->horizon_start( time() ) );
	}

	/**
	 * The scope as a WHERE condition on a user-id column, and its prepare()
	 * arguments — the customer import's count and page query.
	 *
	 * @param string $id_column A trusted column expression, e.g. `u.ID`.
	 * @return array{0: string, 1: array<int, string|int>}
	 */
	public function where_sql( string $id_column, int $now ): array {
		global $wpdb;

		list( $audience_sql, $audience_args ) = ( $this->audience_factory )()->user_id_sql( $id_column );
		list( $buyers_sql, $buyers_args )     = self::buyers_sql( OrderBackfillJob::uses_hpos(), $wpdb->prefix, $this->horizon_start( $now ) );

		return array(
			"( {$audience_sql} OR {$id_column} IN ( {$buyers_sql} ) )",
			array_merge( $audience_args, $buyers_args ),
		);
	}

	/**
	 * The oldest order date (UTC, `Y-m-d H:i:s`) that still puts a buyer in
	 * scope: `$now` − the stored horizon months, the same calendar-month
	 * arithmetic as the orders import's window (CON-245).
	 */
	public function horizon_start( int $now ): string {
		return OrderBackfillJob::window( HistoryConfig::stored( $this->settings ), $now )['horizon'];
	}

	/**
	 * A SELECT of the customer ids of sale orders placed at or after $since,
	 * on the active order table, and its arguments. With $user_id it is
	 * narrowed to that one customer (the live check). PURE (no DB) so both
	 * storages' SQL is unit-pinned; CI runs the integration suite on both.
	 *
	 * @return array{0: string, 1: array<int, string|int>}
	 */
	public static function buyers_sql( bool $hpos, string $prefix, string $since, ?int $user_id = null ): array {
		$spec         = OrderBackfillJob::table_spec( $hpos, $prefix );
		$statuses     = OrderBackfillJob::non_sale_status_slugs();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sale_where   = "o.{$spec['type_col']} = %s AND o.{$spec['status_col']} NOT IN ( {$placeholders} ) AND o.{$spec['created_col']} >= %s";
		$args         = array_merge( array( 'shop_order' ), $statuses, array( $since ) );

		if ( $hpos ) {
			$sql = "SELECT o.customer_id FROM {$spec['table']} o WHERE {$sale_where} AND o.customer_id > 0";
			if ( $user_id !== null ) {
				$sql   .= ' AND o.customer_id = %d';
				$args[] = $user_id;
			}
			return array( $sql, $args );
		}

		$sql = "SELECT CAST( m.meta_value AS UNSIGNED ) FROM {$prefix}postmeta m INNER JOIN {$spec['table']} o ON o.{$spec['id_col']} = m.post_id WHERE m.meta_key = '_customer_user' AND {$sale_where}";
		if ( $user_id !== null ) {
			$sql   .= ' AND m.meta_value = %s';
			$args[] = (string) $user_id;
		}
		return array( $sql, $args );
	}

	/** Has this user a sale order placed at or after $since? Overridden in unit tests. */
	protected function has_sale_order_since( int $user_id, string $since ): bool {
		global $wpdb;

		list( $sql, $args ) = self::buyers_sql( OrderBackfillJob::uses_hpos(), $wpdb->prefix, $since, $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are in $sql (buyers_sql()).
		return $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', $args ) ) !== null;
	}
}
