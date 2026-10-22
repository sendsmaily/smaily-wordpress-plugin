<?php
/**
 * LegacyCartDrain — a failed read never stamps (CON-182: migration 012 drops
 * the table right after the drain, so "could not read" must not pass for
 * "nothing to drain").
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Migration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Migration\LegacyCartDrain;
use Smaily\Connect\Smaily\CartSessionStore;

final class LegacyCartDrainTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_failed_check_for_the_table_throws_and_does_not_stamp(): void {
		$GLOBALS['wpdb'] = $this->wpdb( null, 'Lost connection to MySQL server', array() );
		Functions\expect( 'update_option' )->never();

		$this->expectException( \RuntimeException::class );
		( new LegacyCartDrain( $this->createMock( CartSessionStore::class ) ) )->maybe_run();
	}

	public function test_a_failed_read_of_the_table_throws_and_does_not_stamp(): void {
		$GLOBALS['wpdb'] = $this->wpdb( 'wp_smaily_connect_abandoned_carts', 'Table is marked as crashed', array() );
		Functions\expect( 'update_option' )->never();

		$this->expectException( \RuntimeException::class );
		( new LegacyCartDrain( $this->createMock( CartSessionStore::class ) ) )->maybe_run();
	}

	public function test_a_missing_table_is_nothing_to_drain_and_stamps(): void {
		$GLOBALS['wpdb'] = $this->wpdb( null, '', array() );
		Functions\expect( 'update_option' )->once()->with( LegacyCartDrain::DRAINED_OPTION, \Mockery::type( 'string' ), false );

		$stats = ( new LegacyCartDrain( $this->createMock( CartSessionStore::class ) ) )->maybe_run();

		self::assertSame(
			array(
				'drained' => 0,
				'poison'  => 0,
				'skipped' => 0,
			),
			$stats
		);
	}

	/**
	 * A $wpdb whose SHOW TABLES answers $table; $error is set after the query
	 * that fails (the SHOW TABLES one when $table is null, else the SELECT).
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function wpdb( ?string $table, string $error, array $rows ): object {
		return new class( $table, $error, $rows ) {
			public string $prefix     = 'wp_';
			public string $last_error = '';
			private ?string $table;
			private string $error;
			/** @var array<int, array<string, mixed>> */
			private array $rows;

			/**
			 * @param array<int, array<string, mixed>> $rows
			 */
			public function __construct( ?string $table, string $error, array $rows ) {
				$this->table = $table;
				$this->error = $error;
				$this->rows  = $rows;
			}

			public function prepare( string $query, string ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function get_var( string $query ): ?string {
				$this->last_error = $this->table === null ? $this->error : '';
				return $this->table;
			}

			/**
			 * @return array<int, array<string, mixed>>
			 */
			public function get_results( string $query, string $output ): array {
				$this->last_error = $this->error;
				return $this->error === '' ? $this->rows : array();
			}
		};
	}
}
