<?php
/**
 * CartHookHandler tests (PRO-1195).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\CartHookHandler;
use Smaily\Connect\Smaily\CartSessionStore;

require_once dirname( __DIR__, 4 ) . '/includes/smaily-options.class.php';

final class CartHookHandlerTest extends TestCase {

	/** @var object Recording store double. */
	private $store;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		CartHookHandler::reset_request_guard();

		$this->store = $this->fake_store();
		$this->stub_gates( true, true );

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_email' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'is_email' )->alias(
			static fn ( $email ) => (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		CartHookHandler::reset_request_guard();
		parent::tearDown();
	}

	public function test_cart_update_tracks_own_scalar_item_shape_for_a_guest(): void {
		$handler = $this->handler(
			'guest-token',
			$this->cart(
				array(
					array(
						'product_id'   => 11,
						'variation_id' => 22,
						'quantity'     => 3,
					),
					'i-am-a-poison-string', // Never our writer's shape — skipped, never fatal (F3-53 class).
					array( 'no_product_id' => true ),
				)
			)
		);

		$handler->on_cart_updated();

		self::assertCount( 1, $this->store->upserts );
		$upsert = $this->store->upserts[0];
		self::assertSame( 'guest-token', $upsert['cart_token'] );
		self::assertSame( 0, $upsert['user_id'] );
		self::assertSame( '', $upsert['email'], 'A guest with no captured identity tracks email-less (synced only once an email is known).' );
		self::assertSame(
			array(
				array(
					'product_id'   => 11,
					'variation_id' => 22,
					'quantity'     => 3,
				),
			),
			$upsert['items'],
			'Only scalar {product_id, variation_id, quantity} — never a serialized WC object.'
		);
	}

	public function test_logged_in_user_identity_is_attached_and_guest_remnants_cleared(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn(
			new class( 42, 'user@example.test', 'Jaan', 'Kask' ) extends \WP_User {
				public function __construct( int $id, string $email, string $first, string $last ) {
					$this->ID         = $id;
					$this->user_email = $email;
					$this->first_name = $first;
					$this->last_name  = $last;
				}
			}
		);

		$handler = $this->handler( '42', $this->cart( array( array( 'product_id' => 5, 'quantity' => 1 ) ) ) );
		$handler->on_cart_updated();

		$upsert = $this->store->upserts[0];
		self::assertSame( 42, $upsert['user_id'] );
		self::assertSame( 'user@example.test', $upsert['email'] );
		self::assertSame(
			array( array( 'user@example.test', '42' ) ),
			$this->store->email_cleanups,
			'A login migrates the cart to a new token — the old guest row for the same email must go (no double reminder).'
		);
	}

	public function test_empty_cart_deletes_the_tracker_row(): void {
		$handler = $this->handler( 'tok', $this->cart( array(), true ) );
		$handler->on_cart_updated();

		self::assertSame( array( 'tok' ), $this->store->deleted_tokens );
		self::assertSame( array(), $this->store->upserts );
	}

	public function test_tracking_is_gated_on_the_merchant_toggle_and_the_wizard(): void {
		$this->stub_gates( true, false );
		$handler = $this->handler( 'tok', $this->cart( array( array( 'product_id' => 1, 'quantity' => 1 ) ) ) );
		$handler->on_cart_updated();
		self::assertSame( array(), $this->store->upserts, 'Feature off → no tracking.' );

		CartHookHandler::reset_request_guard();
		$this->stub_gates( false, true );
		$handler = $this->handler( 'tok', $this->cart( array( array( 'product_id' => 1, 'quantity' => 1 ) ) ) );
		$handler->on_cart_updated();
		self::assertSame( array(), $this->store->upserts, 'Wizard incomplete → no cart PII collection.' );
	}

	public function test_cart_update_is_deduped_per_request(): void {
		$handler = $this->handler( 'tok', $this->cart( array( array( 'product_id' => 1, 'quantity' => 1 ) ) ) );
		$handler->on_cart_updated();
		$handler->on_cart_updated();

		self::assertCount( 1, $this->store->upserts, 'woocommerce_cart_updated fires several times per request — one write.' );
	}

	public function test_classic_checkout_posted_email_captures_the_guest_identity(): void {
		$handler = $this->handler( 'guest-token', null );

		$handler->on_checkout_update_order_review(
			'billing_email=guest%40example.test&billing_first_name=Mari&billing_last_name=Maasikas&payment_method=cod'
		);

		self::assertSame(
			array(
				array(
					'cart_token' => 'guest-token',
					'email'      => 'guest@example.test',
					'first_name' => 'Mari',
					'last_name'  => 'Maasikas',
				),
			),
			$this->store->identities
		);
	}

	public function test_invalid_posted_email_is_ignored(): void {
		$handler = $this->handler( 'guest-token', null );
		$handler->on_checkout_update_order_review( 'billing_email=not-an-email' );
		self::assertSame( array(), $this->store->identities );
	}

	public function test_store_api_customer_update_captures_the_guest_identity(): void {
		$handler  = $this->handler( 'guest-token', null );
		$customer = new class {
			public function get_billing_email(): string {
				return 'block@example.test';
			}

			public function get_billing_first_name(): string {
				return 'Block';
			}

			public function get_billing_last_name(): string {
				return 'Buyer';
			}
		};

		$handler->on_store_api_update_customer( $customer, null );

		self::assertSame( 'block@example.test', $this->store->identities[0]['email'] );
	}

	public function test_completed_order_clears_token_user_and_email_rows_ungated(): void {
		// Hygiene must survive a mid-flight feature toggle — clearing is ungated.
		$this->stub_gates( false, false );

		Functions\when( 'wc_get_order' )->justReturn(
			new class( 'buyer@example.test', 42 ) extends \WC_Order {
				private string $email;
				private int $customer_id;

				public function __construct( string $email, int $customer_id ) {
					$this->email       = $email;
					$this->customer_id = $customer_id;
				}

				public function get_billing_email( $context = 'view' ): string {
					return $this->email;
				}

				public function get_customer_id( $context = 'view' ): int {
					return $this->customer_id;
				}
			}
		);

		$handler = $this->handler( 'tok', null );
		$handler->on_order_processed( 501 );

		self::assertSame( array( 'tok' ), $this->store->deleted_tokens );
		self::assertSame( array( 42 ), $this->store->deleted_users );
		self::assertSame( array( 'buyer@example.test' ), $this->store->deleted_emails );
	}

	// --- helpers -------------------------------------------------------------

	private function stub_gates( bool $setup_completed, bool $enabled ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( $setup_completed, $enabled ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return $setup_completed;
				}
				if ( $key === \Smaily_Connect\Includes\Options::ABANDONED_CART_STATUS_OPTION ) {
					return array(
						'enabled'          => $enabled,
						'autoresponder_id' => 0,
					);
				}
				return $fallback;
			}
		);
	}

	/**
	 * Handler with the WC seams stubbed: fixed session token + cart double.
	 *
	 * @param object|null $cart
	 */
	private function handler( string $token, $cart ): CartHookHandler {
		return new class( $this->store, $token, $cart ) extends CartHookHandler {
			private string $token;

			/** @var object|null */
			private $cart;

			public function __construct( CartSessionStore $store, string $token, $cart ) {
				parent::__construct( $store );
				$this->token = $token;
				$this->cart  = $cart;
			}

			protected function session_token(): string {
				return $this->token;
			}

			protected function wc_cart() {
				return $this->cart;
			}

			protected function wc_customer() {
				return null;
			}

			protected function is_admin_context(): bool {
				return false;
			}
		};
	}

	/**
	 * @param array<int, mixed> $items
	 */
	private function cart( array $items, bool $empty = false ): object {
		return new class( $items, $empty ) {
			/** @var array<int, mixed> */
			private array $items;
			private bool $empty;

			public function __construct( array $items, bool $empty ) {
				$this->items = $items;
				$this->empty = $empty;
			}

			public function is_empty(): bool {
				return $this->empty;
			}

			public function get_cart(): array {
				return $this->items;
			}
		};
	}

	private function fake_store(): CartSessionStore {
		return new class extends CartSessionStore {
			/** @var array<int, array<string, mixed>> */
			public array $upserts = array();

			/** @var array<int, array<string, mixed>> */
			public array $identities = array();

			/** @var array<int, string> */
			public array $deleted_tokens = array();

			/** @var array<int, int> */
			public array $deleted_users = array();

			/** @var array<int, string> */
			public array $deleted_emails = array();

			/** @var array<int, array{0: string, 1: string}> */
			public array $email_cleanups = array();

			public function upsert( string $cart_token, int $user_id, string $email, string $first_name, string $last_name, array $items, ?string $cart_updated = null ): void {
				$this->upserts[] = compact( 'cart_token', 'user_id', 'email', 'first_name', 'last_name', 'items', 'cart_updated' );
			}

			public function set_identity( string $cart_token, string $email, string $first_name, string $last_name ): void {
				$this->identities[] = compact( 'cart_token', 'email', 'first_name', 'last_name' );
			}

			public function delete_by_token( string $cart_token ): void {
				$this->deleted_tokens[] = $cart_token;
			}

			public function delete_by_user( int $user_id ): void {
				$this->deleted_users[] = $user_id;
			}

			public function delete_by_email( string $email ): void {
				$this->deleted_emails[] = $email;
			}

			public function delete_other_rows_for_email( string $email, string $keep_token ): void {
				$this->email_cleanups[] = array( $email, $keep_token );
			}
		};
	}
}

// Stubs for classes the anonymous fakes extend (shared shim pattern — see
// HookHandlerTest; declared conditionally, another file may load first).
if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}

if ( ! class_exists( \WC_Order::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
class WC_Order {
	public function get_id(): int { return 0; }
	public function get_billing_email( $context = 'view' ): string { return ''; }
	public function get_billing_first_name( $context = 'view' ): string { return ''; }
	public function get_billing_last_name( $context = 'view' ): string { return ''; }
	public function get_customer_id( $context = 'view' ): int { return 0; }
	public function get_total( $context = 'view' ): string { return '0'; }
	public function get_currency( $context = 'view' ): string { return ''; }
	public function get_status( $context = 'view' ): string { return ''; }
	public function get_total_discount( $ex_tax = true ): string { return '0'; }
	public function get_date_created( $context = 'view' ) { return null; }
	public function get_items( $types = 'line_item' ): array { return array(); }
	public function update_meta_data( $key, $value, $unique_id = 0 ): void {}
	public function get_meta( $key = '', $single = true, $context = 'view' ) { return ''; }
	public function save() { return 0; }
}
PHP
	);
}
