<?php
/**
 * Unit: NotificationManager (3.10.2) — the pure signal-evaluation logic.
 *
 * @package Smaily\Connect\Tests\Unit\Notifications
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Notifications;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RefusalReason;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;

final class NotificationManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function manager(): NotificationManager {
		$client = $this->createMock( RecEngineClient::class );
		return new NotificationManager(
			$this->createMock( RecEngineSettings::class ),
			static fn (): RecEngineClient => $client,
			static fn (): ?SmailyClient => null
		);
	}

	public function test_failed_count_over_threshold_raises_a_notice(): void {
		$notices = $this->manager()->evaluate_signals( 51, null, 1_000_000, 50 );

		self::assertArrayHasKey( 'failed_events', $notices );
		self::assertSame( 51, $notices['failed_events']['count'] );
		self::assertSame( 'error', $notices['failed_events']['severity'] );
		self::assertArrayNotHasKey( 'engine_down', $notices );
	}

	public function test_failed_count_at_threshold_does_not_raise(): void {
		// Strictly greater — 50 is not > 50.
		$notices = $this->manager()->evaluate_signals( 50, null, 1_000_000, 50 );

		self::assertArrayNotHasKey( 'failed_events', $notices );
	}

	public function test_engine_down_over_an_hour_raises_a_notice(): void {
		$now     = 1_000_000;
		$notices = $this->manager()->evaluate_signals( 0, $now - 3700, $now, 50 );

		self::assertArrayHasKey( 'engine_down', $notices );
	}

	public function test_engine_down_within_grace_does_not_raise(): void {
		$now     = 1_000_000;
		// Down for 30 min — under the 1h grace, so no notice yet.
		$notices = $this->manager()->evaluate_signals( 0, $now - 1800, $now, 50 );

		self::assertArrayNotHasKey( 'engine_down', $notices );
	}

	public function test_engine_up_raises_nothing(): void {
		$notices = $this->manager()->evaluate_signals( 0, null, 1_000_000, 50 );

		self::assertSame( array(), $notices );
	}

	public function test_both_signals_can_be_active(): void {
		$now     = 1_000_000;
		$notices = $this->manager()->evaluate_signals( 99, $now - 7200, $now, 50 );

		self::assertArrayHasKey( 'failed_events', $notices );
		self::assertArrayHasKey( 'engine_down', $notices );
	}

	public function test_smaily_down_over_an_hour_raises_a_separate_signal(): void {
		$now     = 1_000_000;
		$notices = $this->manager()->evaluate_signals( 0, null, $now, 50, $now - 3700 );

		self::assertArrayHasKey( 'smaily_down', $notices );
		self::assertArrayNotHasKey( 'engine_down', $notices, 'rec engine is up — only Smaily is down' );
	}

	public function test_smaily_down_within_grace_does_not_raise(): void {
		$now     = 1_000_000;
		$notices = $this->manager()->evaluate_signals( 0, null, $now, 50, $now - 1800 );

		self::assertArrayNotHasKey( 'smaily_down', $notices );
	}

	public function test_both_sync_paths_can_be_down_at_once(): void {
		$now     = 1_000_000;
		$notices = $this->manager()->evaluate_signals( 0, $now - 7200, $now, 50, $now - 7200 );

		self::assertArrayHasKey( 'engine_down', $notices );
		self::assertArrayHasKey( 'smaily_down', $notices );
	}

	public function test_a_package_block_raises_its_own_signal_without_waiting_out_the_grace(): void {
		// PRO-1686: Smaily already answered "227 — a paid package is required".
		// That answer will not change in an hour, and it is not "unreachable".
		$now     = 1_000_000;
		$notices = $this->manager()->evaluate_signals( 0, null, $now, 50, $now - 60, RefusalReason::PLAN_BLOCKED );

		self::assertArrayHasKey( 'smaily_plan_blocked', $notices );
		self::assertArrayNotHasKey( 'smaily_down', $notices, 'a package block is not an outage' );
	}

	public function test_rejected_credentials_raise_their_own_signal(): void {
		$now     = 1_000_000;
		$notices = $this->manager()->evaluate_signals( 0, null, $now, 50, $now - 60, RefusalReason::CREDENTIALS_REJECTED );

		self::assertArrayHasKey( 'smaily_credentials_rejected', $notices );
		self::assertArrayNotHasKey( 'smaily_plan_blocked', $notices );
		self::assertArrayNotHasKey( 'smaily_down', $notices );
	}

	public function test_smaily_answering_again_clears_every_smaily_signal(): void {
		// The restore path: nothing down ⇒ no notice, whatever the last cause was.
		$notices = $this->manager()->evaluate_signals( 0, null, 1_000_000, 50, null, RefusalReason::OK );

		self::assertSame( array(), $notices );
	}

	public function test_consent_advisory_fires_only_when_browse_on_connected_and_no_consent_api(): void {
		$m = $this->manager();

		// The trap: browse on + connected + no WP Consent API present.
		self::assertTrue( $m->needs_consent_api_notice( true, true, false ) );

		// Any one condition off ⇒ no advisory.
		self::assertFalse( $m->needs_consent_api_notice( true, true, true ), 'consent API present ⇒ nothing to advise' );
		self::assertFalse( $m->needs_consent_api_notice( false, true, false ), 'browse off ⇒ no beacon to gate' );
		self::assertFalse( $m->needs_consent_api_notice( true, false, false ), 'disconnected ⇒ beacon not loaded' );
	}

	public function test_dismiss_records_the_key_with_a_timestamp(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$captured = null;
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$captured ): bool {
				$captured = $value;
				return true;
			}
		);

		$this->manager()->dismiss( 'failed_events' );

		self::assertIsArray( $captured );
		self::assertArrayHasKey( 'failed_events', $captured );
		self::assertIsInt( $captured['failed_events'] );
	}
}
