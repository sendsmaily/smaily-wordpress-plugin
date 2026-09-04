<?php
/**
 * Integration: the first-order automation on BOTH checkouts (PRO-1679).
 *
 * The trigger used to hang off woocommerce_checkout_order_processed alone, so
 * on a block-checkout store — the WooCommerce default — it never fired for
 * anyone. These cases drive the real hooks against real WP + WC + the real
 * queue/flusher, with the Smaily API mocked at the pre_http_request seam (the
 * established pattern — CartPipelineTest / TransactionalEmailsPipelineTest).
 *
 * Each block-checkout case fires
 * `woocommerce_store_api_checkout_order_processed` with WC's own 1-arg tuple
 * ($order), exactly as StoreApi\Routes\V1\Checkout::process_order_and_payment()
 * does; the classic parity case fires the 3-arg
 * `woocommerce_checkout_order_processed` ($order_id, $posted_data, $order) that
 * WC_Checkout::process_checkout() fires.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class FirstOrderAutomationPipelineTest extends TestCase {

	/** @var array<int, int> */
	private array $created_orders = array();

	/** @var array<int, int> */
	private array $created_users = array();

	/** @var array<int, int> */
	private array $created_products = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the first-order automation needs WC_Order.' );
		}
		EnvScrub::reset();
		HookHandler::reset_seen();
		RestRequestHelper::login_as_admin();

		// Wizard finished — the master gate the whole live-sync path hangs on.
		update_option( 'smly_plus_setup_completed', true );
	}

	protected function tearDown(): void {
		foreach ( $this->created_orders as $order_id ) {
			// NOT wp_delete_post: under HPOS orders live in wc_orders, so a
			// post-delete is a silent no-op and the order leaks across runs.
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		foreach ( $this->created_products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->created_orders   = array();
		$this->created_users    = array();
		$this->created_products = array();

		HookHandler::reset_seen();
		wp_set_current_user( 0 );

		// Drop any Smaily client cached from this test's seeded credentials.
		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_first_order_fires_on_the_block_checkout_store_api_hook(): void {
		$this->configure( '4242' );

		$user_id  = $this->make_customer( 'block' );
		$order_id = $this->make_order( $user_id );

		$this->fire_block_checkout_order_processed( $order_id );

		$rows = $this->queue_rows();
		self::assertCount( 1, $rows, 'The Store-API hook must enqueue the first-order automation.' );
		self::assertSame( (string) $order_id, $rows[0]['entity_id'] );

		$captured = $this->flush();

		self::assertIsArray( $captured, 'The automation must reach the Smaily transport.' );
		self::assertSame( 4242, $captured['autoresponder'] );
		self::assertSame( $this->email_of( $user_id ), $captured['addresses'][0]['email'] );
		self::assertSame( (string) $order_id, $captured['addresses'][0]['order_id'] );
		self::assertSame( 'sent', $this->queue_rows()[0]['status'] );
	}

	public function test_first_order_still_fires_on_the_classic_checkout(): void {
		$this->configure( '4242' );

		$user_id  = $this->make_customer( 'classic' );
		$order_id = $this->make_order( $user_id );

		$this->fire_checkout_order_processed( $order_id );

		$rows = $this->queue_rows();
		self::assertCount( 1, $rows, 'Classic-checkout behaviour is unchanged.' );

		$captured = $this->flush();
		self::assertIsArray( $captured );
		self::assertSame( 4242, $captured['autoresponder'] );
		self::assertSame( $this->email_of( $user_id ), $captured['addresses'][0]['email'] );
	}

	public function test_a_second_order_on_the_block_checkout_triggers_nothing(): void {
		$this->configure( '4242' );

		$user_id = $this->make_customer( 'repeat' );
		$this->make_order( $user_id );
		$second_id = $this->make_order( $user_id );

		$this->fire_block_checkout_order_processed( $second_id );

		self::assertSame( array(), $this->queue_rows(), 'Only the customer\'s FIRST order triggers.' );
	}

	public function test_a_guest_order_on_the_block_checkout_triggers_nothing(): void {
		$this->configure( '4242' );

		$order_id = $this->make_order( 0 );

		$this->fire_block_checkout_order_processed( $order_id );

		self::assertSame( array(), $this->queue_rows(), 'Guest behaviour is preserved — no order history to key on.' );
	}

	public function test_the_disabled_automation_triggers_nothing_and_raises_no_error(): void {
		// No configure() call: smly_plus_first_order_enabled is at its
		// default (off), matching a fresh install.
		$user_id  = $this->make_customer( 'disabled' );
		$order_id = $this->make_order( $user_id );

		$this->fire_block_checkout_order_processed( $order_id );
		$this->fire_checkout_order_processed( $order_id );

		self::assertSame( array(), $this->queue_rows() );
	}

	public function test_an_unmapped_first_order_sends_nothing_and_raises_no_error(): void {
		$this->configure( null );

		$user_id  = $this->make_customer( 'unmapped' );
		$order_id = $this->make_order( $user_id );

		$this->fire_block_checkout_order_processed( $order_id );

		$captured = $this->flush();

		self::assertNull( $captured, 'No workflow mapped → nothing is POSTed.' );
		$row = $this->queue_rows()[0];
		self::assertSame( 'sent', $row['status'], 'A terminal skip leaves the queue rather than retrying forever.' );
		self::assertStringContainsString( '"outcome":"skipped"', (string) $row['last_response'] );
	}

	public function test_both_checkout_hooks_for_one_order_enqueue_a_single_automation(): void {
		// A store where both hooks somehow run for one order must still
		// enqueue once — they fire in the same request, so the per-request
		// dedupe caps it.
		$this->configure( '4242' );

		$user_id  = $this->make_customer( 'both' );
		$order_id = $this->make_order( $user_id );

		$this->fire_checkout_order_processed( $order_id );
		$this->fire_block_checkout_order_processed( $order_id );

		self::assertCount( 1, $this->queue_rows() );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * Turn the first-order automation on, optionally mapping it to a workflow.
	 *
	 * @param string|null $workflow_id Null = enabled but unmapped.
	 */
	private function configure( ?string $workflow_id ): void {
		$this->seed_credentials();

		$mappings = $workflow_id === null
			? array()
			: array(
				array(
					'triggerType'       => 'first_order',
					'language'          => 'default',
					'accountKey'        => 'default',
					'workflowId'        => $workflow_id,
					'isDefaultFallback' => true,
				),
			);

		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array(
					'firstOrderEnabled'  => true,
					'automationMappings' => $mappings,
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
	}

	/** LEGACY_OPTION_KEY / "default" account credentials — mirrors CartPipelineTest::seed_credentials(). */
	private function seed_credentials(): void {
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	private function make_customer( string $slug ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_first_' . $slug . '_' . wp_generate_password( 6, false ),
				'user_email' => $slug . '-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;
		return $user_id;
	}

	private function email_of( int $user_id ): string {
		return (string) get_userdata( $user_id )->user_email;
	}

	private function make_order( int $customer_id ): int {
		if ( $this->created_products === array() ) {
			$product = new \WC_Product_Simple();
			$product->set_name( 'First Order Product' );
			$product->set_regular_price( '19.90' );
			$product->set_status( 'publish' );
			$product_id = (int) $product->save();
			self::assertGreaterThan( 0, $product_id );
			$this->created_products[] = $product_id;
		}

		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_email(
			$customer_id > 0 ? $this->email_of( $customer_id ) : 'guest@example.test'
		);
		$order->add_product( wc_get_product( $this->created_products[0] ), 1 );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order_id = (int) $order->save();

		$this->created_orders[] = $order_id;
		return $order_id;
	}

	/**
	 * Fires the real 3-arg woocommerce_checkout_order_processed hook — WC's
	 * own shape ($order_id, $posted_data, $order). A bare 1-arg do_action()
	 * trips OTHER real listeners still registered on this hook (the legacy
	 * subscriber-sync callback) that declare all 3 params with no defaults.
	 */
	private function fire_checkout_order_processed( int $order_id ): void {
		do_action( 'woocommerce_checkout_order_processed', $order_id, array(), wc_get_order( $order_id ) );
	}

	/**
	 * Fires the real 1-arg woocommerce_store_api_checkout_order_processed
	 * hook — WC's own Store-API shape ($order), unlike the classic 3-arg hook.
	 */
	private function fire_block_checkout_order_processed( int $order_id ): void {
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order_id ) );
	}

	/**
	 * Drain the queue against a mocked Smaily transport.
	 *
	 * @return array<string, mixed>|null The POSTed body, or null when nothing was sent.
	 */
	private function flush(): ?array {
		$captured = null;
		$fake     = static function ( $pre, $args ) use ( &$captured ) {
			$captured = isset( $args['body'] ) ? $args['body'] : null;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'code'    => 101,
						'message' => 'OK',
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return is_array( $captured ) ? $captured : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function queue_rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s ORDER BY id ASC",
				HookHandler::EVENT_AUTOMATION_FIRST_ORDER
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
