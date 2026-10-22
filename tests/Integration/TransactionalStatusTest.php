<?php
/**
 * Integration: the order/shipping confirmation cards' status line
 * (PRO-4091) — what the boot payload and a settings save hand the admin
 * screen, read against real options, the real mapping and queue tables and
 * whichever order storage the run is on (legacy or HPOS).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use Smaily\Connect\Wizard\EnvDetector;

final class TransactionalStatusTest extends TestCase {

	/** @var array<int, int> */
	private array $created_orders = array();

	/** @var callable|null Answers every outbound request as Smaily's success — nothing leaves the test. */
	private $fake_smaily = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) ) {
			self::markTestSkipped( 'WooCommerce not active.' );
		}
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();

		$this->fake_smaily = static function () {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'code' => 101 ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};
		add_filter( 'pre_http_request', $this->fake_smaily, 10, 3 );
	}

	protected function tearDown(): void {
		if ( $this->fake_smaily !== null ) {
			remove_filter( 'pre_http_request', $this->fake_smaily, 10 );
		}
		foreach ( $this->created_orders as $order_id ) {
			// NOT wp_delete_post: a silent no-op under HPOS.
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		$this->created_orders = array();

		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_a_fresh_store_shows_both_emails_switched_off(): void {
		$status = ( new EnvDetector() )->saved_settings()['transactionalStatus'];

		self::assertSame( TransactionalGate::REASON_SWITCHED_OFF, $status['order_confirmation']['reason'] );
		self::assertSame( TransactionalGate::REASON_SWITCHED_OFF, $status['shipping_confirmation']['reason'] );
		self::assertSame( '', $status['order_confirmation']['lastSentAt'] );
	}

	public function test_a_save_answers_with_the_status_the_save_produced(): void {
		// The merchant picks only the order-confirmation workflow: the save's
		// own answer says that one is ready and the other has no workflow.
		$response = $this->configure( array( 'order_confirmation' => '4242' ), array( 'completed' ) );

		$status = $response->get_data()['transactionalStatus'];
		self::assertSame( '', $status['order_confirmation']['reason'] );
		self::assertSame( TransactionalGate::REASON_NO_WORKFLOW, $status['shipping_confirmation']['reason'] );
	}

	public function test_a_password_that_no_longer_decrypts_says_re_enter_it(): void {
		$this->configure( array( 'order_confirmation' => '4242' ), array( 'completed' ) );
		// What a changed SECURE_AUTH_KEY leaves behind: a stored blob that
		// no longer decrypts.
		$option             = 'smly_plus_credentials_transactional';
		$stored             = get_option( $option );
		$stored['password'] = \Smaily_Connect\Includes\Cypher::V2_PREFIX . base64_encode( str_repeat( 'x', 64 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		update_option( $option, $stored );

		$status = ( new EnvDetector() )->saved_settings()['transactionalStatus'];

		self::assertSame( TransactionalGate::REASON_PASSWORD_UNREADABLE, $status['order_confirmation']['reason'] );
	}

	public function test_the_last_real_send_is_shown_and_a_skipped_row_is_not_a_send(): void {
		$this->configure( array( 'order_confirmation' => '4242' ), array( 'completed' ) );
		$order = $this->make_order( 'status@example.test' );

		// Shipping has no workflow → a skipped row, which is no send.
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );
		self::assertSame( '', ( new EnvDetector() )->saved_settings()['transactionalStatus']['shipping_confirmation']['lastSentAt'] );

		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), wc_get_order( $order->get_id() ) );

		$last = ( new EnvDetector() )->saved_settings()['transactionalStatus']['order_confirmation']['lastSentAt'];
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $last );
	}

	public function test_last_sent_is_each_emails_newest_real_send_among_many_rows(): void {
		global $wpdb;
		$table = $wpdb->prefix . \Smaily\Connect\Smaily\EventQueue::TABLE_SUFFIX;
		$rows  = array(
			// The order confirmation: the newest real send is 10-03 09:00.
			array( 'transactional.order_confirmation', 'sent', '2026-10-01 10:00:00', '{"n":1}' ),
			array( 'transactional.order_confirmation', 'sent', '2026-10-03 09:00:00', '{"n":2}' ),
			array( 'transactional.order_confirmation', 'sent', '2026-10-05 08:00:00', null ),   // skipped: no send.
			array( 'transactional.order_confirmation', 'sent', '2026-10-06 08:00:00', '' ),     // skipped: no send.
			array( 'transactional.order_confirmation', 'failed', '2026-10-07 08:00:00', '{"n":3}' ),
			array( 'transactional.order_confirmation', 'pending', '2026-10-08 08:00:00', null ),
			// The shipping confirmation: 10-02 12:00.
			array( 'transactional.shipping_confirmation', 'sent', '2026-09-30 12:00:00', '{"n":4}' ),
			array( 'transactional.shipping_confirmation', 'sent', '2026-10-02 12:00:00', '{"n":5}' ),
			// Another email's newer sends count for neither.
			array( 'automation.abandoned_cart', 'sent', '2026-10-09 08:00:00', '{"n":6}' ),
			array( 'subscriber.update', 'sent', '2026-10-10 08:00:00', '{"n":7}' ),
		);
		foreach ( $rows as $row ) {
			$wpdb->insert(
				$table,
				array(
					'event_type'   => $row[0],
					'payload'      => '{}',
					'status'       => $row[1],
					'created_at'   => $row[2],
					'sent_payload' => $row[3],
				)
			);
		}

		$status = ( new EnvDetector() )->saved_settings()['transactionalStatus'];

		self::assertSame( '2026-10-03 09:00:00', $status['order_confirmation']['lastSentAt'] );
		self::assertSame( '2026-10-02 12:00:00', $status['shipping_confirmation']['lastSentAt'] );
		self::assertSame( '', $status['follow_up']['lastSentAt'], 'An email with no send shows none.' );
	}

	public function test_a_store_whose_orders_never_reach_a_ticked_status_is_warned(): void {
		// Ticked: a status no order can be on (a custom fulfilment status
		// the store doesn't use any more). The newest order sits at
		// processing — that is what the warning lists.
		$this->configure( array( 'shipping_confirmation' => '5151' ), array( 'smly-test-never-used' ) );
		$order = $this->make_order( 'warn@example.test' );
		$order->update_status( 'processing' );

		$status = ( new EnvDetector() )->saved_settings()['transactionalStatus']['shipping_confirmation'];

		self::assertSame( '', $status['reason'] );
		self::assertContains( 'processing', $status['untickedRecentStatuses'] );
	}

	public function test_no_warning_once_a_recent_order_reached_a_ticked_status(): void {
		$this->configure( array( 'shipping_confirmation' => '5151' ), array( 'processing' ) );
		$this->make_order( 'nowarn@example.test' )->update_status( 'processing' );

		$status = ( new EnvDetector() )->saved_settings()['transactionalStatus']['shipping_confirmation'];

		self::assertSame( array(), $status['untickedRecentStatuses'] );
	}

	/**
	 * Both settings tabs, as the admin screen saves them. Returns the
	 * WooCommerce tab's answer.
	 *
	 * @param array<string, string> $mappings         trigger → workflow id.
	 * @param array<int, string>    $shipped_statuses "Counts as shipped".
	 */
	private function configure( array $mappings, array $shipped_statuses ): \WP_REST_Response {
		$rows = array();
		foreach ( $mappings as $trigger => $workflow_id ) {
			$rows[] = array(
				'triggerType'       => $trigger,
				'language'          => 'default',
				'accountKey'        => 'transactional',
				'workflowId'        => $workflow_id,
				'isDefaultFallback' => true,
			);
		}

		$connection = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'connection',
				'data' => array(
					'smailyCredentials'          => array(
						'subdomain' => 'testsub',
						'username'  => 'tester',
						'password'  => 'test-password',
					),
					'transactionalEmailsEnabled' => true,
					'transactionalCredentials'   => array(
						'subdomain' => 'txsub',
						'username'  => 'txuser',
						'password'  => 'txpass',
					),
				),
			)
		);
		self::assertSame( 200, $connection->get_status() );

		$woocommerce = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array(
					'orderConfirmationEnabled'    => true,
					'shippingConfirmationEnabled' => true,
					'shippedOrderStatuses'        => $shipped_statuses,
					'automationMappings'          => $rows,
				),
			)
		);
		self::assertSame( 200, $woocommerce->get_status() );

		return $woocommerce;
	}

	private function make_order( string $email ): \WC_Order {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->set_status( 'pending' );
		$order_id = (int) $order->save();

		$this->created_orders[] = $order_id;
		return wc_get_order( $order_id );
	}
}
