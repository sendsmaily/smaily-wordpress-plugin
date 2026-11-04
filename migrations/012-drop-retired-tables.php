<?php
/**
 * Schema migration 012 — drop the legacy abandoned-cart table and the unused
 * smly_rec_visitor table, after draining the legacy carts (CON-182).
 *
 * A `.php` migration because dbDelta() silently ignores DROP TABLE. The
 * returned callable answers Migrator::DEFERRED when the step must be retried
 * on the next update: no later migration depends on the drop, so they run
 * meanwhile (CON-282 — answering false held back migration 013 and left the
 * 3.21.0 code on a table without its column). The logic lives in
 * Migration\RetiredTables.
 *
 * @package Smaily\Connect
 */

defined( 'ABSPATH' ) || exit;

return static function (): bool|string {
	return ( new \Smaily\Connect\Migration\RetiredTables() )->run() ? true : \Smaily\Connect\DB\Migrator::DEFERRED;
};
