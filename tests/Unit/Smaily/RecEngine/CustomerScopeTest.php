<?php
/**
 * CustomerScope — which registered users the engine gets as customers
 * (contract v1.20.0 §4, CON-246): the contact-sync audience, or a sale order
 * inside the history horizon; nobody else.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\RecEngine\CustomerScope;
use Smaily\Connect\Smaily\RecEngine\HistoryConfig;
use Smaily\Connect\Smaily\RecEngine\OrderPayloadBuilder;

final class CustomerScopeTest extends TestCase {

	/** @var array<string, mixed> wp_options fixtures. */
	private array $options = array();

	/** @var array<int, string> user id → user_newsletter meta. */
	private array $optin = array();

	/** @var int[] users that exist. */
	private array $users = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$opts =& $this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
			}
		);
		$optin =& $this->optin;
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) use ( &$optin ) {
				return $key === ContactAudience::OPTIN_META ? ( $optin[ $user_id ] ?? '' ) : '';
			}
		);
		$users =& $this->users;
		Functions\when( 'get_userdata' )->alias(
			static function ( int $user_id ) use ( &$users ) {
				if ( ! in_array( $user_id, $users, true ) ) {
					return false;
				}
				$user     = new \WP_User();
				$user->ID = $user_id;
				return $user;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_subscriber_in_the_consent_mode_is_in_scope_without_an_order(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CONSENT;
		$this->users                                   = array( 5 );
		$this->optin[5]                                = '1';
		$scope                                         = $this->scope( array() );

		self::assertTrue( $scope->includes_user( 5 ) );
		self::assertSame( array(), $scope->order_lookups, 'The contact audience answers first; no order query.' );
	}

	public function test_a_buyer_inside_the_horizon_is_in_scope_without_a_subscription(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CONSENT;
		$this->users                                   = array( 6 );

		self::assertTrue( $this->scope( array( 6 ) )->includes_user( 6 ) );
	}

	public function test_an_admin_with_no_order_and_no_subscription_is_never_in_scope(): void {
		// Criterion 4 of CON-246: in the consent mode (the default), an
		// account that never bought and never subscribed is left out.
		$this->users = array( 1 );

		self::assertFalse( $this->scope( array() )->includes_user( 1 ) );
	}

	public function test_with_contact_sync_switched_off_only_buyers_are_in_scope(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ]         = ContactSyncMode::MODE_LEGITIMATE_INTEREST;
		$this->options[ ContactSyncMode::OPTION_SYNC_ENABLED ] = '';
		$this->users = array( 5, 6 );

		$scope = $this->scope( array( 6 ) );

		self::assertFalse( $scope->includes_user( 5 ) );
		self::assertTrue( $scope->includes_user( 6 ) );
	}

	public function test_legitimate_interest_puts_every_registered_user_in_scope(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_LEGITIMATE_INTEREST;
		$this->users                                   = array( 5 );

		self::assertTrue( $this->scope( array() )->includes_user( 5 ) );
	}

	public function test_checkout_optin_mode_leaves_a_non_buyer_out_even_when_opted_in(): void {
		// The checkout-only mode syncs no accounts (ContactAudience), so only (b) applies.
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CHECKOUT_OPTIN;
		$this->users                                   = array( 5 );
		$this->optin[5]                                = '1';

		self::assertFalse( $this->scope( array() )->includes_user( 5 ) );
	}

	public function test_a_missing_or_invalid_user_is_never_in_scope(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_LEGITIMATE_INTEREST;

		$scope = $this->scope( array( 9 ) );

		self::assertFalse( $scope->includes_user( 9 ), 'A deleted account.' );
		self::assertFalse( $scope->includes_user( 0 ) );
	}

	public function test_the_order_lookup_uses_the_stored_horizon(): void {
		$this->users = array( 6 );
		$scope       = $this->scope( array( 6 ), array( 'history' => array( 'start_months' => 12, 'horizon_months' => 24 ) ) );

		$scope->includes_user( 6 );

		self::assertCount( 1, $scope->order_lookups );
		$since = strtotime( $scope->order_lookups[0][1] . ' UTC' );
		self::assertGreaterThan( strtotime( '-25 months' ), $since );
		self::assertLessThan( strtotime( '-23 months' ), $since );
	}

	public function test_the_horizon_falls_back_to_60_months_without_a_stored_window(): void {
		$now = gmmktime( 12, 0, 0, 10, 29, 2026 );

		self::assertSame( '2021-10-29 12:00:00', $this->scope( array() )->horizon_start( $now ) );
		self::assertSame( 60, HistoryConfig::FALLBACK['horizon_months'] );
	}

	public function test_hpos_buyer_sql_reads_wc_orders_customer_id_with_the_sale_denylist(): void {
		list( $sql, $args ) = CustomerScope::buyers_sql( true, 'wp_', '2021-10-29 12:00:00' );

		self::assertStringContainsString( 'SELECT o.customer_id FROM wp_wc_orders o', $sql );
		self::assertStringContainsString( 'o.type = %s', $sql );
		self::assertStringContainsString( 'o.status NOT IN', $sql );
		self::assertStringContainsString( 'o.date_created_gmt >= %s', $sql );
		self::assertStringContainsString( 'o.customer_id > 0', $sql );
		self::assertSame( 'shop_order', $args[0] );
		self::assertSame( '2021-10-29 12:00:00', end( $args ) );
		self::assertCount( count( OrderPayloadBuilder::non_sale_wc_statuses() ) + 2, $args );
		self::assertContains( 'wc-pending', $args );
		self::assertContains( 'trash', $args, 'WP core statuses keep their unprefixed form.' );
	}

	public function test_legacy_buyer_sql_reads_the_customer_user_postmeta(): void {
		list( $sql, $args ) = CustomerScope::buyers_sql( false, 'wp_', '2021-10-29 12:00:00' );

		self::assertStringContainsString( 'FROM wp_postmeta m INNER JOIN wp_posts o ON o.ID = m.post_id', $sql );
		self::assertStringContainsString( "m.meta_key = '_customer_user'", $sql );
		self::assertStringContainsString( 'o.post_type = %s', $sql );
		self::assertStringContainsString( 'o.post_status NOT IN', $sql );
		self::assertStringContainsString( 'o.post_date_gmt >= %s', $sql );
		self::assertSame( 'shop_order', $args[0] );
	}

	public function test_the_per_user_lookup_narrows_to_that_customer_on_both_storages(): void {
		list( $hpos_sql, $hpos_args )     = CustomerScope::buyers_sql( true, 'wp_', '2021-10-29 12:00:00', 42 );
		list( $legacy_sql, $legacy_args ) = CustomerScope::buyers_sql( false, 'wp_', '2021-10-29 12:00:00', 42 );

		self::assertStringEndsWith( 'AND o.customer_id = %d', $hpos_sql );
		self::assertSame( 42, end( $hpos_args ) );
		self::assertStringEndsWith( 'AND m.meta_value = %s', $legacy_sql );
		self::assertSame( '42', end( $legacy_args ) );
	}

	/**
	 * A scope whose order lookup answers from a fixed set of buyer ids.
	 *
	 * @param int[]                $buyers
	 * @param array<string, mixed> $config The stored setup-exchange config.
	 */
	private function scope( array $buyers, array $config = array() ): CustomerScope {
		$settings = new class( $config ) extends RecEngineSettings {
			/** @var array<string, mixed> */
			private array $stored;
			/** @param array<string, mixed> $stored */
			public function __construct( array $stored ) {
				$this->stored = $stored;
			}
			public function config(): array {
				return $this->stored;
			}
		};

		return new class( $settings, $buyers ) extends CustomerScope {
			/** @var array<int, array{0: int, 1: string}> */
			public array $order_lookups = array();
			/** @var int[] */
			private array $buyers;
			/** @param int[] $buyers */
			public function __construct( RecEngineSettings $settings, array $buyers ) {
				parent::__construct( $settings );
				$this->buyers = $buyers;
			}
			protected function has_sale_order_since( int $user_id, string $since ): bool {
				$this->order_lookups[] = array( $user_id, $since );
				return in_array( $user_id, $this->buyers, true );
			}
		};
	}
}

if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}
