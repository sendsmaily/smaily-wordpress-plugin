<?php
/**
 * Tests for the guest buyer's visitor token issued at checkout (PRO-3845).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\GuestVisitorToken;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Support\AttributionShape;
use Smaily\Connect\Support\MarketingConsent;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;

final class GuestVisitorTokenTest extends TestCase {

	/** @var array<string, string> cookie name => value the recording writer sent. */
	private array $written = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->written = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_COOKIE = array();
		parent::tearDown();
	}

	public function test_a_consenting_guest_without_a_token_gets_one_in_the_cookie_and_on_the_order(): void {
		$order = $this->order( 0 );

		$this->issuer( true )->on_block_checkout( $order );

		$token = $this->written['smaily_rec_uid'] ?? '';
		self::assertMatchesRegularExpression( '/^vs_[A-Za-z0-9]{22}$/', $token );
		self::assertTrue( AttributionShape::is_visitor_token( $token ) );
		self::assertSame( $token, $order->get_meta( '_smaily_visitor_token' ) );
		self::assertSame( 1, $order->saved );
		// The same request sees the cookie too, so a second checkout hook mints nothing.
		self::assertSame( $token, $_COOKIE['smaily_rec_uid'] );
	}

	public function test_the_classic_checkout_hook_issues_the_token_too(): void {
		$order = $this->order( 0 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->issuer( true )->on_classic_checkout( 100 );

		self::assertNotSame( '', $order->get_meta( '_smaily_visitor_token' ) );
	}

	public function test_two_tokens_are_never_alike(): void {
		$first  = $this->order( 0 );
		$second = $this->order( 0 );

		$this->issuer( true )->on_block_checkout( $first );
		$_COOKIE = array();
		$this->issuer( true )->on_block_checkout( $second );

		self::assertNotSame( $first->get_meta( '_smaily_visitor_token' ), $second->get_meta( '_smaily_visitor_token' ) );
	}

	public function test_a_shopper_who_already_has_a_token_keeps_it(): void {
		$_COOKIE['smaily_rec_uid'] = 'vt_fromemaillink123';
		$order                     = $this->order( 0 );

		$this->issuer( true )->on_block_checkout( $order );

		self::assertSame( array(), $this->written );
		self::assertSame( '', $order->get_meta( '_smaily_visitor_token' ) );
		self::assertSame( 'vt_fromemaillink123', $_COOKIE['smaily_rec_uid'] );
	}

	public function test_the_tenant_cookie_name_is_used_for_reading_and_writing(): void {
		$_COOKIE['smaily_rec_uid'] = 'vt_underthedefaultname';
		$order                     = $this->order( 0 );

		$this->issuer( true, array( 'tracking_cookie_name' => 'acme_vt' ) )->on_block_checkout( $order );

		self::assertArrayHasKey( 'acme_vt', $this->written );
		self::assertSame( $this->written['acme_vt'], $order->get_meta( '_smaily_visitor_token' ) );
	}

	public function test_a_cookie_outside_the_token_shape_is_replaced(): void {
		$_COOKIE['smaily_rec_uid'] = 'not-a-token';
		$order                     = $this->order( 0 );

		$this->issuer( true )->on_block_checkout( $order );

		self::assertTrue( AttributionShape::is_visitor_token( $this->written['smaily_rec_uid'] ?? '' ) );
	}

	public function test_a_consent_cookie_allow_issues_a_token(): void {
		// What a banner stores through wp_set_consent( 'marketing', 'allow' ).
		$_COOKIE['wp_consent_marketing'] = 'allow';
		$order                           = $this->order( 0 );

		$this->issuer_with_signals( true )->on_block_checkout( $order );

		self::assertTrue( AttributionShape::is_visitor_token( (string) $order->get_meta( '_smaily_visitor_token' ) ) );
	}

	public function test_no_consent_cookie_means_no_token_even_when_wp_has_consent_is_true(): void {
		// The WP Consent API answers true when no consent plugin set a type,
		// and in an opt-out region until the visitor opts out (PRO-3849).
		$order = $this->order( 0 );

		$this->issuer_with_signals( true )->on_block_checkout( $order );

		self::assertSame( array(), $this->written );
		self::assertSame( '', $order->get_meta( '_smaily_visitor_token' ) );
	}

	public function test_a_consent_cookie_deny_means_no_token(): void {
		$_COOKIE['wp_consent_marketing'] = 'deny';
		$order                           = $this->order( 0 );

		$this->issuer_with_signals( true )->on_block_checkout( $order );

		self::assertSame( array(), $this->written );
		self::assertSame( '', $order->get_meta( '_smaily_visitor_token' ) );
	}

	public function test_a_consent_cookie_allow_without_a_wp_has_consent_yes_means_no_token(): void {
		$_COOKIE['wp_consent_marketing'] = 'allow';
		$order                           = $this->order( 0 );

		$this->issuer_with_signals( false )->on_block_checkout( $order );

		self::assertSame( array(), $this->written );
		self::assertSame( '', $order->get_meta( '_smaily_visitor_token' ) );
	}

	public function test_without_marketing_consent_nothing_changes(): void {
		$order = $this->order( 0 );

		$this->issuer( false )->on_block_checkout( $order );

		self::assertSame( array(), $this->written );
		self::assertSame( '', $order->get_meta( '_smaily_visitor_token' ) );
		self::assertSame( 0, $order->saved );
	}

	public function test_without_the_wp_consent_api_there_is_no_consent(): void {
		// The unit runtime never defines wp_has_consent (EnvDetectorTest pins
		// that), so this runs the real check: fail-closed.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$order  = $this->order( 0 );
		$issuer = new GuestVisitorToken( new FakeRecEngineSettings(), $this->writer( array() ) );

		$issuer->on_block_checkout( $order );

		self::assertSame( array(), $this->written );
	}

	public function test_a_registered_buyer_gets_no_token(): void {
		$order = $this->order( 9 );

		$this->issuer( true )->on_block_checkout( $order );

		self::assertSame( array(), $this->written );
	}

	public function test_a_logged_in_request_gets_no_token(): void {
		// An order a logged-in admin creates for a guest from the dashboard.
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$order = $this->order( 0 );

		$this->issuer( true )->on_block_checkout( $order );

		self::assertSame( array(), $this->written );
	}

	public function test_a_cron_request_gets_no_token(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$order = $this->order( 0 );

		$this->issuer( true )->on_block_checkout( $order );

		self::assertSame( array(), $this->written );
	}

	public function test_a_store_without_the_engine_gets_no_token(): void {
		$order  = $this->order( 0 );
		$issuer = $this->issuer( true, array(), false );

		$issuer->on_block_checkout( $order );

		self::assertSame( array(), $this->written );
	}

	public function test_nothing_is_stored_when_the_cookie_cannot_be_written(): void {
		$order  = $this->order( 0 );
		$writer = new class( new FakeRecEngineSettings() ) extends LandingCapture {
			protected function headers_already_sent(): bool {
				return true;
			}
		};
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$issuer = new class( new FakeRecEngineSettings(), $writer ) extends GuestVisitorToken {
			protected function marketing_consent_given(): bool {
				return true;
			}
		};

		$issuer->on_block_checkout( $order );

		self::assertSame( '', $order->get_meta( '_smaily_visitor_token' ) );
		self::assertSame( 0, $order->saved );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function issuer( bool $consent, array $config = array(), bool $connected = true ): GuestVisitorToken {
		$settings = $this->settings( $connected, $config );

		return new class( $settings, $this->writer( $config ), $consent ) extends GuestVisitorToken {
			private bool $consent;

			public function __construct( RecEngineSettings $settings, LandingCapture $cookies, bool $consent ) {
				parent::__construct( $settings, $cookies );
				$this->consent = $consent;
			}

			protected function marketing_consent_given(): bool {
				return $this->consent;
			}
		};
	}

	/**
	 * An issuer whose consent seam runs the real rule on the consent cookie
	 * the test put in $_COOKIE and the wp_has_consent() answer it gives.
	 *
	 * @param mixed $has_consent What wp_has_consent() would return.
	 */
	private function issuer_with_signals( $has_consent ): GuestVisitorToken {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		return new class( $this->settings( true, array() ), $this->writer( array() ), $has_consent ) extends GuestVisitorToken {
			/** @var mixed */
			private $has_consent;

			/**
			 * @param mixed $has_consent
			 */
			public function __construct( RecEngineSettings $settings, LandingCapture $cookies, $has_consent ) {
				parent::__construct( $settings, $cookies );
				$this->has_consent = $has_consent;
			}

			protected function marketing_consent_given(): bool {
				return MarketingConsent::decide(
					$this->has_consent,
					MarketingConsent::stored_consent( MarketingConsent::category() )
				);
			}
		};
	}

	/**
	 * A LandingCapture whose cookie write lands in $this->written.
	 *
	 * @param array<string, mixed> $config
	 */
	private function writer( array $config ): LandingCapture {
		$written = &$this->written;

		return new class( $this->settings( true, $config ), $written ) extends LandingCapture {
			/** @var array<string, string> */
			private array $sink;

			/**
			 * @param array<string, string> $sink
			 */
			public function __construct( RecEngineSettings $settings, array &$sink ) {
				parent::__construct( $settings );
				$this->sink = &$sink;
			}

			protected function headers_already_sent(): bool {
				return false; // PHPUnit's progress output makes the real one true.
			}

			protected function send_cookie( string $name, string $value, int $expires ): void {
				$this->sink[ $name ] = $value;
			}
		};
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function settings( bool $connected, array $config ): RecEngineSettings {
		return new class( $connected, $config ) extends RecEngineSettings {
			private bool $connected;

			/** @var array<string, mixed> */
			private array $cfg;

			/**
			 * @param array<string, mixed> $config
			 */
			public function __construct( bool $connected, array $config ) {
				$this->connected = $connected;
				$this->cfg       = $config;
			}

			public function is_connected(): bool {
				return $this->connected;
			}

			public function config(): array {
				return $this->cfg;
			}
		};
	}

	private function order( int $customer_id ): \WC_Order {
		return new class( $customer_id ) extends \WC_Order {
			public int $saved = 0;

			private int $customer_id;

			/** @var array<string, mixed> */
			private array $meta = array();

			public function __construct( int $customer_id ) {
				$this->customer_id = $customer_id;
			}

			public function get_customer_id( $context = 'view' ): int {
				return $this->customer_id;
			}

			public function update_meta_data( $key, $value, $unique_id = 0 ): void {
				$this->meta[ $key ] = $value;
			}

			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				return $this->meta[ $key ] ?? '';
			}

			public function save() {
				++$this->saved;
				return 1;
			}
		};
	}
}

// The WC_Order the fake extends, when no earlier test declared it.
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
	public function delete_meta_data( $key ): void {}
	public function get_meta( $key = '', $single = true, $context = 'view' ) { return ''; }
	public function save() { return 0; }
}
PHP
	);
}
