<?php
/**
 * Unit: ProfilingConsentAccount ((a).2) — the checkbox → opt-in/out mapping,
 * the "Campaign Intelligence is live" gate and the three display states
 * (PRO-2513), and the unknown state's opt-out button (PRO-3189).
 *
 * @package Smaily\Connect\Tests\Unit\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Privacy\ProfilingConsentAccount;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Settings\SetupState;

final class ProfilingConsentAccountTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		unset(
			$_POST[ ProfilingConsentAccount::ACTION . '_submit' ],
			$_POST[ ProfilingConsentAccount::OPT_OUT_FIELD ],
			$_POST[ ProfilingConsentAccount::FIELD ],
			$_POST['_wpnonce']
		);
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A recording double — ProfilingConsent is non-final by design.
	 *
	 * @param bool|null $known What known_preference() answers (null = unknown).
	 */
	private function spy( ?bool $known = true ): ProfilingConsent {
		return new class( $known ) extends ProfilingConsent {
			/** @var array<int, array{0: string, 1: string}> */
			public array $calls = array();

			private ?bool $known;

			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function __construct( ?bool $known ) { // Skip the real deps — we only record.
				$this->known = $known;
			}

			public function opt_in( string $email ): void {
				$this->calls[] = array( 'in', $email );
			}

			public function opt_out( string $email ): void {
				$this->calls[] = array( 'out', $email );
			}

			public function known_preference( string $email ): ?bool {
				$this->calls[] = array( 'read', $email );
				return $this->known;
			}
		};
	}

	private function account( ProfilingConsent $profiling ): ProfilingConsentAccount {
		return new ProfilingConsentAccount( $profiling, new RecEngineSettings() );
	}

	/**
	 * Seed the three options the gate reads: wizard finished, engine
	 * connected, engine refusal timestamp.
	 */
	private function store( bool $setup_completed, bool $connected, int $refused_at ): void {
		$options = array(
			SetupState::OPTION_SETUP_COMPLETED    => $setup_completed,
			RecEngineSettings::OPTION_CONNECTED   => $connected,
			RecEngineSettings::OPTION_REFUSED_AT  => $refused_at,
		);
		Functions\when( 'get_option' )->alias(
			static fn ( string $name, $fallback = false ) => array_key_exists( $name, $options ) ? $options[ $name ] : $fallback
		);
	}

	private function logged_in_shopper(): void {
		Functions\when( 'wp_get_current_user' )->justReturn(
			new class() extends \WP_User {
				public function __construct() {
					$this->ID         = 7;
					$this->user_email = 'Shopper@Example.com';
				}

				public function exists(): bool {
					return true;
				}
			}
		);
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true ): string {
				if ( (string) $checked === (string) $current ) {
					echo ' checked=\'checked\'';
				}
				return '';
			}
		);
	}

	private function rendered( ProfilingConsentAccount $account ): string {
		ob_start();
		$account->render();
		return (string) ob_get_clean();
	}

	// --- apply(): the checkbox → opt-in/out mapping ------------------------

	public function test_checked_opts_in(): void {
		$spy = $this->spy();
		$this->account( $spy )->apply( 'a@example.com', true );

		self::assertSame( array( array( 'in', 'a@example.com' ) ), $spy->calls );
	}

	public function test_unchecked_opts_out(): void {
		$spy = $this->spy();
		$this->account( $spy )->apply( 'a@example.com', false );

		self::assertSame( array( array( 'out', 'a@example.com' ) ), $spy->calls );
	}

	public function test_empty_email_is_a_no_op(): void {
		$spy = $this->spy();
		$this->account( $spy )->apply( '', false );

		self::assertSame( array(), $spy->calls );
	}

	// --- the gate (PRO-2513) ------------------------------------------------

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: int}>
	 */
	public static function hidden_stores(): array {
		return array(
			'Campaign Intelligence not connected' => array( true, false, 0 ),
			'engine account deactivated'          => array( true, true, 1757000000 ),
		);
	}

	/**
	 * @dataProvider hidden_stores
	 */
	public function test_section_is_hidden( bool $setup_completed, bool $connected, int $refused_at ): void {
		$this->store( $setup_completed, $connected, $refused_at );
		$this->logged_in_shopper();
		$spy     = $this->spy();
		$account = $this->account( $spy );

		self::assertFalse( $account->is_shown() );
		self::assertSame( '', $this->rendered( $account ), 'A hidden section prints nothing.' );
		self::assertSame( array(), $spy->calls, 'A hidden section does not even read the preference.' );
	}

	/**
	 * @dataProvider hidden_stores
	 */
	public function test_post_is_ignored_while_hidden( bool $setup_completed, bool $connected, int $refused_at ): void {
		$this->store( $setup_completed, $connected, $refused_at );
		$_POST[ ProfilingConsentAccount::ACTION . '_submit' ] = '1';
		Functions\expect( 'is_user_logged_in' )->never();
		Functions\expect( 'wp_verify_nonce' )->never();
		Functions\expect( 'wp_safe_redirect' )->never();
		$spy = $this->spy();

		$this->account( $spy )->handle_post();

		self::assertSame( array(), $spy->calls, 'A hidden section must not accept a submit.' );
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function setup_states(): array {
		return array(
			'email setup wizard finished'     => array( true ),
			'email setup wizard not finished' => array( false ),
		);
	}

	/**
	 * Shown whenever Campaign Intelligence is connected and active — the
	 * email wizard does not gate it (PRO-3189): engine ingest doesn't wait
	 * for the wizard, so neither may the opt-out.
	 *
	 * @dataProvider setup_states
	 */
	public function test_section_is_shown_when_active( bool $setup_completed ): void {
		$this->store( $setup_completed, true, 0 );
		$this->logged_in_shopper();
		$account = $this->account( $this->spy( true ) );

		self::assertTrue( $account->is_shown() );
		self::assertStringContainsString( 'Smaily Campaign Intelligence', $this->rendered( $account ) );
	}

	// --- the three display states (PRO-2513) --------------------------------

	public function test_known_opt_in_renders_a_checked_box(): void {
		$this->store( true, true, 0 );
		$this->logged_in_shopper();
		$spy  = $this->spy( true );
		$html = $this->rendered( $this->account( $spy ) );

		self::assertStringContainsString( 'name="' . ProfilingConsentAccount::FIELD . '"', $html );
		self::assertStringContainsString( "checked='checked'", $html );
		self::assertSame( array( array( 'read', 'shopper@example.com' ) ), $spy->calls );
	}

	public function test_known_opt_out_renders_an_unchecked_box(): void {
		$this->store( true, true, 0 );
		$this->logged_in_shopper();
		$html = $this->rendered( $this->account( $this->spy( false ) ) );

		self::assertStringContainsString( 'name="' . ProfilingConsentAccount::FIELD . '"', $html );
		self::assertStringNotContainsString( 'checked', $html );
	}

	public function test_unknown_preference_renders_a_notice_and_an_opt_out_button(): void {
		$this->store( true, true, 0 );
		$this->logged_in_shopper();
		$html = $this->rendered( $this->account( $this->spy( null ) ) );

		self::assertStringContainsString( "We couldn't load your preference right now. Please try again later.", html_entity_decode( $html, ENT_QUOTES ) );
		self::assertStringContainsString( 'name="' . ProfilingConsentAccount::OPT_OUT_FIELD . '"', $html );
		self::assertStringContainsString( 'Opt out of personalised recommendations', $html );
		self::assertStringNotContainsString( 'type="checkbox"', $html, 'No box — nothing pre-ticked.' );
		self::assertStringNotContainsString( 'checked', $html );
		self::assertStringNotContainsString( 'name="' . ProfilingConsentAccount::FIELD . '"', $html );
		self::assertStringNotContainsString( ProfilingConsentAccount::ACTION . '_submit', $html );
	}

	// --- the POST handler (PRO-3189) ----------------------------------------

	/**
	 * Drive handle_post() through its nonce + login checks; the redirect
	 * throws so the handler's `exit` is never reached.
	 *
	 * @param array<string, string> $post The submitted fields besides the nonce.
	 * @return bool Whether the handler got as far as its redirect.
	 */
	private function submit( ProfilingConsentAccount $account, array $post, bool $nonce_ok = true, bool $logged_in = true ): bool {
		$this->logged_in_shopper();
		Functions\when( 'is_user_logged_in' )->justReturn( $logged_in );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->alias(
			static fn ( string $nonce, string $action ): bool => $nonce_ok && $nonce === 'nonce-ok' && $action === ProfilingConsentAccount::ACTION
		);
		Functions\when( 'wc_add_notice' )->justReturn( null );
		Functions\when( 'wc_get_account_endpoint_url' )->justReturn( 'https://shop.test/my-account/' );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);

		$_POST = array_merge( array( '_wpnonce' => 'nonce-ok' ), $post );
		try {
			$account->handle_post();
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirected', $e->getMessage() );
			return true;
		}
		return false;
	}

	/**
	 * @dataProvider setup_states
	 */
	public function test_opt_out_button_opts_out( bool $setup_completed ): void {
		$this->store( $setup_completed, true, 0 );
		$spy = $this->spy( null );

		self::assertTrue( $this->submit( $this->account( $spy ), array( ProfilingConsentAccount::OPT_OUT_FIELD => '1' ) ) );

		self::assertSame( array( array( 'out', 'shopper@example.com' ) ), $spy->calls );
	}

	public function test_a_crafted_opt_out_button_post_cannot_opt_in(): void {
		$this->store( true, true, 0 );
		$spy = $this->spy( null );

		$this->submit(
			$this->account( $spy ),
			array(
				ProfilingConsentAccount::OPT_OUT_FIELD     => '1',
				ProfilingConsentAccount::FIELD             => '1',
				ProfilingConsentAccount::ACTION . '_submit' => '1',
			)
		);

		self::assertSame( array( array( 'out', 'shopper@example.com' ) ), $spy->calls );
	}

	public function test_opt_out_button_needs_a_valid_nonce(): void {
		$this->store( true, true, 0 );
		$spy = $this->spy( null );

		self::assertFalse( $this->submit( $this->account( $spy ), array( ProfilingConsentAccount::OPT_OUT_FIELD => '1' ), false ) );
		self::assertSame( array(), $spy->calls );
	}

	public function test_opt_out_button_needs_a_logged_in_shopper(): void {
		$this->store( true, true, 0 );
		$spy = $this->spy( null );

		self::assertFalse( $this->submit( $this->account( $spy ), array( ProfilingConsentAccount::OPT_OUT_FIELD => '1' ), true, false ) );
		self::assertSame( array(), $spy->calls );
	}

	public function test_checkbox_form_still_opts_in_when_ticked(): void {
		$this->store( true, true, 0 );
		$spy = $this->spy( true );

		$this->submit(
			$this->account( $spy ),
			array(
				ProfilingConsentAccount::FIELD             => '1',
				ProfilingConsentAccount::ACTION . '_submit' => '1',
			)
		);

		self::assertSame( array( array( 'in', 'shopper@example.com' ) ), $spy->calls );
	}
}

// Shared shim pattern (see GdprHandlerTest) — declared conditionally,
// another test file loaded earlier in the same process may already have it.
if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}
