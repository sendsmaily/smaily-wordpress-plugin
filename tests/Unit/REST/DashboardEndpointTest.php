<?php
/**
 * DashboardEndpoint's rules (CON-464): which stored health notice makes
 * Smaily "refusing", how many nights the nightly product list is missing,
 * and when the background tasks read "Not running". The queue counts are
 * pinned by the integration suite (DashboardEndpointTest).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\DashboardEndpoint;

final class DashboardEndpointTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function test_a_plan_without_api_access_and_rejected_credentials_are_refusals(): void {
		self::assertSame( 'plan_blocked', DashboardEndpoint::smaily_refusal( array( 'smaily_plan_blocked' => array() ) ) );
		self::assertSame( 'credentials_rejected', DashboardEndpoint::smaily_refusal( array( 'smaily_credentials_rejected' => array() ) ) );
	}

	public function test_an_unreachable_smaily_or_other_notices_are_no_refusal(): void {
		self::assertSame( '', DashboardEndpoint::smaily_refusal( array() ) );
		self::assertSame( '', DashboardEndpoint::smaily_refusal( array( 'smaily_down' => array(), 'engine_down' => array(), 'failed_events' => array() ) ) );
	}

	public function test_the_product_list_is_missing_from_the_third_night_after_the_last_one_sent(): void {
		$day = 86400;
		self::assertSame( 0, DashboardEndpoint::unsent_nights( self::NOW - 2 * $day - 3600, null, self::NOW ) );
		self::assertSame( 3, DashboardEndpoint::unsent_nights( self::NOW - 3 * $day, null, self::NOW ) );
		self::assertSame( 5, DashboardEndpoint::unsent_nights( self::NOW - 5 * $day - 60, self::NOW - 90 * $day, self::NOW ) );
	}

	/** No list was ever sent: counted from the connection, so a new connection has nothing missing. */
	public function test_with_no_list_sent_the_nights_count_from_the_connection(): void {
		$day = 86400;
		self::assertSame( 0, DashboardEndpoint::unsent_nights( null, self::NOW - $day, self::NOW ) );
		self::assertSame( 4, DashboardEndpoint::unsent_nights( null, self::NOW - 4 * $day, self::NOW ) );
		self::assertSame( 0, DashboardEndpoint::unsent_nights( null, null, self::NOW ) );
	}

	public function test_a_401_answer_is_a_rejected_key(): void {
		self::assertTrue( DashboardEndpoint::key_rejected( 'unauthorised' ) );
		self::assertTrue( DashboardEndpoint::key_rejected( 'http_401' ) );
		self::assertTrue( DashboardEndpoint::key_rejected( 'http_403' ) );
	}

	/** A deactivated account is is_refused(), not a rejected key; an outage or a success is neither. */
	public function test_other_answers_are_no_rejected_key(): void {
		self::assertFalse( DashboardEndpoint::key_rejected( null ) );
		self::assertFalse( DashboardEndpoint::key_rejected( 'tenant_inactive' ) );
		self::assertFalse( DashboardEndpoint::key_rejected( 'network_error' ) );
		self::assertFalse( DashboardEndpoint::key_rejected( 'http_503' ) );
	}

	public function test_background_tasks_run_while_an_action_ran_in_the_last_fifteen_minutes(): void {
		self::assertTrue( DashboardEndpoint::background_running( self::NOW - 60, self::NOW ) );
		self::assertTrue( DashboardEndpoint::background_running( self::NOW - 900, self::NOW ) );
		self::assertFalse( DashboardEndpoint::background_running( self::NOW - 901, self::NOW ) );
		self::assertFalse( DashboardEndpoint::background_running( null, self::NOW ) );
	}
}
