<?php
/**
 * Integration: a personal-data export and erasure cover the discount codes
 * the plugin made for the address (CON-213).
 *
 * The codes are made by the real AutomationDiscount::issue() into real
 * WooCommerce coupons, one is used on a real order, and the export and
 * erasure run through WordPress's own page-by-page sequence with every
 * registered exporter and eraser, WooCommerce's among them.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\AutomationDiscount;
use Smaily\Connect\Integrations\WooCommerce\CustomerHookHandler;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

final class AutomationDiscountPrivacyTest extends TestCase {

	private const EMAIL = 'jane@example.test';

	/** WordPress's own anonymised address (wp_privacy_anonymize_data( 'email' )). */
	private const ANONYMISED = 'deleted@site.invalid';

	/** @var int[] */
	private array $coupons = array();
	/** @var int[] */
	private array $orders = array();
	/** @var int[] */
	private array $products = array();
	/** @var int[] */
	private array $requests = array();

	private int $template_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Coupon' ) ) {
			self::markTestSkipped( 'WooCommerce not active — discount codes are WooCommerce coupons.' );
		}
		EnvScrub::reset();

		$template = new \WC_Coupon();
		$template->set_props(
			array(
				'code'          => 'smly-privacy-template-' . strtolower( wp_generate_password( 6, false ) ),
				'discount_type' => 'percent',
				'amount'        => '10',
			)
		);
		$this->template_id = (int) $template->save();
		$this->coupons[]   = $this->template_id;

		$enabled = array(
			'enabled'     => true,
			'template_id' => $this->template_id,
			'valid_days'  => 7,
		);
		update_option(
			AutomationDiscount::OPTION,
			array(
				'welcome'        => $enabled,
				'first_order'    => $enabled,
				'abandoned_cart' => $enabled,
			)
		);
	}

	protected function tearDown(): void {
		foreach ( $this->orders as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		foreach ( array_merge( $this->coupons, $this->products, $this->requests ) as $id ) {
			wp_delete_post( $id, true );
		}
		delete_option( AutomationDiscount::OPTION );
		$this->coupons  = array();
		$this->orders   = array();
		$this->products = array();
		$this->requests = array();
		parent::tearDown();
	}

	public function test_an_erasure_deletes_the_unused_codes_made_for_the_address_and_nothing_else(): void {
		$welcome = $this->issue( 'welcome', self::EMAIL );
		$cart    = $this->issue( 'abandoned_cart', self::EMAIL );

		$longer    = $this->issue( 'welcome', 'xjane@example.test' );
		$other     = $this->issue( 'first_order', 'john@example.test' );
		$accented  = $this->issue( 'welcome', 'jane-neighbour@example.test' );
		$merchants = $this->merchant_coupon( self::EMAIL );
		// WooCommerce refuses an accented address on its forms, so it is
		// written straight into the coupon, as an import would leave it. The
		// database compares accent-blind unless told otherwise (PRO-3986).
		update_post_meta( $accented, 'customer_email', array( 'jäne@example.test' ) );

		$this->run_wordpress_erasure( 'Jane@Example.TEST' );

		self::assertNull( get_post( $welcome ), 'The unused welcome code made for the address is deleted.' );
		self::assertNull( get_post( $cart ), 'The unused abandoned-cart code made for the address is deleted.' );

		self::assertSame( array( 'xjane@example.test' ), $this->restrictions( $longer ), 'A longer address is another person.' );
		self::assertSame( array( 'john@example.test' ), $this->restrictions( $other ), 'Another address\'s code stays.' );
		self::assertSame( array( 'jäne@example.test' ), $this->restrictions( $accented ), 'An address that differs by an accent is another person.' );
		self::assertSame( array( self::EMAIL ), $this->restrictions( $merchants ), 'A coupon the merchant made is never touched.' );
		self::assertNotNull( get_post( $this->template_id ), 'The template stays.' );
	}

	public function test_an_erasure_keeps_a_used_code_with_its_order_and_removes_the_address_from_it(): void {
		$coupon_id = $this->issue( 'first_order', self::EMAIL );
		$code      = ( new \WC_Coupon( $coupon_id ) )->get_code();
		$order_id  = $this->order_using( $code, self::EMAIL );

		$used = new \WC_Coupon( $coupon_id );
		self::assertSame( 1, $used->get_usage_count(), 'WooCommerce counted the use.' );
		self::assertContains( self::EMAIL, $used->get_used_by(), 'WooCommerce recorded the guest buyer\'s address.' );

		$this->run_wordpress_erasure( self::EMAIL );

		self::assertNotNull( get_post( $coupon_id ), 'A used code stays.' );
		$kept = new \WC_Coupon( $coupon_id );
		self::assertSame( array( self::ANONYMISED ), $kept->get_email_restrictions(), 'The address is replaced, so the code still works for nobody.' );
		self::assertNotContains( self::EMAIL, array_map( 'strtolower', $kept->get_used_by() ), 'WooCommerce\'s record of who used it loses the address.' );
		self::assertSame( 1, $kept->get_usage_count(), 'It still counts as used.' );
		self::assertSame( $coupon_id, wc_get_coupon_id_by_code( $code ), 'The order\'s coupon line still resolves.' );

		$order = wc_get_order( $order_id );
		self::assertInstanceOf( \WC_Order::class, $order );
		self::assertSame( array( $code ), $order->get_coupon_codes(), 'The order keeps its coupon line.' );
	}

	public function test_an_export_lists_the_codes_made_for_the_address(): void {
		$unused    = $this->issue( 'welcome', self::EMAIL );
		$used      = $this->issue( 'first_order', self::EMAIL );
		$accented  = $this->issue( 'welcome', 'jane-neighbour@example.test' );
		$merchants = $this->merchant_coupon( self::EMAIL );
		update_post_meta( $accented, 'customer_email', array( 'jäne@example.test' ) );
		$this->order_using( ( new \WC_Coupon( $used ) )->get_code(), self::EMAIL );

		$items = array();
		foreach ( $this->run_wordpress_export( 'JANE@example.test' ) as $item ) {
			if ( $item['group_label'] === 'Discount codes' ) {
				$items[ $item['item_id'] ] = array_column( $item['data'], 'value', 'name' );
			}
		}

		self::assertSame(
			array( 'smaily-connect-rec-engine-discount-code-' . $unused, 'smaily-connect-rec-engine-discount-code-' . $used ),
			array_keys( $items ),
			'Only the codes the plugin made for the address — not a neighbour\'s, not the merchant\'s.'
		);
		self::assertNotContains( 'smaily-connect-rec-engine-discount-code-' . $merchants, array_keys( $items ) );

		$first = $items[ 'smaily-connect-rec-engine-discount-code-' . $unused ];
		self::assertSame( ( new \WC_Coupon( $unused ) )->get_code(), $first['Code'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $first['Created'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} 23:59$/', $first['Expires'], 'The end of the last valid day, store time.' );
		self::assertSame( 'No', $first['Used'] );
		self::assertSame( 'Yes', $items[ 'smaily-connect-rec-engine-discount-code-' . $used ]['Used'] );
	}

	// --- helpers -------------------------------------------------------------

	/** A code made the way an automation email makes it; returns the coupon id. */
	private function issue( string $trigger, string $email ): int {
		$issued = ( new AutomationDiscount() )->issue( $trigger, array( 'email' => $email ) );
		$code   = (string) ( $issued['fields'][ AutomationDiscount::FIELDS[ $trigger ][0] ] ?? '' );
		self::assertNotSame( '', $code, 'issue() made a code: ' . $issued['note'] );

		$id = wc_get_coupon_id_by_code( $code );
		self::assertGreaterThan( 0, $id );
		$this->coupons[] = $id;
		return $id;
	}

	/** A coupon the merchant made, restricted to one address. */
	private function merchant_coupon( string $email ): int {
		$coupon = new \WC_Coupon();
		$coupon->set_props(
			array(
				'code'               => 'smly-privacy-merchant-' . strtolower( wp_generate_password( 6, false ) ),
				'discount_type'      => 'fixed_cart',
				'amount'             => '5',
				'email_restrictions' => array( $email ),
			)
		);
		$id              = (int) $coupon->save();
		$this->coupons[] = $id;
		return $id;
	}

	/** A guest order billed to $email that uses $code, paid (processing). */
	private function order_using( string $code, string $email ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Privacy Product' );
		$product->set_regular_price( '40' );
		$product->save();
		$this->products[] = (int) $product->get_id();

		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$applied = $order->apply_coupon( $code );
		self::assertTrue( $applied, 'WooCommerce accepted the code on the order.' );
		$order->set_status( 'processing' );
		$id             = (int) $order->save();
		$this->orders[] = $id;
		return $id;
	}

	/**
	 * The coupon's allowed emails as stored.
	 *
	 * @return array<int, string>
	 */
	private function restrictions( int $coupon_id ): array {
		self::assertNotNull( get_post( $coupon_id ), "Coupon {$coupon_id} still exists." );
		return array_values( (array) get_post_meta( $coupon_id, 'customer_email', true ) );
	}

	/**
	 * Every registered exporter, page by page until it answers `done`, the
	 * way wp_ajax_wp_privacy_export_personal_data() calls them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function run_wordpress_export( string $email ): array {
		$data = array();
		foreach ( apply_filters( 'wp_privacy_personal_data_exporters', array() ) as $exporter ) {
			$page = 1;
			do {
				$response = call_user_func( $exporter['callback'], $email, $page );
				$data     = array_merge( $data, $response['data'] );
				++$page;
			} while ( empty( $response['done'] ) && $page < 100 );
		}
		return $data;
	}

	/**
	 * An erasure request the way WordPress runs it: every registered eraser
	 * page by page, each answer through
	 * wp_privacy_process_personal_data_erasure_page(), which completes the
	 * request and fires wp_privacy_personal_data_erased after the last one.
	 */
	private function run_wordpress_erasure( string $email ): void {
		require_once ABSPATH . 'wp-admin/includes/privacy-tools.php';

		$request_id = wp_create_user_request( $email, 'remove_personal_data', array(), 'confirmed' );
		self::assertIsInt( $request_id );
		$this->requests[] = $request_id;

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		self::assertArrayHasKey( 'smaily-connect-rec-engine', $erasers );

		$index = 0;
		foreach ( $erasers as $eraser ) {
			++$index;
			$page = 1;
			do {
				CustomerHookHandler::reset_seen();
				HookHandler::reset_seen();
				$response = call_user_func( $eraser['callback'], $email, $page );
				$response = wp_privacy_process_personal_data_erasure_page( $response, $index, $email, $page, $request_id );
				++$page;
			} while ( empty( $response['done'] ) && $page < 100 );
		}

		$request = wp_get_user_request( $request_id );
		self::assertInstanceOf( \WP_User_Request::class, $request );
		self::assertSame( 'request-completed', $request->status );
	}
}
