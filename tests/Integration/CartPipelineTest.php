<?php
/**
 * Integration: the namespaced abandoned-cart pipeline, end to end (PRO-1195).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CartHookHandler;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * What this pins, against real WP + WC + the real tables:
 *
 *   - LOGGED-IN E2E: a real WC()->cart change tracks a scalar-shape row →
 *     past the merchant's cutoff the smly_plus_abandoned_cart tick enqueues
 *     ONE automation.abandoned_cart event → the CartFlusher POSTs the mapped
 *     workflow to the (mocked) Smaily transport with the legacy-parity
 *     address fields → the row is sent + its F3-44 exchange stored; the
 *     tracker row never re-reminds.
 *   - GUEST capture: an email typed at checkout (update_order_review POST)
 *     attaches the identity to the session row; the reminder carries it.
 *   - NAMES (PRO-1729): with no stored field selection the shopper's name
 *     rides the reminder — and when there is no name to send, the fields are
 *     OMITTED rather than sent empty (an empty value wipes the Smaily contact).
 *   - Settings CARRY-OVER (upgrade continuity): with no mapping row, the
 *     legacy option's autoresponder_id drives the send — an upgrading store
 *     needs zero reconfiguration.
 *   - F3-37 backlog guard: a row older than the reminder window is expired
 *     without emailing; nothing is enqueued for it.
 *
 * The Smaily API is mocked at the pre_http_request seam (the established
 * pattern for the contact/autoresponder API) — no live traffic.
 */
final class CartPipelineTest extends TestCase {

	private const STATUS_OPTION = 'smaily_connect_abandoned_cart_status';
	private const CUTOFF_OPTION = 'smaily_connect_abandoned_cart_cutoff';

	/** @var array<int, int> */
	private array $created_users = array();

	/** @var array<int, int> */
	private array $created_products = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		$this->wipe_tracker();
		CartHookHandler::reset_request_guard();

		// Wizard done + feature on + a 10-minute delay (the legacy min).
		update_option( 'smly_plus_setup_completed', true );
		update_option(
			self::STATUS_OPTION,
			array(
				'enabled'          => true,
				'autoresponder_id' => 0,
			)
		);
		update_option( self::CUTOFF_OPTION, 10 );
		// The fields option is deliberately NOT seeded: EnvScrub deleted it, so
		// every case here runs on FRESH-INSTALL defaults (PRO-1680 — product
		// details must ride the wire without any stored selection).

