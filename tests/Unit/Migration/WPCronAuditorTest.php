<?php
/**
 * WPCronAuditor tests.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Migration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Migration\WPCronAuditor;

final class WPCronAuditorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_audit_before_clear_returns_only_scheduled_hooks(): void {
		Functions\when( 'wp_next_scheduled' )->alias(
			static function ( string $hook ): int|bool {
				// Only one of the three legacy hooks is currently scheduled.
				return $hook === 'smaily_connect_cron_sync_subscribers' ? 1234567890 : false;
			}
		);

		$found = ( new WPCronAuditor() )->audit_before_clear();

		self::assertCount( 1, $found );
		self::assertArrayHasKey( 'smaily_connect_cron_sync_subscribers', $found );
		self::assertSame( 1234567890, $found['smaily_connect_cron_sync_subscribers'] );
	}

	public function test_audit_before_clear_returns_empty_when_no_legacy_hooks_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		self::assertSame( array(), ( new WPCronAuditor() )->audit_before_clear() );
	}

	public function test_clear_legacy_crons_calls_wp_clear_scheduled_hook_for_each(): void {
		$cleared = array();
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( string $hook ) use ( &$cleared ): bool {
				$cleared[] = $hook;
				return true;
			}
		);

		$result = ( new WPCronAuditor() )->clear_legacy_crons();

		self::assertSame( WPCronAuditor::LEGACY_HOOKS, $cleared );
		self::assertSame( WPCronAuditor::LEGACY_HOOKS, $result );
	}

	public function test_audit_after_clear_returns_empty_when_all_hooks_were_cleared(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		self::assertSame( array(), ( new WPCronAuditor() )->audit_after_clear() );
	}

	public function test_audit_after_clear_flags_survivors(): void {
		Functions\when( 'wp_next_scheduled' )->alias(
			static function ( string $hook ): int|bool {
				return $hook === 'smaily_connect_cron_abandoned_carts_email' ? 1700000000 : false;
			}
		);

		$survivors = ( new WPCronAuditor() )->audit_after_clear();

		self::assertSame( array( 'smaily_connect_cron_abandoned_carts_email' ), $survivors );
	}

	public function test_legacy_hooks_constant_exposes_all_three_known_hooks(): void {
		// Guard against accidentally dropping one — the constant feeds the
		// audit + clear methods, so missing entries would silently leave
		// WP-Cron rows in place after migration.
		self::assertContains( 'smaily_connect_cron_sync_subscribers', WPCronAuditor::LEGACY_HOOKS );
		self::assertContains( 'smaily_connect_cron_abandoned_carts_status', WPCronAuditor::LEGACY_HOOKS );
		self::assertContains( 'smaily_connect_cron_abandoned_carts_email', WPCronAuditor::LEGACY_HOOKS );
		self::assertCount( 3, WPCronAuditor::LEGACY_HOOKS );
	}
}
