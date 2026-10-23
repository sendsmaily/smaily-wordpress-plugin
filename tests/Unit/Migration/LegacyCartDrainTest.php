<?php
/**
 * LegacyCartDrain — a failed read never stamps (CON-182: migration 012 drops
 * the table right after the drain, so "could not read" must not pass for
 * "nothing to drain"), and neither does a cart the tracker did not take
 * (CON-207: "copied" means the write succeeded).
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

	public function test_a_cart_the_tracker_does_not_take_stops_the_drain_and_does_not_stamp(): void {
		$GLOBALS['wpdb'] = $this->wpdb( 'wp_smaily_connect_abandoned_carts', '', $this->two_legacy_rows() );
		$this->stub_users();
		Functions\expect( 'update_option' )->never();

		$store = $this->createMock( CartSessionStore::class );
		$store->expects( self::once() )->method( 'upsert' )->willReturn( false );

		$this->expectException( \RuntimeException::class );
		( new LegacyCartDrain( $store ) )->maybe_run();
	}

	public function test_every_cart_the_tracker_takes_counts_as_drained_and_stamps(): void {
		$GLOBALS['wpdb'] = $this->wpdb( 'wp_smaily_connect_abandoned_carts', '', $this->two_legacy_rows() );
		$this->stub_users();
		Functions\expect( 'update_option' )->once()->with( LegacyCartDrain::DRAINED_OPTION, \Mockery::type( 'string' ), false );

		$store = $this->createMock( CartSessionStore::class );
		$store->expects( self::exactly( 2 ) )->method( 'upsert' )->willReturn( true );

		$stats = ( new LegacyCartDrain( $store ) )->maybe_run();

		self::assertSame(
			array(
				'drained' => 2,
				'poison'  => 0,
				'skipped' => 0,
			),
			$stats
		);
	}

	/**
	 * Two in-flight legacy rows in the 2.0.0 writer's shape.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function two_legacy_rows(): array {
		$content = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the legacy writer's shape.
			array(
				'k1' => array(
					'product_id'   => 11,
					'variation_id' => 0,
					'quantity'     => 1,
				),
			)
		);

		return array(
			array(
				'customer_id'  => '7',
				'cart_updated' => '2026-10-20 10:00:00',
				'cart_content' => $content,
			),
			array(
				'customer_id'  => '8',
				'cart_updated' => '2026-10-20 11:00:00',
				'cart_content' => $content,
			),
		);
	}

	private function stub_users(): void {
		Functions\when( 'get_userdata' )->alias(
			static function ( int $id ): \WP_User {
				$user             = new \WP_User();
				$user->ID         = $id;
				$user->user_email = 'shopper' . $id . '@example.test';
				return $user;
			}
		);
		Functions\when( 'maybe_unserialize' )->alias(
			static function ( string $value ) {
				return unserialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- the test's own fixture.
			}
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

// WP_User stub for the drain's identity check (shared shim pattern — see
// HookHandlerTest). Declared conditionally: another test file may have
// loaded it first.
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
