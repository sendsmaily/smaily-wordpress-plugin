<?php
/**
 * Router (WorkflowResolverInterface) unit tests.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Multilingual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Multilingual\Router;
use Smaily\Connect\Settings\EmailDesignWorkflows;
use Smaily\Connect\Smaily\WorkflowMatch;

final class RouterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset( $GLOBALS['wpdb'] );
	}

	public function test_current_mode_falls_back_to_single_when_option_is_unset(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $opt, $default = false ): mixed => $default
		);

		self::assertSame( Router::MODE_SINGLE, ( new Router() )->current_mode() );
	}

	public function test_current_mode_validates_against_known_values(): void {
		Functions\when( 'get_option' )->justReturn( 'nonsense' );

		self::assertSame(
			Router::MODE_SINGLE,
			( new Router() )->current_mode(),
			'Unknown stored mode must fall back to single rather than be returned as-is.'
		);
	}

	public function test_single_mode_collapses_language_to_default_bucket(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_SINGLE );

		$wpdb->next_get_row = array(
			'workflow_id' => '7',
			'account_key' => 'default',
			'language'    => 'default',
		);

		$match = ( new Router() )->resolve_workflow( 'welcome', 'et' );

		self::assertInstanceOf( WorkflowMatch::class, $match );
		self::assertSame( 7, $match->workflow_id );
		self::assertSame( 'default', $match->account_key );
		self::assertSame(
			'default',
			$wpdb->captured_args[1],
			'Single-mode lookup must ignore the caller-supplied language.'
		);
	}

	public function test_mode_b_uses_caller_language(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_B );

		$wpdb->next_get_row = array(
			'workflow_id' => '42',
			'account_key' => 'default',
			'language'    => 'et',
		);

		$match = ( new Router() )->resolve_workflow( 'welcome', 'et' );

		self::assertInstanceOf( WorkflowMatch::class, $match );
		self::assertSame( 42, $match->workflow_id );
		self::assertSame( 'et', $wpdb->captured_args[1] );
	}

	public function test_mode_a_preserves_account_key_from_row(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_A );

		$wpdb->next_get_row = array(
			'workflow_id' => '99',
			'account_key' => 'et_account',
			'language'    => 'et',
		);

		$match = ( new Router() )->resolve_workflow( 'welcome', 'et' );

		self::assertNotNull( $match );
		self::assertSame( 'et_account', $match->account_key );
		self::assertSame( 'et', $match->matched_language );
	}

	public function test_mode_c_collapses_language_just_like_single(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_C );

		$wpdb->next_get_row = array(
			'workflow_id' => '13',
			'account_key' => 'default',
			'language'    => 'default',
		);

		( new Router() )->resolve_workflow( 'first_order', 'en' );

		self::assertSame( 'default', $wpdb->captured_args[1] );
	}

	public function test_null_caller_language_in_mode_b_falls_back_to_default(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_B );

		$wpdb->next_get_row = array(
			'workflow_id' => '5',
			'account_key' => 'default',
			'language'    => 'default',
		);

		( new Router() )->resolve_workflow( 'welcome', null );

		self::assertSame( 'default', $wpdb->captured_args[1] );
	}

	public function test_returns_null_when_no_exact_match_and_no_fallback(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_B );

		// Both the exact-match and the fallback queries return null.
		$wpdb->next_get_row          = null;
		$wpdb->next_fallback_get_row = null;

		$match = ( new Router() )->resolve_workflow( 'welcome', 'et' );

		self::assertNull( $match );
	}

	public function test_uses_default_fallback_row_when_exact_match_absent(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_B );

		$wpdb->next_get_row          = null;
		$wpdb->next_fallback_get_row = array(
			'workflow_id' => '100',
			'account_key' => 'default',
			'language'    => 'en',
		);

		$match = ( new Router() )->resolve_workflow( 'welcome', 'lt' );

		self::assertNotNull( $match );
		self::assertSame( 100, $match->workflow_id );
	}

	public function test_zero_workflow_id_in_row_is_treated_as_no_match(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_B );

		$wpdb->next_get_row          = array(
			'workflow_id' => '0',
			'account_key' => 'default',
			'language'    => 'et',
		);
		$wpdb->next_fallback_get_row = null;

		self::assertNull( ( new Router() )->resolve_workflow( 'welcome', 'et' ) );
	}

	public function test_transactional_trigger_uses_the_callers_language_even_in_single_mode(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_SINGLE );

		$wpdb->next_get_row = array(
			'workflow_id' => '31',
			'account_key' => 'transactional',
			'language'    => 'en',
		);

		$match = ( new Router() )->resolve_workflow( 'order_confirmation', 'en' );

		self::assertNotNull( $match );
		self::assertSame( 31, $match->workflow_id );
		self::assertSame( array( 'order_confirmation', 'en' ), $wpdb->all_args[0], 'Transactional workflows cannot branch in Smaily, so no mode collapses their language.' );
	}

	public function test_transactional_trigger_prefers_the_fallback_row_over_the_default_row(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_C );

		$wpdb->row_queue = array(
			null,
			array(
				'workflow_id' => '32',
				'account_key' => 'transactional',
				'language'    => 'et',
			),
		);

		$match = ( new Router() )->resolve_workflow( 'shipping_confirmation', 'lt' );

		self::assertNotNull( $match );
		self::assertSame( 32, $match->workflow_id );
		self::assertSame( 2, $wpdb->get_row_calls, 'The default row is not consulted once a fallback row answers.' );
	}

	public function test_transactional_trigger_falls_back_to_the_single_pre_per_language_row(): void {
		// A store that mapped the one language='default' row before it had
		// per-language rows, without that row being flagged as fallback.
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_B );

		$wpdb->row_queue = array(
			null,
			null,
			array(
				'workflow_id' => '33',
				'account_key' => 'transactional',
				'language'    => 'default',
			),
		);

		$match = ( new Router() )->resolve_workflow( 'order_confirmation', 'en' );

		self::assertNotNull( $match );
		self::assertSame( 33, $match->workflow_id );
		self::assertSame( array( 'order_confirmation', 'default' ), $wpdb->all_args[2] );
	}

	public function test_transactional_trigger_with_null_language_looks_up_the_default_row(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->justReturn( Router::MODE_B );

		$wpdb->next_get_row = array(
			'workflow_id' => '34',
			'account_key' => 'transactional',
			'language'    => 'default',
		);

		( new Router() )->resolve_workflow( 'order_confirmation', null );

		self::assertSame( array( 'order_confirmation', 'default' ), $wpdb->all_args[0] );
	}

	public function test_a_pushed_design_workflow_replaces_the_mapped_one_on_the_default_account_and_language(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$this->stub_options(
			array(
				EmailDesignWorkflows::OPTION => array(
					'plugin.welcome'     => 9001,
					'plugin.first_order' => 9002,
				),
			)
		);

		$wpdb->row_queue = array(
			array( 'workflow_id' => '7', 'account_key' => 'default', 'language' => 'default' ),
			array( 'workflow_id' => '8', 'account_key' => 'default', 'language' => 'default' ),
			array( 'workflow_id' => '9', 'account_key' => 'default', 'language' => 'default' ),
		);

		$router  = new Router( null, $this->detector( 'et' ) );
		$welcome = $router->resolve_workflow( 'welcome', null );
		$first   = $router->resolve_workflow( 'first_order', null );
		$cart    = $router->resolve_workflow( 'abandoned_cart', null );

		self::assertSame( 9001, $welcome->workflow_id );
		self::assertSame( 'default', $welcome->account_key );
		self::assertSame( 'default', $welcome->matched_language );
		self::assertSame( 9002, $first->workflow_id );
		self::assertSame( 9, $cart->workflow_id, 'No pushed workflow: the mapped one is used.' );
	}

	public function test_a_pushed_design_workflow_replaces_the_mode_b_row_of_the_store_default_language(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$this->stub_options(
			array(
				Router::OPTION_MODE          => Router::MODE_B,
				EmailDesignWorkflows::OPTION => array( 'plugin.welcome' => 9001 ),
			)
		);

		$wpdb->row_queue = array(
			array( 'workflow_id' => '41', 'account_key' => 'default', 'language' => 'et' ),
		);

		$match = ( new Router( null, $this->detector( 'et' ) ) )->resolve_workflow( 'welcome', 'et' );

		self::assertSame( 9001, $match->workflow_id );
		self::assertSame( 'default', $match->account_key );
		self::assertSame( 'et', $match->matched_language );
	}

	public function test_a_mode_b_row_of_another_language_keeps_its_mapped_workflow(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$this->stub_options(
			array(
				Router::OPTION_MODE          => Router::MODE_B,
				EmailDesignWorkflows::OPTION => array( 'plugin.welcome' => 9001 ),
			)
		);

		$wpdb->row_queue = array(
			array( 'workflow_id' => '42', 'account_key' => 'default', 'language' => 'en' ),
		);

		$match = ( new Router( null, $this->detector( 'et' ) ) )->resolve_workflow( 'welcome', 'en' );

		self::assertSame( 42, $match->workflow_id, 'One pushed design does not replace a per-language workflow.' );
	}

	public function test_a_mode_a_language_account_keeps_its_mapped_workflow(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$this->stub_options(
			array(
				Router::OPTION_MODE          => Router::MODE_A,
				EmailDesignWorkflows::OPTION => array( 'plugin.abandoned_cart' => 9003 ),
			)
		);

		$wpdb->row_queue = array(
			array( 'workflow_id' => '51', 'account_key' => 'lt_account', 'language' => 'lt' ),
			array( 'workflow_id' => '52', 'account_key' => 'et_account', 'language' => 'et' ),
		);

		$router = new Router( null, $this->detector( 'et' ) );
		$lt     = $router->resolve_workflow( 'abandoned_cart', 'lt' );
		$et     = $router->resolve_workflow( 'abandoned_cart', 'et' );

		self::assertSame( 51, $lt->workflow_id );
		self::assertSame( 'lt_account', $lt->account_key );
		self::assertSame( 52, $et->workflow_id, 'Another account than the default one, even in the default language.' );
	}

	public function test_the_transactional_account_keeps_its_mapped_workflow(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$this->stub_options(
			array(
				EmailDesignWorkflows::OPTION => array(
					'plugin.follow_up'                    => 9004,
					'transactional.order_confirmation'    => 9005,
					'transactional.shipping_confirmation' => 9006,
				),
			)
		);

		$wpdb->row_queue = array(
			array( 'workflow_id' => '61', 'account_key' => 'transactional', 'language' => 'default' ),
			array( 'workflow_id' => '62', 'account_key' => 'transactional', 'language' => 'default' ),
			array( 'workflow_id' => '63', 'account_key' => 'transactional', 'language' => 'default' ),
		);

		$router = new Router( null, $this->detector( 'et' ) );

		self::assertSame( 61, $router->resolve_workflow( 'follow_up', null )->workflow_id );
		self::assertSame( 62, $router->resolve_workflow( 'order_confirmation', null )->workflow_id );
		self::assertSame( 63, $router->resolve_workflow( 'shipping_confirmation', null )->workflow_id );
	}

	public function test_a_pushed_design_workflow_does_not_start_an_unmapped_email(): void {
		$wpdb            = $this->fake_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$this->stub_options( array( EmailDesignWorkflows::OPTION => array( 'plugin.welcome' => 9001 ) ) );

		self::assertNull( ( new Router() )->resolve_workflow( 'welcome', null ) );
	}

	/** A detector whose store default language is $default. */
	private function detector( string $default ): DetectorInterface {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_default_language' )->willReturn( $default );

		return $detector;
	}

	/**
	 * @param array<string, mixed> $options Stored options; anything else reads its default.
	 */
	private function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $name, $default = false ) => array_key_exists( $name, $options ) ? $options[ $name ] : $default
		);
	}

	/**
	 * Builds a fake $wpdb whose prepare() returns its SQL with the args
	 * inlined (good enough for our get_row branching) and whose get_row()
	 * returns whichever queue position matches.
	 */
	private function fake_wpdb(): object {
		return new class() {
			public string $prefix              = 'wp_';
			public array $captured_args        = array();
			public ?array $next_get_row        = null;
			public ?array $next_fallback_get_row = null;
			public int $get_row_calls          = 0;
			/** When set, get_row() answers from this queue (one entry per call) instead. */
			public ?array $row_queue           = null;
			/** prepare() args of every lookup, in call order. */
			public array $all_args             = array();

			public function prepare( string $sql, ...$args ): string {
				$this->captured_args = $args;
				$this->all_args[]    = $args;
				return $sql;
			}

			public function get_row( string $sql, string $output = ARRAY_A ): ?array {
				++$this->get_row_calls;
				if ( $this->row_queue !== null ) {
					return array_shift( $this->row_queue );
				}
				if ( $this->get_row_calls === 1 ) {
					return $this->next_get_row;
				}
				return $this->next_fallback_get_row;
			}
		};
	}
}
