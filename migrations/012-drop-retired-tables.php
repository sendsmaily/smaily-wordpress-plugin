<?php
/**
 * Schema migration 012 — drop the legacy abandoned-cart table and the unused
 * smly_rec_visitor table, after draining the legacy carts (CON-182).
 *
 * A `.php` migration because dbDelta() silently ignores DROP TABLE. The
 * returned callable answers false when the step must be retried on the next
 * update; the Migrator then does not record version 12. The logic lives in
 * Migration\RetiredTables.
 *
 * @package Smaily\Connect
 */

defined( 'ABSPATH' ) || exit;

return static function (): bool {
	return ( new \Smaily\Connect\Migration\RetiredTables() )->run();
};
