<?php
/**
 * Integration: who gets an abandoned-cart reminder in each contact-sync mode (CON-196).
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
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EventsEndpointFactory;
use Smaily\Connect\Tests\Integration\Support\PipelineFixture;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * Smaily creates an address an automation is triggered for as a SUBSCRIBED
 * contact, so a reminder to a shopper who never agreed to marketing emails
 * also subscribed them. The rule, read at send time from the store and from
 * Smaily (the contact read is faked at the pre_http_request seam):
 *
 *   - a contact Smaily has as unsubscribed gets no reminder, in every mode;
 *   - "Subscribers only" (consent) and "Checkout opt-in only" send only with
 *     the shopper's consent: the store record (`user_newsletter`, consent
 *     mode only), the newsletter box ticked in this unfinished classic
 *     checkout, or a subscribed Smaily contact;
 *   - "All customers" (legitimate interest) sends unless Smaily has the
 *     contact as unsubscribed;
 *   - a Smaily read that fails is retried — nothing is sent blind.
 */
final class AbandonedCartConsentTest extends TestCase {

	private const WORKFLOW_ID = '6161';

	private PipelineFixture $store;

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		$this->wipe_tracker();
		CartHookHandler::reset_request_guard();
		HookHandler::reset_seen();

		update_option( 'smly_plus_setup_completed', true );
		update_option(
			'smaily_connect_abandoned_cart_status',
			array(
				'enabled'          => true,
				'autoresponder_id' => 0,
			)
		);
		update_option( 'smaily_connect_abandoned_cart_cutoff', 10 );
		PipelineFixture::seed_credentials();

		RestRequestHelper::login_as_admin();
		$this->map_workflow();
		wp_set_current_user( 0 );

