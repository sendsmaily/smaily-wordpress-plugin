<?php
/**
 * Unit: GdprHandler's cart-session coverage (PRO-1343) — the WP Privacy
 * exporter/eraser also surfaces/removes `smly_plus_cart_session` rows, not
 * just rec-engine data. The engine + order-meta paths already have
 * integration coverage (RecEngineGdprTest); this file isolates the new
 * cart-session logic with a fake CartSessionStore double, mirroring the
 * fake-store pattern in CartAbandonmentSweeperTest.
 *
 * @package Smaily\Connect\Tests\Unit\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Privacy\GdprHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\CartSessionStore;
use Smaily\Connect\Smaily\RecEngine\Client;

final class GdprHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_user_by' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_export_includes_a_cart_session_row(): void {
		$store = $this->fake_store(
			array(
				array(
					'id'                   => 5,
					'cart_token'           => 'tok-1',
					'user_id'              => 0,
					'email'                => 'shopper@example.test',
					'first_name'           => 'Jane',
					'last_name'            => 'Doe',
					'cart_content'         => '[{"product_id":123,"variation_id":0,"quantity":2}]',
					'cart_updated'         => '2026-07-14 10:00:00',
					'reminder_enqueued_at' => null,
					'created_at'           => '2026-07-14 09:00:00',
				),
			)
		);

		$export = $this->handler( $store )->export( 'shopper@example.test' );
		$item   = $this->find_group_item( $export, 'Abandoned-cart session' );

		self::assertNotNull( $item, 'cart-session row surfaced under its own group label.' );
		self::assertSame( 'tok-1', $this->field( $item, 'cart_token' ) );
		self::assertSame( '[{"product_id":123,"variation_id":0,"quantity":2}]', $this->field( $item, 'cart_content' ) );
	}

	public function test_export_omits_the_id_column_and_empty_fields(): void {
		$store = $this->fake_store(
			array(
				array(
					'id'                   => 9,
					'cart_token'           => 'tok-2',
					'user_id'              => 0,
					'email'                => 'shopper2@example.test',
					'first_name'           => '',
					'last_name'            => '',
					'cart_content'         => '[]',
					'cart_updated'         => '2026-07-14 10:00:00',
					'reminder_enqueued_at' => null,
					'created_at'           => '2026-07-14 09:00:00',
				),
			)
		);

		$export = $this->handler( $store )->export( 'shopper2@example.test' );
		$item   = $this->find_group_item( $export, 'Abandoned-cart session' );
		self::assertNotNull( $item );

		$names = array_column( $item['data'], 'name' );
		self::assertNotContains( 'id', $names, 'the internal row id is not subject-access data.' );
		self::assertNotContains( 'first_name', $names, 'empty column omitted.' );
		self::assertNotContains( 'reminder_enqueued_at', $names, 'null column omitted.' );
	}

	public function test_export_resolves_the_wp_user_id_for_the_store_lookup(): void {
		Functions\when( 'get_user_by' )->alias(
			static fn ( string $field, $value ) => $field === 'email' && $value === 'shopper@example.test'
				? new class() extends \WP_User {
					public function __construct() {
						$this->ID = 7;
					}
				}
				: false
		);
		$store = $this->fake_store( array() );

		$this->handler( $store )->export( 'shopper@example.test' );

		self::assertSame(
			array(
				'email'   => 'shopper@example.test',
				'user_id' => 7,
			),
			$store->lookup_calls[0] ?? null
		);
	}

	public function test_export_uses_user_id_zero_when_no_wp_user_matches(): void {
		$store = $this->fake_store( array() );

		$this->handler( $store )->export( 'nobody@example.test' );

		self::assertSame(
			array(
				'email'   => 'nobody@example.test',
				'user_id' => 0,
			),
			$store->lookup_calls[0] ?? null
		);
	}

	public function test_erase_removes_cart_session_rows_and_reports_removed(): void {
		$store                = $this->fake_store( array() );
		$store->delete_return = 2;

		$result = $this->handler( $store )->erase( 'shopper@example.test' );

		self::assertTrue( $result['items_removed'] );
		self::assertSame(
			array(
				'email'   => 'shopper@example.test',
				'user_id' => 0,
			),
			$store->delete_calls[0] ?? null
		);
	}

	public function test_erase_reports_false_when_nothing_was_removed_anywhere(): void {
		$store = $this->fake_store( array() );
		// delete_return defaults to 0; engine disconnected + no order-meta/user-merge stubs.

		$result = $this->handler( $store )->erase( 'nobody@example.test' );

		self::assertFalse( $result['items_removed'] );
	}

	// --- helpers -------------------------------------------------------

	private function handler( CartSessionStore $store ): GdprHandler {
		$settings = $this->createMock( RecEngineSettings::class );
		$settings->method( 'is_connected' )->willReturn( false );

		return new GdprHandler(
			$settings,
			static function (): Client {
				throw new \RuntimeException( 'engine must not be called while disconnected' );
			},
			$store
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function fake_store( array $rows ): CartSessionStore {
		return new class( $rows ) extends CartSessionStore {
			/** @var array<int, array<string, mixed>> */
			private array $rows;

			public int $delete_return = 0;

			/** @var array<int, array{email: string, user_id: int}> */
			public array $lookup_calls = array();

			/** @var array<int, array{email: string, user_id: int}> */
			public array $delete_calls = array();

			/**
			 * @param array<int, array<string, mixed>> $rows
			 */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			public function rows_for_privacy_request( string $email, int $user_id = 0 ): array {
				$this->lookup_calls[] = array(
					'email'   => $email,
					'user_id' => $user_id,
				);
				return $this->rows;
			}

			public function delete_rows_for_privacy_request( string $email, int $user_id = 0 ): int {
				$this->delete_calls[] = array(
					'email'   => $email,
					'user_id' => $user_id,
				);
				return $this->delete_return;
			}
		};
	}

	/**
	 * @param array{data: array<int, array<string, mixed>>, done: bool} $export
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_group_item( array $export, string $group_label ): ?array {
		foreach ( $export['data'] as $item ) {
			if ( $item['group_label'] === $group_label ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function field( array $item, string $name ): ?string {
		foreach ( $item['data'] as $pair ) {
			if ( $pair['name'] === $name ) {
				return $pair['value'];
			}
		}
		return null;
	}
}

// Shared shim pattern (see CartHookHandlerTest) — declared conditionally,
// another test file loaded earlier in the same process may already have it.
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
