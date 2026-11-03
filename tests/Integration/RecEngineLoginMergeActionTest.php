<?php
/**
 * Integration: the login never calls Campaign Intelligence (CON-268). The
 * login sets the visitor-token cookie and queues one Action Scheduler action;
 * the action sends the customer (§4) with marketing consent, then the merge
 * (§7), and retries a `404 customer_not_found` once (contract v1.23.0).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\IdentityHookHandler;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\LoginMerge;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\LoginMergeQueue;

/**
 * What this catches that the unit tests can't: the real `wp_login` hook with
 * its full arg tuple, the real Action Scheduler queue and runner, the plugin's
 * registered action callback with its real client and customer payload
 * builder, and the mock engine counting every request it gets — the only proof
 * that the login itself sent nothing.
 */
final class RecEngineLoginMergeActionTest extends TestCase {

	private const VISITOR_COOKIE = 'smaily_rec_uid';
	private const SESSION_COOKIE = 'smaily_anon_sid';
	private const STORE_TOKEN    = 'vs_0123456789ABCDEFabcdef';

	private static ?RecEngineMockServer $engine = null;

	/** @var int[] */
	private array $created_users = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::markTestSkipped( 'Action Scheduler not loaded.' );
		}
		EnvScrub::reset();
		RecEngineMockServer::reset();
		LoginMergeQueue::forget();
		$this->forget_browser();

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'identity_merge'   => $base . '/api/v1/identity/merge',
					'ingest_customers' => $base . '/api/v1/ingest/customers',
				),
			)
		);
		self::$engine->reset_request_count();
	}

	protected function tearDown(): void {
		LoginMergeQueue::forget();
		$this->forget_browser();
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $id ) {
			$hash = md5( strtolower( (string) get_userdata( $id )->user_email ) );
			delete_transient( 'smly_profiling_' . $hash ); // The action's profiling read-back caches its answer.
			delete_transient( 'smly_profiling_stale_' . $hash );
			wp_delete_user( $id );
		}
		$this->created_users = array();
		parent::tearDown();
	}

	public function test_the_login_sends_nothing_sets_the_cookie_and_queues_one_action(): void {
		$cookies = $this->recording_cookies();
		$user    = $this->make_user( 'lm-quiet@example.test' );

		$this->login( $user, $cookies, true );

		self::assertSame( 0, self::$engine->request_count(), 'The login request made no engine call.' );
		$token = $cookies->written[ self::VISITOR_COOKIE ] ?? '';
		self::assertMatchesRegularExpression( '/^vs_[A-Za-z0-9]{22}$/', $token, 'The cookie is set during the login.' );
		self::assertSame(
			array( LoginMerge::args( (int) $user->ID, $token, '', true, 1 ) ),
			LoginMergeQueue::pending_args(),
			'One action, with the user id and the token — no email.'
		);
	}

	public function test_the_action_sends_the_customer_and_then_the_merge(): void {
		self::$engine->set_merge_requires_customer( true ); // The live §7 rule.
		$_COOKIE[ self::SESSION_COOKIE ] = 'anon-lm-order';
		$cookies                         = $this->recording_cookies();
		$user                            = $this->make_user( 'lm-order@example.test' );

		$this->login( $user, $cookies, true );
		self::assertSame( 1, LoginMergeQueue::run_due() );

		self::assertSame(
			array( 'POST /api/v1/ingest/customers', 'POST /api/v1/identity/merge' ),
			self::$engine->request_paths(),
			'§4 first, then §7 (contract v1.23.0 §7 "Send the customer first").'
		);
		$customers = self::$engine->state()['last_customers_payload'] ?? array();
		self::assertSame( 'lm-order@example.test', $customers[0]['email'] ?? null );
		self::assertSame( (string) $user->ID, $customers[0]['external_id'] ?? null );
		$merge = self::$engine->state()['last_merge_received'] ?? array();
		self::assertSame( $cookies->written[ self::VISITOR_COOKIE ] ?? null, $merge['smaily_visitor_token'] ?? null );
		self::assertSame( 'anon-lm-order', $merge['anon_session_id'] ?? null );
		self::assertSame( 'anon-lm-order', get_user_meta( (int) $user->ID, IdentityHookHandler::MERGED_META_KEY, true ) );
		self::assertSame( array(), LoginMergeQueue::pending_args(), 'Nothing is left to run.' );
	}

	public function test_a_404_merge_is_retried_once_after_the_customer_is_sent(): void {
		$cookies = $this->recording_cookies();
		$user    = $this->make_user( 'notfound-once@example.test' );

		$this->login( $user, $cookies, true );
		LoginMergeQueue::run_due();

		self::assertSame(
			array( 'POST /api/v1/ingest/customers', 'POST /api/v1/identity/merge', 'POST /api/v1/identity/merge' ),
			self::$engine->request_paths()
		);
		self::assertSame( 1, self::$engine->merge_404_count( 'notfound-once@example.test' ) );
		self::assertSame( 1, self::$engine->state()['merge_count'] ?? null, 'The retry was accepted.' );
	}

	public function test_a_404_that_stays_is_retried_only_once(): void {
		$user = $this->make_user( 'notfound@example.test' );

		$this->login( $user, $this->recording_cookies(), true );
		LoginMergeQueue::run_due();

		self::assertSame( 2, self::$engine->merge_404_count( 'notfound@example.test' ), 'One merge and one retry, never more.' );
		self::assertSame( array(), LoginMergeQueue::pending_args(), 'A 404 is not queued again.' );
	}

	public function test_without_consent_no_customer_and_no_store_token_are_sent(): void {
		self::$engine->set_merge_requires_customer( true );
		self::assertFalse( function_exists( 'wp_has_consent' ), 'Precondition: no WP Consent API on the test site.' );
		$_COOKIE[ self::SESSION_COOKIE ] = 'anon-lm-noconsent';
		$_COOKIE[ self::VISITOR_COOKIE ] = self::STORE_TOKEN;
		$cookies                         = $this->recording_cookies();
		$user                            = $this->make_user( 'lm-noconsent@example.test' );

		$this->login( $user, $cookies, null ); // The real consent check: fail-closed.
		LoginMergeQueue::run_due();

		self::assertSame( array(), $cookies->writes, 'No cookie written or renewed.' );
		self::assertSame( array( 'POST /api/v1/identity/merge' ), self::$engine->request_paths(), 'No §4 send.' );
		self::assertSame( 1, self::$engine->merge_404_count( 'lm-noconsent@example.test' ), 'Not retried: the customer was never sent.' );
	}

	public function test_without_consent_a_store_token_alone_queues_nothing(): void {
		$_COOKIE[ self::VISITOR_COOKIE ] = self::STORE_TOKEN;
		$user                            = $this->make_user( 'lm-vsonly@example.test' );

		$this->login( $user, $this->recording_cookies(), null );

		self::assertSame( array(), LoginMergeQueue::pending_args() );
		self::assertSame( 0, self::$engine->request_count() );
	}

	public function test_nothing_is_sent_when_sending_stops_before_the_action_runs(): void {
		$user = $this->make_user( 'lm-refused@example.test' );
		$this->login( $user, $this->recording_cookies(), true );
		self::assertCount( 1, LoginMergeQueue::pending_args() );

		( new RecEngineSettings() )->mark_refused();
		LoginMergeQueue::run_due();

		self::assertSame( 0, self::$engine->request_count() );
	}

	public function test_a_second_login_does_not_queue_a_second_action(): void {
		$_COOKIE[ self::VISITOR_COOKIE ] = self::STORE_TOKEN;
		$user                            = $this->make_user( 'lm-twice@example.test' );

		$this->login( $user, $this->recording_cookies(), true );
		$this->login( $user, $this->recording_cookies(), true );

		self::assertCount( 1, LoginMergeQueue::pending_args() );
	}

	public function test_a_failed_customer_send_queues_the_action_again_for_later(): void {
		$_COOKIE[ self::VISITOR_COOKIE ] = self::STORE_TOKEN;
		$user                            = $this->make_user( 'retry-500@example.test' ); // §4 answers 500 once.

		$this->login( $user, $this->recording_cookies(), true );
		LoginMergeQueue::run_due();

		self::assertSame( array( 'POST /api/v1/ingest/customers' ), self::$engine->request_paths(), 'No merge before the customer is sent.' );
		self::assertSame(
			array( LoginMerge::args( (int) $user->ID, self::STORE_TOKEN, '', true, 2 ) ),
			LoginMergeQueue::pending_args(),
			'The second run waits for its back-off.'
		);
		$next = as_next_scheduled_action( LoginMerge::HOOK, LoginMerge::args( (int) $user->ID, self::STORE_TOKEN, '', true, 2 ), LoginMerge::AS_GROUP );
		self::assertIsInt( $next );
		self::assertEqualsWithDelta( time() + LoginMerge::BACKOFF_SECONDS[0], $next, 10 );
	}

	// ---- Helpers ---------------------------------------------------------

	/**
	 * Log in through the real `wp_login` hook with its full arg tuple. The
	 * plugin's own registered handler is taken off for the call: the test's
	 * handler answers the consent ($consent null keeps the real, fail-closed
	 * check) and records the cookie writes.
	 */
	private function login( \WP_User $user, LandingCapture $cookies, ?bool $consent ): void {
		$handler = $this->handler( $cookies, $consent );
		global $wp_filter;
		$saved = isset( $wp_filter['wp_login'] ) ? clone $wp_filter['wp_login'] : null;
		remove_all_actions( 'wp_login' );
		$handler->register();
		try {
			do_action( 'wp_login', $user->user_login, $user );
		} finally {
			remove_all_actions( 'wp_login' );
			if ( $saved !== null ) {
				$wp_filter['wp_login'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the hook as it was.
			}
		}
	}

	private function handler( LandingCapture $cookies, ?bool $consent ): IdentityHookHandler {
		$settings = new RecEngineSettings();
		$merge    = Bootstrap::instance()->login_merge();
		if ( null === $consent ) {
			return new IdentityHookHandler( $settings, $merge, null, $cookies );
		}

		return new class( $settings, $merge, $cookies, $consent ) extends IdentityHookHandler {
			private bool $test_consent;

			public function __construct( RecEngineSettings $settings, LoginMerge $merge, LandingCapture $cookies, bool $consent ) {
				parent::__construct( $settings, $merge, null, $cookies );
				$this->test_consent = $consent;
			}

			protected function marketing_consent_given(): bool {
				return $this->test_consent;
			}
		};
	}

	/**
	 * A LandingCapture that records cookie writes instead of sending headers.
	 */
	private function recording_cookies(): LandingCapture {
		return new class( new RecEngineSettings() ) extends LandingCapture {
			/** @var array<string, string> */
			public array $written = array();

			/** @var array<int, array{0: string, 1: string, 2: int}> name, value, expires */
			public array $writes = array();

			protected function headers_already_sent(): bool {
				return false; // PHPUnit's progress output makes the real one true.
			}

			protected function send_cookie( string $name, string $value, int $expires ): void {
				$this->written[ $name ] = $value;
				$this->writes[]         = array( $name, $value, $expires );
			}
		};
	}

	private function make_user( string $email ): \WP_User {
		$id = wp_insert_user(
			array(
				'user_login' => 'lm_' . md5( $email ),
				'user_pass'  => 'x' . wp_generate_password( 12, false ),
				'user_email' => $email,
				'role'       => 'customer',
			)
		);
		self::assertIsInt( $id );
		$this->created_users[] = (int) $id;
		$user                  = get_userdata( (int) $id );
		self::assertInstanceOf( \WP_User::class, $user );
		return $user;
	}

	private function forget_browser(): void {
		unset( $_COOKIE[ self::VISITOR_COOKIE ], $_COOKIE[ self::SESSION_COOKIE ] );
	}
}
