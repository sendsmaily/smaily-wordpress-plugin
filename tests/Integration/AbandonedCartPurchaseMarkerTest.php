<?php
/**
 * Integration: a reminded shopper who buys is marked, and stops being reminded (PRO-1723).
 *
 * The merchant's Smaily workflow sends the abandoned-cart follow-up letters,
 * and until now nothing told it the shopper had bought — so the series ran to
 * the end regardless. The plugin now writes `abandoned_cart_purchased_at` onto
 * the contact at the order-placed moment, which the workflow uses as its exit
 * condition, and withdraws a reminder still sitting in the queue.
 *
 * These cases drive the REAL pipeline on the running store — cart tracker →
 * sweeper → CartFlusher → order hook → Flusher — with only the Smaily API
 * mocked at the pre_http_request seam (the established pattern,
 * AutomationMarkerPipelineTest / CartPipelineTest).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CartHookHandler;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\PipelineFixture;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class AbandonedCartPurchaseMarkerTest extends TestCase {

	/** The marker's wire shape — UTC `Y-m-d H:i:s`, the same as PRO-1681's. */
	private const MARKER_FORMAT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

	private const MARKER_FIELD = 'abandoned_cart_purchased_at';

	private const WORKFLOW_ID = '7272';

	private PipelineFixture $store;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the cart pipeline needs WC.' );
		}
		EnvScrub::reset();
		HookHandler::reset_seen();
		CartHookHandler::reset_request_guard();
		RestRequestHelper::login_as_admin();

		update_option( 'smly_plus_setup_completed', true );
		PipelineFixture::seed_credentials();
		$this->store = new PipelineFixture();
		$this->configure_abandoned_cart();
	}

	protected function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}
		$this->store->clean_up();

		HookHandler::reset_seen();
		CartHookHandler::reset_request_guard();
		wp_set_current_user( 0 );

		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_a_reminded_shopper_who_buys_is_marked_and_never_reminded_again(): void {
		$user_id = $this->store->make_user( 'purchased' );
		$email   = PipelineFixture::email_of( $user_id );

		$this->track_cart_for( $user_id );
		$reminders = $this->sweep_and_flush_reminders();
		self::assertNotSame( array(), $reminders, 'The reminder itself must go out first — it is what the marker is scoped to.' );

		$order_id = $this->store->make_order( $user_id );
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order_id ) );

		$marker = $this->marker_row( PipelineFixture::flush( EventQueue::FLUSH_HOOK ) );

		self::assertNotNull( $marker, 'The purchase must reach the shopper\'s Smaily contact.' );
		self::assertSame( $email, $marker['email'] );
		self::assertMatchesRegularExpression( self::MARKER_FORMAT, $marker[ self::MARKER_FIELD ] );
		self::assertArrayNotHasKey( 'is_abandoned_cart', $marker, 'The marker is a contact update, not a second reminder.' );
		self::assertArrayNotHasKey( 'product_name_1', $marker, 'The reminder\'s product fields belong to the reminder send (PRO-1680).' );

		// …and the cart earns no further reminder afterwards.
		self::assertSame(
			array(),
			$this->sweep_and_flush_reminders(),
			'A cart the shopper has paid for must never produce another reminder.'
		);
	}

	public function test_a_shopper_the_plugin_never_reminded_gets_no_marker(): void {
		// An ordinary purchase: nothing was ever sent to this shopper, so this
		// feature must write nothing — and must not create a contact.
		$user_id  = $this->store->make_user( 'unreminded' );
		$order_id = $this->store->make_order( $user_id );

		do_action( 'woocommerce_checkout_order_processed', $order_id, array(), wc_get_order( $order_id ) );

		self::assertNull( $this->marker_row( PipelineFixture::flush( EventQueue::FLUSH_HOOK ) ) );
	}

	public function test_a_reminder_still_queued_is_withdrawn_by_the_purchase(): void {
		// The sweeper enqueues up to a minute before the CartFlusher drains it;
		// a shopper who buys inside that window used to be reminded anyway.
		$user_id = $this->store->make_user( 'raced' );
		$email   = PipelineFixture::email_of( $user_id );

		$this->track_cart_for( $user_id );
		PipelineFixture::rewind_tracker_row( 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );
		self::assertSame( 'pending', $this->cart_row_state( $email )['status'], 'The reminder must be queued for this case to mean anything.' );

		$order_id = $this->store->make_order( $user_id );
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order_id ) );

		self::assertSame( array(), PipelineFixture::flush( CartFlusher::FLUSH_HOOK ), 'The queued reminder must never be POSTed after the purchase.' );

		$row = $this->cart_row_state( $email );
		self::assertSame( 'sent', $row['status'], 'The withdrawn row is terminal — it must not be retried.' );
		self::assertStringContainsString( 'cancelled', (string) $row['last_response'] );
		self::assertNull( $row['sent_payload'], 'Nothing was POSTed, so the row must not claim a request.' );

		self::assertNull(
			$this->marker_row( PipelineFixture::flush( EventQueue::FLUSH_HOOK ) ),
			'No reminder ever reached this shopper, so there is nothing to mark.'
		);

		// PRO-2372: the merchant must read the withdrawal on the row itself.
		// The Event Log stores it as `sent` (terminal, never retried), so
		// without this the list showed it exactly like a delivered reminder.
		$listed = $this->listed_rows();
		self::assertTrue(
			$listed[ (int) $row['id'] ],
			'The withdrawn reminder must read as cancelled in the Event Log list.'
		);
		foreach ( $listed as $id => $cancelled ) {
			if ( $id !== (int) $row['id'] ) {
				self::assertFalse( $cancelled, 'Only a withdrawn row reads as cancelled — an ordinary send or skip does not.' );
			}
		}
	}

	// --- helpers -------------------------------------------------------------

	/** Track a live cart for a logged-in shopper through the real hook handler. */
	private function track_cart_for( int $user_id ): void {
		wp_set_current_user( $user_id );

		$product_id = $this->store->make_product( 'Purchase Marker Product' );
		if ( ! WC()->cart instanceof \WC_Cart || WC()->session === null ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		CartHookHandler::reset_request_guard();
		WC()->cart->add_to_cart( $product_id, 1 );

		( new CartHookHandler( new CartSessionStore() ) )->on_cart_updated();
	}

	/**
	 * Age the tracker row past the cutoff, sweep, and drain the cart queue.
	 *
	 * @return array<int, mixed> The reminder POST bodies that reached Smaily.
	 */
	private function sweep_and_flush_reminders(): array {
		PipelineFixture::rewind_tracker_row( 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );

		return PipelineFixture::flush( CartFlusher::FLUSH_HOOK );
	}

	/**
	 * The contact row carrying the purchase marker, if one reached Smaily.
	 *
	 * @param array<int, mixed> $bodies
	 *
	 * @return array<string, mixed>|null
	 */
	private function marker_row( array $bodies ): ?array {
		foreach ( $bodies as $body ) {
			// The contact endpoint posts a bare list of subscriber rows.
			if ( ! is_array( $body ) || ! isset( $body[0] ) || ! is_array( $body[0] ) ) {
				continue;
			}
			foreach ( $body as $row ) {
				if ( is_array( $row ) && isset( $row[ self::MARKER_FIELD ] ) ) {
					return $row;
				}
			}
		}

		return null;
	}

	/**
	 * This shopper's abandoned-cart queue row, found the way the checkout path
	 * finds it: by the contact key the enqueue stamped (PRO-1723), never by
	 * searching the stored payload.
	 *
	 * @return array<string, mixed>
	 */
	private function cart_row_state( string $email ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, sent_payload, last_response FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s AND contact_key = %s ORDER BY id DESC LIMIT 1",
				CartFlusher::EVENT_TYPE,
				EventQueue::contact_key( $email )
			),
			ARRAY_A
		);

		self::assertIsArray( $row, 'The sweeper must have enqueued a reminder row keyed to this shopper.' );

		return $row;
	}

	/**
	 * The Smaily queue as the Event Log list renders it: row id => whether the
	 * list says the row was cancelled (PRO-2372 — computed by the read model,
	 * not stored as a status).
	 *
	 * @return array<int, bool>
	 */
	private function listed_rows(): array {
		// The shopper is still the current user from the cart tracking above;
		// the Event Log is the merchant's screen.
		RestRequestHelper::login_as_admin();

		$response = RestRequestHelper::get( '/events', array( 'source' => 'smaily' ) );
		self::assertSame( 200, $response->get_status() );

		$cancelled = array();
		foreach ( $response->get_data()['events'] as $listed ) {
			$cancelled[ (int) $listed['id'] ] = (bool) $listed['cancelled'];
		}

		return $cancelled;
	}

	/** Abandoned cart on, mapped to a workflow, with a 10-minute cutoff. */
	private function configure_abandoned_cart(): void {
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array(
					'abandonedCartEnabled'       => true,
					'abandonedCartCutoffMinutes' => 10,
					'automationMappings'         => array(
						array(
							'triggerType'       => 'abandoned_cart',
							'language'          => 'default',
							'accountKey'        => 'default',
							'workflowId'        => self::WORKFLOW_ID,
							'isDefaultFallback' => true,
						),
					),
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
	}
}
