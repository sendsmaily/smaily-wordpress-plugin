<?php
/**
 * Integration: the My Account "Smaily Campaign Intelligence" section shows
 * only where Campaign Intelligence is live (PRO-2513) — whether or not the
 * email setup wizard is finished — and its "couldn't load" state offers an
 * opt-out button that works without a Smaily client (PRO-3189) — and that
 * opt-out survives a later read of a contact with no preference (PRO-3191).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Privacy\ProfilingConsentAccount;
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
		unset( $_POST['_wpnonce'], $_POST[ ProfilingConsentAccount::OPT_OUT_FIELD ] );
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

	public function test_section_shows_while_the_email_setup_is_not_finished(): void {
		EnvSeed::connect();
		self::assertFalse( SetupState::completed(), 'Precondition: the email wizard is not finished.' );

		$html = $this->dashboard();

		self::assertStringContainsString( 'Smaily Campaign Intelligence', $html );
		// No Smaily client to read from, nothing stored → the unknown state.
		self::assertStringContainsString( 'name="' . ProfilingConsentAccount::OPT_OUT_FIELD . '"', $html );
		self::assertStringNotContainsString( 'type="checkbox"', $html );
	}

	/**
	 * The unknown state's button, end to end on a store with no Smaily
	 * client (wizard unfinished): the opt-out is recorded durably, the
	 * engine is told, and the next load shows the box unticked.
	 */
	public function test_opt_out_button_round_trip_without_a_smaily_client(): void {
		EnvSeed::connect();
		self::assertStringContainsString( 'name="' . ProfilingConsentAccount::OPT_OUT_FIELD . '"', $this->dashboard() );

		if ( WC()->session === null ) {
			wc_load_cart();
		}
		$engine_calls = array();
		$fake_engine  = static function ( $pre, array $args, string $url ) use ( &$engine_calls ) {
			$engine_calls[] = array( $args['method'] ?? '', $url, (string) ( $args['body'] ?? '' ) );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'ok' => true, 'opt_out_status' => true ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		};
		$stop_at_redirect = static function (): void {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'pre_http_request', $fake_engine, 10, 3 );
		add_filter( 'wp_redirect', $stop_at_redirect );

		$_POST['_wpnonce']                             = wp_create_nonce( ProfilingConsentAccount::ACTION );
		$_POST[ ProfilingConsentAccount::OPT_OUT_FIELD ] = '1';
		$account = new ProfilingConsentAccount( Bootstrap::instance()->profiling_consent(), new RecEngineSettings() );
		$redirected = false;
		try {
			$account->handle_post();
		} catch ( \RuntimeException $e ) {
			$redirected = $e->getMessage() === 'redirected';
		} finally {
			remove_filter( 'pre_http_request', $fake_engine, 10 );
			remove_filter( 'wp_redirect', $stop_at_redirect );
			wc_clear_notices();
		}

		self::assertTrue( $redirected, 'The handler accepted the submit and redirected.' );
		self::assertCount( 1, $engine_calls, 'Exactly one engine call — no Smaily write without a client.' );
		self::assertStringContainsString( '/opt-out', $engine_calls[0][1] );
		self::assertStringContainsString( '"opt_out":true', $engine_calls[0][2] );
		self::assertArrayHasKey( md5( 'pro2513@example.test' ), (array) get_option( 'smly_profiling_optouts', array() ) );
		self::assertFalse( Bootstrap::instance()->profiling_consent()->may_profile( 'pro2513@example.test' ) );

		$html = $this->dashboard();
		self::assertStringContainsString( 'type="checkbox"', $html );
		self::assertStringNotContainsString( "checked='checked'", $html );
		self::assertStringNotContainsString( ProfilingConsentAccount::OPT_OUT_FIELD, $html );
	}

	/**
	 * The lapse PRO-3191 closes, end to end: the shopper opts out while the
	 * store has no Smaily client (the write is skipped), the merchant then
	 * finishes the email setup, and the shopper's Smaily contact carries no
	 * profiling preference. Once the daily cache expires, the fresh read
	 * SUCCEEDS — and must not lift the opt-out; the opt-out is written to
	 * the contact instead. Smaily is faked at the pre_http_request seam
	 * (the established one, see CartPipelineTest).
	 */
	public function test_opt_out_survives_a_later_read_of_a_contact_without_a_preference(): void {
		EnvSeed::connect();
		if ( WC()->session === null ) {
			wc_load_cart();
		}
		$smaily_calls = array();
		$fake_http    = static function ( $pre, array $args, string $url ) use ( &$smaily_calls ) {
			$body = array( 'ok' => true, 'opt_out_status' => true ); // the engine's reply.
			if ( strpos( $url, '.sendsmaily.net/api/contact.php' ) !== false ) {
				$smaily_calls[] = array( $args['method'] ?? '', $args['body'] ?? null );
				$body           = ( $args['method'] ?? '' ) === 'GET'
					? array( 'email' => 'pro2513@example.test', 'is_unsubscribed' => '0' ) // no smaily_rec_profiling.
					: array( 'code' => 101, 'message' => 'OK' );
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		};
		$stop_at_redirect = static function (): void {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'pre_http_request', $fake_http, 10, 3 );
		add_filter( 'wp_redirect', $stop_at_redirect );

		try {
			// 1. Opt out from My Account while the email wizard is unfinished.
			self::assertFalse( SetupState::completed(), 'Precondition: no Smaily client yet.' );
			$_POST['_wpnonce']                               = wp_create_nonce( ProfilingConsentAccount::ACTION );
			$_POST[ ProfilingConsentAccount::OPT_OUT_FIELD ] = '1';
			try {
				( new ProfilingConsentAccount( Bootstrap::instance()->profiling_consent(), new RecEngineSettings() ) )->handle_post();
			} catch ( \RuntimeException $e ) {
				self::assertSame( 'redirected', $e->getMessage() );
			}
			self::assertSame( array(), $smaily_calls, 'No Smaily client → the opt-out never reached Smaily.' );

			// 2. The merchant finishes the email setup; the daily cache expires.
			update_option(
				'smaily_connect_api_credentials',
				array(
					'subdomain' => 'testsub',
					'username'  => 'tester',
					'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
				)
			);
			update_option( SetupState::OPTION_SETUP_COMPLETED, true );
			delete_transient( 'smly_profiling_' . md5( 'pro2513@example.test' ) );

			// 3. The fresh read succeeds with no preference — still opted out.
			$consent = Bootstrap::instance()->profiling_consent();
			self::assertFalse( $consent->may_profile( 'pro2513@example.test' ) );
			self::assertFalse( $consent->known_preference( 'pro2513@example.test' ) );
			self::assertArrayHasKey( md5( 'pro2513@example.test' ), (array) get_option( 'smly_profiling_optouts', array() ) );

			// … and the opt-out was carried to the contact that lacked it.
			self::assertCount( 2, $smaily_calls, 'One read, one write-back.' );
			self::assertSame( 'GET', $smaily_calls[0][0] );
			self::assertSame( 'POST', $smaily_calls[1][0] );
			self::assertSame( 'pro2513@example.test', $smaily_calls[1][1][0]['email'] );
			self::assertSame( 0, $smaily_calls[1][1][0]['smaily_rec_profiling'] );
			self::assertNotEmpty( $smaily_calls[1][1][0]['smaily_rec_profiling_ts'] );
		} finally {
			remove_filter( 'pre_http_request', $fake_http, 10 );
			remove_filter( 'wp_redirect', $stop_at_redirect );
			wc_clear_notices();
			// Drop the Smaily client cached from the seeded credentials.
			$prop = new \ReflectionProperty( Bootstrap::instance(), 'smaily_clients' );
			$prop->setAccessible( true );
			$prop->setValue( Bootstrap::instance(), array() );
		}
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
