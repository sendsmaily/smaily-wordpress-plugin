<?php
/**
 * CON-268: a login's engine calls run in a background action. The action
 * sends the customer (§4) with the login's marketing consent, then the merge
 * (§7); a `404 customer_not_found` after a §4 200 is retried once; a failure
 * that may pass later queues the action again, at most three runs; any other
 * 4xx ends it.
 *
 * The real WP + Action Scheduler + engine round trip is integration-tested
 * (RecEngineIdentityMergeTest, RecEngineLoginVisitorTokenTest). Here the
 * Action Scheduler functions are recorded and a Client double answers.
 *
 * @package Smaily\Connect\Tests\Unit\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\LoginMerge;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;

final class LoginMergeTest extends TestCase {

	private const STORE_TOKEN  = 'vs_0123456789ABCDEFabcdef';
	private const ENGINE_TOKEN = 'vt_fromemaillink123';
	private const USER_ID      = 7;

	/** @var array<int, string> user id => merged anon session (the user-meta double). */
	private array $merged_meta = array();

	/** @var array<int, array{0: int, 1: string, 2: array<string, mixed>, 3: string}> time, hook, args, group */
	private array $scheduled = array();

	/** @var array<int, array{0: string, 1: array<string, mixed>, 2: string}> hook, args, group */
	private array $enqueued = array();

	/** @var array<int, array<string, mixed>> The args as_has_scheduled_action() answers true for. */
	private array $pending = array();

	private bool $client_built = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->merged_meta  = array();
		$this->scheduled    = array();
		$this->enqueued     = array();
		$this->pending      = array();
		$this->client_built = false;
		Functions\when( 'wp_generate_uuid4' )->justReturn( '00000000-0000-4000-8000-000000000000' );
		Functions\when( 'get_userdata' )->alias(
			function ( int $user_id ) {
				if ( $user_id !== self::USER_ID ) {
					return false;
				}
				$user             = new \WP_User();
				$user->ID         = self::USER_ID;
				$user->user_email = 'Login.Test@Example.test';
				return $user;
			}
		);
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
		Functions\when( 'as_has_scheduled_action' )->alias(
			function ( string $hook, array $args, string $group ): bool {
				return LoginMerge::HOOK === $hook && LoginMerge::AS_GROUP === $group && in_array( $args, $this->pending, true );
			}
		);
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( string $hook, array $args, string $group ): int {
				$this->enqueued[] = array( $hook, $args, $group );
				return 1;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $time, string $hook, array $args, string $group ): int {
				$this->scheduled[] = array( $time, $hook, $args, $group );
				return 1;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// --- enqueue: the login's side -----------------------------------------

	public function test_the_login_queues_one_action_with_no_email_in_its_args(): void {
		$client = $this->client();

		self::assertTrue( $this->merge( $client )->enqueue( self::USER_ID, self::STORE_TOKEN, 'anon-1', true ) );

		self::assertSame(
			array(
				array(
					LoginMerge::HOOK,
					array(
						'user_id'         => self::USER_ID,
						'token'           => self::STORE_TOKEN,
						'anon_session_id' => 'anon-1',
						'consent'         => 1,
						'attempt'         => 1,
					),
					LoginMerge::AS_GROUP,
				),
			),
			$this->enqueued
		);
		foreach ( $this->enqueued[0][1] as $value ) {
			self::assertStringNotContainsString( '@', (string) $value, 'No email in the args.' );
		}
		self::assertFalse( $this->client_built, 'Queuing builds no engine client.' );
	}

	public function test_a_waiting_action_for_the_same_login_values_is_not_queued_twice(): void {
		$this->pending[] = LoginMerge::args( self::USER_ID, self::STORE_TOKEN, 'anon-1', true, 2 );

		self::assertTrue( $this->merge( $this->client() )->enqueue( self::USER_ID, self::STORE_TOKEN, 'anon-1', true ) );

		self::assertSame( array(), $this->enqueued, 'A retry of the same login is already waiting.' );
	}

	public function test_another_token_of_the_same_user_is_queued(): void {
		$this->pending[] = LoginMerge::args( self::USER_ID, self::STORE_TOKEN, '', true, 1 );

		$this->merge( $this->client() )->enqueue( self::USER_ID, self::ENGINE_TOKEN, '', true );

		self::assertCount( 1, $this->enqueued );
	}

	// --- run: the action's side --------------------------------------------

	public function test_with_consent_the_customer_is_sent_before_the_merge(): void {
		$client = $this->client();

		$this->merge( $client )->run( self::USER_ID, self::STORE_TOKEN, 'anon-1', 1, 1 );

		self::assertSame( array( 'customers', 'merge' ), $client->calls );
		self::assertSame( 'login.test@example.test', $client->customers[0][0]['email'] ?? null, 'The §4 wire object of the customer builder.' );
		$merge = $client->merges[0];
		self::assertSame( 'login.test@example.test', $merge['customer_email'] );
		self::assertSame( (string) self::USER_ID, $merge['customer_external_id'] );
		self::assertSame( self::STORE_TOKEN, $merge['smaily_visitor_token'] );
		self::assertSame( 'anon-1', $merge['anon_session_id'] );
		self::assertSame( 'user_logged_in', $merge['merge_reason'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $merge['merge_ts'] );
		self::assertSame( 'anon-1', $this->merged_meta[ self::USER_ID ] ?? null, 'The merged session is remembered.' );
	}

	public function test_with_consent_and_nothing_to_merge_only_the_customer_is_sent(): void {
		$client = $this->client();

		$this->merge( $client )->run( self::USER_ID, '', '', 1, 1 );

		self::assertSame( array( 'customers' ), $client->calls );
	}

	public function test_without_consent_no_customer_is_sent_and_an_engine_token_is_merged(): void {
		$client = $this->client();

		$this->merge( $client )->run( self::USER_ID, self::ENGINE_TOKEN, '', 0, 1 );

		self::assertSame( array( 'merge' ), $client->calls );
		self::assertSame( self::ENGINE_TOKEN, $client->merges[0]['smaily_visitor_token'] );
	}

	public function test_without_consent_a_store_token_is_not_sent(): void {
		$client = $this->client();

		$this->merge( $client )->run( self::USER_ID, self::STORE_TOKEN, 'anon-1', 0, 1 );

		self::assertSame( array( 'merge' ), $client->calls );
		self::assertArrayNotHasKey( 'smaily_visitor_token', $client->merges[0] );
	}

	public function test_without_consent_a_store_token_alone_sends_nothing(): void {
		$client = $this->client();

		$this->merge( $client )->run( self::USER_ID, self::STORE_TOKEN, '', 0, 1 );

		self::assertSame( array(), $client->calls );
	}

	public function test_a_token_outside_the_contract_shape_is_not_sent(): void {
		$client = $this->client();

		$this->merge( $client )->run( self::USER_ID, 'vs_short', 'anon-1', 1, 1 );

		self::assertArrayNotHasKey( 'smaily_visitor_token', $client->merges[0] );
	}

	public function test_an_already_merged_session_is_left_out(): void {
		$this->merged_meta[ self::USER_ID ] = 'anon-1';
		$client                             = $this->client();

		$this->merge( $client )->run( self::USER_ID, '', 'anon-1', 0, 1 );

		self::assertSame( array(), $client->calls );
	}

	public function test_nothing_is_sent_while_sending_is_not_allowed(): void {
		$client = $this->client();

		$this->merge( $client, new FakeRecEngineSettings( true, true ) )->run( self::USER_ID, self::STORE_TOKEN, 'anon-1', 1, 1 );

		self::assertFalse( $this->client_built );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_nothing_is_sent_for_a_contact_who_objected_to_profiling(): void {
		$profiling = $this->createMock( ProfilingConsent::class );
		$profiling->method( 'may_profile' )->willReturn( false );
		$client = $this->client();

		$this->merge( $client, null, $profiling )->run( self::USER_ID, self::STORE_TOKEN, 'anon-1', 1, 1 );

		self::assertSame( array(), $client->calls );
	}

	public function test_nothing_is_sent_for_a_user_who_no_longer_exists(): void {
		$client = $this->client();

		$this->merge( $client )->run( 99, self::STORE_TOKEN, 'anon-1', 1, 1 );

		self::assertFalse( $this->client_built );
	}

	// --- §7 v1.23.0: one retry after a 404 ---------------------------------

	public function test_a_404_after_the_customer_was_sent_is_retried_once(): void {
		$client = $this->client( array( self::not_found() ) );

		$this->merge( $client )->run( self::USER_ID, self::STORE_TOKEN, 'anon-1', 1, 1 );

		self::assertSame( array( 'customers', 'merge', 'merge' ), $client->calls );
		self::assertSame( 'anon-1', $this->merged_meta[ self::USER_ID ] ?? null );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_a_second_404_ends_it(): void {
		$client = $this->client( array( self::not_found(), self::not_found() ) );

		$this->merge( $client )->run( self::USER_ID, self::STORE_TOKEN, 'anon-1', 1, 1 );

		self::assertSame( array( 'customers', 'merge', 'merge' ), $client->calls, 'Never more than one retry.' );
		self::assertSame( array(), $this->scheduled, 'A 404 is not queued again.' );
		self::assertArrayNotHasKey( self::USER_ID, $this->merged_meta );
	}

	public function test_a_404_without_a_customer_send_is_not_retried(): void {
		$client = $this->client( array( self::not_found() ) );

		$this->merge( $client )->run( self::USER_ID, self::ENGINE_TOKEN, '', 0, 1 );

		self::assertSame( array( 'merge' ), $client->calls, 'No retry before the customer is sent (§7).' );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_a_404_after_a_refused_customer_is_not_retried(): void {
		$client = $this->client( array( self::not_found() ), array( 'errors' => array( array( 'index' => 0 ) ) ) );

		$this->merge( $client )->run( self::USER_ID, self::STORE_TOKEN, '', 1, 1 );

		self::assertSame( array( 'customers', 'merge' ), $client->calls, 'The engine did not take the customer.' );
	}

	// --- Action Scheduler retry --------------------------------------------

	public function test_a_server_error_queues_the_next_run_after_a_back_off(): void {
		$client = $this->client( array( new ApiException( 503, 'unavailable', 'down' ) ) );

		$this->merge( $client )->run( self::USER_ID, self::STORE_TOKEN, 'anon-1', 1, 1 );

		self::assertCount( 1, $this->scheduled );
		self::assertEqualsWithDelta( time() + 60, $this->scheduled[0][0], 2 );
		self::assertSame( LoginMerge::args( self::USER_ID, self::STORE_TOKEN, 'anon-1', true, 2 ), $this->scheduled[0][2] );
		self::assertSame( LoginMerge::AS_GROUP, $this->scheduled[0][3] );
		self::assertArrayNotHasKey( self::USER_ID, $this->merged_meta );
	}

	public function test_the_second_run_waits_longer(): void {
		$client = $this->client( array( new ApiException( 0, 'network_error', 'no answer' ) ) );

		$this->merge( $client )->run( self::USER_ID, self::ENGINE_TOKEN, '', 0, 2 );

		self::assertEqualsWithDelta( time() + 300, $this->scheduled[0][0], 2 );
		self::assertSame( 3, $this->scheduled[0][2]['attempt'] );
	}

	public function test_a_429_waits_at_least_what_the_engine_asked(): void {
		$client = $this->client( array( new ApiException( 429, 'rate_limit_exceeded', 'slow down', array(), 120 ) ) );

		$this->merge( $client )->run( self::USER_ID, self::ENGINE_TOKEN, '', 0, 1 );

		self::assertEqualsWithDelta( time() + 120, $this->scheduled[0][0], 2 );
	}

	public function test_the_third_run_is_the_last(): void {
		$client = $this->client( array( new ApiException( 500, 'internal_error', 'boom' ) ) );

		$this->merge( $client )->run( self::USER_ID, self::ENGINE_TOKEN, '', 0, LoginMerge::MAX_ATTEMPTS );

		self::assertSame( array(), $this->scheduled, 'Never more than three runs.' );
	}

	public function test_a_failed_customer_send_that_may_pass_later_queues_the_whole_action_again(): void {
		$client = $this->client( array(), null, new ApiException( 502, 'bad_gateway', 'proxy' ) );

		$this->merge( $client )->run( self::USER_ID, self::STORE_TOKEN, 'anon-1', 1, 1 );

		self::assertSame( array( 'customers' ), $client->calls, 'No merge before the customer is sent.' );
		self::assertSame( 2, $this->scheduled[0][2]['attempt'] ?? null );
	}

	public function test_another_4xx_ends_it(): void {
		$client = $this->client( array( new ApiException( 400, 'validation_failed', 'bad' ) ) );

		$this->merge( $client )->run( self::USER_ID, self::STORE_TOKEN, 'anon-1', 1, 1 );

		self::assertSame( array( 'customers', 'merge' ), $client->calls );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_a_terminal_customer_failure_still_sends_the_merge(): void {
		$client = $this->client( array(), null, new ApiException( 422, 'validation_failed', 'bad' ) );

		$this->merge( $client )->run( self::USER_ID, self::STORE_TOKEN, 'anon-1', 1, 1 );

		self::assertSame( array( 'customers', 'merge' ), $client->calls );
		self::assertSame( array(), $this->scheduled );
	}

	// --- CON-275: the eraser cancels the user's waiting runs ---------------

	public function test_forget_user_cancels_every_waiting_run_of_the_user_and_no_other(): void {
		$waiting = array(
			LoginMerge::args( self::USER_ID, self::STORE_TOKEN, 'anon-1', true, 1 ),
			LoginMerge::args( self::USER_ID, self::ENGINE_TOKEN, '', false, 3 ), // A back-off run, another token.
			LoginMerge::args( 70, self::STORE_TOKEN, '', true, 1 ), // Another user, whose id starts with the same digit.
		);
		$query       = array();
		$unscheduled = array();
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args ) use ( $waiting, &$query ): array {
				$query = $args;
				return array_map(
					static function ( array $action_args ) {
						return new class( $action_args ) {
							/** @var array<string, mixed> */
							private array $args;

							/** @param array<string, mixed> $args */
							public function __construct( array $args ) {
								$this->args = $args;
							}

							/** @return array<string, mixed> */
							public function get_args(): array {
								return $this->args;
							}
						};
					},
					$waiting
				);
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook, array $args, string $group ) use ( &$unscheduled ): void {
				$unscheduled[] = array( $hook, $args, $group );
			}
		);

		self::assertSame( 2, LoginMerge::forget_user( self::USER_ID ) );

		self::assertSame(
			array(
				'hook'     => LoginMerge::HOOK,
				'group'    => LoginMerge::AS_GROUP,
				'status'   => 'pending',
				'per_page' => -1,
			),
			$query,
			'Every waiting action of the hook, not the first page.'
		);
		self::assertSame(
			array(
				array( LoginMerge::HOOK, $waiting[0], LoginMerge::AS_GROUP ),
				array( LoginMerge::HOOK, $waiting[1], LoginMerge::AS_GROUP ),
			),
			$unscheduled,
			'Each of the user\'s runs is cancelled by its own exact args.'
		);
	}

	public function test_forget_user_does_nothing_without_a_user(): void {
		$read = false;
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function () use ( &$read ): array {
				$read = true;
				return array();
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );

		self::assertSame( 0, LoginMerge::forget_user( 0 ) );
		self::assertFalse( $read, 'User id 0 would match a malformed action\'s args.' );
	}

	// --- doubles ----------------------------------------------------------

	private static function not_found(): ApiException {
		return new ApiException( 404, 'customer_not_found', 'No customer found.' );
	}

	private function merge( Client $client, ?RecEngineSettings $settings = null, ?ProfilingConsent $profiling = null ): LoginMerge {
		$built = function () use ( $client ): Client {
			$this->client_built = true;
			return $client;
		};

		return new LoginMerge( $settings ?? new FakeRecEngineSettings(), $built, $this->builder(), $profiling );
	}

	/** A builder that maps the user without WooCommerce or the language resolver. */
	private function builder(): CustomerPayloadBuilder {
		return new class() extends CustomerPayloadBuilder {
			public function build( \WP_User $user, string $event_uuid ): array {
				return array(
					'event_id'    => $event_uuid,
					'email'       => strtolower( (string) $user->user_email ),
					'external_id' => (string) $user->ID,
				);
			}
		};
	}

	/**
	 * A Client double: records the calls in order; each merge throws the next
	 * queued exception (none left = 200).
	 *
	 * @param array<int, ApiException>  $merge_failures
	 * @param array<string, mixed>|null $customers_answer The §4 answer (null = all taken).
	 */
	private function client( array $merge_failures = array(), ?array $customers_answer = null, ?ApiException $customers_failure = null ): Client {
		return new class( $merge_failures, $customers_answer, $customers_failure ) extends Client {
			/** @var array<int, string> */
			public array $calls = array();

			/** @var array<int, array<int, array<string, mixed>>> */
			public array $customers = array();

			/** @var array<int, array<string, mixed>> */
			public array $merges = array();

			/** @var array<int, ApiException> */
			private array $merge_failures;

			/** @var array<string, mixed> */
			private array $customers_answer;

			private ?ApiException $customers_failure;

			/**
			 * @param array<int, ApiException>  $merge_failures
			 * @param array<string, mixed>|null $customers_answer
			 */
			public function __construct( array $merge_failures, ?array $customers_answer, ?ApiException $customers_failure ) {
				parent::__construct( 'sk_unit', 'https://engine.unit' );
				$this->merge_failures    = $merge_failures;
				$this->customers_answer  = $customers_answer ?? array(
					'ok'           => true,
					'processed'    => 1,
					'deduplicated' => 0,
					'errors'       => array(),
				);
				$this->customers_failure = $customers_failure;
			}

			public function ingest_customers( array $customers ): array {
				$this->calls[]     = 'customers';
				$this->customers[] = $customers;
				if ( $this->customers_failure !== null ) {
					throw $this->customers_failure;
				}
				return $this->customers_answer;
			}

			public function merge_identity( array $merge ): array {
				$this->calls[]  = 'merge';
				$this->merges[] = $merge;
				$failure        = array_shift( $this->merge_failures );
				if ( $failure !== null ) {
					throw $failure;
				}
				return array( 'ok' => true );
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
