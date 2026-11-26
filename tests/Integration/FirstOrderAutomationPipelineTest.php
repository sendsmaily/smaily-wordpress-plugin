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
use Smaily\Connect\Smaily\AutomationConsent;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\PipelineFixture;
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
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
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
		delete_option( ContactSyncMode::OPTION_MODE );
		$this->forget_mode_memo();

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

	// --- the consent rule (CON-386) ------------------------------------------
	//
	// Smaily creates an address an automation is triggered for as a subscribed
	// contact, so the first-order email follows the abandoned-cart reminder's
	// consent rule (AutomationConsent): the flusher reads the contact from
	// Smaily before the send.

	public function test_all_customers_sends_to_a_buyer_smaily_does_not_have(): void {
		$this->set_mode( ContactSyncMode::MODE_LEGITIMATE_INTEREST );
		$this->configure( '4242' );
		$user_id = $this->make_customer( 'li-new' );

		$this->fire_block_checkout_order_processed( $this->make_order( $user_id ) );
		$captured = $this->flush( self::smaily_not_found() );

		self::assertIsArray( $captured );
		self::assertSame( $this->email_of( $user_id ), $captured['addresses'][0]['email'] );
		self::assertFalse( $this->listed( (int) $this->queue_rows()[0]['id'] )['skipped'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function every_mode(): array {
		return array(
			'all customers'        => array( ContactSyncMode::MODE_LEGITIMATE_INTEREST ),
			'subscribers only'     => array( ContactSyncMode::MODE_CONSENT ),
			'checkout opt-in only' => array( ContactSyncMode::MODE_CHECKOUT_OPTIN ),
		);
	}

	/**
	 * @dataProvider every_mode
	 */
	public function test_a_buyer_unsubscribed_in_smaily_gets_nothing_in_any_mode( string $mode ): void {
		$this->set_mode( $mode );
		$this->configure( '4242' );
		$user_id = $this->make_customer( 'unsubscribed' );
		update_user_meta( $user_id, ContactAudience::OPTIN_META, '1' );

		$this->fire_checkout_order_processed( $this->make_order( $user_id ), array( 'user_newsletter' => 1 ) );
		$captured = $this->flush( self::smaily_contact( '1' ) );

		self::assertNull( $captured, 'An unsubscribed contact gets no first-order email.' );
		$this->assert_skipped( AutomationConsent::REASON_UNSUBSCRIBED );
	}

	/**
	 * @dataProvider consent_modes
	 */
	public function test_a_consent_mode_skips_a_buyer_who_consented_nowhere( string $mode ): void {
		$this->set_mode( $mode );
		$this->configure( '4242' );
		$user_id = $this->make_customer( 'no-consent' );

		$this->fire_block_checkout_order_processed( $this->make_order( $user_id ) );
		$captured = $this->flush( self::smaily_not_found() );

		self::assertNull( $captured, 'The email would subscribe a buyer who never subscribed.' );
		$this->assert_skipped( AutomationConsent::REASON_NO_MARKETING_CONSENT );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function consent_modes(): array {
		return array(
			'subscribers only'     => array( ContactSyncMode::MODE_CONSENT ),
			'checkout opt-in only' => array( ContactSyncMode::MODE_CHECKOUT_OPTIN ),
		);
	}

	/**
	 * The order's own newsletter tick is consent for its first-order email,
	 * on either checkout.
	 *
	 * @dataProvider consent_modes
	 */
	public function test_a_consent_mode_sends_when_the_buyer_ticks_the_box_at_the_classic_checkout( string $mode ): void {
		$this->set_mode( $mode );
		$this->configure( '4242' );
		$user_id = $this->make_customer( 'classic-tick' );

		$this->fire_checkout_order_processed( $this->make_order( $user_id ), array( 'user_newsletter' => 1 ) );
		$captured = $this->flush( self::smaily_not_found() );

		self::assertIsArray( $captured );
		self::assertSame( $this->email_of( $user_id ), $captured['addresses'][0]['email'] );
	}

	/**
	 * @dataProvider consent_modes
	 */
	public function test_a_consent_mode_sends_when_the_buyer_ticks_the_box_at_the_block_checkout( string $mode ): void {
		$this->set_mode( $mode );
		$this->configure( '4242' );
		$user_id = $this->make_customer( 'block-tick' );
		$order   = wc_get_order( $this->make_order( $user_id ) );

		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'extensions', array( 'smaily-checkout-optin' => array( 'user_newsletter' => true ) ) );
		do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $request );
		$order->save();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$captured = $this->flush( self::smaily_not_found() );

		self::assertIsArray( $captured );
		self::assertSame( $this->email_of( $user_id ), $captured['addresses'][0]['email'] );
	}

	/**
	 * @dataProvider consent_modes
	 */
	public function test_a_consent_mode_sends_to_a_buyer_smaily_has_as_subscribed( string $mode ): void {
		$this->set_mode( $mode );
		$this->configure( '4242' );
		$user_id = $this->make_customer( 'smaily-subscribed' );

		$this->fire_block_checkout_order_processed( $this->make_order( $user_id ) );
		$captured = $this->flush( self::smaily_contact( '0' ) );

		self::assertIsArray( $captured );
	}

	public function test_subscribers_only_sends_to_a_buyer_the_store_has_as_subscribed(): void {
		$this->set_mode( ContactSyncMode::MODE_CONSENT );
		$this->configure( '4242' );
		$user_id = $this->make_customer( 'store-subscribed' );
		update_user_meta( $user_id, ContactAudience::OPTIN_META, '1' );

		$this->fire_block_checkout_order_processed( $this->make_order( $user_id ) );
		$captured = $this->flush( self::smaily_not_found() );

		self::assertIsArray( $captured );
	}

	public function test_checkout_opt_in_only_keeps_no_store_record_for_an_account(): void {
		$this->set_mode( ContactSyncMode::MODE_CHECKOUT_OPTIN );
		$this->configure( '4242' );
		$user_id = $this->make_customer( 'checkout-record' );
		update_user_meta( $user_id, ContactAudience::OPTIN_META, '1' );

		$this->fire_block_checkout_order_processed( $this->make_order( $user_id ) );
		$captured = $this->flush( self::smaily_not_found() );

		self::assertNull( $captured );
		$this->assert_skipped( AutomationConsent::REASON_NO_MARKETING_CONSENT );
	}

	public function test_the_email_waits_and_is_retried_when_smaily_cannot_be_asked(): void {
		$this->set_mode( ContactSyncMode::MODE_LEGITIMATE_INTEREST );
		$this->configure( '4242' );
		$user_id = $this->make_customer( 'unreachable' );

		$this->fire_block_checkout_order_processed( $this->make_order( $user_id ) );
		$captured = $this->flush( self::smaily_unreachable() );

		self::assertNull( $captured, 'Nothing is sent blind.' );
		$row = $this->queue_rows()[0];
		self::assertSame( EventQueue::STATUS_PENDING, $row['status'], 'Not dropped: the row waits for its retry.' );
		self::assertSame( 1, (int) $row['attempts'] );

		// The retry, once Smaily answers.
		$this->make_due( (int) $row['id'] );
		$captured = $this->flush( self::smaily_not_found() );

		self::assertIsArray( $captured );
		self::assertSame( 'sent', $this->queue_rows()[0]['status'] );
	}

	// --- helpers -------------------------------------------------------------

	private function set_mode( string $mode ): void {
		update_option( ContactSyncMode::OPTION_MODE, $mode );
		$this->forget_mode_memo();
	}

	/** Clear the registered HookHandler's mode memo, so no later test inherits this one's mode (PRO-4093). */
	private function forget_mode_memo(): void {
		global $wp_filter;

		foreach ( $wp_filter['woocommerce_created_customer']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && $function[0] instanceof HookHandler ) {
					foreach ( array( 'mode', 'audience' ) as $memo ) {
						$prop = new \ReflectionProperty( HookHandler::class, $memo );
						$prop->setAccessible( true );
						$prop->setValue( $function[0], null );
					}
				}
			}
		}
	}

	/** The only first-order row closed as skipped with $reason — in the queue and in the Event Log. */
	private function assert_skipped( string $reason ): void {
		$rows = $this->queue_rows();
		self::assertCount( 1, $rows );
		self::assertSame( 'sent', $rows[0]['status'], 'Closed for good, never retried.' );
		self::assertNull( $rows[0]['sent_payload'], 'Nothing was sent.' );
		self::assertSame( $reason, EventQueue::skip_reason( (string) $rows[0]['last_response'] ) );

		$logged = $this->listed( (int) $rows[0]['id'] );
		self::assertTrue( $logged['skipped'], 'The Event Log reads it as skipped, never as sent.' );
		self::assertSame( $reason, $logged['skip_reason'] );
	}

	/**
	 * The row as the Event Log lists it.
	 *
	 * @return array<string, mixed>
	 */
	private function listed( int $id ): array {
		$response = RestRequestHelper::get( '/events', array( 'source' => 'smaily' ) );
		self::assertSame( 200, $response->get_status() );

		foreach ( $response->get_data()['events'] as $event ) {
			if ( (int) $event['id'] === $id ) {
				return $event;
			}
		}
		self::fail( 'The row must be listed in the Event Log.' );
	}

	private function make_due( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'smly_plus_event_queue', array( 'next_retry_at' => null ), array( 'id' => $id ) );
	}

	/** A contact read finding the buyer with this status. */
	private static function smaily_contact( string $is_unsubscribed ): callable {
		return self::smaily_answers(
			200,
			array(
				'email'           => 'buyer@example.test',
				'is_unsubscribed' => $is_unsubscribed,
			)
		);
	}

	/** A contact read for an address Smaily does not have. */
	private static function smaily_not_found(): callable {
		return self::smaily_answers(
			200,
			array(
				'code'    => 206,
				'message' => 'Could not find requested email address',
			)
		);
	}

	/** A contact read Smaily cannot answer. */
	private static function smaily_unreachable(): callable {
		return self::smaily_answers( 503, array() );
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private static function smaily_answers( int $status, array $body ): callable {
		return static fn (): array => array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => $status === 200 ? 'OK' : 'Service Unavailable',
			),
			'cookies'  => array(),
			'filename' => '',
		);
	}

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
	 *
	 * @param array<string, mixed> $posted_data The posted checkout fields (the newsletter box).
	 */
	private function fire_checkout_order_processed( int $order_id, array $posted_data = array() ): void {
		do_action( 'woocommerce_checkout_order_processed', $order_id, $posted_data, wc_get_order( $order_id ) );
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
	 * @param (callable(): mixed)|null $answer_read What the contact read (GET)
	 *                                             answers; null finds the buyer
	 *                                             subscribed, so the default
	 *                                             consent mode lets the email
	 *                                             through (CON-386).
	 *
	 * @return array<string, mixed>|null The first-order automation's POSTed
	 *                                   body, or null when none was sent.
	 */
	private function flush( ?callable $answer_read = null ): ?array {
		$bodies = PipelineFixture::flush( EventQueue::FLUSH_HOOK, $answer_read ?? PipelineFixture::subscribed_contact_read() );

		foreach ( array_reverse( $bodies ) as $body ) {
			if ( is_array( $body ) && isset( $body['autoresponder'] ) ) {
				return $body;
			}
		}

		return null;
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
