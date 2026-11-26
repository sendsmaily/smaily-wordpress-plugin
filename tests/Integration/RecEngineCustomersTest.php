<?php
/**
 * Integration: CustomerFlusher → Client::ingest_customers against the mock
 * engine — the D6 per-item contract end-to-end (partial success splits the
 * batch), email-first wire format, and the terminal-4xx path.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CustomerHookHandler;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerPayloadBuilder;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Support\ContactLanguageResolver;
use Smaily\Connect\Tests\Integration\Fixtures\RecEngineMockServer;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;

/**
 * What this catches that the unit tests can't:
 *
 *   - The full chain CustomerPayloadBuilder → Client → real HTTP → engine
 *     with the `customers` wrapper actually on the wire.
 *
 *   - The D6 partial-success split end-to-end: a batch where one item is
 *     rejected (errors[]) must mark exactly that row failed and the rest
 *     sent — the practical proof of the D6 contract plugin-side.
 *
 *   - Terminal 4xx (revoked key): the whole batch fails, no retry.
 */
final class RecEngineCustomersTest extends TestCase {

	private static ?RecEngineMockServer $engine = null;

	/** @var array<int, int> User ids created by a test, torn down after. */
	private array $created_users = array();

	/** @var array<int, int> Order ids created by a test, torn down after. */
	private array $created_orders = array();

