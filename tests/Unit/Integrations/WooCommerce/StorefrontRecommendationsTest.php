<?php
/**
 * Tests for the logged-in shopper's storefront recommendations (contract
 * v1.9.0, §15): when the engine may be asked, what is cached, and which slots
 * survive to the render. The product cards themselves need WooCommerce and
 * are covered by the integration suite.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\StorefrontRecommendations;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;

final class StorefrontRecommendationsTest extends TestCase {

	private const REC_A = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
	private const REC_B = '11111111-2222-4333-8444-555555555555';

	/** @var array<string, array{value: mixed, ttl: int}> */
	private array $transients = array();

	/** @var array<int, array{id: string, limit: int}> */
	private array $calls = array();

	/** @var array<string, mixed>|\Throwable */
	private $answer = array( 'slots' => array() );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = array();
		$this->calls      = array();

		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				return $this->transients[ $key ]['value'] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl ): bool {
				$this->transients[ $key ] = array(
					'value' => $value,
					'ttl'   => $ttl,
				);
				return true;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( int $user_id ) {
				$user             = new \stdClass();
				$user->ID         = $user_id;
				$user->user_email = 'Shopper' . $user_id . '@Example.test';
				return $user;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_logged_out_visitor_gets_nothing_and_the_engine_is_not_asked(): void {
		self::assertSame( array(), $this->service()->slots( 0 ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_nothing_is_asked_when_the_engine_may_not_be_called(): void {
		// Disconnected, or the engine refused this account (PRO-1893).
		self::assertSame( array(), $this->service( false )->slots( 42 ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_a_shopper_who_opted_out_of_profiling_gets_nothing_and_the_engine_is_not_asked(): void {
		self::assertSame( array(), $this->service( true, false )->slots( 42 ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_asks_the_engine_by_the_wordpress_user_id(): void {
		$this->service()->slots( 42 );

		self::assertSame(
			array(
				array(
					'id'    => '42',
					'limit' => StorefrontRecommendations::LIMIT,
				),
			),
			$this->calls
		);
	}

	public function test_maps_each_slot_to_its_recommendation_and_store_product(): void {
		$this->answer = array(
			'slots' => array(
				array(
					'position'    => 1,
					'rec_id'      => self::REC_A,
					'sku'         => 'woo-24150',
					'external_id' => '24150',
				),
				// external_id null ⇒ the id is the sku without its `woo-` prefix (§15).
				array(
					'position'    => 2,
					'rec_id'      => self::REC_B,
					'sku'         => 'woo-77',
					'external_id' => null,
				),
			),
		);

		self::assertSame(
			array(
				array(
					'rec_id'     => self::REC_A,
					'product_id' => 24150,
				),
				array(
					'rec_id'     => self::REC_B,
					'product_id' => 77,
				),
			),
			$this->service()->slots( 42 )
		);
	}

	public function test_drops_a_slot_without_a_usable_recommendation_id_or_product(): void {
		$this->answer = array(
			'slots' => array(
				array(
					'rec_id'      => 'not-a-uuid',
					'sku'         => 'woo-1',
					'external_id' => '1',
				),
				array(
					'rec_id'      => self::REC_A,
					'sku'         => 'shp-99',
					'external_id' => null,
				),
				'not-a-slot',
			),
		);

		self::assertSame( array(), $this->service()->slots( 42 ) );
	}

	public function test_the_answer_is_cached_per_shopper_for_a_finite_time(): void {
		$service = $this->service();

		$service->slots( 42 );
		$service->slots( 42 );

		self::assertCount( 1, $this->calls, 'The second render is served from the cache.' );
		self::assertCount( 1, $this->transients );
		$key = (string) array_key_first( $this->transients );
		self::assertStringNotContainsString( '42', $key, 'The cache key hashes the customer id (§15).' );
		self::assertSame( HOUR_IN_SECONDS, $this->transients[ $key ]['ttl'], 'One hour per §15 — and a per-shopper cache never lives forever.' );

		$service->slots( 43 );
		self::assertCount( 2, $this->calls, 'Another shopper is asked separately.' );
	}

	public function test_an_engine_error_renders_nothing_and_is_not_cached(): void {
		$this->answer = new ApiException( 0, 'network_error', 'timed out' );

		self::assertSame( array(), $this->service()->slots( 42 ) );
		self::assertSame( array(), $this->transients );
	}

	public function test_a_page_with_cards_is_marked_not_to_be_cached_with_the_constant_and_the_headers(): void {
		$marked = $this->record_marking();

		$this->marker( false )->mark();

		self::assertSame( array( 'DONOTCACHEPAGE' => true, 'nocache_headers' => 1 ), $marked->getArrayCopy() );
	}

	public function test_once_the_headers_are_sent_only_the_constant_is_set(): void {
		$marked = $this->record_marking();

		$this->marker( true )->mark();

		self::assertSame( array( 'DONOTCACHEPAGE' => true ), $marked->getArrayCopy() );
	}

	/**
	 * Records the constants defined through WooCommerce's helper and the
	 * no-cache header calls.
	 *
	 * @return \ArrayObject<string, mixed>
	 */
	private function record_marking(): \ArrayObject {
		$marked = new \ArrayObject();
		Functions\when( 'wc_maybe_define_constant' )->alias(
			static function ( string $name, $value ) use ( $marked ): void {
				$marked[ $name ] = $value;
			}
		);
		Functions\when( 'nocache_headers' )->alias(
			static function () use ( $marked ): void {
				$marked['nocache_headers'] = ( $marked['nocache_headers'] ?? 0 ) + 1;
			}
		);
		return $marked;
	}

	/**
	 * The renderer with its do-not-cache step exposed and the headers-sent
	 * seam fixed (PRO-3832). The marking touches none of the constructor's
	 * collaborators.
	 */
	private function marker( bool $headers_sent ): object {
		return new class( $headers_sent ) extends StorefrontRecommendations {
			private bool $headers_sent;

			public function __construct( bool $headers_sent ) {
				$this->headers_sent = $headers_sent;
			}

			public function mark(): void {
				$this->mark_not_cacheable();
			}

			protected function headers_already_sent(): bool {
				return $this->headers_sent;
			}
		};
	}

	private function service( bool $sending_allowed = true, bool $may_profile = true ): StorefrontRecommendations {
		$settings = new class( $sending_allowed ) extends RecEngineSettings {
			private bool $allowed;

			public function __construct( bool $allowed ) {
				$this->allowed = $allowed;
			}

			public function sending_allowed(): bool {
				return $this->allowed;
			}

			public function tenant_id(): string {
				return 'tenant-1';
			}
		};

		$profiling = new class( $may_profile ) extends ProfilingConsent {
			private bool $allowed;

			public function __construct( bool $allowed ) {
				$this->allowed = $allowed;
			}

			public function may_profile( string $email ): bool {
				return $this->allowed;
			}
		};

		$test = $this;

		return new StorefrontRecommendations(
			$settings,
			$profiling,
			static function () use ( $test ): Client {
				return $test->client();
			}
		);
	}

	/**
	 * A Client double that records each §15 request and plays back
	 * `$this->answer`.
	 */
	public function client(): Client {
		$test = $this;

		return new class( $test ) extends Client {
			private StorefrontRecommendationsTest $test;

			public function __construct( StorefrontRecommendationsTest $test ) {
				$this->test = $test;
			}

			public function customer_recommendations( string $customer_external_id, int $limit ): array {
				return $this->test->answer_for( $customer_external_id, $limit );
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function answer_for( string $customer_external_id, int $limit ): array {
		$this->calls[] = array(
			'id'    => $customer_external_id,
			'limit' => $limit,
		);
		if ( $this->answer instanceof \Throwable ) {
			throw $this->answer;
		}
		return $this->answer;
	}
}
