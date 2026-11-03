<?php
/**
 * PRO-3860: the login identity merge sends a store-created `vs_` visitor
 * token only with the shopper's marketing consent; the engine's `vt_` token
 * from an email link is attribution and stays ungated.
 *
 * CON-251 (contract v1.22.0 §7): a consenting shopper with no visitor-token
 * cookie gets a new store token at login, set by the server and sent in the
 * merge; every login that carries a token calls the merge once; with consent
 * a login writes an existing token's cookie again with a fresh lifetime.
 *
 * The real WP user / engine round trip is integration-tested
 * (RecEngineIdentityMergeTest). Here the consent answer is the protected
 * marketing_consent_given() seam, the Client double records the §7 body, and
 * a LandingCapture double records the cookie writes.
 *
 * @package Smaily\Connect\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\IdentityHookHandler;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\LoginMerge;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;

final class IdentityHookHandlerTest extends TestCase {

	private const STORE_TOKEN  = 'vs_0123456789ABCDEFabcdef';
	private const ENGINE_TOKEN = 'vt_fromemaillink123';

	/** The contract's store-created token format (§5, §7). */
	private const STORE_TOKEN_PATTERN = '/^vs_[A-Za-z0-9]{22}$/';

	/** @var array<int, string> user id => merged anon session (the user-meta double). */
	private array $merged_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		$this->merged_meta = array();
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id ): string {
				return $this->merged_meta[ $user_id ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, string $value ): bool {
				$this->merged_meta[ $user_id ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_COOKIE = array();
		parent::tearDown();
	}

	public function test_a_store_token_is_not_sent_without_marketing_consent(): void {
		$_COOKIE['smaily_anon_sid'] = 'anon-1';
		$_COOKIE['smaily_rec_uid']  = self::STORE_TOKEN;
		$merge = $this->recording_merge();

		$this->handler( $merge, false )->on_login( 'mari', $this->user() );

		self::assertCount( 1, $merge->queued, 'The anon session is still merged.' );
		self::assertArrayNotHasKey( 'smaily_visitor_token', $merge->queued[0] );
	}

	public function test_a_store_token_alone_without_consent_means_nothing_to_merge(): void {
		$_COOKIE['smaily_rec_uid'] = self::STORE_TOKEN;
		$merge = $this->recording_merge();

		$this->handler( $merge, false )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $merge->queued );
	}

	public function test_a_store_token_is_sent_with_marketing_consent(): void {
		$_COOKIE['smaily_rec_uid'] = self::STORE_TOKEN;
		$merge = $this->recording_merge();

		$this->handler( $merge, true )->on_login( 'mari', $this->user() );

		self::assertSame( self::STORE_TOKEN, $merge->queued[0]['smaily_visitor_token'] ?? null );
	}

	public function test_an_engine_token_is_sent_without_marketing_consent(): void {
		$_COOKIE['smaily_rec_uid'] = self::ENGINE_TOKEN;
		$merge = $this->recording_merge();

		$this->handler( $merge, false )->on_login( 'mari', $this->user() );

		self::assertSame( self::ENGINE_TOKEN, $merge->queued[0]['smaily_visitor_token'] ?? null );
	}

	public function test_a_cookie_value_in_neither_token_format_is_not_sent_without_consent(): void {
		$_COOKIE['smaily_anon_sid'] = 'anon-1';
		$_COOKIE['smaily_rec_uid']  = 'vs_short';
		$merge = $this->recording_merge();

		$this->handler( $merge, false )->on_login( 'mari', $this->user() );

		self::assertArrayNotHasKey( 'smaily_visitor_token', $merge->queued[0] ?? array() );
	}

	// --- CON-251: a store token at login ------------------------------------

	public function test_a_consenting_shopper_without_a_token_gets_a_store_token_sent_in_the_merge(): void {
		$merge = $this->recording_merge();
		$cookies = $this->recording_cookies();

		$this->handler( $merge, true, $cookies )->on_login( 'mari', $this->user() );

		$token = $cookies->written['smaily_rec_uid'] ?? '';
		self::assertMatchesRegularExpression( self::STORE_TOKEN_PATTERN, $token, 'The token has the contract format.' );
		self::assertEqualsWithDelta( time() + 365 * 86400, $cookies->expires['smaily_rec_uid'] ?? 0, 5, 'The engine config lifetime (default 365 days).' );
		self::assertCount( 1, $merge->queued );
		self::assertSame( $token, $merge->queued[0]['smaily_visitor_token'] ?? null );
		self::assertArrayNotHasKey( 'anon_session_id', $merge->queued[0] );
	}

	public function test_a_new_store_token_goes_with_the_anon_session_in_one_merge(): void {
		$_COOKIE['smaily_anon_sid'] = 'anon-1';
		$merge = $this->recording_merge();
		$cookies                    = $this->recording_cookies();

		$this->handler( $merge, true, $cookies )->on_login( 'mari', $this->user() );

		self::assertCount( 1, $merge->queued );
		self::assertSame( 'anon-1', $merge->queued[0]['anon_session_id'] ?? null );
		self::assertSame( $cookies->written['smaily_rec_uid'] ?? null, $merge->queued[0]['smaily_visitor_token'] ?? null );
	}

	public function test_no_token_is_created_without_marketing_consent(): void {
		$merge = $this->recording_merge();
		$cookies = $this->recording_cookies();

		$this->handler( $merge, false, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->written, 'No cookie is written.' );
		self::assertSame( array(), $merge->queued, 'Nothing to merge.' );
	}

	public function test_without_consent_the_anon_session_is_merged_with_no_token(): void {
		$_COOKIE['smaily_anon_sid'] = 'anon-1';
		$merge = $this->recording_merge();
		$cookies                    = $this->recording_cookies();

		$this->handler( $merge, false, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->written );
		self::assertCount( 1, $merge->queued );
		self::assertArrayNotHasKey( 'smaily_visitor_token', $merge->queued[0] );
	}

	public function test_an_existing_store_token_is_kept_and_sent(): void {
		$_COOKIE['smaily_rec_uid'] = self::STORE_TOKEN;
		$merge = $this->recording_merge();
		$cookies                   = $this->recording_cookies();

		$this->handler( $merge, true, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array( 'smaily_rec_uid' => self::STORE_TOKEN ), $cookies->written, 'An existing token is never replaced, only renewed.' );
		self::assertSame( self::STORE_TOKEN, $merge->queued[0]['smaily_visitor_token'] ?? null );
	}

	public function test_an_existing_engine_token_is_kept_and_sent_with_consent(): void {
		$_COOKIE['smaily_rec_uid'] = self::ENGINE_TOKEN;
		$merge = $this->recording_merge();
		$cookies                   = $this->recording_cookies();

		$this->handler( $merge, true, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array( 'smaily_rec_uid' => self::ENGINE_TOKEN ), $cookies->written, 'The `vt_` cookie is renewed too, with consent.' );
		self::assertSame( self::ENGINE_TOKEN, $merge->queued[0]['smaily_visitor_token'] ?? null );
	}

	public function test_an_existing_engine_token_without_consent_is_sent_and_no_store_token_is_created(): void {
		$_COOKIE['smaily_rec_uid'] = self::ENGINE_TOKEN;
		$merge = $this->recording_merge();
		$cookies                   = $this->recording_cookies();

		$this->handler( $merge, false, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->written );
		self::assertSame( self::ENGINE_TOKEN, $merge->queued[0]['smaily_visitor_token'] ?? null );
	}

	public function test_a_store_token_without_consent_is_not_replaced_by_a_new_one(): void {
		$_COOKIE['smaily_rec_uid'] = self::STORE_TOKEN;
		$merge = $this->recording_merge();
		$cookies                   = $this->recording_cookies();

		$this->handler( $merge, false, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->written );
		self::assertSame( array(), $merge->queued );
	}

	public function test_a_cookie_outside_the_token_shape_is_replaced_by_a_store_token_with_consent(): void {
		$_COOKIE['smaily_rec_uid'] = 'vs_short';
		$merge = $this->recording_merge();
		$cookies                   = $this->recording_cookies();

		$this->handler( $merge, true, $cookies )->on_login( 'mari', $this->user() );

		self::assertMatchesRegularExpression( self::STORE_TOKEN_PATTERN, $cookies->written['smaily_rec_uid'] ?? '' );
		self::assertSame( $cookies->written['smaily_rec_uid'] ?? null, $merge->queued[0]['smaily_visitor_token'] ?? null );
	}

	public function test_no_token_when_the_headers_are_already_sent(): void {
		$merge = $this->recording_merge();
		$cookies = $this->recording_cookies( true );

		$this->handler( $merge, true, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->written );
		self::assertSame(
			array(
				array(
					'user_id' => 7,
					'consent' => true,
				),
			),
			$merge->queued,
			'No cookie, so no token to send; the consenting customer is still sent (§4 group c).'
		);
	}

	public function test_no_token_in_cron(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$merge = $this->recording_merge();
		$cookies = $this->recording_cookies();

		$this->handler( $merge, true, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->written );
		self::assertArrayNotHasKey( 'smaily_visitor_token', $merge->queued[0] ?? array(), 'No token is created in cron.' );
	}

	public function test_no_token_for_a_shopper_who_objected_to_profiling(): void {
		$profiling = $this->createMock( ProfilingConsent::class );
		$profiling->method( 'known_opt_out' )->willReturn( true );
		$merge = $this->recording_merge();
		$cookies = $this->recording_cookies();

		$this->handler( $merge, true, $cookies, $profiling )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->written );
		self::assertSame( array(), $merge->queued );
	}

	public function test_no_token_while_sending_is_not_allowed(): void {
		$merge = $this->recording_merge();
		$cookies = $this->recording_cookies();

		$this->handler( $merge, true, $cookies, null, new FakeRecEngineSettings( true, true ) )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->written );
		self::assertSame( array(), $merge->queued );
	}

	// --- CON-251: one merge per login with a token --------------------------

	public function test_every_login_with_a_token_calls_the_merge_once(): void {
		$_COOKIE['smaily_anon_sid'] = 'anon-1';
		$_COOKIE['smaily_rec_uid']  = self::STORE_TOKEN;
		$merge = $this->recording_merge();
		$handler                    = $this->handler( $merge, true );

		$handler->on_login( 'mari', $this->user() );
		$this->merged_meta[7] = 'anon-1'; // The first login's action ran.
		$handler->on_login( 'mari', $this->user() );
		$handler->on_login( 'mari', $this->user() );

		self::assertCount( 3, $merge->queued, 'Each login renews the token in the engine (§7).' );
		self::assertSame( 'anon-1', $merge->queued[0]['anon_session_id'] ?? null );
		self::assertArrayNotHasKey( 'anon_session_id', $merge->queued[1], 'The session already merged is left out.' );
		self::assertSame( self::STORE_TOKEN, $merge->queued[1]['smaily_visitor_token'] ?? null );
		self::assertSame( self::STORE_TOKEN, $merge->queued[2]['smaily_visitor_token'] ?? null );
	}

	public function test_the_token_created_at_the_first_login_is_sent_at_the_next(): void {
		$merge = $this->recording_merge();
		$cookies = $this->recording_cookies();
		$handler = $this->handler( $merge, true, $cookies );

		$handler->on_login( 'mari', $this->user() );
		$handler->on_login( 'mari', $this->user() );

		self::assertCount( 2, $cookies->writes, 'Created at the first login, renewed at the second.' );
		self::assertSame( $cookies->writes[0][1], $cookies->writes[1][1], 'One token for the browser.' );
		self::assertCount( 2, $merge->queued );
		self::assertSame( $merge->queued[0]['smaily_visitor_token'] ?? null, $merge->queued[1]['smaily_visitor_token'] ?? 'missing' );
	}

	public function test_a_login_on_a_merged_session_without_a_token_makes_no_call(): void {
		$_COOKIE['smaily_anon_sid'] = 'anon-1';
		$this->merged_meta[7]       = 'anon-1';
		$merge = $this->recording_merge();

		$this->handler( $merge, false )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $merge->queued );
	}

	// --- Erkki 2026-11-02: a consenting login renews the cookie ------------

	/**
	 * @dataProvider existing_tokens
	 */
	public function test_a_consenting_login_renews_the_cookie_with_the_same_value_and_a_fresh_lifetime( string $token ): void {
		$_COOKIE['smaily_rec_uid'] = $token;
		$merge = $this->recording_merge();
		$cookies                   = $this->recording_cookies();

		$this->handler( $merge, true, $cookies )->on_login( 'mari', $this->user() );

		self::assertCount( 1, $cookies->writes );
		self::assertSame( array( 'smaily_rec_uid', $token ), array_slice( $cookies->writes[0], 0, 2 ), 'Same cookie, same value.' );
		self::assertEqualsWithDelta( time() + 365 * 86400, $cookies->writes[0][2], 5, 'A fresh lifetime from now.' );
		self::assertSame( $token, $merge->queued[0]['smaily_visitor_token'] ?? null );
	}

	/**
	 * @dataProvider existing_tokens
	 */
	public function test_without_consent_the_cookie_is_not_renewed( string $token ): void {
		$_COOKIE['smaily_anon_sid'] = 'anon-1';
		$_COOKIE['smaily_rec_uid']  = $token;
		$merge = $this->recording_merge();
		$cookies                    = $this->recording_cookies();

		$this->handler( $merge, false, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->writes );
		self::assertCount( 1, $merge->queued, 'The merge itself keeps today\'s rules.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function existing_tokens(): array {
		return array(
			'store token'  => array( self::STORE_TOKEN ),
			'engine token' => array( self::ENGINE_TOKEN ),
		);
	}

	public function test_no_renewal_when_the_headers_are_already_sent_and_the_merge_still_goes(): void {
		$_COOKIE['smaily_rec_uid'] = self::STORE_TOKEN;
		$merge = $this->recording_merge();
		$cookies                   = $this->recording_cookies( true );

		$this->handler( $merge, true, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->writes );
		self::assertSame( self::STORE_TOKEN, $merge->queued[0]['smaily_visitor_token'] ?? null );
	}

	public function test_no_renewal_for_a_shopper_who_objected_to_profiling(): void {
		$_COOKIE['smaily_rec_uid'] = self::STORE_TOKEN;
		$profiling                 = $this->createMock( ProfilingConsent::class );
		$profiling->method( 'known_opt_out' )->willReturn( true );
		$cookies = $this->recording_cookies();

		$this->handler( $this->recording_merge(), true, $cookies, $profiling )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->writes );
	}

	public function test_no_renewal_in_cron(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$_COOKIE['smaily_rec_uid'] = self::STORE_TOKEN;
		$cookies                   = $this->recording_cookies();

		$this->handler( $this->recording_merge(), true, $cookies )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $cookies->writes );
	}

	// --- doubles ----------------------------------------------------------

	private function handler(
		LoginMerge $merge,
		bool $consent,
		?LandingCapture $cookies = null,
		?ProfilingConsent $profiling = null,
		?RecEngineSettings $settings = null
	): IdentityHookHandler {
		return new class( $settings ?? new FakeRecEngineSettings(), $merge, $consent, $cookies ?? $this->recording_cookies(), $profiling ) extends IdentityHookHandler {
			private bool $test_consent;

			public function __construct( RecEngineSettings $settings, LoginMerge $merge, bool $consent, LandingCapture $cookies, ?ProfilingConsent $profiling ) {
				parent::__construct( $settings, $merge, $profiling, $cookies );
				$this->test_consent = $consent;
			}

			protected function marketing_consent_given(): bool {
				return $this->test_consent;
			}
		};
	}

	/**
	 * A LandingCapture that records its cookie writes instead of sending
	 * Set-Cookie headers.
	 */
	private function recording_cookies( bool $headers_sent = false ): LandingCapture {
		return new class( new FakeRecEngineSettings(), $headers_sent ) extends LandingCapture {
			/** @var array<string, string> */
			public array $written = array();

			/** @var array<string, int> */
			public array $expires = array();

			/** @var array<int, array{0: string, 1: string, 2: int}> name, value, expires */
			public array $writes = array();

			private bool $headers_sent;

			public function __construct( RecEngineSettings $settings, bool $headers_sent ) {
				parent::__construct( $settings );
				$this->headers_sent = $headers_sent;
			}

			protected function headers_already_sent(): bool {
				return $this->headers_sent;
			}

			protected function send_cookie( string $name, string $value, int $expires ): void {
				$this->written[ $name ] = $value;
				$this->expires[ $name ] = $expires;
				$this->writes[]         = array( $name, $value, $expires );
			}
		};
	}

	private function user(): \WP_User {
		$user             = new \WP_User();
		$user->ID         = 7;
		$user->user_email = 'mari@example.com';
		return $user;
	}

	/**
	 * A LoginMerge double that records what each login queued, shaped like
	 * the §7 body the action sends: `anon_session_id` and
	 * `smaily_visitor_token` only when set, plus the user id and the consent.
	 */
	private function recording_merge(): LoginMerge {
		return new class( new FakeRecEngineSettings() ) extends LoginMerge {
			/** @var array<int, array<string, mixed>> */
			public array $queued = array();

			public function __construct( RecEngineSettings $settings ) {
				parent::__construct(
					$settings,
					static function (): Client {
						throw new \LogicException( 'The login sends nothing to the engine (CON-268).' );
					}
				);
			}

			public function enqueue( int $user_id, string $token, string $anon_session_id, bool $consent ): bool {
				$entry = array(
					'user_id' => $user_id,
					'consent' => $consent,
				);
				if ( $anon_session_id !== '' ) {
					$entry['anon_session_id'] = $anon_session_id;
				}
				if ( $token !== '' ) {
					$entry['smaily_visitor_token'] = $token;
				}
				$this->queued[] = $entry;
				return true;
			}
		};
	}
}

// Stub for the WP_User class (shared shim pattern — see HookHandlerTest;
// declared conditionally, another file may load first).
if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}
