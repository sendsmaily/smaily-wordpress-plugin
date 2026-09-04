<?php
/**
 * Tests for ContactReconciler — Smaily → WP marketing-consent mirror (F3-48).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Smaily\ContactReconciler;
use Smaily\Connect\Smaily\ContactSyncMode;

final class ContactReconcilerTest extends TestCase {

	/** @var array<string, mixed> wp_options fixtures. */
	private array $options = array();

	/** @var array<string, mixed> get_user_meta fixtures keyed "<id>:<key>". */
	private array $user_meta = array();

	/** @var array<string, int> email → user id. */
	private array $users_by_email = array();

	/** @var array<int, array{id:int, key:string, value:mixed}> recorded update_user_meta calls. */
	private array $meta_writes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$opts =& $this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value, $autoload = null ) use ( &$opts ): bool {
				$opts[ $key ] = $value;
				return true;
			}
		);

		$meta =& $this->user_meta;
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) use ( &$meta ) {
				return $meta[ $user_id . ':' . $key ] ?? '';
			}
		);

		$writes =& $this->meta_writes;
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, $value ) use ( &$writes, &$meta ): bool {
				$writes[]                       = array(
					'id'    => $user_id,
					'key'   => $key,
					'value' => $value,
				);
				$meta[ $user_id . ':' . $key ] = $value;
				return true;
			}
		);

		$by_email =& $this->users_by_email;
		Functions\when( 'get_user_by' )->alias(
			static function ( string $field, $value ) use ( &$by_email ) {
				if ( $field !== 'email' || ! isset( $by_email[ $value ] ) ) {
					return false;
				}
				return new class( $by_email[ $value ] ) extends \WP_User {
					public function __construct( int $id ) {
						$this->ID = $id;
					}
				};
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$this->options        = array();
		$this->user_meta      = array();
		$this->users_by_email = array();
		$this->meta_writes    = array();
	}

	public function test_reconcile_is_a_noop_outside_consent_mode(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_LEGITIMATE_INTEREST;

		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'get_action_log' );

		self::assertSame( 0, $this->reconciler( $client )->reconcile() );
	}

	public function test_reconcile_optout_clears_wp_newsletter_flag(): void {
		$this->consent_mode();
		$this->user( 'a@x.test', 10, 1 ); // currently opted in

		$client = $this->createMock( Client::class );
		$client->method( 'get_action_log' )->willReturn(
			array( array( 'seq_id' => 5, 'email' => 'a@x.test', 'action' => 'optout' ) )
		);

		$changed = $this->reconciler( $client )->reconcile();

		self::assertSame( 1, $changed );
		self::assertSame( 0, $this->meta_writes[0]['value'] );
		self::assertSame( 5, $this->options[ ContactReconciler::OPTION_CURSOR ], 'Cursor advances to the max seq_id.' );
	}

	public function test_reconcile_optin_sets_wp_newsletter_flag(): void {
		$this->consent_mode();
		$this->user( 'a@x.test', 10, 0 ); // currently opted out

		$client = $this->createMock( Client::class );
		$client->method( 'get_action_log' )->willReturn(
			array( array( 'seq_id' => 9, 'email' => 'a@x.test', 'action' => 'optin' ) )
		);

		self::assertSame( 1, $this->reconciler( $client )->reconcile() );
		self::assertSame( 1, $this->meta_writes[0]['value'] );
	}

	public function test_reconcile_skips_unchanged_flag(): void {
		$this->consent_mode();
		$this->user( 'a@x.test', 10, 0 ); // already opted out

		$client = $this->createMock( Client::class );
		$client->method( 'get_action_log' )->willReturn(
			array( array( 'seq_id' => 5, 'email' => 'a@x.test', 'action' => 'optout' ) )
		);

		self::assertSame( 0, $this->reconciler( $client )->reconcile() );
		self::assertSame( array(), $this->meta_writes );
	}

	public function test_reconcile_skips_emails_with_no_wp_user(): void {
		$this->consent_mode();

		$client = $this->createMock( Client::class );
		$client->method( 'get_action_log' )->willReturn(
			array( array( 'seq_id' => 7, 'email' => 'stranger@x.test', 'action' => 'optout' ) )
		);

		self::assertSame( 0, $this->reconciler( $client )->reconcile() );
		self::assertSame( array(), $this->meta_writes );
		self::assertSame( 7, $this->options[ ContactReconciler::OPTION_CURSOR ], 'Cursor still advances past a non-WP contact.' );
	}

	public function test_reconcile_resumes_from_stored_cursor(): void {
		$this->consent_mode();
		$this->options[ ContactReconciler::OPTION_CURSOR ] = 100;
		$this->user( 'a@x.test', 10, 1 );

		$client = $this->createMock( Client::class );
		$client->expects( $this->once() )
			->method( 'get_action_log' )
			->with( 100, $this->anything() )
			->willReturn( array( array( 'seq_id' => 105, 'email' => 'a@x.test', 'action' => 'optout' ) ) );

		$this->reconciler( $client )->reconcile();

		self::assertSame( 105, $this->options[ ContactReconciler::OPTION_CURSOR ] );
	}

	public function test_rebaseline_sets_flags_from_is_unsubscribed(): void {
		$this->consent_mode();
		$this->user( 'a@x.test', 10, 1 ); // in Smaily unsubscribed → should drop to 0
		$this->user( 'b@x.test', 11, 0 ); // in Smaily subscribed → should rise to 1

		$client = $this->createMock( Client::class );
		$client->method( 'list_contacts' )->willReturn(
			array(
				array( 'email' => 'a@x.test', 'is_unsubscribed' => '1' ),
				array( 'email' => 'b@x.test', 'is_unsubscribed' => '0' ),
			)
		);

		$changed = $this->reconciler( $client )->rebaseline();

		self::assertSame( 2, $changed );
		self::assertSame( 0, $this->user_meta[ '10:user_newsletter' ] );
		self::assertSame( 1, $this->user_meta[ '11:user_newsletter' ] );
	}

	public function test_rebaseline_is_a_noop_outside_consent_mode(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CHECKOUT_OPTIN;

		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'list_contacts' );

		self::assertSame( 0, $this->reconciler( $client )->rebaseline() );
	}

	public function test_apply_runs_with_the_echo_suppression_flag(): void {
		$this->consent_mode();
		$this->user( 'a@x.test', 10, 1 );

		$applying_during_write = null;
		$meta                  =& $this->user_meta;
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $id, string $key, $value ) use ( &$applying_during_write, &$meta ): bool {
				$applying_during_write          = ContactReconciler::is_applying();
				$meta[ $id . ':' . $key ] = $value;
				return true;
			}
		);

		$client = $this->createMock( Client::class );
		$client->method( 'get_action_log' )->willReturn(
			array( array( 'seq_id' => 5, 'email' => 'a@x.test', 'action' => 'optout' ) )
		);

		$this->reconciler( $client )->reconcile();

		self::assertTrue( $applying_during_write, 'Reconciler writes run with the echo-suppression flag set.' );
		self::assertFalse( ContactReconciler::is_applying(), 'Flag is reset after the write.' );
	}

	private function reconciler( Client $client ): ContactReconciler {
		return new ContactReconciler( $client, new ContactSyncMode() );
	}

	private function consent_mode(): void {
		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_CONSENT;
	}

	private function user( string $email, int $id, int $newsletter ): void {
		$this->users_by_email[ $email ]                = $id;
		$this->user_meta[ $id . ':user_newsletter' ]   = $newsletter;
	}
}

if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval(
		<<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}
