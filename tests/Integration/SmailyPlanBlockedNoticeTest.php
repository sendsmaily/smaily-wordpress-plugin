<?php
/**
 * Integration: what a store is told when its Smaily account moves to a package
 * that does not include the API (PRO-1686).
 *
 * Runs the REAL health check with the REAL Smaily Client, only the HTTP
 * transport faked — replaying the exact answer a live freemium account gave on
 * 2026-08-04: HTTP 403 {"code":227,"message":"A paid package is required."} to
 * every endpoint the plugin uses. Against the pre-fix code the store was told
 * the API was "unreachable … until the connection recovers", which was false
 * twice over.
 *
 * The restore half is the same run with the transport answering normally
 * again — the notice must clear by itself, with no credentials re-entered.
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

final class SmailyPlanBlockedNoticeTest extends TestCase {

	protected function setUp(): void {
		delete_option( NotificationManager::OPTION_NOTICES );
		delete_option( NotificationManager::OPTION_SMAILY_DOWN_SINCE );
		delete_option( NotificationManager::OPTION_DOWN_SINCE );
		// Disconnected rec engine — this test is about the Smaily email path.
		delete_option( 'smly_rec_connected' );
	}

	protected function tearDown(): void {
		delete_option( NotificationManager::OPTION_NOTICES );
		delete_option( NotificationManager::OPTION_SMAILY_DOWN_SINCE );
	}

	private function manager(): NotificationManager {
		return new NotificationManager(
			new RecEngineSettings(),
			static fn (): RecEngineClient => new RecEngineClient( 'sk_unused', 'https://unused.test' ),
			static fn (): ?SmailyClient => new SmailyClient( 'demo', 'alice', 's3cret' )
		);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function run_health_check_against( int $status, array $body ): void {
		$fake = static function ( $preempt, $args ) use ( $status, $body ) {
			unset( $preempt, $args );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			$this->manager()->run_health_check();
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}
	}

	/** @return array<string, array<string, mixed>> */
	private function notices(): array {
		$notices = get_option( NotificationManager::OPTION_NOTICES, array() );
		return is_array( $notices ) ? $notices : array();
	}

	public function test_a_freemium_account_is_told_the_package_is_the_cause(): void {
		$this->run_health_check_against(
			403,
			array(
				'code'    => 227,
				'message' => 'A paid package is required.',
			)
		);

		$notices = $this->notices();

		self::assertArrayHasKey( 'smaily_plan_blocked', $notices, 'The package refusal must raise its own signal.' );
		self::assertArrayNotHasKey( 'smaily_down', $notices, 'Smaily answered — it is not unreachable.' );
		self::assertArrayNotHasKey( 'smaily_credentials_rejected', $notices, 'The credentials were never refused.' );
	}

	public function test_wrong_credentials_are_not_blamed_on_the_package(): void {
		$this->run_health_check_against( 401, array( 'error' => 'unauthorized' ) );

		$notices = $this->notices();

		self::assertArrayHasKey( 'smaily_credentials_rejected', $notices );
		self::assertArrayNotHasKey( 'smaily_plan_blocked', $notices );
	}

	public function test_a_real_outage_still_reads_as_an_outage_after_the_grace_hour(): void {
		// First tick stamps down_since; a 5xx is the "might pass" case, so
		// nothing is raised yet.
		$this->run_health_check_against( 503, array( 'error' => 'boom' ) );
		self::assertArrayNotHasKey( 'smaily_down', $this->notices(), 'A fresh outage waits out the grace hour.' );

		// Backdate the stamp past the grace and run again.
		update_option( NotificationManager::OPTION_SMAILY_DOWN_SINCE, time() - 7200, false );
		$this->run_health_check_against( 503, array( 'error' => 'boom' ) );

		$notices = $this->notices();
		self::assertArrayHasKey( 'smaily_down', $notices );
		self::assertArrayNotHasKey( 'smaily_plan_blocked', $notices );
	}

	public function test_the_notice_clears_itself_once_the_package_is_restored(): void {
		$this->run_health_check_against(
			403,
			array(
				'code'    => 227,
				'message' => 'A paid package is required.',
			)
		);
		self::assertArrayHasKey( 'smaily_plan_blocked', $this->notices() );

		// The plan is restored: Smaily answers the workflow listing again. No
		// credentials are touched — the same stored ones are used.
		$this->run_health_check_against( 200, array() );

		$notices = $this->notices();
		self::assertArrayNotHasKey( 'smaily_plan_blocked', $notices, 'A restored package clears the notice on its own.' );
		self::assertArrayNotHasKey( 'smaily_down', $notices );
		self::assertArrayNotHasKey( 'smaily_credentials_rejected', $notices );
		self::assertFalse(
			(bool) get_option( NotificationManager::OPTION_SMAILY_DOWN_SINCE, false ),
			'The down_since stamp is cleared too, so a later outage starts its own grace.'
		);
	}
}
