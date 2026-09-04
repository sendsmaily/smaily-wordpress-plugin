<?php
/**
 * Integration: IdentityHookHandler (3.7) — wp_login → /identity/merge binding
 * an anonymous session to the now-known customer.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\IdentityHookHandler;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * What this catches that a unit test can't: the full chain reads the real
 * anon-session / visitor-token cookies from $_COOKIE, builds the §7 body, and
 * the real Client posts it to the engine. It also pins the dedup (a repeat
 * login on the same session does NOT re-hit the engine) and the 404 handling
 * (customer not yet ingested → log + skip, never throws).
 */
final class RecEngineIdentityMergeTest extends TestCase {

	private const SESSION_COOKIE = 'smaily_anon_sid';
	private const VISITOR_COOKIE = 'smaily_rec_uid';

	private static ?RecEngineMockServer $engine = null;

	/** @var int[] */
	private array $created_users = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RecEngineMockServer::reset();
		unset( $_COOKIE[ self::SESSION_COOKIE ], $_COOKIE[ self::VISITOR_COOKIE ] );

		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array( 'identity_merge' => $base . '/api/v1/identity/merge' ),
			)
		);
	}

	protected function tearDown(): void {
		unset( $_COOKIE[ self::SESSION_COOKIE ], $_COOKIE[ self::VISITOR_COOKIE ] );
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $id ) {
			wp_delete_user( $id );
		}
		$this->created_users = array();
		parent::tearDown();
	}

	public function test_login_merges_the_anon_session_to_the_customer(): void {
		$_COOKIE[ self::SESSION_COOKIE ] = 'anon-sess-123';
		$_COOKIE[ self::VISITOR_COOKIE ] = 'vt_abc';
		$user = $this->make_user( 'merge-mari@example.test' );

		$this->handler()->on_login( $user->user_login, $user );

		$received = self::$engine->state()['last_merge_received'] ?? null;
		self::assertIsArray( $received );
		self::assertSame( 'anon-sess-123', $received['anon_session_id'] );
		self::assertSame( 'vt_abc', $received['smaily_visitor_token'] );
		self::assertSame( 'merge-mari@example.test', $received['customer_email'] );
		self::assertSame( 'user_logged_in', $received['merge_reason'] );

		// Dedup marker stored.
		self::assertSame( 'anon-sess-123', get_user_meta( (int) $user->ID, IdentityHookHandler::MERGED_META_KEY, true ) );
	}

	public function test_repeat_login_on_same_session_is_deduped(): void {
		$_COOKIE[ self::SESSION_COOKIE ] = 'anon-dedupe';
		$user = $this->make_user( 'merge-dedupe@example.test' );

		$this->handler()->on_login( $user->user_login, $user );
		self::assertIsArray( self::$engine->state()['last_merge_received'] ?? null );

		// Forget what the engine saw; a deduped second login must NOT call it.
		RecEngineMockServer::reset();
		$this->handler()->on_login( $user->user_login, $user );
		self::assertNull( self::$engine->state()['last_merge_received'] ?? null, 'Same session ⇒ no second merge call.' );
	}

	public function test_new_session_after_merge_triggers_a_fresh_merge(): void {
		$user = $this->make_user( 'merge-newsess@example.test' );

		$_COOKIE[ self::SESSION_COOKIE ] = 'anon-first';
		$this->handler()->on_login( $user->user_login, $user );

		RecEngineMockServer::reset();
		$_COOKIE[ self::SESSION_COOKIE ] = 'anon-second'; // cookies cleared → new session
		$this->handler()->on_login( $user->user_login, $user );

		$received = self::$engine->state()['last_merge_received'] ?? null;
		self::assertIsArray( $received );
		self::assertSame( 'anon-second', $received['anon_session_id'], 'A new anon session re-merges.' );
	}

	public function test_customer_not_found_404_logs_and_skips_without_throwing(): void {
		$_COOKIE[ self::SESSION_COOKIE ] = 'anon-404';
		$user = $this->make_user( 'notfound@example.test' ); // mock 404 trigger

		$this->handler()->on_login( $user->user_login, $user );

		// No throw; the dedup marker is NOT set (merge didn't succeed).
		self::assertSame( '', (string) get_user_meta( (int) $user->ID, IdentityHookHandler::MERGED_META_KEY, true ) );
	}

	public function test_no_anon_cookie_means_nothing_to_merge(): void {
		$user = $this->make_user( 'merge-nocookie@example.test' );

		$this->handler()->on_login( $user->user_login, $user );

		self::assertNull( self::$engine->state()['last_merge_received'] ?? null );
	}

	public function test_not_connected_does_not_merge(): void {
		EnvScrub::reset(); // drops the connection seeded in setUp
		$_COOKIE[ self::SESSION_COOKIE ] = 'anon-disco';
		$user = $this->make_user( 'merge-disco@example.test' );

		$this->handler()->on_login( $user->user_login, $user );

		self::assertNull( self::$engine->state()['last_merge_received'] ?? null );
	}

	// --- helpers --------------------------------------------------------

	private function handler(): IdentityHookHandler {
		$settings = new RecEngineSettings();
		return new IdentityHookHandler(
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
	}

	/** Same handler, but with the (a).1 profiling gate wired in. */
	private function handler_with_profiling(): IdentityHookHandler {
		$settings = new RecEngineSettings();
		$client   = static function () use ( $settings ): Client {
			return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
		};
		$profiling = new ProfilingConsent(
			$settings,
			static fn (): ?SmailyClient => null, // unused — the cache is pre-seeded below.
			$client
		);

		return new IdentityHookHandler( $settings, $client, $profiling );
	}

	public function test_opted_out_contact_is_not_retroactively_bound(): void {
		$_COOKIE[ self::SESSION_COOKIE ] = 'anon-optout';
		$_COOKIE[ self::VISITOR_COOKIE ] = 'vt_optout';
		$email = 'merge-optout@example.test';
		$user  = $this->make_user( $email );

		// Pre-seed the profiling cache to opted-out (cache hit → no Smaily call).
		$key = 'smly_profiling_' . md5( strtolower( $email ) );
		set_transient( $key, '0', DAY_IN_SECONDS );

		$this->handler_with_profiling()->on_login( $user->user_login, $user );

		self::assertNull(
			self::$engine->state()['last_merge_received'] ?? null,
			'an opted-out contact must not be merged — their anon browse history is not retroactively bound'
		);
		self::assertSame(
			'',
			(string) get_user_meta( (int) $user->ID, IdentityHookHandler::MERGED_META_KEY, true ),
			'no dedup marker is written when the merge is skipped'
		);

		delete_transient( $key );
	}

	private function make_user( string $email ): \WP_User {
		$id = wp_insert_user(
			array(
				'user_login' => 'idm_' . md5( $email ),
				'user_pass'  => 'x' . wp_generate_password( 12, false ),
				'user_email' => $email,
				'role'       => 'customer',
			)
		);
		self::assertIsInt( $id );
		$this->created_users[] = (int) $id;
		$user = get_userdata( (int) $id );
		self::assertInstanceOf( \WP_User::class, $user );
		return $user;
	}
}
