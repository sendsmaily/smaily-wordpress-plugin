<?php
/**
 * Integration: NotificationManager::run_health_check (3.10.2) — proves the
 * failed-count signal sets a persisted admin notice against the real queues.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;

final class RecEngineHealthCheckTest extends TestCase {

	private function rec_table(): string {
		global $wpdb;
		return $wpdb->prefix . IngestQueue::TABLE_SUFFIX;
	}

	protected function setUp(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB
		$wpdb->query( 'DELETE FROM ' . $this->rec_table() );
		// phpcs:enable WordPress.DB
		delete_option( NotificationManager::OPTION_NOTICES );
		delete_option( NotificationManager::OPTION_DOWN_SINCE );
		// Force disconnected so the engine probe is a clean no-op (no ping).
		delete_option( 'smly_rec_connected' );

		// Lower the threshold so two seeded failures trip it.
		add_filter( 'smaily_connect_failed_notice_threshold', array( $this, 'low_threshold' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'smaily_connect_failed_notice_threshold', array( $this, 'low_threshold' ) );
		delete_option( NotificationManager::OPTION_NOTICES );
	}

	public function low_threshold(): int {
		return 1;
	}

	private function seed_failed( int $n ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		for ( $i = 0; $i < $n; $i++ ) {
			$wpdb->insert(
				$this->rec_table(),
				array(
					'event_type'   => 'order.upsert',
					'entity_id'    => (string) $i,
					'event_uuid'   => wp_generate_uuid4(),
					'payload'      => '{}',
					'created_at'   => $now,
					'attempts'     => 5,
					'max_attempts' => 5,
					'last_error'   => 'http_503 down',
					'status'       => 'failed',
				)
			);
		}
	}

	private function manager(): NotificationManager {
		return new NotificationManager(
			new RecEngineSettings(),
			// Never invoked while disconnected; a valid client keeps the type honest.
			static fn (): RecEngineClient => new RecEngineClient( 'sk_unused', 'https://unused.test' ),
			// Smaily not probed in this test — null = email side unconfigured.
			static fn (): ?SmailyClient => null
		);
	}

	public function test_failed_count_over_threshold_persists_an_admin_notice(): void {
		$this->seed_failed( 2 );

		$this->manager()->run_health_check();

		$notices = get_option( NotificationManager::OPTION_NOTICES, array() );
		self::assertArrayHasKey( 'failed_events', $notices );
		self::assertSame( 2, (int) $notices['failed_events']['count'] );
		self::assertArrayNotHasKey( 'engine_down', $notices, 'disconnected → no engine-down signal' );
	}

	public function test_no_failures_clears_the_notice(): void {
		// No seeded failures — the health check should persist an empty notice set.
		$this->manager()->run_health_check();

		$notices = get_option( NotificationManager::OPTION_NOTICES, array() );
		self::assertArrayNotHasKey( 'failed_events', $notices );
	}
}
