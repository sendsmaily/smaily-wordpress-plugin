<?php
/**
 * Schema migration 012: drop the two tables nothing uses any more (CON-182).
 *
 * @package Smaily\Connect\Migration
 */

declare(strict_types=1);

namespace Smaily\Connect\Migration;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time DROP of the plugin's own retired tables on upgrade; names are prefix + constants.

use Smaily\Connect\Support\DebugLog;

/**
 * Drops, once, on update:
 *
 *   - `{prefix}smaily_connect_abandoned_carts` — the legacy (1.x / 2.0.0)
 *     abandoned-cart table. It holds customer ids with their cart contents and
 *     has no retention period. Nothing has written it since the PRO-1195
 *     rewrite; it was kept only as a rollback source.
 *   - `{prefix}smly_rec_visitor` — created by schema migration 005 for a
 *     visitor-merge tracker that was never built; nothing has ever written it.
 *
 * The legacy cart drain runs FIRST. wordpress.org still offers 1.x and 2.0.0,
 * which write the legacy table and have no drain (it first shipped in
 * 3.11.2), so a store can update straight from a version that never drained
 * its in-flight carts. The drain is a no-op on a store that already ran it
 * (its option stamp). When the drain cannot read the table, nothing is
 * dropped and run() returns false: the Migrator then leaves the schema
 * version where it is and the step is retried on the next upgrade.
 */
class RetiredTables {

	public const LEGACY_CART_TABLE = 'smaily_connect_abandoned_carts';

	public const VISITOR_TABLE = 'smly_rec_visitor';

	private LegacyCartDrain $drain;

	public function __construct( ?LegacyCartDrain $drain = null ) {
		$this->drain = $drain ?? new LegacyCartDrain();
	}

	/**
	 * @return bool False when the step must be retried on the next upgrade.
	 */
	public function run(): bool {
		global $wpdb;

		try {
			$this->drain->maybe_run();
		} catch ( \Throwable $e ) {
			DebugLog::write(
				sprintf(
					'[smaily-connect migration.012] Legacy cart drain failed (%s) - legacy table kept, retried on the next update.',
					get_class( $e )
				)
			);
			return false;
		}

		foreach ( array( self::LEGACY_CART_TABLE, self::VISITOR_TABLE ) as $suffix ) {
			if ( $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$suffix}`" ) === false ) {
				DebugLog::write(
					sprintf( '[smaily-connect migration.012] Could not drop %s%s - retried on the next update.', $wpdb->prefix, $suffix )
				);
				return false;
			}
		}

		// The stamp only guarded the drain, and nothing can drain any more.
		delete_option( LegacyCartDrain::DRAINED_OPTION );

		return true;
	}
}
