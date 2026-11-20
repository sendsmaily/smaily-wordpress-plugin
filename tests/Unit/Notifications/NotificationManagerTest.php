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
use Smaily\Connect\Constants;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\Report\ReportBuilder;
use Smaily\Connect\Settings\CredentialSet;
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

	// --- the invitation to a store with no Smaily account (CON-321) ----------

	private const INVITE_TEXT = 'Connect your store to Smaily to sync contacts and set up automated emails.';

	/**
	 * What render() prints for a user, with the given main account and
	 * dismissal stamp.
	 */
	private function render_invite( ?CredentialSet $main, ?int $dismissed_at = null, bool $can = true ): string {
		$builder = $this->createMock( ReportBuilder::class );
		$builder->method( 'main_credentials' )->willReturn( $main );

		$client  = $this->createMock( RecEngineClient::class );
		$manager = new NotificationManager(
			$this->createMock( RecEngineSettings::class ),
			static fn (): RecEngineClient => $client,
			static fn (): ?SmailyClient => null,
			$builder
		);

		$dismissed = $dismissed_at === null ? array() : array( NotificationManager::INVITE_KEY => $dismissed_at );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'current_user_can' )->justReturn( $can );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $fallback = false ) use ( $dismissed ) {
				return $name === NotificationManager::OPTION_DISMISSED ? $dismissed : $fallback;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_nonce_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();

		ob_start();
		$manager->render();

		return (string) ob_get_clean();
	}

	public function test_a_store_with_no_smaily_account_is_invited_to_connect_one(): void {
		$html = $this->render_invite( null );

		self::assertStringContainsString( self::INVITE_TEXT, $html );
		self::assertStringContainsString( 'notice-info', $html );
		self::assertStringContainsString( 'admin.php?page=smaily-connect-settings#connection', $html );
		self::assertStringContainsString( 'href="' . Constants::SIGNUP_URL . '"', $html, 'A store with no account can create one.' );
		self::assertStringContainsString( 'href="' . Constants::DOCS_URL . '#step1"', $html, 'The guide to the API credentials is one click away.' );
		self::assertStringContainsString( 'key=' . NotificationManager::INVITE_KEY, $html, 'The invitation can be dismissed.' );
	}

	public function test_a_store_with_a_connected_smaily_account_is_not_invited(): void {
		$html = $this->render_invite( new CredentialSet( 'demo', 'alice', 's3cret' ) );

		self::assertStringNotContainsString( self::INVITE_TEXT, $html );
	}

	public function test_a_user_who_cannot_manage_the_plugin_sees_nothing(): void {
		self::assertSame( '', $this->render_invite( null, null, false ) );
	}

	public function test_a_dismissed_invitation_stays_away_for_ninety_days(): void {
		$html = $this->render_invite( null, time() - 89 * DAY_IN_SECONDS );

		self::assertStringNotContainsString( self::INVITE_TEXT, $html );
	}

	public function test_a_dismissed_invitation_comes_back_after_ninety_days(): void {
		$html = $this->render_invite( null, time() - 90 * DAY_IN_SECONDS - 1 );

		self::assertStringContainsString( self::INVITE_TEXT, $html );
	}
}
