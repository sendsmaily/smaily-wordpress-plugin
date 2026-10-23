<?php
/**
 * The plugin never asks the engine to delete the store's data (CON-51).
 *
 * Contract §16 (`POST /api/v1/store/uninstall`, v1.14.0) deleted ALL of a
 * store's engine data when the store's last connection called it. Erkki's
 * decision (2026-10-22): the plugin never sends it — not on uninstall, not
 * on Disconnect. Both only remove the plugin's own stored engine key; the
 * store's data is deleted when the merchant closes the Smaily account (a
 * merchant who uninstalls may only be changing platform). Contract v1.18.0
 * made §16 a disconnect that revokes the calling key and pauses the store,
 * never deletes; this test pins the current behaviour until Erkki revisits
 * the decision (INT-145).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LogicException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Smaily\Connect\REST\RecEngineEndpoint;
use Smaily\Connect\Settings\RecEngineSettings;
use WP_REST_Request;

final class NoStoreDeletionRequestTest extends TestCase {

	/** WordPress HTTP functions — none may run on Disconnect. */
	private const HTTP_FUNCTIONS = array(
		'wp_remote_request',
		'wp_remote_post',
		'wp_remote_get',
		'wp_safe_remote_request',
		'wp_safe_remote_post',
		'wp_safe_remote_get',
	);

	/** @var array<string, mixed> wp_options fixtures. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$opts =& $this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $key ) use ( &$opts ): bool {
				unset( $opts[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$this->options = array();
	}

	public function test_disconnect_removes_the_stored_key_and_sends_nothing_to_the_engine(): void {
		$this->options = array(
			RecEngineSettings::OPTION_CONNECTED => true,
			RecEngineSettings::OPTION_API_KEY   => 'encrypted-key',
			RecEngineSettings::OPTION_BASE_URL  => 'https://intelligence.smaily.com',
			RecEngineSettings::OPTION_ENDPOINTS => array(
				'store_uninstall' => 'https://intelligence.smaily.com/api/v1/store/uninstall',
			),
		);
		foreach ( self::HTTP_FUNCTIONS as $function ) {
			Functions\expect( $function )->never();
		}
		$fail = static function (): void {
			throw new LogicException( 'Disconnect must not build an engine client or run a setup exchange (CON-51).' );
		};

		$endpoint = new RecEngineEndpoint( new RecEngineSettings(), $fail, $fail, $fail );
		$response = $endpoint->disconnect( new WP_REST_Request() );

		self::assertSame( array( 'disconnected' => true ), $response->get_data() );
		self::assertArrayNotHasKey( RecEngineSettings::OPTION_API_KEY, $this->options, 'Disconnect removes the stored engine key.' );
		self::assertArrayNotHasKey( RecEngineSettings::OPTION_CONNECTED, $this->options );
	}

	public function test_uninstall_makes_no_network_request(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );

		self::assertNotSame( '', $source );
		foreach ( array( 'wp_remote_', 'wp_safe_remote_', 'curl_', 'store/uninstall', 'store_uninstall' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $source, "uninstall.php must make no engine request (CON-51): found '{$needle}'." );
		}
	}

	public function test_no_shipped_code_names_the_store_deletion_endpoint(): void {
		$root  = dirname( __DIR__, 3 );
		$found = array();
		foreach ( array( 'includes', 'integrations', 'admin/src', 'public/js' ) as $dir ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$path = (string) $file;
				if ( ! preg_match( '/\.(php|ts|tsx)$/', $path ) || preg_match( '/\.test\.tsx?$/', $path ) ) {
					continue;
				}
				$code = (string) file_get_contents( $path );
				if ( false !== strpos( $code, 'store/uninstall' ) || false !== strpos( $code, 'store_uninstall' ) ) {
					$found[] = substr( $path, strlen( $root ) + 1 );
				}
			}
		}

		self::assertSame( array(), $found, 'No plugin code may call the §16 store-deletion endpoint (CON-51).' );
	}
}
