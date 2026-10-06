<?php
/**
 * Tests for the storefront recommendations (contract v1.9.0, §15, and the
 * visitor-token request of v1.10.0): who the engine may be asked about,
 * what is cached, and which slots survive to the render. The product cards
 * and the page placeholder need WooCommerce and are covered by the
 * integration suite.
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

	private const TOKEN = 'vt_AbC123xyz';

	/** @var array<string, array{value: mixed, ttl: int}> */
	private array $transients = array();

	/** @var array<int, array{type: string, id: string, limit: int}> */
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

	public function test_a_visitor_with_neither_an_account_nor_a_visitor_token_gets_nothing_and_the_engine_is_not_asked(): void {
		self::assertSame( array(), $this->service()->slots( 0, '' ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_a_visitor_token_of_the_wrong_shape_is_not_sent(): void {
		self::assertSame( array(), $this->service()->slots( 0, 'not-a-token' ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_nothing_is_asked_when_the_engine_may_not_be_called(): void {
		// Disconnected, or the engine refused this account (PRO-1893).
		self::assertSame( array(), $this->service( false )->slots( 42, self::TOKEN ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_a_shopper_who_opted_out_of_profiling_gets_nothing_and_the_engine_is_not_asked(): void {
		self::assertSame( array(), $this->service( true, false )->slots( 42, '' ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_an_opted_out_shopper_is_not_asked_about_by_their_visitor_token_either(): void {
		self::assertSame( array(), $this->service( true, false )->slots( 42, self::TOKEN ) );
		self::assertSame( array(), $this->calls, 'The account decides: an opted-out shopper is never asked for, under any identifier.' );
	}

	public function test_asks_the_engine_by_the_wordpress_user_id(): void {
		$this->service()->slots( 42, '' );

		self::assertSame(
			array(
				array(
					'type'  => 'customer',
					'id'    => '42',
					'limit' => StorefrontRecommendations::LIMIT,
				),
			),
			$this->calls
		);
	}

	public function test_a_logged_in_shopper_is_named_by_the_account_even_with_a_visitor_token(): void {
		$this->service()->slots( 42, self::TOKEN );

		self::assertSame( 'customer', $this->calls[0]['type'] );
		self::assertSame( '42', $this->calls[0]['id'] );
	}

	public function test_a_guest_is_asked_about_by_the_visitor_token(): void {
		$this->service()->slots( 0, self::TOKEN );

		self::assertSame(
			array(
				array(
					'type'  => 'visitor',
					'id'    => self::TOKEN,
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
			$this->service()->slots( 42, '' )
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

		self::assertSame( array(), $this->service()->slots( 42, '' ) );
	}

	public function test_the_answer_is_cached_per_shopper_for_a_finite_time(): void {
		$service = $this->service();

		$service->slots( 42, '' );
		$service->slots( 42, '' );

		self::assertCount( 1, $this->calls, 'The second render is served from the cache.' );
		self::assertCount( 1, $this->transients );
		$key = (string) array_key_first( $this->transients );
		self::assertStringNotContainsString( '42', $key, 'The cache key hashes the customer id (§15).' );
		self::assertSame( HOUR_IN_SECONDS, $this->transients[ $key ]['ttl'], 'One hour per §15 — and a per-shopper cache never lives forever.' );

		$service->slots( 43, '' );
		self::assertCount( 2, $this->calls, 'Another shopper is asked separately.' );
	}

	public function test_a_guest_answer_is_cached_under_a_hash_of_the_visitor_token(): void {
		$service = $this->service();

		$service->slots( 0, self::TOKEN );
		$service->slots( 0, self::TOKEN );

		self::assertCount( 1, $this->calls, 'The second request is served from the cache.' );
		$key = (string) array_key_first( $this->transients );
		self::assertStringNotContainsString( self::TOKEN, $key, 'The cache key hashes the visitor token.' );
		self::assertSame( HOUR_IN_SECONDS, $this->transients[ $key ]['ttl'] );
	}

	public function test_an_engine_failure_shows_nothing_and_is_cached_briefly(): void {
		$this->answer = new ApiException( 400, 'validation_failed', 'visitor_token not accepted' );
		$service      = $this->service();

		self::assertSame( array(), $service->slots( 0, self::TOKEN ) );
		self::assertSame( array(), $service->slots( 0, self::TOKEN ) );

		self::assertCount( 1, $this->calls, 'The second request makes no engine call.' );
		$key = (string) array_key_first( $this->transients );
		self::assertSame( array(), $this->transients[ $key ]['value'] );
		self::assertSame( 10 * MINUTE_IN_SECONDS, $this->transients[ $key ]['ttl'], 'A failure is kept for 10 minutes, not the hour an answer gets.' );
	}

	public function test_the_engine_gets_the_ten_seconds_section_15_allows_a_background_request(): void {
		// §15 v1.10.0: the engine ends a request after 10 s; ask from the
		// background with a client timeout of at most 10 s.
		self::assertSame( 10, StorefrontRecommendations::TIMEOUT_SECONDS );
	}

	public function test_a_timeout_is_cached_briefly_too(): void {
		$this->answer = new ApiException( 0, 'network_error', 'timed out' );

		$this->service()->slots( 42, '' );

		$key = (string) array_key_first( $this->transients );
		self::assertSame( StorefrontRecommendations::FAILURE_CACHE_TTL, $this->transients[ $key ]['ttl'] );
	}

	/**
	 * PRO-3857: a guest names its own cache key, so the per-shopper failure
	 * cache bounds nothing per client. A timeout or 5xx pauses every shopper's
	 * engine calls for a short, finite time.
	 *
	 * @dataProvider store_wide_failures
	 */
	public function test_a_timeout_or_5xx_pauses_every_shoppers_engine_calls_briefly( ApiException $failure ): void {
		$this->answer = $failure;
		$service      = $this->service();

		$service->slots( 0, self::TOKEN );
		self::assertSame( StorefrontRecommendations::PAUSE_TTL, $this->transients[ StorefrontRecommendations::PAUSE_KEY ]['ttl'] ?? null );
		self::assertSame( 2 * MINUTE_IN_SECONDS, StorefrontRecommendations::PAUSE_TTL );

		$this->answer = array( 'slots' => array() );
		self::assertSame( array(), $service->slots( 0, 'vt_AnotherGuest1' ) );
		self::assertSame( array(), $service->slots( 42, '' ) );
		self::assertCount( 1, $this->calls, 'During the pause no shopper\'s cache miss calls the engine.' );
	}

	/**
	 * @return array<string, array{0: ApiException}>
	 */
	public static function store_wide_failures(): array {
		return array(
			'timeout' => array( new ApiException( 0, 'network_error', 'timed out' ) ),
			'500'     => array( new ApiException( 500, 'http_500', 'Engine returned HTTP 500' ) ),
			'503'     => array( new ApiException( 503, 'http_503', 'Engine returned HTTP 503' ) ),
		);
	}

	/**
	 * @dataProvider shopper_only_answers
	 *
	 * @param array<string, mixed>|ApiException $answer
	 */
	public function test_an_answer_or_a_4xx_does_not_pause_the_store( $answer ): void {
		$this->answer = $answer;
		$service      = $this->service();

		$service->slots( 0, self::TOKEN );

		self::assertArrayNotHasKey( StorefrontRecommendations::PAUSE_KEY, $this->transients );
		$this->answer = array( 'slots' => array() );
		$service->slots( 0, 'vt_AnotherGuest1' );
		self::assertCount( 2, $this->calls, 'The next shopper is still asked.' );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|ApiException}>
	 */
	public static function shopper_only_answers(): array {
		return array(
			'an answer'       => array(
				array(
					'slots' => array(
						array(
							'rec_id'      => self::REC_A,
							'sku'         => 'woo-1',
							'external_id' => '1',
						),
					),
				),
			),
			'an empty answer' => array( array( 'slots' => array() ) ),
			'400'             => array( new ApiException( 400, 'validation_failed', 'visitor_token not accepted' ) ),
			'429'             => array( new ApiException( 429, 'rate_limit_exceeded', 'slow down' ) ),
		);
	}

	public function test_a_cached_answer_is_still_served_during_the_pause(): void {
		$this->answer = array(
			'slots' => array(
				array(
					'rec_id'      => self::REC_A,
					'sku'         => 'woo-1',
					'external_id' => '1',
				),
			),
		);
		$service      = $this->service();
		$cached       = $service->slots( 42, '' );

		$this->transients[ StorefrontRecommendations::PAUSE_KEY ] = array(
			'value' => 1,
			'ttl'   => StorefrontRecommendations::PAUSE_TTL,
		);

		self::assertSame( $cached, $service->slots( 42, '' ) );
		self::assertCount( 1, $this->calls );
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
	 * A Client double that records each §15 request — by customer id or by
	 * visitor token — and plays back `$this->answer`.
	 */
	public function client(): Client {
		$test = $this;

		return new class( $test ) extends Client {
			private StorefrontRecommendationsTest $test;

			public function __construct( StorefrontRecommendationsTest $test ) {
				$this->test = $test;
			}

			public function customer_recommendations( string $customer_external_id, int $limit ): array {
				return $this->test->answer_for( 'customer', $customer_external_id, $limit );
			}

			public function visitor_recommendations( string $visitor_token, int $limit ): array {
				return $this->test->answer_for( 'visitor', $visitor_token, $limit );
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function answer_for( string $type, string $id, int $limit ): array {
		$this->calls[] = array(
			'type'  => $type,
			'id'    => $id,
			'limit' => $limit,
		);
		if ( $this->answer instanceof \Throwable ) {
			throw $this->answer;
		}
		return $this->answer;
	}
}
