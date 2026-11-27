<?php
/**
 * Tests for the network index of Smaily accounts and contact sync modes (CON-467).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\Router;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\NetworkAccounts;

/**
 * This site is blog 1. Its options, the network's index and the network's
 * sites are arrays the WordPress stubs read; every index write is recorded.
 */
final class NetworkAccountsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int|string, mixed> */
	private array $index = array();

	/** @var array<int, object|false> */
	private array $sites = array();

	private int $writes = 0;

	private int $site_lookups = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		\Smaily_Connect\Includes\Cypher::$decrypt_return = 'secret';

		$this->options = array(
			Credentials::LEGACY_OPTION_KEY => array(
				'subdomain' => ' Demo ',
				'username'  => 'api-user',
				'password'  => 'encrypted',
			),
			ContactSyncMode::OPTION_MODE   => ContactSyncMode::MODE_CONSENT,
		);

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'sanitize_key' )->alias(
			static fn ( string $key ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $key ) )
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->alias(
			fn ( string $key, $fallback = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback
		);
		Functions\when( 'get_site_option' )->alias(
			fn () => $this->index
		);
		Functions\when( 'update_site_option' )->alias(
			function ( string $key, $value ): bool {
				self::assertSame( NetworkAccounts::SITE_OPTION, $key );
				$this->index = $value;
				++$this->writes;
				return true;
			}
		);
		Functions\when( 'get_blog_details' )->alias(
			function ( int $blog_id ) {
				++$this->site_lookups;
				return $this->sites[ $blog_id ] ?? false;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_names_another_site_on_the_same_account_and_never_its_credentials(): void {
		$this->index = array(
			2 => $this->entry( array( 'demo' ), ContactSyncMode::MODE_LEGITIMATE_INTEREST ),
			3 => $this->entry( array( 'other' ), ContactSyncMode::MODE_CHECKOUT_OPTIN ),
		);
		$this->sites = array(
			2 => $this->site( 'Outlet', 'https://outlet.example' ),
			3 => $this->site( 'Elsewhere', 'https://elsewhere.example' ),
		);

		$sites = NetworkAccounts::sites_sharing_account();

		self::assertSame(
			array(
				array(
					'name' => 'Outlet',
					'url'  => 'https://outlet.example',
					'mode' => ContactSyncMode::MODE_LEGITIMATE_INTEREST,
				),
			),
			$sites
		);
		self::assertSame( 1, $this->site_lookups, 'Only a site on the same account is looked up.' );
		self::assertSame( $this->entry( array( 'demo' ), ContactSyncMode::MODE_CONSENT ), $this->index[1], 'This site wrote its own entry, subdomain normalised.' );
		self::assertStringNotContainsString( 'secret', (string) json_encode( array( $this->index, $sites ) ) );
	}

	public function test_a_sharing_site_is_listed_whatever_its_mode_for_the_screen_to_compare(): void {
		$this->index = array( 2 => $this->entry( array( 'demo' ), ContactSyncMode::MODE_CONSENT ) );
		$this->sites = array( 2 => $this->site( 'Twin', 'https://twin.example' ) );

		$sites = NetworkAccounts::sites_sharing_account();

		self::assertCount( 1, $sites );
		self::assertSame( ContactSyncMode::MODE_CONSENT, $sites[0]['mode'] );
	}

	public function test_no_other_site_on_the_account_lists_nothing(): void {
		$this->index = array( 3 => $this->entry( array( 'other' ), ContactSyncMode::MODE_LEGITIMATE_INTEREST ) );

		self::assertSame( array(), NetworkAccounts::sites_sharing_account() );
		self::assertSame( 0, $this->site_lookups );
	}

	public function test_a_single_site_reads_and_writes_no_index(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'get_site_option' )->never();
		Functions\expect( 'update_site_option' )->never();

		self::assertSame( array(), NetworkAccounts::sites_sharing_account() );
		NetworkAccounts::refresh();
		NetworkAccounts::forget();
	}

	public function test_a_removed_archived_or_spam_site_is_skipped(): void {
		$this->index = array(
			2 => $this->entry( array( 'demo' ), ContactSyncMode::MODE_LEGITIMATE_INTEREST ),
			3 => $this->entry( array( 'demo' ), ContactSyncMode::MODE_LEGITIMATE_INTEREST ),
			4 => $this->entry( array( 'demo' ), ContactSyncMode::MODE_LEGITIMATE_INTEREST ),
		);
		$this->sites = array(
			3 => $this->site( 'Archived', 'https://archived.example', array( 'archived' => '1' ) ),
			4 => $this->site( 'Spam', 'https://spam.example', array( 'spam' => '1' ) ),
		);

		self::assertSame( array(), NetworkAccounts::sites_sharing_account() );
	}

	public function test_names_at_most_the_cap(): void {
		for ( $blog_id = 2; $blog_id < 2 + NetworkAccounts::MAX_LISTED + 5; $blog_id++ ) {
			$this->index[ $blog_id ] = $this->entry( array( 'demo' ), ContactSyncMode::MODE_CHECKOUT_OPTIN );
			$this->sites[ $blog_id ] = $this->site( 'Site ' . $blog_id, 'https://site' . $blog_id . '.example' );
		}

		$sites = NetworkAccounts::sites_sharing_account();

		self::assertCount( NetworkAccounts::MAX_LISTED, $sites );
		self::assertSame( NetworkAccounts::MAX_LISTED, $this->site_lookups );
	}

	public function test_a_language_account_counts_in_the_account_per_language_mode(): void {
		$this->options[ Router::OPTION_MODE ] = Router::MODE_A;
		$this->options[ Credentials::PHASE2_OPTION_PREFIX . 'account_et' ] = array(
			'subdomain' => 'shop-et',
			'username'  => 'api-user',
			'password'  => 'encrypted',
		);
		$GLOBALS['wpdb'] = new class() {
			public string $options = 'wp_2_options';

			public function esc_like( string $text ): string {
				return $text;
			}

			public function prepare( string $query, string $like ): string {
				return $like;
			}

			/** @return array<int, string> */
			public function get_col( string $query ): array {
				return array( Credentials::PHASE2_OPTION_PREFIX . 'account_et' );
			}
		};
		$this->index = array( 2 => $this->entry( array( 'shop-et' ), ContactSyncMode::MODE_LEGITIMATE_INTEREST ) );
		$this->sites = array( 2 => $this->site( 'Estonian shop', 'https://ee.example' ) );

		$sites = NetworkAccounts::sites_sharing_account();

		self::assertSame( 'Estonian shop', $sites[0]['name'] ?? null );
		self::assertSame( $this->entry( array( 'demo', 'shop-et' ), ContactSyncMode::MODE_CONSENT ), $this->index[1] );
	}

	public function test_refresh_writes_only_a_change(): void {
		$this->index = array( 1 => $this->entry( array( 'demo' ), ContactSyncMode::MODE_CONSENT ) );

		NetworkAccounts::refresh();
		self::assertSame( 0, $this->writes, 'An unchanged entry is not written again.' );

		$this->options[ ContactSyncMode::OPTION_MODE ] = ContactSyncMode::MODE_LEGITIMATE_INTEREST;
		NetworkAccounts::refresh();
		self::assertSame( 1, $this->writes );
		self::assertSame( ContactSyncMode::MODE_LEGITIMATE_INTEREST, $this->index[1]['mode'] );
	}

	public function test_contact_sync_off_or_no_account_removes_the_entry(): void {
		$this->index = array(
			1 => $this->entry( array( 'demo' ), ContactSyncMode::MODE_CONSENT ),
			2 => $this->entry( array( 'demo' ), ContactSyncMode::MODE_LEGITIMATE_INTEREST ),
		);
		$this->options[ ContactSyncMode::OPTION_SYNC_ENABLED ] = '';

		NetworkAccounts::refresh();
		self::assertArrayNotHasKey( 1, $this->index );
		self::assertSame( array(), NetworkAccounts::sites_sharing_account(), 'With contact sync off nothing is listed.' );

		$this->options[ ContactSyncMode::OPTION_SYNC_ENABLED ] = '1';
		unset( $this->options[ Credentials::LEGACY_OPTION_KEY ] );
		NetworkAccounts::refresh();
		self::assertArrayNotHasKey( 1, $this->index );
	}

	public function test_forget_removes_this_site_only(): void {
		$this->index = array(
			1 => $this->entry( array( 'demo' ), ContactSyncMode::MODE_CONSENT ),
			2 => $this->entry( array( 'demo' ), ContactSyncMode::MODE_LEGITIMATE_INTEREST ),
		);

		NetworkAccounts::forget();

		self::assertSame( array( 2 ), array_keys( $this->index ) );
	}

	public function test_a_tracked_option_change_refreshes_once_at_shutdown(): void {
		$added = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$added ): bool {
				$added[] = $hook;
				return true;
			}
		);

		NetworkAccounts::on_option_change( 'blogname' );
		NetworkAccounts::on_option_change( ContactSyncMode::OPTION_MODE );
		NetworkAccounts::on_option_change( Credentials::PHASE2_OPTION_PREFIX . 'account_et' );

		self::assertSame( array( 'shutdown', 'shutdown' ), $added );
	}

	/**
	 * @param array<int, string> $accounts
	 * @return array{accounts: array<int, string>, mode: string}
	 */
	private function entry( array $accounts, string $mode ): array {
		return array(
			'accounts' => $accounts,
			'mode'     => $mode,
		);
	}

	/**
	 * @param array<string, string> $flags
	 */
	private function site( string $name, string $home, array $flags = array() ): object {
		return (object) ( $flags + array(
			'blogname' => $name,
			'home'     => $home,
			'deleted'  => '0',
			'archived' => '0',
			'spam'     => '0',
		) );
	}
}