		RestRequestHelper::login_as_admin();
	}

	protected function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}
		wp_set_current_user( 0 );

		$this->wipe_tracker();
		foreach ( $this->created_products as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$product->delete( true );
			}
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_products = array();
		$this->created_users    = array();

		// Drop any Smaily client cached from this test's seeded credentials.
		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_logged_in_cart_abandonment_end_to_end(): void {
		$this->map_abandoned_cart_workflow( '4242' );
		$this->seed_credentials();

		$user_id = $this->make_user( 'e2e-cart' );
		wp_set_current_user( $user_id );

		$product_id = $this->make_product( 'E2E Cart Product', 19.90 );

		$this->boot_wc_cart();
		WC()->cart->add_to_cart( $product_id, 2 );

		$handler = new CartHookHandler( new CartSessionStore() );
		$handler->on_cart_updated();

		// The tracker row exists: our own scalar shape + the user's identity.
		$row = $this->tracker_row();
		self::assertNotNull( $row, 'A cart change must create a tracker row.' );
		self::assertSame( (string) $user_id, (string) $row['user_id'] );
		$email = get_userdata( $user_id )->user_email;
		self::assertSame( $email, $row['email'] );
		$items = json_decode( (string) $row['cart_content'], true );
		self::assertSame( $product_id, $items[0]['product_id'] );
		self::assertSame( 2, $items[0]['quantity'] );

		// Fresh cart: the sweep must NOT consider it abandoned yet.
		do_action( 'smly_plus_abandoned_cart' );
		self::assertSame( 0, $this->queue_count(), 'A cart inside the cutoff window must not be enqueued.' );

		// Age it past the 10-minute cutoff, inside the 24h window.
		$this->rewind_tracker_row( (int) $row['id'], 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );

		self::assertSame( 1, $this->queue_count(), 'Past the cutoff the sweep enqueues exactly one reminder event.' );

		// Re-sweeping must not enqueue a second reminder (mail_sent parity).
		do_action( 'smly_plus_abandoned_cart' );
		self::assertSame( 1, $this->queue_count() );

		// Flush to the mocked Smaily transport.
		$captured = null;
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			do_action( CartFlusher::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertIsArray( $captured, 'The reminder must reach the transport.' );
		self::assertSame( '4242', (string) $captured['autoresponder'], 'The mapped workflow drives the send.' );
		$address = $captured['addresses'][0];
		self::assertSame( $email, $address['email'] );
		self::assertSame( 'true', $address['is_abandoned_cart'] );
		self::assertSame( 'E2E Cart Product', $address['product_name_1'], 'Legacy template parity: product fields fill the _1..10 matrix.' );
		self::assertSame( '2', $address['product_quantity_1'] );
		// PRO-1680: on a fresh install (no stored field selection) EVERY
		// product detail rides the wire, not just a merchant-picked subset.
		self::assertNotSame( '', $address['product_price_1'], 'Product price must be sent without any merchant selection.' );
		self::assertNotSame( '', $address['product_base_price_1'] );
		self::assertArrayHasKey( 'product_sku_1', $address );
		self::assertArrayHasKey( 'product_description_1', $address );
		self::assertArrayHasKey( 'product_image_url_1', $address );
		self::assertSame( '', $address['product_name_2'] );
		self::assertArrayHasKey( 'language', $address, 'Default fields include language — resolver-sourced, never \'\'.' );
		self::assertNotSame( '', $address['language'] );

		// Queue row terminal + F3-44 exchange stored (real request + reply,
		// no Authorization header anywhere).
		$event = $this->queue_rows()[0];
		self::assertSame( 'sent', $event['status'] );
		self::assertStringContainsString( '"endpoint":"autoresponder"', (string) $event['sent_payload'] );
		self::assertStringContainsString( '"code":101', (string) $event['last_response'] );
		self::assertStringNotContainsString( 'Authorization', (string) $event['sent_payload'] );
	}

	public function test_a_later_cart_overwrites_every_product_slot_from_the_earlier_one(): void {
		// PRO-1680: the contact's product fields in Smaily are whatever the
		// LAST send wrote. A second reminder for the same shopper must
		// therefore overwrite all 10 slots — the earlier cart's details must
		// survive nowhere.
		$this->map_abandoned_cart_workflow( '7070' );
		$this->seed_credentials();

		$user_id = $this->make_user( 'two-carts' );
		wp_set_current_user( $user_id );

		$first_a = $this->make_product( 'First Cart Alpha', 10.00 );
		$first_b = $this->make_product( 'First Cart Beta', 20.00 );
		$second  = $this->make_product( 'Second Cart Only', 30.00 );

		$handler = new CartHookHandler( new CartSessionStore() );

		// Cart #1: two products, abandoned and reminded. (Bootstrap binds the
		// tracker to woocommerce_cart_updated, which add_to_cart fires — the
		// per-request guard means only the first add is tracked, so the guard
		// is reset before the tracking call that must see the WHOLE cart.)
		$this->boot_wc_cart();
		WC()->cart->add_to_cart( $first_a, 1 );
		WC()->cart->add_to_cart( $first_b, 1 );
		CartHookHandler::reset_request_guard();
		$handler->on_cart_updated();
		$this->rewind_tracker_row( (int) $this->tracker_row()['id'], 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );
		$first_payload = $this->flush_capturing();

		self::assertIsArray( $first_payload );
		$first_address = $first_payload['addresses'][0];
		self::assertSame( 'First Cart Alpha', $first_address['product_name_1'] );
		self::assertSame( 'First Cart Beta', $first_address['product_name_2'] );

		// The shopper empties that cart (the tracker row goes with it) and
		// later abandons a different one.
		CartHookHandler::reset_request_guard();
		WC()->cart->empty_cart();
		$handler->on_cart_updated();
		self::assertNull( $this->tracker_row(), 'Emptying the cart clears the tracker row — a later cart earns a fresh reminder.' );

		CartHookHandler::reset_request_guard();
		WC()->cart->add_to_cart( $second, 1 );
		$handler->on_cart_updated();
		$this->rewind_tracker_row( (int) $this->tracker_row()['id'], 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );
		$second_payload = $this->flush_capturing();

		self::assertIsArray( $second_payload );
		$second_address = $second_payload['addresses'][0];
		self::assertSame( 'Second Cart Only', $second_address['product_name_1'] );

		// Every slot past the second cart's single product is sent EMPTY —
		// that is the wire mechanism that clears the earlier cart from the
		// contact (a template renders only the filled slots).
		foreach ( array( 'product_name', 'product_price', 'product_base_price', 'product_sku', 'product_quantity', 'product_description', 'product_image_url' ) as $key ) {
			for ( $i = 2; $i <= 10; $i++ ) {
				self::assertArrayHasKey( $key . '_' . $i, $second_address, 'Unused slots must still be present on the wire.' );
				self::assertSame( '', $second_address[ $key . '_' . $i ], 'Unused slots must be overwritten with an empty value.' );
			}
		}

		$serialized = (string) wp_json_encode( $second_address );
		self::assertStringNotContainsString( 'First Cart Alpha', $serialized, 'The earlier cart must not appear anywhere in the second reminder.' );
		self::assertStringNotContainsString( 'First Cart Beta', $serialized );
	}

	public function test_more_than_ten_products_flag_further_items_on_the_wire(): void {
		$this->map_abandoned_cart_workflow( '7171' );
		$this->seed_credentials();

		$user_id = $this->make_user( 'over-ten' );
		wp_set_current_user( $user_id );

		$this->boot_wc_cart();
		for ( $i = 1; $i <= 11; $i++ ) {
			WC()->cart->add_to_cart( $this->make_product( 'Bulk Product ' . $i, 1.00 + $i ), 1 );
		}

		$handler = new CartHookHandler( new CartSessionStore() );
		CartHookHandler::reset_request_guard();
		$handler->on_cart_updated();
		$this->rewind_tracker_row( (int) $this->tracker_row()['id'], 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );

		$payload = $this->flush_capturing();

		self::assertIsArray( $payload );
		$address = $payload['addresses'][0];
		self::assertSame( 'true', $address['over_10_products'], 'Past slot 10 the template flag tells the email there are further items.' );
		self::assertNotSame( '', $address['product_name_10'], 'Slots 1..10 are filled before the flag trips.' );
		self::assertArrayNotHasKey( 'product_name_11', $address, 'The matrix stops at 10 slots (legacy template parity).' );
	}

	public function test_the_shoppers_name_rides_the_reminder_on_a_fresh_install(): void {
		// PRO-1729: the name fields were gated on the same never-written
		// selection PRO-1680 retired for the products, so a fresh install's
		// reminder carried no name and a template's first-name merge tag
		// rendered nothing.
		$this->map_abandoned_cart_workflow( '9090' );
		$this->seed_credentials();

		$address = $this->remind_one_cart( $this->make_user( 'named-cart', 'Mari', 'Maasikas' ) );

		self::assertSame( 'Mari', $address['first_name'], 'The shopper\'s name must ride the reminder with no stored field selection.' );
		self::assertSame( 'Maasikas', $address['last_name'] );
	}

	public function test_a_shopper_with_no_stored_name_omits_the_name_fields(): void {
		// Contact-level omit rule (F3-47): Smaily leaves an ABSENT field intact
		// and WIPES an empty one — a nameless shopper must not clear the name
		// their contact already carries. (The product slots are the opposite by
		// design: sending them empty is what clears the previous cart.)
		$this->map_abandoned_cart_workflow( '9191' );
		$this->seed_credentials();

		$address = $this->remind_one_cart( $this->make_user( 'nameless-cart' ) );

		self::assertArrayNotHasKey( 'first_name', $address, 'An unknown name is omitted, never sent empty.' );
		self::assertArrayNotHasKey( 'last_name', $address );
		self::assertSame( '', $address['product_name_2'], 'The product slots still ride the wire empty — only the CONTACT fields are omit-on-unknown.' );
	}

	public function test_guest_cart_syncs_once_a_checkout_email_is_known(): void {
		$this->map_abandoned_cart_workflow( '5151' );
		$this->seed_credentials();

		wp_set_current_user( 0 );
		$product_id = $this->make_product( 'Guest Product', 5.00 );

		$this->boot_wc_cart();
		WC()->cart->add_to_cart( $product_id, 1 );

		$handler = new CartHookHandler( new CartSessionStore() );
		$handler->on_cart_updated();

		$row = $this->tracker_row();
		self::assertNotNull( $row, 'Guest carts are tracked (new capability vs the legacy logged-in-only tracker).' );
		self::assertSame( '0', (string) $row['user_id'] );

		// Without an identity the row is tracked but never synced.
		$this->rewind_tracker_row( (int) $row['id'], 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );
		self::assertSame( 0, $this->queue_count(), 'No email identity → no sync (design rule).' );

		// The guest types their email at checkout (classic order-review POST).
		$handler->on_checkout_update_order_review(
			'billing_email=guest-cart%40example.test&billing_first_name=Mari&billing_last_name=Maasikas'
		);

		do_action( 'smly_plus_abandoned_cart' );
		self::assertSame( 1, $this->queue_count(), 'Once the checkout email is known the abandoned cart syncs.' );

		$captured = null;
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			do_action( CartFlusher::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertIsArray( $captured );
		self::assertSame( 'guest-cart@example.test', $captured['addresses'][0]['email'] );
		self::assertSame( '5151', (string) $captured['autoresponder'] );
		// PRO-1729: a guest has no WP profile, so the checkout-captured columns
		// are the name source — and they ride the reminder unselected.
		self::assertSame( 'Mari', $captured['addresses'][0]['first_name'] );
		self::assertSame( 'Maasikas', $captured['addresses'][0]['last_name'] );
	}

	public function test_legacy_autoresponder_id_carries_over_as_the_fallback(): void {
		// Upgrade continuity (F3-54 order): no mapping row — the id the
		// upgraded store still has in the legacy option drives the send.
		update_option(
			self::STATUS_OPTION,
			array(
				'enabled'          => true,
				'autoresponder_id' => 88,
			)
		);
		$this->seed_credentials();

		$user_id = $this->make_user( 'fallback-cart' );
		( new CartSessionStore() )->upsert(
			(string) $user_id,
			$user_id,
			get_userdata( $user_id )->user_email,
			'',
			'',
			array(
				array(
					'product_id'   => 1,
					'variation_id' => 0,
					'quantity'     => 1,
				),
			),
			gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS )
		);

		do_action( 'smly_plus_abandoned_cart' );
		self::assertSame( 1, $this->queue_count() );

		$captured = null;
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			do_action( CartFlusher::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		self::assertIsArray( $captured, 'The legacy fallback must reach the transport.' );
		self::assertSame( '88', (string) $captured['autoresponder'], 'With no mapping row the legacy autoresponder_id is the workflow source — zero reconfiguration on upgrade.' );
	}

	public function test_backlog_guard_expires_stale_carts_without_emailing(): void {
		// F3-37 carried over: a re-armed scheduler must never mass-mail
		// history. A row older than the window is deleted, not enqueued.
		$user_id = $this->make_user( 'stale-cart' );
		( new CartSessionStore() )->upsert(
			(string) $user_id,
			$user_id,
			get_userdata( $user_id )->user_email,
			'',
			'',
			array(),
			gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
		);

		do_action( 'smly_plus_abandoned_cart' );

		self::assertSame( 0, $this->queue_count(), 'A stale cart must be expired without any send attempt.' );
		self::assertNull( $this->tracker_row(), 'The expired row is pruned.' );
	}

	public function test_completing_an_order_clears_the_tracker_row(): void {
		$user_id = $this->make_user( 'order-clears' );
		wp_set_current_user( $user_id );

		$product_id = $this->make_product( 'Cleared Product', 3.50 );
		$this->boot_wc_cart();
		WC()->cart->add_to_cart( $product_id, 1 );

		$handler = new CartHookHandler( new CartSessionStore() );
		$handler->on_cart_updated();
		self::assertNotNull( $this->tracker_row() );

		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->set_billing_email( get_userdata( $user_id )->user_email );
		$order->save();

		try {
			$handler->on_order_processed( $order->get_id() );
			self::assertNull( $this->tracker_row(), 'A completed order must clear the buyer\'s tracker row.' );
		} finally {
			$order->delete( true ); // wp_delete_post is an HPOS no-op — always delete via the order object.
		}
	}

	public function test_cart_hooks_are_registered_by_bootstrap(): void {
		self::assertNotFalse( has_action( 'woocommerce_cart_updated' ), 'Bootstrap must bind the cart tracker to woocommerce_cart_updated.' );
		self::assertNotFalse( has_action( 'woocommerce_checkout_update_order_review' ), 'Guest identity capture (classic checkout) must be bound.' );
		self::assertNotFalse( has_action( CartFlusher::FLUSH_HOOK ), 'The cart flusher AS callback must be bound.' );
		self::assertNotFalse( has_action( 'smly_plus_abandoned_cart' ), 'The sweep tick must be bound.' );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * Initialize WC session + cart in the CLI context (real WC_Cart).
	 */
	private function boot_wc_cart(): void {
		if ( ! WC()->cart instanceof \WC_Cart || WC()->session === null ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		CartHookHandler::reset_request_guard();
	}

	private function map_abandoned_cart_workflow( string $workflow_id ): void {
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
							'workflowId'        => $workflow_id,
							'isDefaultFallback' => true,
						),
					),
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
	}

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

	/**
	 * Drain the cart queue through the mocked transport and return the POST
	 * body that reached it.
	 *
	 * @return mixed
	 */
	private function flush_capturing() {
		$captured = null;
		$fake     = $this->fake_transport( $captured );
		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			do_action( CartFlusher::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}
		return $captured;
	}

	/**
	 * A pre_http_request fake: captures the POST body, replies Smaily 101.
	 *
	 * @param mixed $captured By-ref capture target.
	 */
	private function fake_transport( &$captured ): callable {
		return static function ( $pre, $args ) use ( &$captured ) {
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
	}

	/**
	 * Drive one shopper's cart the whole way: track → age past the cutoff →
	 * sweep → flush, and hand back the address the transport received.
	 *
	 * @return array<string, mixed>
	 */
	private function remind_one_cart( int $user_id ): array {
		wp_set_current_user( $user_id );

		$this->boot_wc_cart();
		WC()->cart->add_to_cart( $this->make_product( 'Reminded Product', 7.50 ), 1 );

		$handler = new CartHookHandler( new CartSessionStore() );
		CartHookHandler::reset_request_guard();
		$handler->on_cart_updated();
		$this->rewind_tracker_row( (int) $this->tracker_row()['id'], 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );

		$payload = $this->flush_capturing();
		self::assertIsArray( $payload, 'The reminder must reach the transport.' );

		return $payload['addresses'][0];
	}

	private function make_user( string $slug, string $first = '', string $last = '' ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_cart_' . $slug . '_' . wp_generate_password( 6, false ),
				'user_email' => $slug . '-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
				'first_name' => $first,
				'last_name'  => $last,
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;
		return $user_id;
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

	/**
	 * @return array<string, mixed>|null The single tracker row (tests keep at most one).
	 */
	private function tracker_row(): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}smly_plus_cart_session ORDER BY id DESC LIMIT 1", ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private function rewind_tracker_row( int $id, int $seconds ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->prefix . 'smly_plus_cart_session',
			array( 'cart_updated' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ),
			array( 'id' => $id )
		);
	}

	private function queue_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s",
				CartFlusher::EVENT_TYPE
			)
		);
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
				CartFlusher::EVENT_TYPE
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	private function wipe_tracker(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}smly_plus_cart_session" );
	}
}
