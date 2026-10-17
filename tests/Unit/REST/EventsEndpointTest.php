<?php
/**
 * EventsEndpoint::is_skipped() — which Event Log rows read "skipped"
 * (PRO-4098). The SQL twin behind the Sent filter is pinned by the
 * integration suite (RecEngineEventsTest).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\EventsEndpoint;

final class EventsEndpointTest extends TestCase {

	public function test_a_sent_row_with_a_skipped_outcome_is_skipped_with_or_without_a_reason(): void {
		self::assertTrue( EventsEndpoint::is_skipped( 'sent', '{"outcome":"skipped","note":"nothing was POSTed"}' ) );
		self::assertTrue( EventsEndpoint::is_skipped( 'sent', '{"outcome":"skipped","reason":"no_workflow"}' ) );
	}

	public function test_a_row_the_engine_answered_is_not_skipped(): void {
		self::assertFalse( EventsEndpoint::is_skipped( 'sent', '{"http":200,"outcome":"accepted"}' ) );
		self::assertFalse( EventsEndpoint::is_skipped( 'sent', '{"outcome":"cancelled","note":"x"}' ) );
	}

	public function test_a_sent_row_with_no_stored_response_is_not_skipped(): void {
		self::assertFalse( EventsEndpoint::is_skipped( 'sent', '' ) );
		self::assertFalse( EventsEndpoint::is_skipped( 'sent', 'not json' ) );
	}

	/** A failed row keeps its failed status even when nothing was sent (the nightly manifest's own stops). */
	public function test_only_a_sent_row_can_be_skipped(): void {
		self::assertFalse( EventsEndpoint::is_skipped( 'failed', '{"outcome":"skipped","reason":"build_failed"}' ) );
		self::assertFalse( EventsEndpoint::is_skipped( 'pending', '{"outcome":"skipped"}' ) );
	}
}
