<?php
/**
 * Unit: OrderBackfillJob's pure storage-mode mapping (3.5.2) + the single-source
 * mapped-status list. The HPOS path runs no integration query (the pilot is
 * legacy), so its table/column mapping is verified here as a forward-compat
 * guarantee — see STATUS / CLAUDE "OrderBackfill HPOS path".
 *
 * @package Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine\Backfill;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\Backfill\OrderBackfillJob;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;

final class OrderBackfillJobTest extends TestCase {

	public function test_legacy_table_spec_targets_wp_posts(): void {
		$spec = OrderBackfillJob::table_spec( false, 'wp_' );
		self::assertSame( 'wp_posts', $spec['table'] );
		self::assertSame( 'ID', $spec['id_col'] );
		self::assertSame( 'post_type', $spec['type_col'] );
		self::assertSame( 'post_status', $spec['status_col'] );
	}

	public function test_hpos_table_spec_targets_wc_orders(): void {
		$spec = OrderBackfillJob::table_spec( true, 'wp_' );
		self::assertSame( 'wp_wc_orders', $spec['table'], 'HPOS reads the custom orders table, not wp_posts.' );
		self::assertSame( 'id', $spec['id_col'] );
		self::assertSame( 'type', $spec['type_col'] );
		self::assertSame( 'status', $spec['status_col'] );
	}

	public function test_non_sale_statuses_are_the_single_source_for_the_filter(): void {
		// The backfill filters `status NOT IN (non-sale)`, so its single source is
		// the DENYLIST of non-sale statuses (F3-42). Sale + custom statuses are
		// NOT denylisted, so the backfill includes them.
		$non_sale = OrderPayloadBuilder::non_sale_wc_statuses();

		self::assertContains( 'pending', $non_sale );
		self::assertContains( 'failed', $non_sale );
		self::assertContains( 'trash', $non_sale );
		self::assertNotContains( 'completed', $non_sale );
		self::assertNotContains( 'shipped', $non_sale, 'A custom status is a sale now — not denylisted.' );

		$builder = new OrderPayloadBuilder();

		// Drift guard: every denylisted status maps to '' (excluded / skipped).
		foreach ( $non_sale as $status ) {
			self::assertSame( '', $builder->map_status( $status ), "{$status} must be excluded (map to '')." );
		}

		// A custom status is NOT denylisted and maps to a non-empty sale enum.
		self::assertSame( 'processing', $builder->map_status( 'label-printed' ), 'A custom status defaults through as a sale (F3-42).' );
	}
}