		$this->store = new PipelineFixture();
	}

	protected function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}
		wp_set_current_user( 0 );

		$this->store->clean_up();
		$this->wipe_tracker();
		delete_option( ContactSyncMode::OPTION_MODE );
		delete_option( ContactSyncMode::OPTION_SYNC_ENABLED );
		$this->forget_mode_memo();

		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}> [ mode, shopper, expected outcome ]
	 */
	public static function rule_cases(): array {
		$expected = array(
			ContactSyncMode::MODE_CONSENT             => array(
				'store_consent'       => 'sent',
				'checkout_tick'       => 'sent',
				'smaily_subscribed'   => 'sent',
				'smaily_unsubscribed' => 'unsubscribed',
				'smaily_unknown'      => 'no_marketing_consent',
				'smaily_unreachable'  => 'retry',
			),
			ContactSyncMode::MODE_CHECKOUT_OPTIN      => array(
				// Checkout-only keeps no store record for account holders.
				'store_consent'       => 'no_marketing_consent',
				'checkout_tick'       => 'sent',
				'smaily_subscribed'   => 'sent',
				'smaily_unsubscribed' => 'unsubscribed',
				'smaily_unknown'      => 'no_marketing_consent',
				'smaily_unreachable'  => 'retry',
			),
			ContactSyncMode::MODE_LEGITIMATE_INTEREST => array(
				'store_consent'       => 'sent',
				'checkout_tick'       => 'sent',
				'smaily_subscribed'   => 'sent',
				'smaily_unsubscribed' => 'unsubscribed',
				'smaily_unknown'      => 'sent',
				'smaily_unreachable'  => 'retry',
			),
		);

		$cases = array();
		foreach ( $expected as $mode => $shoppers ) {
			foreach ( $shoppers as $shopper => $outcome ) {
				$cases[ $mode . ' / ' . $shopper ] = array( $mode, $shopper, $outcome );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider rule_cases
	 */
	public function test_the_reminder_follows_the_consent_rule( string $mode, string $shopper, string $expected ): void {
		$this->set_mode( $mode );

		$email = $this->abandon_a_cart( $shopper );

		$bodies = PipelineFixture::flush( CartFlusher::FLUSH_HOOK, self::smaily_read_for( $shopper, $email ) );
		$row    = $this->cart_row( $email );

		if ( $expected === 'sent' ) {
			self::assertNotNull( $this->reminder( $bodies ), 'The reminder must reach Smaily.' );
			self::assertSame( 'sent', $row['status'] );
			self::assertSame( '', EventQueue::skip_reason( (string) $row['last_response'] ) );
			return;
		}

		self::assertNull( $this->reminder( $bodies ), 'No reminder may reach Smaily: it would subscribe the shopper.' );

		if ( $expected === 'retry' ) {
			self::assertSame( 'pending', $row['status'], 'A failed read keeps the row on the normal retry ladder.' );
			self::assertSame( '1', (string) $row['attempts'] );
			return;
		}

		self::assertSame( 'sent', $row['status'], 'The skip is terminal — a retry cannot grow consent.' );
		self::assertNull( $row['sent_payload'], 'Nothing was POSTed, so the row must not claim a request.' );
		self::assertSame( $expected, EventQueue::skip_reason( (string) $row['last_response'] ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function consent_modes(): array {
		return array(
			'Subscribers only'      => array( ContactSyncMode::MODE_CONSENT ),
			'Checkout opt-in only' => array( ContactSyncMode::MODE_CHECKOUT_OPTIN ),
		);
	}

	/**
	 * CON-202: the shopper fills in the address (one order review, box clear),
	 * then ticks the box as the last thing before leaving. The box now
	 * refreshes the order review, so that second review carries the tick.
	 *
	 * @dataProvider consent_modes
	 */
	public function test_a_tick_made_last_gets_a_reminder( string $mode ): void {
		$this->set_mode( $mode );

		$email  = $this->abandon_a_cart( 'tick_last' );
		$bodies = PipelineFixture::flush( CartFlusher::FLUSH_HOOK, self::smaily_not_found() );

		self::assertNotNull( $this->reminder( $bodies ), 'The tick made last is consent for the reminder.' );
		self::assertSame( 'sent', $this->cart_row( $email )['status'] );
	}

	public function test_a_tick_does_not_override_an_unsubscribe_in_smaily(): void {
		$this->set_mode( ContactSyncMode::MODE_CONSENT );

		$email  = $this->abandon_a_cart( 'checkout_tick' );
		$bodies = PipelineFixture::flush( CartFlusher::FLUSH_HOOK, self::smaily_contact( $email, '1' ) );

		self::assertNull( $this->reminder( $bodies ) );
		self::assertSame( 'unsubscribed', EventQueue::skip_reason( (string) $this->cart_row( $email )['last_response'] ) );
	}

	public function test_a_box_unticked_again_is_no_consent(): void {
		$this->set_mode( ContactSyncMode::MODE_CONSENT );

		$email  = $this->abandon_a_cart( 'checkout_tick', true );
		$bodies = PipelineFixture::flush( CartFlusher::FLUSH_HOOK, self::smaily_not_found() );

		self::assertNull( $this->reminder( $bodies ) );
		self::assertSame( 'no_marketing_consent', EventQueue::skip_reason( (string) $this->cart_row( $email )['last_response'] ) );
	}

	public function test_the_reminder_does_not_depend_on_the_contact_sync_switch(): void {
		$this->set_mode( ContactSyncMode::MODE_CONSENT );
		update_option( ContactSyncMode::OPTION_SYNC_ENABLED, '' );

		$email  = $this->abandon_a_cart( 'store_consent' );
		$bodies = PipelineFixture::flush( CartFlusher::FLUSH_HOOK, self::smaily_not_found() );

		self::assertNotNull( $this->reminder( $bodies ), 'Contact sync off does not stop a reminder the shopper consented to (PRO-1678).' );
		self::assertSame( 'sent', $this->cart_row( $email )['status'] );
	}

	public function test_the_event_log_lists_a_skipped_reminder_with_its_reason_and_not_as_sent(): void {
		$this->set_mode( ContactSyncMode::MODE_CONSENT );

		$email = $this->abandon_a_cart( 'smaily_unknown' );
		PipelineFixture::flush( CartFlusher::FLUSH_HOOK, self::smaily_not_found() );
		$id = (int) $this->cart_row( $email )['id'];

		RestRequestHelper::login_as_admin();
		$listed = $this->listed( array( 'source' => 'smaily' ) );
		$event  = current( array_filter( $listed, static fn ( $e ) => (int) $e['id'] === $id ) );

		self::assertIsArray( $event, 'The skipped reminder is listed under All statuses.' );
		self::assertTrue( $event['skipped'] );
		self::assertSame( 'no_marketing_consent', $event['skip_reason'] );
		self::assertNotContains(
			$id,
			array_map( 'intval', array_column( $this->listed( array( 'status' => 'sent' ) ), 'id' ) ),
			'Nothing was sent, so the Sent filter leaves the row out.'
		);
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * Track one shopper's cart, age it past the cutoff and let the sweeper
	 * queue the reminder. Returns the cart's email.
	 *
	 * @param bool $untick For the classic-checkout tick: the shopper clears the box again before leaving.
	 */
	private function abandon_a_cart( string $shopper, bool $untick = false ): string {
		$product_id = $this->store->make_product( 'Consent Cart Product' );

		if ( $shopper === 'tick_last' ) {
			// A guest who types the address, then ticks the box and leaves:
			// the address change posts a review with the box clear, and the
			// tick posts one more that changes nothing else (CON-202).
			wp_set_current_user( 0 );
			$this->boot_wc_cart();
			WC()->cart->add_to_cart( $product_id, 1 );
			$this->track_cart();

			$email = 'con202-tick-last-' . wp_generate_password( 6, false ) . '@example.test';
			do_action( 'woocommerce_checkout_update_order_review', self::posted_checkout( $email, false ) );
			$this->track_cart();
			do_action( 'woocommerce_checkout_update_order_review', self::posted_checkout( $email, true ) );
			$this->track_cart();
		} elseif ( $shopper === 'checkout_tick' ) {
			// A guest who ticks the box in the classic checkout and leaves.
			wp_set_current_user( 0 );
			$this->boot_wc_cart();
			WC()->cart->add_to_cart( $product_id, 1 );
			$this->track_cart();

			$email = 'con196-tick-' . wp_generate_password( 6, false ) . '@example.test';
			do_action( 'woocommerce_checkout_update_order_review', self::posted_checkout( $email, true ) );
			// The order review recalculates the cart, which saves the
			// tracker row again — the tick must survive that.
			$this->track_cart();

			if ( $untick ) {
				// The next order review reports the cleared box.
				do_action( 'woocommerce_checkout_update_order_review', self::posted_checkout( $email, false ) );
				$this->track_cart();
			}
		} else {
			$user_id = $this->store->make_user( 'con196-' . str_replace( '_', '-', $shopper ) );
			$email   = PipelineFixture::email_of( $user_id );
			if ( $shopper === 'store_consent' ) {
				update_user_meta( $user_id, 'user_newsletter', 1 );
			}

			wp_set_current_user( $user_id );
			$this->boot_wc_cart();
			WC()->cart->add_to_cart( $product_id, 1 );
			$this->track_cart();
		}

		PipelineFixture::rewind_tracker_row( 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );
		self::assertSame( 'pending', $this->cart_row( $email )['status'], 'The reminder must be queued for this case to mean anything.' );

		return $email;
	}

	private function track_cart(): void {
		CartHookHandler::reset_request_guard();
		( new CartHookHandler( new CartSessionStore() ) )->on_cart_updated();
	}

	private static function posted_checkout( string $email, bool $ticked ): string {
		$fields = array(
			'billing_email'      => $email,
			'billing_first_name' => 'Mari',
			'payment_method'     => 'cod',
		);
		if ( $ticked ) {
			$fields['user_newsletter'] = '1';
		}
		return http_build_query( $fields );
	}

	private static function smaily_read_for( string $shopper, string $email ): callable {
		switch ( $shopper ) {
			case 'smaily_subscribed':
				return self::smaily_contact( $email, '0' );
			case 'smaily_unsubscribed':
				return self::smaily_contact( $email, '1' );
			case 'smaily_unreachable':
				return static fn (): array => array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 503,
						'message' => 'Service Unavailable',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			default:
				return self::smaily_not_found();
		}
	}

	private static function smaily_contact( string $email, string $is_unsubscribed ): callable {
		return self::smaily_answers(
			array(
				'email'           => $email,
				'is_unsubscribed' => $is_unsubscribed,
			)
		);
	}

	private static function smaily_not_found(): callable {
		return self::smaily_answers(
			array(
				'code'    => 206,
				'message' => 'Could not find requested email address',
			)
		);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private static function smaily_answers( array $body ): callable {
		return static fn (): array => array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => '',
		);
	}

	/**
	 * The reminder POST among the bodies that reached Smaily, if any.
	 *
	 * @param array<int, mixed> $bodies
	 *
	 * @return array<string, mixed>|null
	 */
	private function reminder( array $bodies ): ?array {
		foreach ( $bodies as $body ) {
			if ( is_array( $body ) && (string) ( $body['autoresponder'] ?? '' ) === self::WORKFLOW_ID ) {
				return $body;
			}
		}
		return null;
	}

	/**
	 * The shopper's abandoned-cart row in the Smaily queue.
	 *
	 * @return array<string, mixed>
	 */
	private function cart_row( string $email ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s AND contact_key = %s ORDER BY id DESC LIMIT 1",
				CartFlusher::EVENT_TYPE,
				EventQueue::contact_key( $email )
			),
			ARRAY_A
		);
		self::assertIsArray( $row, 'The sweeper must have queued a reminder for ' . $email );
		return $row;
	}

	/**
	 * @param array<string, string> $params
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function listed( array $params ): array {
		$request = new \WP_REST_Request( 'GET', '/smaily-connect/v1/events' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return EventsEndpointFactory::create()->list_events( $request )->get_data()['events'];
	}

	private function boot_wc_cart(): void {
		if ( ! WC()->cart instanceof \WC_Cart || WC()->session === null ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		CartHookHandler::reset_request_guard();
	}

	private function map_workflow(): void {
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

	private function set_mode( string $mode ): void {
		update_option( ContactSyncMode::OPTION_MODE, $mode );
		$this->forget_mode_memo();
	}

	/** Clear the registered HookHandler's mode memo, so no later test inherits this one's mode. */
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

	private function wipe_tracker(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . CartSessionStore::TABLE_SUFFIX );
	}
}
