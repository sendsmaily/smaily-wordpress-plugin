<?php
/**
 * Unit: ProfilingConsent ((a).0) — the opt-out enforcement rule + resolver.
 *
 * @package Smaily\Connect\Tests\Unit\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Privacy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;

final class ProfilingConsentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// --- the pure enforcement rule (opt-out, default-on) -------------------

	/**
	 * @dataProvider rule_cases
	 */
	public function test_is_allowed_rule( ?string $is_unsubscribed, ?string $profiling, bool $expected ): void {
		self::assertSame( $expected, ProfilingConsent::is_allowed( $is_unsubscribed, $profiling ) );
	}

	/**
	 * @return array<string, array{0: ?string, 1: ?string, 2: bool}>
	 */
	public static function rule_cases(): array {
		return array(
			'default-on: both absent'          => array( null, null, true ),
			'default-on: subscribed, no field' => array( '0', null, true ),
			'explicit opt-in'                  => array( '0', '1', true ),
			'explicit profiling opt-out'       => array( '0', '0', false ),
			'general unsubscribe (stronger)'   => array( '1', '1', false ),
			'unsubscribe beats opt-in'         => array( '1', null, false ),
			'both off'                         => array( '1', '0', false ),
		);
	}

	// --- the cached resolver ----------------------------------------------

	private function resolver( ?SmailyClient $smaily, ?RecEngineClient $rec = null, bool $connected = true ): ProfilingConsent {
		$settings = $this->createMock( RecEngineSettings::class );
		$settings->method( 'is_connected' )->willReturn( $connected );
		// The engine-sending gate is sending_allowed() (connected AND not
		// refused, PRO-1893); a mocked class stubs it independently.
		$settings->method( 'sending_allowed' )->willReturn( $connected );
		$rec = $rec ?? $this->createMock( RecEngineClient::class );

		return new ProfilingConsent(
			$settings,
			static fn (): ?SmailyClient => $smaily,
			static fn (): RecEngineClient => $rec
		);
	}

	public function test_may_profile_uses_the_cache_without_a_readback(): void {
		Functions\when( 'get_transient' )->justReturn( '0' );

		// A cache hit must NOT touch the Smaily client.
		$smaily = $this->createMock( SmailyClient::class );
		$smaily->expects( self::never() )->method( 'get_contact_consent' );

		self::assertFalse( $this->resolver( $smaily )->may_profile( 'a@example.com' ) );
	}

	public function test_cache_miss_reads_back_and_caches_the_decision(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		$cached = null;
		$ttls   = array();
		Functions\when( 'set_transient' )->alias(
			static function ( string $k, $v, int $ttl = 0 ) use ( &$cached, &$ttls ): bool {
				$cached       = $v;
				$ttls[ $k ] = $ttl;
				return true;
			}
		);

		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willReturn(
			array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '1' )
		);

		self::assertTrue( $this->resolver( $smaily )->may_profile( 'a@example.com' ) );
		self::assertSame( '1', $cached );
		// PRO-2435: a transient with NO expiry is an autoloaded option forever —
		// one per contact. Every cache write, the stale one included, must
		// carry a finite TTL.
		self::assertCount( 2, $ttls, 'Both the fresh and the stale cache are written on a read-back.' );
		foreach ( $ttls as $key => $ttl ) {
			self::assertGreaterThan( 0, $ttl, "Cache key {$key} must not be a no-expiry (autoloaded) transient." );
		}
	}

	public function test_readback_opt_out_caches_false_and_engine_opts_out(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willReturn(
			array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '0' )
		);

		$rec = $this->createMock( RecEngineClient::class );
		$rec->expects( self::once() )
			->method( 'customer_opt_out' )
			->with( 'a@example.com', self::callback( static fn ( array $b ): bool => $b['opt_out'] === true ) )
			->willReturn( array( 'ok' => true ) );

		self::assertFalse( $this->resolver( $smaily, $rec )->refresh( 'a@example.com' ) );
	}

	public function test_readback_error_fails_open(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );

		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willThrowException( new \RuntimeException( 'network' ) );

		// Never-seen contact: no stale cache, no durable opt-out → true fail-open.
		self::assertTrue( $this->resolver( $smaily )->refresh( 'a@example.com' ) );
	}

	public function test_error_serves_the_stale_value_when_one_exists(): void {
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		// Fresh cache misses; the no-expiry stale cache holds a prior '0' (opted out).
		Functions\when( 'get_transient' )->alias(
			static fn ( string $key ) => strpos( $key, 'stale' ) !== false ? '0' : false
		);

		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willThrowException( new \RuntimeException( 'network' ) );

		self::assertFalse( $this->resolver( $smaily )->refresh( 'a@example.com' ) );
	}

	public function test_durable_optout_wins_over_a_read_error_with_no_stale_entry(): void {
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false ); // no stale entry either.
		Functions\when( 'get_option' )->justReturn( array( md5( 'a@example.com' ) => true ) );

		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willThrowException( new \RuntimeException( 'network' ) );

		// A durably known opt-out can never be re-allowed by a transient error.
		self::assertFalse( $this->resolver( $smaily )->refresh( 'a@example.com' ) );
	}

	public function test_successful_opt_in_readback_clears_the_durable_optout(): void {
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( md5( 'a@example.com' ) => true ) );
		$stored = null;
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$stored ): bool {
				$stored = $value;
				return true;
			}
		);

		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willReturn(
			array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '1' )
		);

		self::assertTrue( $this->resolver( $smaily )->refresh( 'a@example.com' ) );
		self::assertIsArray( $stored );
		self::assertArrayNotHasKey( md5( 'a@example.com' ), $stored );
	}

	public function test_opt_out_readback_persists_the_durable_registry_entry(): void {
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		$stored = null;
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$stored ): bool {
				$stored = $value;
				return true;
			}
		);

		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willReturn(
			array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '0' )
		);

		self::assertFalse( $this->resolver( $smaily )->refresh( 'a@example.com' ) );
		self::assertIsArray( $stored );
		self::assertArrayHasKey( md5( 'a@example.com' ), $stored );
	}

	public function test_opt_out_writes_to_smaily_and_engine(): void {
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		$smaily = $this->createMock( SmailyClient::class );
		$smaily->expects( self::once() )
			->method( 'write_profiling_consent' )
			->with( 'a@example.com', false, self::isType( 'string' ) )
			->willReturn( array( 'code' => 101 ) );

		$rec = $this->createMock( RecEngineClient::class );
		$rec->expects( self::once() )
			->method( 'customer_opt_out' )
			->with( 'a@example.com', self::callback( static fn ( array $b ): bool => $b['opt_out'] === true ) )
			->willReturn( array( 'ok' => true ) );

		$this->resolver( $smaily, $rec )->opt_out( 'a@example.com' );
	}

	// --- known_preference(): the display accessor (PRO-2513) ---------------

	/**
	 * An in-memory transient store, so a read can see what an earlier
	 * write in the same call chain left behind.
	 *
	 * @param array<string, string> $seed
	 * @return array<string, string> The live store (by reference).
	 */
	private function &transients( array $seed = array() ): array {
		$store = $seed;
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$store ): bool {
				$store[ $key ] = (string) $value;
				return true;
			}
		);
		return $store;
	}

	private function failing_smaily(): SmailyClient {
		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willThrowException( new \RuntimeException( 'network' ) );
		return $smaily;
	}

	public function test_read_failure_leaves_may_profile_unchanged_but_the_preference_unknown(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$store = &$this->transients();
		$fresh = 'smly_profiling_' . md5( 'a@example.com' );

		// The gate still fails open and caches that for the day (PRO-1194 / F3-31) …
		self::assertTrue( $this->resolver( $this->failing_smaily() )->may_profile( 'a@example.com' ) );
		self::assertSame( '1', $store[ $fresh ] );

		// … but for display nothing is known, and the fail-open daily cache
		// written above does not count as knowledge.
		self::assertNull( $this->resolver( $this->failing_smaily() )->known_preference( 'a@example.com' ) );
		self::assertSame( '1', $store[ $fresh ], 'The display read leaves the gate cache as it was.' );
	}

	public function test_no_smaily_client_means_unknown_for_display(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->transients();

		// Email setup not finished → the factory yields no client.
		self::assertNull( $this->resolver( null )->known_preference( 'a@example.com' ) );
	}

	public function test_successful_read_is_known(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->transients();
		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willReturn(
			array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '1' )
		);

		self::assertTrue( $this->resolver( $smaily )->known_preference( 'a@example.com' ) );
	}

	public function test_previous_successful_read_is_known_through_a_read_failure(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->transients( array( 'smly_profiling_stale_' . md5( 'a@example.com' ) => '0' ) );

		self::assertFalse( $this->resolver( $this->failing_smaily() )->known_preference( 'a@example.com' ) );
	}

	/**
	 * The My Account opt-out button (PRO-3189) is offered on a store whose
	 * email wizard isn't finished — no Smaily client, so the Smaily write is
	 * skipped. The opt-out must still stick: the durable registry records it
	 * (it survives the transients going away), the engine is told, and both
	 * the gate and the display read it back as "opted out".
	 */
	public function test_opt_out_without_a_smaily_client_is_durable_and_reaches_the_engine(): void {
		$options = array();
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $fallback = false ) use ( &$options ) {
				return $options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$options ): bool {
				$options[ $name ] = $value;
				return true;
			}
		);
		$store = &$this->transients();

		$rec = $this->createMock( RecEngineClient::class );
		$rec->expects( self::once() )
			->method( 'customer_opt_out' )
			->with( 'a@example.com', self::callback( static fn ( array $b ): bool => $b['opt_out'] === true ) )
			->willReturn( array( 'ok' => true ) );
		$resolver = $this->resolver( null, $rec );

		$resolver->opt_out( 'a@example.com' );

		self::assertArrayHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
		self::assertFalse( $resolver->may_profile( 'a@example.com' ) );
		self::assertFalse( $resolver->known_preference( 'a@example.com' ) );

		// Even with every cache gone, the durable registry keeps the answer.
		$store = array();
		self::assertFalse( $resolver->may_profile( 'a@example.com' ) );
		self::assertFalse( $resolver->known_preference( 'a@example.com' ) );
	}

	// --- a durable opt-out holds until an explicit opt-in (PRO-3191) -------

	/**
	 * An in-memory options store holding a durable opt-out for a@example.com.
	 *
	 * @return array<string, mixed> The live store (by reference).
	 */
	private function &opted_out_options(): array {
		$options = array( 'smly_profiling_optouts' => array( md5( 'a@example.com' ) => true ) );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $fallback = false ) use ( &$options ) {
				return $options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$options ): bool {
				$options[ $name ] = $value;
				return true;
			}
		);
		return $options;
	}

	/**
	 * @param array{found: bool, is_unsubscribed: ?string, smaily_rec_profiling: ?string} $consent
	 */
	private function smaily_reading( array $consent ): SmailyClient {
		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willReturn( $consent );
		return $smaily;
	}

	/**
	 * @dataProvider no_preference_values
	 */
	public function test_durable_opt_out_holds_when_the_contact_carries_no_preference( ?string $profiling ): void {
		$options  = &$this->opted_out_options();
		$store    = &$this->transients();
		$resolver = $this->resolver(
			$this->smaily_reading( array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => $profiling ) )
		);

		// The daily cache has expired; the fresh read succeeds but says nothing.
		self::assertFalse( $resolver->may_profile( 'a@example.com' ) );
		self::assertFalse( $resolver->known_preference( 'a@example.com' ) );
		self::assertArrayHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );

		// Every cache gone again: the next successful read still doesn't lift it.
		$store = array();
		self::assertFalse( $resolver->may_profile( 'a@example.com' ) );
	}

	/**
	 * @return array<string, array{0: ?string}>
	 */
	public static function no_preference_values(): array {
		return array(
			'field absent' => array( null ),
			'field empty'  => array( '' ),
		);
	}

	public function test_durable_opt_out_is_written_to_a_contact_that_lacks_the_field(): void {
		$this->opted_out_options();
		$this->transients();
		$smaily = $this->smaily_reading( array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => null ) );
		$smaily->expects( self::once() )
			->method( 'write_profiling_consent' )
			->with( 'a@example.com', false, self::isType( 'string' ) )
			->willReturn( array( 'code' => 101 ) );

		self::assertFalse( $this->resolver( $smaily )->refresh( 'a@example.com' ) );
	}

	public function test_contact_not_found_keeps_the_opt_out_and_writes_nothing(): void {
		$options = &$this->opted_out_options();
		$this->transients();
		$smaily = $this->smaily_reading( array( 'found' => false, 'is_unsubscribed' => null, 'smaily_rec_profiling' => null ) );
		// An upsert here would CREATE a Smaily contact just to hold the opt-out.
		$smaily->expects( self::never() )->method( 'write_profiling_consent' );
		$smaily->expects( self::never() )->method( 'upsert_subscribers' );

		self::assertFalse( $this->resolver( $smaily )->refresh( 'a@example.com' ) );
		self::assertArrayHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
	}

	public function test_explicit_opt_in_on_the_contact_clears_the_durable_opt_out(): void {
		$options = &$this->opted_out_options();
		$this->transients();
		$smaily = $this->smaily_reading( array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '1' ) );
		$smaily->expects( self::never() )->method( 'write_profiling_consent' );

		$resolver = $this->resolver( $smaily );

		self::assertTrue( $resolver->refresh( 'a@example.com' ) );
		self::assertArrayNotHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
		self::assertTrue( $resolver->known_preference( 'a@example.com' ) );
	}

	public function test_opt_in_from_my_account_clears_the_durable_opt_out(): void {
		$options = &$this->opted_out_options();
		$this->transients();
		$smaily = $this->smaily_reading( array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => null ) );
		$smaily->expects( self::once() )
			->method( 'write_profiling_consent' )
			->with( 'a@example.com', true, self::isType( 'string' ) )
			->willReturn( array( 'code' => 101 ) );

		$resolver = $this->resolver( $smaily );
		$resolver->opt_in( 'a@example.com' );

		self::assertArrayNotHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
		self::assertTrue( $resolver->may_profile( 'a@example.com' ) );
	}

	public function test_durable_opt_out_is_known_through_a_read_failure(): void {
		Functions\when( 'get_option' )->justReturn( array( md5( 'a@example.com' ) => true ) );
		$this->transients();

		self::assertFalse( $this->resolver( $this->failing_smaily() )->known_preference( 'a@example.com' ) );
	}
}
