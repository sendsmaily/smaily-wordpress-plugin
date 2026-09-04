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
use Smaily\Connect\Multilingual\Router;
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

			public function prepare( string $sql, ...$args ): string {
				$this->captured_args = $args;
				return $sql;
			}

			public function get_row( string $sql, string $output = ARRAY_A ): ?array {
				++$this->get_row_calls;
				if ( $this->get_row_calls === 1 ) {
					return $this->next_get_row;
				}
				return $this->next_fallback_get_row;
			}
		};
	}
}