	public static function setUpBeforeClass(): void {
		self::$engine = RecEngineMockServer::start();
	}

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RecEngineMockServer::reset();
		CustomerHookHandler::reset_seen();
		$this->connect();
		// Every registered user is in the contact audience in this mode, so
		// the live hook queues each new user (the §4 customer scope, CON-246).
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_LEGITIMATE_INTEREST );
	}

	protected function tearDown(): void {
		foreach ( $this->created_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true ); // Not wp_delete_post: a no-op under HPOS.
			}
		}
		$this->created_orders = array();
		DetectorFactory::reset();
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();
		delete_option( ContactSyncMode::OPTION_MODE );
		parent::tearDown();
	}

	public function test_a_new_account_out_of_scope_is_not_queued(): void {
		// CON-246: in the consent mode (the default) an account that has not
		// subscribed and has no order — an administrator here — never reaches
		// the engine.
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		$queue = new IngestQueue();
		$this->truncate_customer_rows();

		$this->make_user( 'cust-admin-scope@example.test', 'administrator' );

		self::assertSame( array(), $queue->pending( 10, array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) ) );
	}

	public function test_a_subscription_puts_the_account_in_scope(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		$queue = new IngestQueue();
		$this->truncate_customer_rows();
		$user_id = $this->make_user( 'cust-optin-scope@example.test', 'customer' );
		self::assertSame( array(), $queue->pending( 10, array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) ) );

		update_user_meta( $user_id, ContactAudience::OPTIN_META, '1' );

		$pending = $queue->pending( 10, array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) );
		self::assertCount( 1, $pending, 'The opt-in meta write queues the customer.' );
		self::assertSame( (string) $user_id, $pending[0]['entity_id'] );
	}

	private function truncate_customer_rows(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}smly_rec_event_queue WHERE event_type = %s", CustomerFlusher::EVENT_CUSTOMER_UPSERT ) );
	}

	public function test_d6_partial_success_marks_errored_row_failed_and_rest_sent(): void {
		$queue = new IngestQueue();

		// Two valid customers + one whose email triggers a per-item error in
		// the mock (`d6err-` prefix). Creating each user fires the registered
		// user_register hook, which enqueues the customer.upsert row — the
		// real wiring, no manual enqueue.
		$this->register_customer( 'valid-a@example.test' );
		$this->register_customer( 'valid-b@example.test' );
		$this->register_customer( 'd6err-bad@example.test' );

		$stats = $this->flusher()->flush();

		self::assertSame( 2, $stats['sent'], 'The two valid customers are processed → sent.' );
		self::assertSame( 1, $stats['failed'], 'The errors[] item is marked failed, not the whole batch.' );
		self::assertSame(
			array(),
			$queue->pending( 10, array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) ),
			'Every customer row reached a terminal state (sent or failed).'
		);

		$received = self::$engine->state()['last_customers_received'] ?? null;
		self::assertIsArray( $received );
		self::assertCount( 3, $received, 'All three customers were sent in one batch.' );
	}

	public function test_all_valid_customers_are_sent(): void {
		$queue = new IngestQueue();
		$this->register_customer( 'all-good-1@example.test' );
		$this->register_customer( 'all-good-2@example.test' );

		$stats = $this->flusher()->flush();

		self::assertSame( 2, $stats['sent'] );
		self::assertSame( 0, $stats['failed'] );
		self::assertSame( array(), $queue->pending( 10, array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) ) );
	}

	public function test_revoked_key_401_fails_batch_without_retry(): void {
		// `auth-401@` on the first customer makes the mock return a terminal 401.
		$this->register_customer( 'auth-401@example.test' );

		$stats = $this->flusher()->flush();

		self::assertSame( 0, $stats['sent'] );
		self::assertSame( 1, $stats['failed'], 'A revoked key is terminal — mark failed, no retry.' );
		self::assertSame( 0, $stats['retried'] );
	}

	public function test_user_register_hook_enqueues_one_row_and_flusher_sends_it(): void {
		// The whole 3.3.3 chain wired: a real user_register fires the
		// Bootstrap-registered CustomerHookHandler, which enqueues one
		// customer.upsert row; the real flusher drains it to the mock engine.
		$queue   = new IngestQueue();
		$user_id = $this->register_customer( 'hook-e2e@example.test' );

		$pending = $queue->pending( 10, array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) );
		self::assertCount( 1, $pending, 'The registered user_register hook enqueues exactly one customer row.' );
		self::assertSame( CustomerFlusher::EVENT_CUSTOMER_UPSERT, $pending[0]['event_type'] );
		self::assertSame( (string) $user_id, $pending[0]['entity_id'] );

		$stats = $this->flusher()->flush();

		self::assertSame( 1, $stats['sent'] );
		self::assertSame(
			array(),
			$queue->pending( 10, array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) ),
			'The row is sent and no longer pending.'
		);
	}

	/**
	 * CON-354: a registered buyer's order queues the buyer's customer row,
	 * and that row carries the order's language — the rule the Smaily
	 * contact sync uses, not the WordPress profile locale.
	 */
	public function test_a_buyers_order_re_sends_the_customer_with_the_order_language(): void {
		$this->multilingual_store( 'et', array( 'et', 'ru', 'lv' ) );
		$user_id = $this->register_customer( 'lang-buyer@example.test' );
		update_user_meta( $user_id, 'locale', 'en_US' );
		$this->flusher()->flush();
		self::assertSame( 'et', $this->sent_customer( $user_id )['language'] ?? null, 'No order yet: the store default.' );

		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		self::assertInstanceOf( \WC_Order::class, $order );
		$this->created_orders[] = (int) $order->get_id();
		$order->set_billing_email( 'lang-buyer@example.test' );
		$order->update_meta_data( 'wpml_language', 'lv' );
		$order->save();
		$order->update_status( 'completed' );

		$pending = ( new IngestQueue() )->pending( 10, array( CustomerFlusher::EVENT_CUSTOMER_UPSERT ) );
		self::assertSame( array( (string) $user_id ), array_column( $pending, 'entity_id' ), 'The order queues the buyer.' );

		$this->flusher()->flush();

		self::assertSame( 'lv', $this->sent_customer( $user_id )['language'] ?? null );
	}

	public function test_the_language_is_left_out_when_none_resolves(): void {
		$this->multilingual_store( 'et', array( 'et' ) );
		add_filter( ContactLanguageResolver::FILTER_LANGUAGE, '__return_empty_string' );
		try {
			$user_id = $this->register_customer( 'lang-none@example.test' );
			$this->flusher()->flush();
		} finally {
			remove_filter( ContactLanguageResolver::FILTER_LANGUAGE, '__return_empty_string' );
		}

		$sent = $this->sent_customer( $user_id );
		self::assertNotSame( array(), $sent );
		self::assertArrayNotHasKey( 'language', $sent, 'Absent, never "".' );
	}

	/** @return array<string, mixed> The last wire object the mock got for $user_id, empty when none. */
	private function sent_customer( int $user_id ): array {
		foreach ( (array) ( self::$engine->state()['last_customers_payload'] ?? array() ) as $customer ) {
			if ( ( $customer['external_id'] ?? null ) === (string) $user_id ) {
				return $customer;
			}
		}
		return array();
	}

	/**
	 * Stands in for the store's multilingual plugin: its default and active
	 * languages. tearDown() resets the factory.
	 *
	 * @param array<int, string> $active
	 */
	private function multilingual_store( string $default_language, array $active ): void {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_default_language' )->willReturn( $default_language );
		$detector->method( 'get_detected_languages' )->willReturn( $active );

		$cached = new \ReflectionProperty( DetectorFactory::class, 'cached' );
		$cached->setAccessible( true );
		$cached->setValue( null, $detector );
	}

	private function flusher(): CustomerFlusher {
		$settings = new RecEngineSettings();
		return new CustomerFlusher(
			new IngestQueue(),
			new CustomerPayloadBuilder(),
			$settings,
			static function () use ( $settings ): Client {
				return new Client( $settings->api_key(), $settings->base_url(), $settings->endpoints(), 2 );
			}
		);
	}

	/**
	 * Create a customer user. wp_insert_user fires user_register, which the
	 * Bootstrap-registered CustomerHookHandler turns into a queued
	 * customer.upsert row (the gate is open — setUp connected the tenant). No
	 * manual enqueue: this exercises the real hook wiring.
	 */
	private function register_customer( string $email ): int {
		return $this->make_user( $email );
	}

	private function make_user( string $email, string $role = 'customer' ): int {
		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof \WP_User ) {
			wp_delete_user( $existing->ID );
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => 'cust_' . substr( md5( $email ), 0, 12 ),
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 16 ),
				'first_name' => 'Test',
				'last_name'  => 'Customer',
				'role'       => $role,
			)
		);

		self::assertFalse( is_wp_error( $user_id ), 'User creation must succeed for the probe.' );
		$this->created_users[] = (int) $user_id;
		return (int) $user_id;
	}

	private function connect(): void {
		$base = (string) self::$engine->base_url();
		EnvSeed::connect(
			array(
				'engine_base_url' => $base,
				'endpoints'       => array(
					'ingest_ping'      => $base . '/api/v1/ingest/ping',
					'ingest_catalog'   => $base . '/api/v1/ingest/catalog',
					'ingest_customers' => $base . '/api/v1/ingest/customers',
					'ingest_orders'    => $base . '/api/v1/ingest/orders',
				),
			)
		);
	}
}
