<?php
/**
 * Integration: every automation trigger marks the contact it enrols (PRO-1681).
 *
 * A contact Smaily holds only because an automation enrolled them used to look
 * exactly like someone who subscribed themselves — only abandoned cart sent
 * anything (`is_abandoned_cart`, a template flag, not a run record). Each
 * trigger now writes its own `<trigger>_automation_at` field carrying when it
 * last ran, so a merchant can segment on it.
 *
 * These cases drive the REAL trigger pipelines against real WP + WC + the real
 * queue/flushers, with the Smaily API mocked at the pre_http_request seam (the
 * established pattern — CartPipelineTest / FirstOrderAutomationPipelineTest),
 * and assert the exact field names and values that reach the wire.
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

final class AutomationMarkerPipelineTest extends TestCase {

	/** UTC `Y-m-d H:i:s` — the shape every marker must reach the wire in. */
	private const MARKER_FORMAT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

	private PipelineFixture $store;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			self::markTestSkipped( 'WooCommerce not active — the trigger pipelines need WC.' );
		}
		EnvScrub::reset();
		HookHandler::reset_seen();
		CartHookHandler::reset_request_guard();
		RestRequestHelper::login_as_admin();

		// Wizard finished — the master gate the whole live-sync path hangs on.
		update_option( 'smly_plus_setup_completed', true );
		PipelineFixture::seed_credentials();
		$this->store = new PipelineFixture();
	}

	protected function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}
		$this->store->clean_up();

		HookHandler::reset_seen();
		wp_set_current_user( 0 );

		// Drop any Smaily client cached from this test's seeded credentials.
		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_welcome_marks_the_contact_and_a_plain_contact_sync_does_not(): void {
		$this->configure_triggers( array( 'welcomeEnabled' => true ), 'welcome', '4242' );

		// Real WooCommerce account creation → the registrar's
		// woocommerce_created_customer callback (PRO-1682: a bare user_register
		// is no longer a welcome trigger).
		$user_id = $this->store->make_customer( 'welcome' );
		// Opting the new customer in produces a REAL contact sync (consent
		// change), and editing their profile another one — neither is an
		// automation run, so neither may carry a marker.
		update_user_meta( $user_id, 'user_newsletter', 1 );
		HookHandler::reset_seen();
		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => 'Mari',
			)
		);

		$bodies = PipelineFixture::flush( EventQueue::FLUSH_HOOK );

		$address = $this->automation_address( $bodies, '4242' );
		self::assertMatchesRegularExpression( self::MARKER_FORMAT, $address['welcome_automation_at'] );
		self::assertSame( PipelineFixture::email_of( $user_id ), $address['email'] );

		$contacts = $this->contact_rows( $bodies );
		self::assertNotSame( array(), $contacts, 'The contact syncs must have reached the transport too.' );
		foreach ( $contacts as $contact ) {
			foreach ( $this->marker_fields() as $field ) {
				self::assertArrayNotHasKey(
					$field,
					$contact,
					'A contact sync is not an automation run — it must carry no marker at all (absent preserves whatever Smaily holds).'
				);
			}
		}
	}

	public function test_first_order_marks_the_contact_alongside_the_order_fields(): void {
		$this->configure_triggers( array( 'firstOrderEnabled' => true ), 'first_order', '5252' );

		$user_id  = $this->store->make_user( 'first-order' );
		$order_id = $this->store->make_order( $user_id );

		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order_id ) );

		$address = $this->automation_address( PipelineFixture::flush( EventQueue::FLUSH_HOOK ), '5252' );

		self::assertMatchesRegularExpression( self::MARKER_FORMAT, $address['first_order_automation_at'] );
		self::assertSame( (string) $order_id, $address['order_id'], 'The existing order fields are unchanged.' );
		self::assertArrayNotHasKey( 'welcome_automation_at', $address, 'A trigger marks only its own field.' );
	}

	public function test_abandoned_cart_marks_the_contact_without_touching_is_abandoned_cart(): void {
		$this->configure_triggers(
			array(
				'abandonedCartEnabled'       => true,
				'abandonedCartCutoffMinutes' => 10,
			),
			'abandoned_cart',
			'6262'
		);

		$user_id = $this->store->make_user( 'cart' );
		wp_set_current_user( $user_id );

		$product_id = $this->store->make_product( 'Marker Cart Product' );
		$this->boot_wc_cart();
		WC()->cart->add_to_cart( $product_id, 1 );

		$handler = new CartHookHandler( new CartSessionStore() );
		CartHookHandler::reset_request_guard();
		$handler->on_cart_updated();

		PipelineFixture::rewind_tracker_row( 30 * MINUTE_IN_SECONDS );
		do_action( 'smly_plus_abandoned_cart' );

		$address = $this->automation_address( PipelineFixture::flush( CartFlusher::FLUSH_HOOK ), '6262' );

		self::assertMatchesRegularExpression( self::MARKER_FORMAT, $address['abandoned_cart_automation_at'] );
		self::assertSame( 'true', $address['is_abandoned_cart'], 'The legacy template flag keeps its exact name and meaning.' );
		self::assertSame( 'Marker Cart Product', $address['product_name_1'], 'The legacy product matrix is untouched.' );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * @return array<int, string> Every marker field this plugin can send.
	 */
	private function marker_fields(): array {
		return array( 'welcome_automation_at', 'first_order_automation_at', 'abandoned_cart_automation_at' );
	}

	/**
	 * Turn one trigger on and map it to a workflow.
	 *
	 * @param array<string, mixed> $toggles Trigger-specific woocommerce-tab toggles.
	 */
	private function configure_triggers( array $toggles, string $trigger_type, string $workflow_id ): void {
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array_merge(
					$toggles,
					array(
						'automationMappings' => array(
							array(
								'triggerType'       => $trigger_type,
								'language'          => 'default',
								'accountKey'        => 'default',
								'workflowId'        => $workflow_id,
								'isDefaultFallback' => true,
							),
						),
					)
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
	}

	/**
	 * The single automation POST's address row, asserted to be the mapped workflow.
	 *
	 * @param array<int, mixed> $bodies
	 *
	 * @return array<string, mixed>
	 */
	private function automation_address( array $bodies, string $workflow_id ): array {
		foreach ( $bodies as $body ) {
			if ( is_array( $body ) && isset( $body['autoresponder'] ) && (string) $body['autoresponder'] === $workflow_id ) {
				self::assertIsArray( $body['addresses'][0] );
				return $body['addresses'][0];
			}
		}

		self::fail( 'The automation must reach the Smaily transport on workflow ' . $workflow_id . '.' );
	}

	/**
	 * The address rows of every contact-API POST (the `contact` endpoint posts
	 * a bare list of subscriber rows, unlike the autoresponder envelope).
	 *
	 * @param array<int, mixed> $bodies
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contact_rows( array $bodies ): array {
		$rows = array();
		foreach ( $bodies as $body ) {
			if ( is_array( $body ) && isset( $body[0] ) && is_array( $body[0] ) ) {
				foreach ( $body as $row ) {
					$rows[] = $row;
				}
			}
		}
		return $rows;
	}

	private function boot_wc_cart(): void {
		if ( ! WC()->cart instanceof \WC_Cart || WC()->session === null ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		CartHookHandler::reset_request_guard();
	}
}
