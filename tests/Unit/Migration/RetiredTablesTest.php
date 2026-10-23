<?php
/**
 * RetiredTables (schema migration 012, CON-182) — the drain-before-drop order
 * and the retry-instead-of-lose rule, with a recording $wpdb.
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

	/** @var object{prefix: string, queries: array<int, string>, fail: bool} */
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb = new class() {
			public string $prefix = 'wp_';
			/** @var array<int, string> */
			public array $queries = array();
			public bool $fail     = false;

			/**
			 * @return int|false
			 */
			public function query( string $sql ) {
				$this->queries[] = $sql;
				return $this->fail ? false : 0;
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

	public function test_a_failed_drop_keeps_the_stamp_and_asks_for_a_retry(): void {
		$drain = $this->createMock( LegacyCartDrain::class );
		$drain->method( 'maybe_run' )->willReturn( null );
		$this->wpdb->fail = true;
		Functions\expect( 'delete_option' )->never();

		self::assertFalse( ( new RetiredTables( $drain ) )->run() );
		self::assertCount( 1, $this->wpdb->queries, 'The run stops at the first failed drop.' );
	}
}
