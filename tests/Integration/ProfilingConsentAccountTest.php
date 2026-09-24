<?php
/**
 * Integration: the My Account "Smaily Campaign Intelligence" section shows
 * only where Campaign Intelligence is live (PRO-2513).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Settings\SetupState;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * What this catches that the unit test can't: it fires the REAL
 * `woocommerce_account_dashboard` action the plugin registered at boot, on a
 * store whose options are real wp_options rows — so "plugin installed,
 * Campaign Intelligence never configured" is the actual store state, not a
 * stub.
 */
final class ProfilingConsentAccountTest extends TestCase {

	private int $user_id = 0;

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 'pro2513_' . wp_generate_password( 6, false ),
				'user_email' => 'pro2513@example.test',
				'user_pass'  => wp_generate_password(),
			)
		);
		wp_set_current_user( $this->user_id );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->user_id );
		$hash = md5( 'pro2513@example.test' );
		delete_transient( 'smly_profiling_' . $hash );
		delete_transient( 'smly_profiling_stale_' . $hash );
		EnvScrub::reset();
		parent::tearDown();
	}

	private function dashboard(): string {
		ob_start();
		do_action( 'woocommerce_account_dashboard' );
		return (string) ob_get_clean();
	}

	/** A previous successful read, so the shown section needs no Smaily call. */
	private function seed_known_opt_in(): void {
		$hash = md5( 'pro2513@example.test' );
		set_transient( 'smly_profiling_' . $hash, '1', DAY_IN_SECONDS );
		set_transient( 'smly_profiling_stale_' . $hash, '1', DAY_IN_SECONDS );
	}

	public function test_no_section_when_campaign_intelligence_was_never_configured(): void {
		update_option( SetupState::OPTION_SETUP_COMPLETED, true );
		self::assertFalse( ( new RecEngineSettings() )->is_connected(), 'Precondition: no engine connection.' );

		self::assertStringNotContainsString( 'smly-profiling-consent', $this->dashboard() );
	}

	public function test_no_section_when_the_email_setup_is_not_finished(): void {
		EnvSeed::connect();

		self::assertStringNotContainsString( 'smly-profiling-consent', $this->dashboard() );
	}

	public function test_no_section_when_the_engine_account_was_deactivated(): void {
		update_option( SetupState::OPTION_SETUP_COMPLETED, true );
		EnvSeed::connect();
		( new RecEngineSettings() )->mark_refused();

		self::assertStringNotContainsString( 'smly-profiling-consent', $this->dashboard() );
	}

	public function test_section_appears_on_connect_and_goes_on_disconnect_keeping_the_preference(): void {
		update_option( SetupState::OPTION_SETUP_COMPLETED, true );
		$this->seed_known_opt_in();
		self::assertStringNotContainsString( 'smly-profiling-consent', $this->dashboard() );

		// The merchant connects — the next page load shows it, nothing re-registered.
		EnvSeed::connect();
		$html = $this->dashboard();
		self::assertStringContainsString( 'Smaily Campaign Intelligence', $html );
		self::assertStringContainsString( "checked='checked'", $html );

		// The merchant disconnects — hidden again, the saved preference kept.
		( new RecEngineSettings() )->disconnect();
		self::assertStringNotContainsString( 'smly-profiling-consent', $this->dashboard() );
		self::assertSame( '1', get_transient( 'smly_profiling_stale_' . md5( 'pro2513@example.test' ) ) );
	}
}
