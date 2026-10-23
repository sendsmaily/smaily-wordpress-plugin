<?php
/**
 * Integration: each automation email carries the shopper's own single-use
 * WooCommerce discount code (CON-199).
 *
 * Drives the real queue, flushers and WooCommerce coupons, with the Smaily
 * API mocked at the pre_http_request seam (the AutomationMarkerPipelineTest
 * pattern), and asserts what reaches the wire and what lands in WooCommerce:
 * the clone of the template, its single use, its email restriction and its
 * last day; one coupon per queue row, however often the row is tried; no
 * coupon for a skipped send; WooCommerce's own refusal of a used, foreign or
 * expired code; and the daily cleanup.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\DB\QueueJanitor;
use Smaily\Connect\Integrations\WooCommerce\AutomationDiscount;
use Smaily\Connect\Integrations\WooCommerce\CartHookHandler;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\PipelineFixture;
use Smaily\Connect\Tests\Integration\Support\QueueRowFixture;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class AutomationDiscountCodeTest extends TestCase {

	private const TIMEZONE = 'Europe/Tallinn';

	private PipelineFixture $store;

	/** @var array<int, int> Coupons this test made itself (templates, seeded codes). */
	private array $coupons = array();

	/** @var mixed */
	private $timezone_before;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Coupon' ) ) {
			self::markTestSkipped( 'WooCommerce not active — discount codes are WooCommerce coupons.' );
		}
		EnvScrub::reset();
		HookHandler::reset_seen();
		CartHookHandler::reset_request_guard();
		RestRequestHelper::login_as_admin();

		update_option( 'smly_plus_setup_completed', true );
		PipelineFixture::seed_credentials();
		$this->store = new PipelineFixture();

		$this->timezone_before = get_option( 'timezone_string' );
		update_option( 'timezone_string', self::TIMEZONE );
	}

	protected function tearDown(): void {
		$this->store->clean_up();
		foreach ( array_merge( $this->coupons, $this->generated_coupon_ids() ) as $coupon_id ) {
			wp_delete_post( $coupon_id, true );
		}
		$this->coupons = array();
		update_option( 'timezone_string', $this->timezone_before );

		HookHandler::reset_seen();
		wp_set_current_user( 0 );

		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_a_welcome_email_carries_a_new_single_use_code_cloned_from_the_template(): void {
		$template_id = $this->make_template();
		$this->configure( 'welcome', '4242', $template_id, 14, array( 'welcomeEnabled' => true ) );

		$user_id = $this->store->make_customer( 'discount-welcome' );
		HookHandler::record_newsletter_optin( $user_id, HookHandler::SOURCE_CHECKOUT );

		$address = $this->automation_address( PipelineFixture::flush( EventQueue::FLUSH_HOOK ), '4242' );

		$last_day = $this->store_day( 14 );
		self::assertSame( $last_day, $address['welcome_coupon_expires_at'] );
		$code   = (string) $address['welcome_coupon_code'];
		$coupon = new \WC_Coupon( $code );
		self::assertGreaterThan( 0, $coupon->get_id(), 'The code is a real WooCommerce coupon.' );
		self::assertNotSame( $template_id, $coupon->get_id() );

		// Single use, for the shopper only, until the end of the last day.
		self::assertSame( 1, $coupon->get_usage_limit() );
		self::assertSame( 1, $coupon->get_usage_limit_per_user() );
		self::assertSame( array( strtolower( PipelineFixture::email_of( $user_id ) ) ), $coupon->get_email_restrictions() );
		self::assertSame(
			( new \DateTimeImmutable( $last_day . ' 23:59:59', new \DateTimeZone( self::TIMEZONE ) ) )->getTimestamp(),
			$coupon->get_date_expires()->getTimestamp()
		);
		self::assertSame( (string) $template_id, (string) $coupon->get_meta( AutomationDiscount::META_GENERATED_FROM ) );

		// Every other setting is the template's.
		$template = new \WC_Coupon( $template_id );
		foreach ( array( 'discount_type', 'amount', 'individual_use', 'product_ids', 'excluded_product_ids', 'free_shipping', 'exclude_sale_items', 'minimum_amount', 'maximum_amount', 'limit_usage_to_x_items' ) as $prop ) {
			self::assertSame( $template->{'get_' . $prop}(), $coupon->{'get_' . $prop}(), $prop . ' is cloned from the template.' );
		}

		// The template itself is untouched.
		self::assertSame( array(), $template->get_email_restrictions() );
		self::assertSame( 0, $template->get_usage_limit() );
	}

	public function test_a_retried_send_posts_the_same_code_and_makes_no_second_coupon(): void {
		$template_id = $this->make_template();
		$this->configure( 'first_order', '5252', $template_id, 7, array( 'firstOrderEnabled' => true ) );

		$user_id  = $this->store->make_user( 'discount-retry' );
		$order_id = $this->store->make_order( $user_id );
		do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $order_id ) );

		// Smaily is down on the first attempt: the code is made, the post fails.
		$first_code = (string) $this->automation_address( $this->flush_answering( 500 ), '5252' )['first_order_coupon_code'];
		self::assertNotSame( '', $first_code );
		$row = $this->only_row( HookHandler::EVENT_AUTOMATION_FIRST_ORDER );
		self::assertSame( EventQueue::STATUS_PENDING, $row['status'], 'The failed post is retried.' );
		self::assertStringContainsString( $first_code, (string) $row['payload'], 'The row keeps its code for the retry.' );

		$this->make_due( (int) $row['id'] );
		$address = $this->automation_address( PipelineFixture::flush( EventQueue::FLUSH_HOOK ), '5252' );

		self::assertSame( $first_code, $address['first_order_coupon_code'] );
		self::assertCount( 1, $this->generated_coupon_ids(), 'A retry makes no second coupon.' );
		self::assertSame( EventQueue::STATUS_SENT, $this->only_row( HookHandler::EVENT_AUTOMATION_FIRST_ORDER )['status'] );
	}

	public function test_a_reminder_the_consent_check_skips_makes_no_code(): void {
		$template_id = $this->make_template();
		$this->configure( 'abandoned_cart', '6262', $template_id, 3, array( 'abandonedCartEnabled' => true ) );
		$this->enqueue_cart_reminder( 'skipped-shopper@example.test' );

		// Default consent mode; Smaily does not know the address.
		$bodies = PipelineFixture::flush( CartFlusher::FLUSH_HOOK );

		foreach ( $bodies as $body ) {
			self::assertFalse( is_array( $body ) && isset( $body['autoresponder'] ), 'No reminder is posted.' );
		}
		self::assertSame( array(), $this->generated_coupon_ids(), 'A skipped send makes no code.' );
	}

	public function test_an_abandoned_cart_reminder_carries_its_code(): void {
		$template_id = $this->make_template();
		$this->configure( 'abandoned_cart', '6262', $template_id, 3, array( 'abandonedCartEnabled' => true ) );
		$this->enqueue_cart_reminder( 'Cart-Shopper@Example.test' );

		$address = $this->automation_address( PipelineFixture::flush( CartFlusher::FLUSH_HOOK, PipelineFixture::subscribed_contact_read() ), '6262' );

		self::assertSame( $this->store_day( 3 ), $address['abandoned_cart_coupon_expires_at'] );
		$coupon = new \WC_Coupon( (string) $address['abandoned_cart_coupon_code'] );
		self::assertSame( array( 'cart-shopper@example.test' ), $coupon->get_email_restrictions() );
		self::assertSame( 'true', $address['is_abandoned_cart'], 'The reminder\'s own fields are unchanged.' );
	}

	/**
	 * @dataProvider unusable_templates
	 *
	 * @param callable(int): void $spoil What makes the template unusable.
	 */
	public function test_a_template_that_cannot_give_a_code_sends_the_email_without_one_and_says_why( callable $spoil, string $reason ): void {
		$template_id = $this->make_template();
		$this->configure( 'welcome', '4242', $template_id, 7, array( 'welcomeEnabled' => true ) );
		$spoil( $template_id );

		$this->enqueue_welcome( 'no-code@example.test' );
		$address = $this->automation_address( PipelineFixture::flush( EventQueue::FLUSH_HOOK ), '4242' );

		self::assertArrayNotHasKey( 'welcome_coupon_code', $address );
		self::assertArrayNotHasKey( 'welcome_coupon_expires_at', $address );
		self::assertSame( array(), $this->generated_coupon_ids() );

		$row = $this->only_row( HookHandler::EVENT_AUTOMATION_WELCOME );
		self::assertSame( EventQueue::STATUS_SENT, $row['status'] );
		self::assertFalse( \Smaily\Connect\REST\EventsEndpoint::is_skipped( (string) $row['status'], (string) $row['last_response'] ), 'The email went: the row is not a skipped send.' );
		$response = json_decode( (string) $row['last_response'], true );
		self::assertStringContainsString( 'Sent without a discount code', (string) $response['note'] );
		self::assertStringContainsString( '#' . $template_id, (string) $response['note'] );
		self::assertStringContainsString( $reason, (string) $response['note'] );
	}

	/**
	 * @return array<string, array{0: callable(int): void, 1: string}>
	 */
	public static function unusable_templates(): array {
		return array(
			'expired'            => array(
				static function ( int $id ): void {
					$coupon = new \WC_Coupon( $id );
					$coupon->set_date_expires( time() - DAY_IN_SECONDS );
					$coupon->save();
				},
				'expired',
			),
			'in the trash'       => array(
				static function ( int $id ): void {
					wp_trash_post( $id );
				},
				'deleted',
			),
			'deleted'            => array(
				static function ( int $id ): void {
					wp_delete_post( $id, true );
				},
				'deleted',
			),
			'another plugin\'s type' => array(
				static function ( int $id ): void {
					update_post_meta( $id, 'discount_type', 'store_credit' );
				},
				'type',
			),
		);
	}

	public function test_no_code_is_sent_while_no_template_is_picked(): void {
		$this->configure( 'welcome', '4242', 0, 7, array( 'welcomeEnabled' => true ) );

		$this->enqueue_welcome( 'no-template@example.test' );
		$address = $this->automation_address( PipelineFixture::flush( EventQueue::FLUSH_HOOK ), '4242' );

		self::assertArrayNotHasKey( 'welcome_coupon_code', $address );
		$response = json_decode( (string) $this->only_row( HookHandler::EVENT_AUTOMATION_WELCOME )['last_response'], true );
		self::assertStringContainsString( 'no WooCommerce coupon is picked', (string) $response['note'] );
	}

	public function test_woocommerce_takes_the_code_once_only_from_the_shopper_and_not_after_its_last_day(): void {
		$template_id = $this->make_template(
			array(
				'minimum_amount' => '',
				'product_ids'    => array(),
			)
		);
		$this->configure( 'welcome', '4242', $template_id, 7, array( 'welcomeEnabled' => true ) );

		$shopper = $this->store->make_user( 'discount-owner' );
		$this->enqueue_welcome( PipelineFixture::email_of( $shopper ) );
		$code = (string) $this->automation_address( PipelineFixture::flush( EventQueue::FLUSH_HOOK ), '4242' )['welcome_coupon_code'];

		$own_order = wc_get_order( $this->store->make_order( $shopper ) );
		self::assertTrue( $this->is_valid( $code, $own_order ), 'The shopper can use the code.' );

		$someone_else = wc_get_order( $this->store->make_order( $this->store->make_user( 'discount-other' ) ) );
		self::assertInstanceOf( \WP_Error::class, $this->is_valid( $code, $someone_else ), 'Only the shopper\'s email may use it.' );

		$coupon = new \WC_Coupon( $code );
		$coupon->set_date_expires( time() - 60 );
		$coupon->save();
		self::assertInstanceOf( \WP_Error::class, $this->is_valid( $code, $own_order ), 'WooCommerce itself refuses it after its last day.' );

		$coupon->set_date_expires( time() + DAY_IN_SECONDS );
		$coupon->set_usage_count( 1 );
		$coupon->save();
		self::assertInstanceOf( \WP_Error::class, $this->is_valid( $code, $own_order ), 'It works once.' );
	}

	public function test_the_daily_cleanup_deletes_unused_codes_30_days_after_their_expiry(): void {
		$expired_unused = $this->seed_generated_code( 31, 0 );
		$expired_used   = $this->seed_generated_code( 31, 1 );
		$recent_unused  = $this->seed_generated_code( 29, 0 );
		$still_valid    = $this->seed_generated_code( -5, 0 );
		$merchants_own  = $this->make_template( array( 'date_expires' => time() - 100 * DAY_IN_SECONDS ) );

		( new QueueJanitor() )->on_tick();

		self::assertNull( get_post( $expired_unused ), 'Expired over 30 days ago and never used: deleted.' );
		self::assertNotNull( get_post( $expired_used ), 'A used code stays.' );
		self::assertNotNull( get_post( $recent_unused ), 'Not yet 30 days past its expiry.' );
		self::assertNotNull( get_post( $still_valid ) );
		self::assertNotNull( get_post( $merchants_own ), 'A coupon the plugin did not make is never touched.' );
	}

	public function test_the_picker_lists_only_coupons_a_code_can_be_cloned_from(): void {
		$usable     = $this->make_template();
		$draft      = $this->make_template( array( 'status' => 'draft' ) );
		$expired    = $this->make_template( array( 'date_expires' => time() - DAY_IN_SECONDS ) );
		$other_type = $this->make_template();
		update_post_meta( $other_type, 'discount_type', 'store_credit' );
		$generated = $this->seed_generated_code( -5, 0 );

		$listed = array_column( AutomationDiscount::templates(), 'id' );

		self::assertContains( $usable, $listed );
		self::assertNotContains( $draft, $listed );
		self::assertNotContains( $expired, $listed );
		self::assertNotContains( $other_type, $listed );
		self::assertNotContains( $generated, $listed, 'A code the plugin made is never a template.' );
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * A merchant's coupon — the template. Its settings differ from every
	 * WooCommerce default so a clone that drops one shows.
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function make_template( array $overrides = array() ): int {
		$product_id = $this->store->make_product( 'Discounted Product' );

		$coupon = new \WC_Coupon();
		$coupon->set_props(
			array_merge(
				array(
					'code'                   => 'smly-template-' . strtolower( wp_generate_password( 6, false ) ),
					'discount_type'          => 'percent',
					'amount'                 => '15',
					'individual_use'         => true,
					'product_ids'            => array( $product_id ),
					'free_shipping'          => true,
					'exclude_sale_items'     => true,
					'minimum_amount'         => '20',
					'maximum_amount'         => '500',
					'limit_usage_to_x_items' => 2,
				),
				array_diff_key( $overrides, array( 'status' => true ) )
			)
		);
		$coupon->set_status( (string) ( $overrides['status'] ?? 'publish' ) );
		$id = (int) $coupon->save();
		self::assertGreaterThan( 0, $id );
		$this->coupons[] = $id;
		return $id;
	}

	/** A code as the plugin would have made it, expired $days_ago days ago. */
	private function seed_generated_code( int $days_ago, int $usage_count ): int {
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'smly-seeded-' . strtolower( wp_generate_password( 8, false ) ) );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( '10' );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( time() - $days_ago * DAY_IN_SECONDS );
		$coupon->set_usage_count( $usage_count );
		$coupon->update_meta_data( AutomationDiscount::META_GENERATED_FROM, '1' );
		$id = (int) $coupon->save();
		$this->coupons[] = $id;
		return $id;
	}

	/**
	 * @return array<int, int>
	 */
	private function generated_coupon_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => 'shop_coupon',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
					'meta_key'    => AutomationDiscount::META_GENERATED_FROM,
				)
			)
		);
	}

	/**
	 * Turn one automation on, map it and set its discount code.
	 *
	 * @param array<string, mixed> $toggles
	 */
	private function configure( string $trigger, string $workflow_id, int $template_id, int $days, array $toggles ): void {
		$discount  = array(
			'enabled'    => true,
			'templateId' => $template_id,
			'validDays'  => $days,
		);
		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array_merge(
					$toggles,
					array(
						'automationDiscounts' => array( $trigger => $discount ),
						'automationMappings'  => array(
							array(
								'triggerType'       => $trigger,
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

	/** A welcome row as HookHandler enqueues it, where the opt-in itself is not under test. */
	private function enqueue_welcome( string $email ): void {
		\Smaily\Connect\Bootstrap::instance()->event_queue()->enqueue(
			HookHandler::EVENT_AUTOMATION_WELCOME,
			$email,
			array(
				'email'    => $email,
				'language' => '',
				'fields'   => array( 'welcome_automation_at' => gmdate( 'Y-m-d H:i:s' ) ),
			)
		);
	}

	private function enqueue_cart_reminder( string $email ): void {
		\Smaily\Connect\Bootstrap::instance()->event_queue()->enqueue(
			CartFlusher::EVENT_TYPE,
			$email,
			array(
				'email'    => $email,
				'language' => '',
				'fields'   => array( 'is_abandoned_cart' => 'true' ),
			)
		);
	}

	/** Today plus $days in the store's timezone, `Y-m-d`. */
	private function store_day( int $days ): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( self::TIMEZONE ) ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * @return true|\WP_Error
	 */
	private function is_valid( string $code, \WC_Order $order ) {
		return ( new \WC_Discounts( $order ) )->is_coupon_valid( new \WC_Coupon( $code ) );
	}

	/**
	 * Flush the main queue with Smaily answering every POST with $status.
	 *
	 * @return array<int, mixed> The POST bodies.
	 */
	private function flush_answering( int $status ): array {
		$bodies = array();
		$fake   = static function ( $pre, $args ) use ( &$bodies, $status ) {
			$bodies[] = $args['body'] ?? null;
			return array(
				'headers'  => array(),
				'body'     => '{"code":0,"message":"down"}',
				'response' => array(
					'code'    => $status,
					'message' => 'Error',
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
		return $bodies;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function only_row( string $event_type ): array {
		global $wpdb;
		$table = QueueRowFixture::table( EventQueue::TABLE_SUFFIX );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s", $event_type ), ARRAY_A );
		self::assertCount( 1, $rows );
		return $rows[0];
	}

	private function make_due( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( QueueRowFixture::table( EventQueue::TABLE_SUFFIX ), array( 'next_retry_at' => null ), array( 'id' => $id ) );
	}

	/**
	 * The automation POST's address row on the mapped workflow.
	 *
	 * @param array<int, mixed> $bodies
	 *
	 * @return array<string, mixed>
	 */
	private function automation_address( array $bodies, string $workflow_id ): array {
		foreach ( $bodies as $body ) {
			if ( is_string( $body ) ) {
				$body = json_decode( $body, true );
			}
			if ( is_array( $body ) && isset( $body['autoresponder'] ) && (string) $body['autoresponder'] === $workflow_id ) {
				return $body['addresses'][0];
			}
		}

		self::fail( 'The automation must reach the Smaily transport on workflow ' . $workflow_id . '.' );
	}
}

