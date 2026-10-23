<?php
/**
 * RetiredTables (schema migration 012, CON-182) — the drain-before-drop order
 * and the retry-instead-of-lose rule, with a recording $wpdb. Nothing is
 * drained or dropped while the cart tracker table is missing (CON-207).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Migration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Migration\LegacyCartDrain;
use Smaily\Connect\Migration\RetiredTables;

final class RetiredTablesTest extends TestCase {

	/** @var object{prefix: string, last_error: string, queries: array<int, string>, fail: bool, tracker: string|null, tracker_error: string} */
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb = new class() {
			public string $prefix = 'wp_';
			/** @var array<int, string> */
			public array $queries = array();
			public bool $fail     = false;
			public string $last_error    = '';
			public ?string $tracker      = 'wp_smly_plus_cart_session';
			public string $tracker_error = '';

			/**
			 * @return int|false
			 */
			public function query( string $sql ) {
				$this->queries[] = $sql;
				return $this->fail ? false : 0;
			}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, string ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function get_var( string $query ): ?string {
				$this->last_error = $this->tracker_error;
				return $this->tracker;
			}
		};
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_drains_first_then_drops_both_tables_and_the_stamp(): void {
		$order = array();
		$drain = $this->createMock( LegacyCartDrain::class );
		$drain->expects( self::once() )->method( 'maybe_run' )->willReturnCallback(
			function () use ( &$order ) {
				$order[] = 'drain';
				self::assertSame( array(), $this->wpdb->queries, 'Nothing is dropped before the drain has run.' );
				return null;
			}
		);
		Functions\expect( 'delete_option' )->once()->with( LegacyCartDrain::DRAINED_OPTION );

		self::assertTrue( ( new RetiredTables( $drain ) )->run() );

		self::assertSame( array( 'drain' ), $order );
		self::assertSame(
			array(
				'DROP TABLE IF EXISTS `wp_smaily_connect_abandoned_carts`',
				'DROP TABLE IF EXISTS `wp_smly_rec_visitor`',
			),
			$this->wpdb->queries
		);
	}

	public function test_a_failed_drain_drops_nothing_and_asks_for_a_retry(): void {
		$drain = $this->createMock( LegacyCartDrain::class );
		$drain->method( 'maybe_run' )->willThrowException( new \RuntimeException( 'The legacy abandoned-cart table could not be read.' ) );
		Functions\expect( 'delete_option' )->never();

		self::assertFalse( ( new RetiredTables( $drain ) )->run() );
		self::assertSame( array(), $this->wpdb->queries, 'The legacy carts must not be dropped uncopied.' );
	}

	public function test_without_the_cart_tracker_nothing_is_drained_or_dropped(): void {
		$this->wpdb->tracker = null;
		$drain               = $this->createMock( LegacyCartDrain::class );
		$drain->expects( self::never() )->method( 'maybe_run' );
		Functions\expect( 'delete_option' )->never();

		self::assertFalse( ( new RetiredTables( $drain ) )->run() );
		self::assertSame( array(), $this->wpdb->queries, 'The legacy carts have nowhere to go: the table stays.' );
	}

	public function test_a_failed_check_for_the_cart_tracker_drops_nothing_and_asks_for_a_retry(): void {
		$this->wpdb->tracker       = null;
		$this->wpdb->tracker_error = 'Lost connection to MySQL server';
		$drain                     = $this->createMock( LegacyCartDrain::class );
		$drain->expects( self::never() )->method( 'maybe_run' );
		Functions\expect( 'delete_option' )->never();

		self::assertFalse( ( new RetiredTables( $drain ) )->run() );
		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_a_failed_drop_keeps_the_stamp_and_asks_for_a_retry(): void {
		$drain = $this->createMock( LegacyCartDrain::class );
		$drain->method( 'maybe_run' )->willReturn( null );
		$this->wpdb->fail = true;
		Functions\expect( 'delete_option' )->never();

		self::assertFalse( ( new RetiredTables( $drain ) )->run() );
		self::assertCount( 1, $this->wpdb->queries, 'The run stops at the first failed drop.' );
	}
}
