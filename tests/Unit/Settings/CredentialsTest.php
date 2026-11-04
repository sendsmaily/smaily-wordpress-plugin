<?php
/**
 * Tests for the Settings\Credentials resolver.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Settings\Credentials;

/**
 * Legacy Cypher shim is declared in tests/bootstrap.php as a single
 * source of truth across every PHP test that touches encryption /
 * decryption. Tests seed $decrypt_return / $encrypt_return on each
 * case to control what the shim returns.
 */
final class CredentialsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->alias(
			static fn ( string $key ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ?? '' )
		);

		\Smaily_Connect\Includes\Cypher::$decrypt_calls = array();
		\Smaily_Connect\Includes\Cypher::$decrypt_return  = '';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_account_reads_legacy_option(): void {
		\Smaily_Connect\Includes\Cypher::$decrypt_return = 'plain-password';

		$captured_key = null;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$captured_key ) {
				$captured_key = $key;
				return array(
					'subdomain' => 'demo',
					'username'  => 'alice',
					'password'  => 'encrypted-blob',
				);
			}
		);

		$set = ( new Credentials() )->get();

		self::assertSame( Credentials::LEGACY_OPTION_KEY, $captured_key );
		self::assertInstanceOf( CredentialSet::class, $set );
		self::assertSame( 'demo', $set->subdomain );
		self::assertSame( 'alice', $set->username );
		self::assertSame( 'plain-password', $set->password );
		self::assertTrue( $set->is_complete() );
	}

	public function test_named_account_reads_phase2_option_prefix(): void {
		\Smaily_Connect\Includes\Cypher::$decrypt_return = 'pw';

		$captured_key = null;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$captured_key ) {
				$captured_key = $key;
				return array(
					'subdomain' => 'demo-et',
					'username'  => 'eve',
					'password'  => 'enc',
				);
			}
		);

		( new Credentials() )->get( 'et' );

		self::assertSame( Credentials::PHASE2_OPTION_PREFIX . 'et', $captured_key );
	}

	public function test_returns_null_when_option_is_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		self::assertNull( ( new Credentials() )->get() );
	}

	public function test_returns_null_when_option_is_not_array(): void {
		Functions\when( 'get_option' )->justReturn( 'corrupted-string' );

		self::assertNull( ( new Credentials() )->get() );
	}

	public function test_empty_password_after_decrypt_does_not_block_partial_credentials(): void {
		\Smaily_Connect\Includes\Cypher::$decrypt_return = '';

		Functions\when( 'get_option' )->justReturn(
			array(
				'subdomain' => 'demo',
				'username'  => 'alice',
				'password'  => 'enc',
			)
		);

		$set = ( new Credentials() )->get();

		self::assertNotNull( $set );
		self::assertFalse(
			$set->is_complete(),
			'Empty password must yield is_complete()=false so callers refuse to issue API calls.'
		);
	}

	public function test_password_unreadable_when_a_stored_password_decrypts_to_nothing(): void {
		// PRO-4091: the site's keys changed, so the saved password can no
		// longer be read — the merchant must re-enter it.
		\Smaily_Connect\Includes\Cypher::$decrypt_return = '';
		Functions\when( 'get_option' )->justReturn(
			array(
				'subdomain' => 'demo',
				'username'  => 'alice',
				'password'  => 'enc',
			)
		);

		self::assertTrue( ( new Credentials() )->password_unreadable( 'transactional' ) );
	}

	public function test_password_not_unreadable_when_none_is_stored_or_it_decrypts(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'subdomain' => 'demo',
				'username'  => 'alice',
				'password'  => '',
			)
		);
		self::assertFalse( ( new Credentials() )->password_unreadable( 'transactional' ), 'Nothing stored is an incomplete connection, not an unreadable password.' );

		\Smaily_Connect\Includes\Cypher::$decrypt_return = 'pw';
		Functions\when( 'get_option' )->justReturn( array( 'password' => 'enc' ) );
		self::assertFalse( ( new Credentials() )->password_unreadable( 'transactional' ) );
	}

	public function test_has_default_returns_false_when_credentials_missing(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		self::assertFalse( ( new Credentials() )->has_default() );
	}

	public function test_has_default_returns_true_for_complete_credentials(): void {
		\Smaily_Connect\Includes\Cypher::$decrypt_return = 'pw';

		Functions\when( 'get_option' )->justReturn(
			array(
				'subdomain' => 'demo',
				'username'  => 'alice',
				'password'  => 'enc',
			)
		);

		self::assertTrue( ( new Credentials() )->has_default() );
	}

	public function test_account_key_is_sanitised_to_prevent_arbitrary_option_lookup(): void {
		\Smaily_Connect\Includes\Cypher::$decrypt_return = 'pw';

		$captured_key = null;
		Functions\when( 'get_option' )->alias(
			static function ( string $key ) use ( &$captured_key ) {
				$captured_key = $key;
				return array();
			}
		);

		( new Credentials() )->get( 'et/../OTHER!@#' );

		self::assertStringStartsWith( Credentials::PHASE2_OPTION_PREFIX, (string) $captured_key );
		self::assertStringNotContainsString( '..', (string) $captured_key );
		self::assertStringNotContainsString( '/', (string) $captured_key );
	}

	public function test_matches_is_true_only_for_the_account_the_set_was_stored_for(): void {
		// PRO-2286: the stored password may only vouch for its own account.
		$set = new CredentialSet( 'demo', 'alice', 's3cret' );

		self::assertTrue( $set->matches( 'demo', 'alice' ) );
		self::assertFalse( $set->matches( 'other', 'alice' ), 'A different subdomain is a different account.' );
		self::assertFalse( $set->matches( 'demo', 'bob' ), 'A different username is a different account.' );
		self::assertFalse( $set->matches( 'Demo', 'alice' ), 'Comparison is exact, not case-folded.' );
	}

	public function test_subdomains_lists_every_stored_account_trimmed_lower_case_and_once(): void {
		// CON-289: the accounts a Campaign Intelligence setup link may name.
		$options = array(
			Credentials::LEGACY_OPTION_KEY                       => array( 'subdomain' => ' MyStore ' ),
			Credentials::PHASE2_OPTION_PREFIX . 'en'             => array( 'subdomain' => 'mystore-en' ),
			Credentials::PHASE2_OPTION_PREFIX . 'et'             => array( 'subdomain' => 'mystore' ),
			Credentials::PHASE2_OPTION_PREFIX . 'transactional' => array( 'subdomain' => '' ),
		);

		$GLOBALS['wpdb'] = new class( array_keys( $options ) ) {
			/** @var string */
			public $options = 'wp_options';
			/** @var array<int, string> */
			private array $names;
			/** @param array<int, string> $names */
			public function __construct( array $names ) {
				$this->names = $names;
			}
			public function esc_like( string $text ): string {
				return $text;
			}
			public function prepare( string $query, string $like ): string {
				return str_replace( '%s', $like, $query );
			}
			/** @return array<int, string> The option names the query's LIKE prefix matches. */
			public function get_col( string $query ): array {
				if ( strpos( $query, 'LIKE ' . Credentials::PHASE2_OPTION_PREFIX . '%' ) === false ) {
					return array();
				}
				return array_values(
					array_filter( $this->names, static fn ( string $name ): bool => strpos( $name, Credentials::PHASE2_OPTION_PREFIX ) === 0 )
				);
			}
		};
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $default = false ) => $options[ $key ] ?? $default
		);

		try {
			self::assertSame( array( 'mystore', 'mystore-en' ), ( new Credentials() )->subdomains() );
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}
}
