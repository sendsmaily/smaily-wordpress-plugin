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
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;

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
		// An entry mirroring an earlier Smaily read-back (PRO-3192: moment 0).
		Functions\when( 'get_option' )->justReturn( array( md5( 'a@example.com' ) => 0 ) );
		$stored = null;
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$stored ): bool {
				$stored = $value;
				return true;
			}
		);

		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willReturn(
			array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '1', 'smaily_rec_profiling_ts' => '2026-09-01T10:00:00Z' )
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

		$smaily = $this->smaily_reading( array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => null ) );
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

	// --- a choice never creates a Smaily contact (PRO-3627) ----------------

	/**
	 * Smaily creates a contact sent without a status as subscribed, so a
	 * choice for an address Smaily does not have stays in the store: the
	 * durable opt-out and the engine still get it, Smaily gets nothing.
	 */
	public function test_opt_out_for_an_address_smaily_does_not_have_stays_in_the_store(): void {
		$options = &$this->options();
		$this->transients();
		$smaily = $this->smaily_reading( array( 'found' => false, 'is_unsubscribed' => null, 'smaily_rec_profiling' => null ) );
		$smaily->expects( self::never() )->method( 'write_profiling_consent' );
		$smaily->expects( self::never() )->method( 'upsert_subscribers' );

		$rec = $this->createMock( RecEngineClient::class );
		$rec->expects( self::once() )
			->method( 'customer_opt_out' )
			->with( 'a@example.com', self::callback( static fn ( array $b ): bool => $b['opt_out'] === true ) )
			->willReturn( array( 'ok' => true ) );

		$resolver = $this->resolver( $smaily, $rec );
		$resolver->opt_out( 'a@example.com' );

		self::assertArrayHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
		self::assertFalse( $resolver->may_profile( 'a@example.com' ) );
	}

	public function test_opt_in_for_an_address_smaily_does_not_have_stays_in_the_store(): void {
		$options = &$this->opted_out_options();
		$this->transients();
		$smaily = $this->smaily_reading( array( 'found' => false, 'is_unsubscribed' => null, 'smaily_rec_profiling' => null ) );
		$smaily->expects( self::never() )->method( 'write_profiling_consent' );
		$smaily->expects( self::never() )->method( 'upsert_subscribers' );

		$resolver = $this->resolver( $smaily );
		$resolver->opt_in( 'a@example.com' );

		self::assertArrayNotHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
		self::assertTrue( $resolver->may_profile( 'a@example.com' ) );
	}

	/**
	 * When Smaily cannot say whether it has the contact, nothing is written
	 * blind; the opt-out is still recorded store-side and reaches the engine.
	 */
	public function test_opt_out_writes_nothing_when_the_contact_check_fails(): void {
		$options = &$this->options();
		$this->transients();
		$smaily = $this->failing_smaily();
		$smaily->expects( self::never() )->method( 'write_profiling_consent' );
		$smaily->expects( self::never() )->method( 'upsert_subscribers' );

		$rec = $this->createMock( RecEngineClient::class );
		$rec->expects( self::once() )->method( 'customer_opt_out' )->willReturn( array( 'ok' => true ) );

		$resolver = $this->resolver( $smaily, $rec );
		$resolver->opt_out( 'a@example.com' );

		self::assertArrayHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
		self::assertFalse( $resolver->may_profile( 'a@example.com' ) );
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
		$smaily->method( 'has_contact' )->willThrowException( new \RuntimeException( 'network' ) );
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
	 * @param int|true $entry The registry value: the opt-out's moment, or `true`
	 *                        for an entry recorded before PRO-3192.
	 * @return array<string, mixed> The live store (by reference).
	 */
	private function &opted_out_options( $entry = true ): array {
		$options = array( 'smly_profiling_optouts' => array( md5( 'a@example.com' ) => $entry ) );
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
	 * @param array{found: bool, is_unsubscribed: ?string, smaily_rec_profiling: ?string, smaily_rec_profiling_ts?: ?string} $consent
	 */
	private function smaily_reading( array $consent ): SmailyClient {
		$smaily = $this->createMock( SmailyClient::class );
		$smaily->method( 'get_contact_consent' )->willReturn( $consent );
		$smaily->method( 'has_contact' )->willReturn( $consent['found'] );
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
		$options = &$this->opted_out_options( (int) strtotime( '2026-09-20T00:00:00Z' ) );
		$this->transients();
		$smaily = $this->smaily_reading(
			array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '1', 'smaily_rec_profiling_ts' => '2026-09-25T00:00:00Z' )
		);
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

	// --- the newest choice wins (PRO-3192) --------------------------------

	/**
	 * An in-memory options store, empty to start with.
	 *
	 * @return array<string, mixed> The live store (by reference).
	 */
	private function &options(): array {
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
		return $options;
	}

	/**
	 * A Smaily client whose contact reads `$profiling` + `$ts`, recording every
	 * profiling write as [may_profile, changed_at]. The first write fails when
	 * `$first_write_fails` (an opt-out whose Smaily write never landed).
	 *
	 * @param array<int, array{0: bool, 1: string}> $writes
	 */
	private function smaily_contact( string $profiling, ?string $ts, array &$writes, bool $first_write_fails = false ): SmailyClient {
		$smaily = $this->smaily_reading(
			array( 'found' => true, 'is_unsubscribed' => '0', 'smaily_rec_profiling' => $profiling, 'smaily_rec_profiling_ts' => $ts )
		);
		$smaily->method( 'write_profiling_consent' )->willReturnCallback(
			static function ( string $email, bool $may_profile, string $changed_at ) use ( &$writes, $first_write_fails ): array {
				$writes[] = array( $may_profile, $changed_at );
				if ( $first_write_fails && count( $writes ) === 1 ) {
					throw new \RuntimeException( 'network' );
				}
				return array( 'code' => 101 );
			}
		);
		return $smaily;
	}

	public function test_opt_out_newer_than_the_contacts_allowed_holds_and_is_written_again(): void {
		$options = &$this->options();
		$store   = &$this->transients();
		$writes  = array();
		// The shopper opted in earlier: the contact holds "allowed" from 2020.
		$resolver = $this->resolver( $this->smaily_contact( '1', '2020-01-01T00:00:00Z', $writes, true ) );

		// They opt out in My Account now, but the Smaily write fails.
		$resolver->opt_out( 'a@example.com' );
		self::assertIsInt( $options['smly_profiling_optouts'][ md5( 'a@example.com' ) ], 'The store records when it made the opt-out.' );

		// The daily cache expires; the read finds the OLDER "allowed".
		$store = array();
		self::assertFalse( $resolver->may_profile( 'a@example.com' ) );
		self::assertFalse( $resolver->known_preference( 'a@example.com' ) );
		self::assertArrayHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
		self::assertCount( 2, $writes );
		self::assertFalse( $writes[1][0], 'The opt-out is written to the contact again.' );
	}

	public function test_contacts_allowed_newer_than_the_opt_out_is_respected(): void {
		$options = &$this->opted_out_options( (int) strtotime( '2026-09-20T00:00:00Z' ) );
		$this->transients();
		$writes   = array();
		$resolver = $this->resolver( $this->smaily_contact( '1', '2026-09-25T00:00:00Z', $writes ) );

		self::assertTrue( $resolver->may_profile( 'a@example.com' ) );
		self::assertTrue( $resolver->known_preference( 'a@example.com' ) );
		self::assertArrayNotHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
		self::assertSame( array(), $writes, 'A newer answer is left as it is.' );
	}

	/**
	 * @dataProvider missing_timestamps
	 */
	public function test_contacts_allowed_without_a_timestamp_counts_as_older( ?string $ts ): void {
		$options = &$this->opted_out_options( (int) strtotime( '2026-09-20T00:00:00Z' ) );
		$this->transients();
		$writes   = array();
		$resolver = $this->resolver( $this->smaily_contact( '1', $ts, $writes ) );

		self::assertFalse( $resolver->may_profile( 'a@example.com' ) );
		self::assertArrayHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
		self::assertCount( 1, $writes );
		self::assertFalse( $writes[0][0], 'The opt-out is written to the contact.' );
	}

	/**
	 * @return array<string, array{0: ?string}>
	 */
	public static function missing_timestamps(): array {
		return array(
			'field absent' => array( null ),
			'field empty'  => array( '' ),
			'not a moment' => array( 'not-a-date' ),
		);
	}

	/**
	 * PRO-3434: only a real past moment in the exact Z form counts. A relative
	 * word, another format or a date in the future (beyond the clock-skew
	 * allowance) counts as older, so the opt-out holds and is written again.
	 *
	 * @dataProvider contact_timestamps
	 */
	public function test_contacts_allowed_counts_only_with_a_strict_past_timestamp( string $ts, bool $lifts ): void {
		$options                           = &$this->options();
		$options['smly_profiling_optouts'] = array( md5( 'shopper@example.test' ) => time() - 2 * DAY_IN_SECONDS );
		$this->transients();
		$writes   = array();
		$resolver = $this->resolver( $this->smaily_contact( '1', $ts, $writes ) );

		self::assertSame( $lifts, $resolver->may_profile( 'shopper@example.test' ) );
		self::assertSame( ! $lifts, array_key_exists( md5( 'shopper@example.test' ), (array) $options['smly_profiling_optouts'] ) );
		self::assertSame( $lifts ? array() : array( false ), array_column( $writes, 0 ), 'An opt-out that holds is written to the contact again.' );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function contact_timestamps(): array {
		$now = time();
		return array(
			'relative word'          => array( 'tomorrow', false ),
			'one year ahead'         => array( IsoDate::to_z( $now + YEAR_IN_SECONDS ), false ),
			'beyond the skew'        => array( IsoDate::to_z( $now + 600 ), false ),
			'garbage'                => array( '2026-13-45T99:99:99Z', false ),
			'offset instead of Z'    => array( gmdate( 'c', $now - DAY_IN_SECONDS ), false ),
			'valid past, older'      => array( IsoDate::to_z( $now - 3 * DAY_IN_SECONDS ), false ),
			'valid past, newer'      => array( IsoDate::to_z( $now - DAY_IN_SECONDS ), true ),
			'within the skew, newer' => array( IsoDate::to_z( $now + 60 ), true ),
		);
	}

	/**
	 * An opt-out recorded before PRO-3192 (`true`, moment unknown) is read as
	 * the newest answer: a contact's "allowed" does not lift it, however
	 * recent. Writing it to the contact records the moment, so an "allowed"
	 * made after that write is respected again.
	 */
	public function test_opt_out_recorded_before_the_change_holds_until_written_then_newer_wins(): void {
		$options = &$this->opted_out_options( true );
		$store   = &$this->transients();
		$writes  = array();
		$before  = time();

		self::assertFalse( $this->resolver( $this->smaily_contact( '1', '2026-09-25T00:00:00Z', $writes ) )->may_profile( 'a@example.com' ) );
		self::assertCount( 1, $writes );
		self::assertFalse( $writes[0][0] );
		$moment = $options['smly_profiling_optouts'][ md5( 'a@example.com' ) ];
		self::assertIsInt( $moment );
		self::assertGreaterThanOrEqual( $before, $moment );

		// The shopper opts in again elsewhere, after that write.
		$store = array();
		$later = gmdate( 'Y-m-d\TH:i:s\Z', $moment + 60 );
		self::assertTrue( $this->resolver( $this->smaily_contact( '1', $later, $writes ) )->may_profile( 'a@example.com' ) );
		self::assertArrayNotHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
	}

	/**
	 * An entry that only mirrors a Smaily read-back (here an unsubscribe) has
	 * no store-side moment: the contact's own "allowed" lifts it as before
	 * PRO-3192 once they resubscribe, and nothing is written to the contact.
	 */
	public function test_read_back_opt_out_is_lifted_by_the_contacts_allowed(): void {
		$options = &$this->options();
		$store   = &$this->transients();
		$writes  = array();

		$unsubscribed = $this->smaily_reading(
			array( 'found' => true, 'is_unsubscribed' => '1', 'smaily_rec_profiling' => '1', 'smaily_rec_profiling_ts' => '2026-09-01T00:00:00Z' )
		);
		self::assertFalse( $this->resolver( $unsubscribed )->may_profile( 'a@example.com' ) );
		self::assertSame( 0, $options['smly_profiling_optouts'][ md5( 'a@example.com' ) ] );

		// Resubscribed; the profiling answer is the same one as before.
		$store = array();
		self::assertTrue( $this->resolver( $this->smaily_contact( '1', '2026-09-01T00:00:00Z', $writes ) )->may_profile( 'a@example.com' ) );
		self::assertArrayNotHasKey( md5( 'a@example.com' ), (array) $options['smly_profiling_optouts'] );
		self::assertSame( array(), $writes );
	}
}
