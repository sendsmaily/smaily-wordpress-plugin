<?php
/**
 * Integration: transactional emails end to end (PRO-1504 Stage 2) — the
 * sync-first send via message/send.php, native-WC-email suppression, the
 * once-per-order-per-type meta guard, the queue-retry fallback on its own
 * flusher, and fail-open on a terminal Smaily rejection.
 *
 * The Smaily API is mocked at the pre_http_request seam — the same
 * established pattern CartPipelineTest uses for the marketing API. This is
 * NOT the rec-engine mock (message/send.php is the Smaily marketing API).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\TransactionalFlusher;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class TransactionalEmailsPipelineTest extends TestCase {

	/** @var array<int, int> */
	private array $created_orders = array();

	/** @var array<int, int> */
	private array $created_products = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — transactional emails need WC_Order.' );
		}
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();
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
		foreach ( $this->created_products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->created_orders    = array();
		$this->created_products  = array();

		// Drop any Smaily client cached from this test's seeded credentials.
		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_order_confirmation_sends_once_with_the_mapped_workflow_and_product_matrix(): void {
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'E2E Confirmation Product', 19.90 );
		$order_id = $this->make_order( 'buyer@example.test', $product );

		$captured = array();
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertCount( 1, $captured, 'Exactly one send-message POST.' );
		self::assertStringContainsString( '/api/message/send.php', $captured[0]['url'] );
		self::assertSame( 4242, $captured[0]['body']['autoresponder_id'] );
		self::assertSame( array( 'buyer@example.test' ), $captured[0]['body']['to'] );
		self::assertSame( 'E2E Confirmation Product', $captured[0]['body']['context']['product_name_1'] );
		self::assertSame( '1', $captured[0]['body']['context']['product_quantity_1'] );

		$order = wc_get_order( $order_id );
		self::assertSame( TransactionalFlusher::META_STATUS_SENT, $order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ) );
	}

	public function test_order_confirmation_fires_on_block_checkout_store_api_hook(): void {
		// PRO-1518: WooCommerce Blocks / Store-API checkout never fires
		// woocommerce_checkout_order_processed, so this is the Store-API
		// twin — same gap shape F3-46 already fixed for attribution capture.
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'Block Checkout Product', 15.00 );
		$order_id = $this->make_order( 'block@example.test', $product );

		$captured = array();
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$this->fire_block_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertCount( 1, $captured, 'Exactly one send-message POST from the Store-API hook.' );
		self::assertSame( array( 'block@example.test' ), $captured[0]['body']['to'] );

		$order = wc_get_order( $order_id );
		self::assertSame( TransactionalFlusher::META_STATUS_SENT, $order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ) );
	}

	public function test_order_confirmation_is_idempotent_across_classic_and_block_checkout_hooks(): void {
		// PRO-1518: if both hooks ever fired for the same order, the
		// once-per-order-per-type meta guard must still cap it at one send.
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'Both Hooks Product', 7.50 );
		$order_id = $this->make_order( 'both@example.test', $product );

		$captured = array();
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
			$this->fire_block_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertCount( 1, $captured, 'The meta guard blocks the Store-API hook once the classic hook already sent.' );
	}

	public function test_shipping_confirmation_sends_once_and_repeated_flips_do_not_resend(): void {
		$this->configure( array( 'shipping_confirmation' => '5151' ) );

		$product  = $this->make_product( 'E2E Shipping Product', 9.00 );
		$order_id = $this->make_order( 'ship@example.test', $product );
		$order    = wc_get_order( $order_id );
		$order->set_status( 'processing' );
		$order->save();

		$captured = array();
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$order->update_status( 'completed' );

			// A later flip away and back into the shipped set must not re-send —
			// the once-per-order-per-type meta guard.
			$order->update_status( 'on-hold' );
			$order->update_status( 'completed' );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertCount( 1, $captured, 'Only the FIRST transition into a shipped status sends.' );
		self::assertSame( 5151, $captured[0]['body']['autoresponder_id'] );
	}

	public function test_native_processing_order_email_suppressed_only_while_the_gate_holds(): void {
		$this->configure( array( 'order_confirmation' => '4242' ) );

		// Real WC_Email::is_enabled() calls this filter as
		// apply_filters( 'woocommerce_email_enabled_{id}', bool, $order, $email ) —
		// match that shape so any other real listener (e.g. WC core's own
		// POS suppression filter) doesn't choke on a missing arg.
		$product = $this->make_product( 'Suppression Product', 4.00 );
		$order   = wc_get_order( $this->make_order( 'suppress@example.test', $product ) );

		self::assertFalse(
			apply_filters( 'woocommerce_email_enabled_customer_processing_order', true, $order, null ),
			'The gate is open (toggle on, mapping present, credentials complete) → suppressed.'
		);

		update_option( 'smly_plus_transactional_emails_enabled', false );

		self::assertTrue(
			apply_filters( 'woocommerce_email_enabled_customer_processing_order', true, $order, null ),
			'Toggling the master switch off must instantly restore the native email — zero behavior change.'
		);
	}

	public function test_everything_off_is_zero_behavior_change(): void {
		// No configure() call — every transactional option is at its default
		// (off), matching a fresh install.
		$product  = $this->make_product( 'Untouched Product', 5.00 );
		$order_id = $this->make_order( 'off@example.test', $product );

		$captured = array();
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertSame( array(), $captured, 'No transactional send attempted.' );
		self::assertSame( 0, $this->queue_count( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION ) );
		self::assertTrue( apply_filters( 'woocommerce_email_enabled_customer_processing_order', true, wc_get_order( $order_id ), null ) );
	}

	public function test_terminal_smaily_rejection_marks_the_row_failed_and_fails_open(): void {
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'Terminal Product', 12.00 );
		$order_id = $this->make_order( 'terminal@example.test', $product );

		$fake = $this->fake_transport_with_code( 203 );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertNotNull( $row );
		self::assertSame( 'failed', $row['status'], 'A non-101 body code is deterministic — mark_failed, not an eternal retry.' );
		self::assertStringContainsString( 'smaily_response_code_203', (string) $row['last_error'] );

		$order = wc_get_order( $order_id );
		self::assertSame(
			TransactionalFlusher::META_STATUS_FAILED_OPEN,
			$order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ),
			'Fail-open decision recorded on the order — the WC mailer trigger itself is unit-covered.'
		);
	}

	public function test_transient_failure_lands_in_the_queue_and_only_its_own_flusher_drains_it(): void {
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'Retry Product', 8.00 );
		$order_id = $this->make_order( 'retry@example.test', $product );

		$fake_5xx = $this->fake_transport_with_code( 500, 500 );
		add_filter( 'pre_http_request', $fake_5xx, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake_5xx, 10 );
		}

		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertNotNull( $row );
		self::assertSame( 'pending', $row['status'], 'A 5xx is transient — the row stays pending for the dedicated retry flusher.' );

		// The MAIN flusher must never touch it (event-type scoping).
		$fake_ok = $this->fake_transport_with_code( 101, 200 );
		add_filter( 'pre_http_request', $fake_ok, 10, 3 );
		try {
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake_ok, 10 );
		}
		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertSame( 'pending', $row['status'], 'The main flusher excludes transactional.* event types.' );

		// TransactionalFlusher's OWN hook drains + retries it successfully.
		add_filter( 'pre_http_request', $fake_ok, 10, 3 );
		try {
			do_action( TransactionalFlusher::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake_ok, 10 );
		}
		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertSame( 'sent', $row['status'] );
	}

	public function test_a_row_stuck_transient_past_the_retry_ceiling_fails_open_instead_of_retrying_forever(): void {
		// PRO-1519: a row that keeps hitting a transient failure (5xx) must
		// stop retrying once it's older than RETRY_CEILING_SECONDS and fail
		// open — the customer's native order-confirmation email, suppressed
		// the whole time this row is pending, must eventually re-fire rather
		// than wait on a Smaily outage that never ends.
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'Ceiling Product', 5.00 );
		$order_id = $this->make_order( 'ceiling@example.test', $product );

		$fake_5xx = $this->fake_transport_with_code( 500, 500 );
		add_filter( 'pre_http_request', $fake_5xx, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake_5xx, 10 );
		}

		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertNotNull( $row );
		self::assertSame( 'pending', $row['status'], 'Still transient at this point — sanity check before backdating.' );

		// Simulate the row having sat in the queue past the ceiling (rather
		// than sleeping for real) by backdating its created_at directly.
		$this->backdate_queue_row( (int) $row['id'], TransactionalFlusher::RETRY_CEILING_SECONDS + 60 );

		$captured = array();
		$fake_5xx_again = $this->fake_transport_with_code( 500, 500 );
		add_filter( 'pre_http_request', $fake_5xx_again, 10, 3 );
		try {
			do_action( TransactionalFlusher::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake_5xx_again, 10 );
		}

		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertSame( 'failed', $row['status'], 'Past the ceiling — the next tick must terminal-fail instead of retrying again.' );
		self::assertStringContainsString( 'retry_ceiling_exceeded', (string) $row['last_error'] );

		$order = wc_get_order( $order_id );
		self::assertSame(
			TransactionalFlusher::META_STATUS_FAILED_OPEN,
			$order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) ),
			'Fail-open must fire once the ceiling forces the terminal failure.'
		);
	}

	public function test_a_non_transactional_queue_row_ignores_the_retry_ceiling(): void {
		// PRO-1519 scoping: the ceiling is owned entirely by
		// TransactionalFlusher — a marketing-side row (drained by the main
		// Flusher, never by TransactionalFlusher) keeps the existing
		// unbounded-retry convention no matter how old it is.
		$this->seed_default_credentials();

		global $wpdb;

		$table = $wpdb->prefix . 'smly_plus_event_queue';
		$wpdb->insert(
			$table,
			array(
				'event_type' => 'contact.sync',
				'entity_id'  => 'ceiling-scope-test@example.test',
				'payload'    => wp_json_encode( array( 'email' => 'ceiling-scope-test@example.test' ) ),
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - TransactionalFlusher::RETRY_CEILING_SECONDS - ( 10 * DAY_IN_SECONDS ) ),
				'attempts'   => 0,
				'status'     => 'pending',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		$fake_5xx = $this->fake_transport_with_code( 500, 500 );
		add_filter( 'pre_http_request', $fake_5xx, 10, 3 );
		try {
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake_5xx, 10 );
		}

		$row = $this->queue_row( 'contact.sync' );
		self::assertNotNull( $row );
		self::assertSame( 'pending', $row['status'], 'The main Flusher has no retry ceiling — an ancient row is still just a normal transient retry.' );
		self::assertNotSame( 'retry_ceiling_exceeded', $row['last_error'] );
	}

	public function test_retry_is_refused_for_a_failed_row_the_woocommerce_email_already_covered(): void {
		// PRO-1733: this shipping confirmation replaced WC's own
		// customer_completed_order email, so its terminal failure re-fired
		// that email — the shopper HAS a confirmation and a retry would be
		// the second one. The route must refuse it and leave the row alone.
		$this->configure( array( 'shipping_confirmation' => '5151' ) );

		$product = $this->make_product( 'Refused Retry Product', 11.00 );
		$order   = wc_get_order( $this->make_order( 'refused@example.test', $product ) );
		$order->set_status( 'processing' );
		$order->save();

		$fake = $this->fake_transport_with_code( 203 );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$order->update_status( 'completed' );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION );
		self::assertNotNull( $row );
		self::assertSame( 'failed', $row['status'] );

		$response = RestRequestHelper::post(
			'/events/retry',
			array(
				'source' => 'smaily',
				'id'     => (int) $row['id'],
			)
		);

		self::assertSame( 409, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 'transactional_retry_refused', $data['error'] );
		self::assertSame( 'wc_email_sent', $data['reason'] );
		self::assertStringContainsString( 'standard WooCommerce email', (string) $data['message'] );

		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION );
		self::assertSame( 'failed', $row['status'], 'The refused row must not be re-queued.' );

		// The Event Log itself hides Retry for it — same guard, read side.
		$listed = $this->listed_row( (int) $row['id'] );
		self::assertSame( 'wc_email_sent', $listed['retry_refusal'] );

		// A bulk "Retry all failed" must not sneak it back in either.
		self::assertSame( 200, RestRequestHelper::post( '/events/retry', array( 'source' => 'smaily' ) )->get_status() );
		self::assertSame( 'failed', $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION )['status'] );
	}

	public function test_retry_re_attempts_a_merchant_status_shipping_confirmation_the_shopper_never_got(): void {
		// PRO-1733: WooCommerce has no native email for a merchant-defined
		// shipped status, so fail-open sent nothing — the shopper has no
		// confirmation at all. Retry must therefore be a real re-attempt:
		// the row is revived, its PRO-1519 hour starts over (without which
		// the next tick would terminal-fail it again), and its own flusher
		// drains it to the Smaily API.
		$this->configure( array( 'shipping_confirmation' => '5151' ), array( 'shipped' ) );

		$custom_status = static function ( array $statuses ): array {
			$statuses['wc-shipped'] = 'Shipped';
			return $statuses;
		};
		add_filter( 'wc_order_statuses', $custom_status );

		try {
			$product = $this->make_product( 'Custom Status Product', 21.00 );
			$order   = wc_get_order( $this->make_order( 'custom@example.test', $product ) );
			$order->set_status( 'processing' );
			$order->save();

			$fake_5xx = $this->fake_transport_with_code( 500, 500 );
			add_filter( 'pre_http_request', $fake_5xx, 10, 3 );
			try {
				$order->update_status( 'shipped' );
			} finally {
				remove_filter( 'pre_http_request', $fake_5xx, 10 );
			}

			$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION );
			self::assertNotNull( $row, 'The custom shipped status must reach the transactional queue.' );
			self::assertSame( 'pending', $row['status'] );

			// Age it past the ceiling and let the next tick terminal-fail it —
			// the exact state a merchant finds in the Event Log.
			$this->backdate_queue_row( (int) $row['id'], TransactionalFlusher::RETRY_CEILING_SECONDS + 60 );
			add_filter( 'pre_http_request', $fake_5xx, 10, 3 );
			try {
				do_action( TransactionalFlusher::FLUSH_HOOK );
			} finally {
				remove_filter( 'pre_http_request', $fake_5xx, 10 );
			}

			$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION );
			self::assertSame( 'failed', $row['status'] );
			self::assertStringContainsString( 'retry_ceiling_exceeded', (string) $row['last_error'] );
			self::assertSame( '', $this->listed_row( (int) $row['id'] )['retry_refusal'], 'Nothing was sent — the Event Log keeps Retry.' );

			$response = RestRequestHelper::post(
				'/events/retry',
				array(
					'source' => 'smaily',
					'id'     => (int) $row['id'],
				)
			);
			self::assertSame( 200, $response->get_status() );
			self::assertSame( 1, $response->get_data()['reset'] );

			$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION );
			self::assertSame( 'pending', $row['status'] );

			$captured = array();
			$fake_ok  = $this->fake_transport( $captured );
			add_filter( 'pre_http_request', $fake_ok, 10, 3 );
			try {
				do_action( TransactionalFlusher::FLUSH_HOOK );
			} finally {
				remove_filter( 'pre_http_request', $fake_ok, 10 );
			}

			self::assertCount( 1, $captured, 'The revived row must actually be re-sent, not aged out again.' );
			self::assertSame( 5151, $captured[0]['body']['autoresponder_id'] );
			self::assertSame( array( 'custom@example.test' ), $captured[0]['body']['to'] );
			self::assertSame( 'sent', $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION )['status'] );
		} finally {
			remove_filter( 'wc_order_statuses', $custom_status );
		}
	}

	public function test_a_shipped_status_whose_plugin_is_gone_still_counts_as_an_existing_order(): void {
		// PRO-2326: on legacy order storage the Event Log used to resolve
		// "does this order still exist?" through a status-filtered lookup, so
		// an order left on a merchant-defined shipped status whose plugin has
		// since been deactivated — the status is no longer registered — was
		// reported as gone and its Retry refused. That is precisely the row
		// PRO-1733 keeps Retry for: WooCommerce never had an email for that
		// status, so the shopper has no confirmation at all.
		$this->configure( array( 'shipping_confirmation' => '5151' ), array( 'shipped' ) );

		$custom_status = static function ( array $statuses ): array {
			$statuses['wc-shipped'] = 'Shipped';
			return $statuses;
		};
		add_filter( 'wc_order_statuses', $custom_status );

		$fake_5xx = $this->fake_transport_with_code( 500, 500 );
		$gone_id  = 0;
		$kept_row = 0;
		$gone_row = 0;

		try {
			$product = $this->make_product( 'Deactivated Status Product', 31.00 );

			foreach ( array( 'kept', 'gone' ) as $which ) {
				$order = wc_get_order( $this->make_order( $which . '@example.test', $product ) );
				$order->set_status( 'processing' );
				$order->save();

				add_filter( 'pre_http_request', $fake_5xx, 10, 3 );
				try {
					$order->update_status( 'shipped' );
				} finally {
					remove_filter( 'pre_http_request', $fake_5xx, 10 );
				}

				$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION );
				self::assertNotNull( $row );
				$this->backdate_queue_row( (int) $row['id'], TransactionalFlusher::RETRY_CEILING_SECONDS + 60 );

				if ( $which === 'kept' ) {
					$kept_row = (int) $row['id'];
				} else {
					$gone_id  = $order->get_id();
					$gone_row = (int) $row['id'];
				}
			}

			// One tick past the ceiling terminal-fails both — the state a
			// merchant finds in the Event Log.
			add_filter( 'pre_http_request', $fake_5xx, 10, 3 );
			try {
				do_action( TransactionalFlusher::FLUSH_HOOK );
			} finally {
				remove_filter( 'pre_http_request', $fake_5xx, 10 );
			}
		} finally {
			// The status plugin is deactivated: nothing registers `wc-shipped`
			// any more, while both orders stay parked on it.
			remove_filter( 'wc_order_statuses', $custom_status );
		}

		// ...and one of the two orders is genuinely deleted.
		wc_get_order( $gone_id )->delete( true );

		$kept = $this->listed_row( $kept_row );
		self::assertSame( 'failed', $kept['status'] );
		self::assertSame( '', $kept['retry_refusal'], 'An order on an unregistered status still exists — Retry stays offered.' );
		self::assertSame( '', $kept['retry_refusal_message'] );

		$gone = $this->listed_row( $gone_row );
		self::assertSame( 'order_missing', $gone['retry_refusal'], 'A deleted order is still reported as gone.' );
		self::assertStringContainsString( 'no longer exists', (string) $gone['retry_refusal_message'] );

		$allowed = RestRequestHelper::post(
			'/events/retry',
			array(
				'source' => 'smaily',
				'id'     => $kept_row,
			)
		);
		self::assertSame( 200, $allowed->get_status() );
		self::assertSame( 1, $allowed->get_data()['reset'] );

		$refused = RestRequestHelper::post(
			'/events/retry',
			array(
				'source' => 'smaily',
				'id'     => $gone_row,
			)
		);
		self::assertSame( 409, $refused->get_status() );
		self::assertSame( 'order_missing', $refused->get_data()['reason'] );
	}

	public function test_send_again_queues_a_second_confirmation_the_status_path_would_never_send(): void {
		// PRO-2324 end to end: a shipping confirmation Smaily sent, then the
		// merchant's explicit "Send again" — a NEW row, a SECOND POST, and
		// the once-per-order guard untouched throughout.
		$this->configure( array( 'shipping_confirmation' => '5151' ) );

		$product = $this->make_product( 'Send Again Product', 25.00 );
		$order   = wc_get_order( $this->make_order( 'again@example.test', $product ) );
		$order->set_status( 'processing' );
		$order->save();

		$captured = array();
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$order->update_status( 'completed' );

			// The guard the action deliberately steps around, pinned: moving
			// the order out of and back into the shipped status sends nothing.
			$order->update_status( 'on-hold' );
			$order->update_status( 'completed' );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertCount( 1, $captured, 'Status transitions stay once-per-order.' );

		$first = $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION );
		self::assertNotNull( $first );
		self::assertSame( 'sent', $first['status'] );
		self::assertTrue(
			$this->listed_row( (int) $first['id'], 'sent' )['can_send_again'],
			'A confirmation Smaily sent must offer the action in the Event Log.'
		);

		$response = RestRequestHelper::post(
			'/events/resend',
			array(
				'source' => 'smaily',
				'id'     => (int) $first['id'],
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 1, $response->get_data()['queued'] );

		self::assertSame( 2, $this->queue_count( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION ), 'The log shows both sends.' );
		$second = $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION );
		self::assertNotSame( (int) $first['id'], (int) $second['id'] );
		self::assertSame( 'pending', $second['status'], 'It goes out on the flusher\'s next scheduled pass, not now.' );
		self::assertTrue(
			(bool) ( json_decode( (string) $second['payload'], true )[ TransactionalFlusher::PAYLOAD_KEY_RESEND ] ?? false ),
			'The new row records that a merchant asked for it.'
		);

		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			do_action( TransactionalFlusher::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertCount( 2, $captured, 'The second confirmation really reaches Smaily.' );
		self::assertSame( 5151, $captured[1]['body']['autoresponder_id'] );
		self::assertSame( array( 'again@example.test' ), $captured[1]['body']['to'] );
		self::assertSame( 'sent', $this->queue_row( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION )['status'] );

		$order = wc_get_order( $order->get_id() );
		self::assertSame(
			TransactionalFlusher::META_STATUS_SENT,
			$order->get_meta( TransactionalFlusher::meta_key_for( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ) ),
			'The once-per-order marker is where it was — the bypass was scoped to that one enqueue.'
		);
	}

	public function test_send_again_is_refused_for_a_row_smaily_never_sent(): void {
		// PRO-2324: the action is offered on — and accepted for — nothing but
		// a confirmation Smaily itself sent. A failed one keeps Retry.
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'No Send Again Product', 6.00 );
		$order_id = $this->make_order( 'nosendagain@example.test', $product );

		$fake = $this->fake_transport_with_code( 203 );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		$row = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertNotNull( $row );
		self::assertSame( 'failed', $row['status'] );
		self::assertFalse( $this->listed_row( (int) $row['id'] )['can_send_again'] );

		$response = RestRequestHelper::post(
			'/events/resend',
			array(
				'source' => 'smaily',
				'id'     => (int) $row['id'],
			)
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'resend_not_available', $response->get_data()['error'] );
		self::assertSame( 1, $this->queue_count( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION ), 'Nothing was queued.' );
	}

	public function test_a_failed_second_confirmation_says_the_first_one_still_stands(): void {
		// PRO-2368: a re-send deliberately does NOT fail open — the customer
		// already has a confirmation. So when the re-send itself fails, the
		// Event Log must not tell the merchant WooCommerce covered it.
		$this->configure( array( 'order_confirmation' => '4242' ) );

		$product  = $this->make_product( 'Failed Resend Product', 9.00 );
		$order_id = $this->make_order( 'failedresend@example.test', $product );

		$captured = array();
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$this->fire_checkout_order_processed( $order_id );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		$first = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertSame( 'sent', $first['status'], 'The first confirmation must really have gone out.' );

		self::assertSame(
			200,
			RestRequestHelper::post(
				'/events/resend',
				array(
					'source' => 'smaily',
					'id'     => (int) $first['id'],
				)
			)->get_status()
		);

		// Smaily refuses the second one outright.
		$reject = $this->fake_transport_with_code( 203 );
		add_filter( 'pre_http_request', $reject, 10, 3 );
		try {
			do_action( TransactionalFlusher::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $reject, 10 );
		}

		$resent = $this->queue_row( TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION );
		self::assertNotSame( (int) $first['id'], (int) $resent['id'] );
		self::assertSame( 'failed', $resent['status'] );

		$listed = $this->listed_row( (int) $resent['id'] );
		self::assertSame( 'resend_failed', $listed['retry_refusal'] );
		self::assertStringContainsString( 'second confirmation could not be sent', (string) $listed['retry_refusal_message'] );
		self::assertStringContainsString( 'still stands', (string) $listed['retry_refusal_message'] );
		self::assertStringNotContainsString( 'WooCommerce', (string) $listed['retry_refusal_message'] );

		// The retry route says the same thing — one wording, both surfaces.
		$refused = RestRequestHelper::post(
			'/events/retry',
			array(
				'source' => 'smaily',
				'id'     => (int) $resent['id'],
			)
		);
		self::assertSame( 409, $refused->get_status() );
		self::assertSame( 'resend_failed', $refused->get_data()['reason'] );
		self::assertStringNotContainsString( 'WooCommerce', (string) $refused->get_data()['message'] );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * @param array<string, string> $mappings         trigger_type => workflow id.
	 * @param array<int, string>    $shipped_statuses The statuses a shipping
	 *                                                confirmation fires on.
	 */
	private function configure( array $mappings, array $shipped_statuses = array( 'completed' ) ): void {
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

		// PRO-1540 — the transactional account (toggle + credentials) is
		// persisted via the connection tab's payload now, alongside the main
		// account it's an optional capability on top of.
		$connection_response = RestRequestHelper::post(
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
		self::assertSame( 200, $connection_response->get_status() );

		$woocommerce_response = RestRequestHelper::post(
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
		self::assertSame( 200, $woocommerce_response->get_status() );
	}

	private function make_product( string $name, float $price ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( (string) $price );
		$product->set_status( 'publish' );
		$product_id = $product->save();
		self::assertGreaterThan( 0, $product_id );
		$this->created_products[] = $product_id;
		return $product_id;
	}

	private function make_order( string $email, int $product_id ): int {
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->add_product( wc_get_product( $product_id ), 1 );
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
	 * A pre_http_request fake that captures every request and replies with
	 * Smaily {code:101} (success).
	 *
	 * @param array<int, array{url: string, body: array<string, mixed>}> $captured By-ref.
	 */
	private function fake_transport( array &$captured ): callable {
		return static function ( $pre, $args, $url ) use ( &$captured ) {
			$captured[] = array(
				'url'  => $url,
				'body' => json_decode( (string) ( $args['body'] ?? '{}' ), true ),
			);
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'code' => 101, 'message' => 'OK' ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => '',
			);
		};
	}

	/**
	 * A pre_http_request fake replying with a fixed Smaily body {code} at a
	 * fixed HTTP status.
	 */
	private function fake_transport_with_code( int $smaily_code, int $http_code = 200 ): callable {
		return static function ( $pre, $args, $url ) use ( $smaily_code, $http_code ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'code' => $smaily_code ) ),
				'response' => array( 'code' => $http_code, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => '',
			);
		};
	}

	/** LEGACY_OPTION_KEY / "default" account credentials (Credentials::DEFAULT_ACCOUNT_KEY) — mirrors CartPipelineTest::seed_credentials(). */
	private function seed_default_credentials(): void {
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	/**
	 * One row as the Event Log list renders it (PRO-1733 — retry_refusal is
	 * computed by the read model, not stored).
	 *
	 * @return array<string, mixed>
	 */
	private function listed_row( int $id, string $status = 'failed' ): array {
		$response = RestRequestHelper::get(
			'/events',
			array(
				'source' => 'smaily',
				'status' => $status,
			)
		);

		foreach ( $response->get_data()['events'] as $row ) {
			if ( (int) $row['id'] === $id ) {
				return $row;
			}
		}

		self::fail( sprintf( 'Row %d not in the event list.', $id ) );
	}

	/** PRO-1519 test-only: push a queue row's created_at back by $seconds without sleeping. */
	private function backdate_queue_row( int $id, int $seconds ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'smly_plus_event_queue',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	private function queue_count( string $event_type ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s",
				$event_type
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function queue_row( string $event_type ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s ORDER BY id DESC LIMIT 1",
				$event_type
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}
}
