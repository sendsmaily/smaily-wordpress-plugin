<?php
/**
 * Tests for the legacy Smaily_Client workflow trigger default (PRO-3889).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily_Connect\Includes\Smaily_Client;

require_once dirname( __DIR__, 2 ) . '/includes/smaily-options.class.php';
require_once dirname( __DIR__, 2 ) . '/includes/smaily-client.class.php';

/**
 * A workflow trigger that does not say whether to resubscribe must not
 * resubscribe a contact who unsubscribed (PRO-3889, the PRO-1716 rule of the
 * newer client). Every current caller passes the value explicitly; this pins
 * the default for a future caller that leaves it out.
 */
final class LegacySmailyClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'SMAILY_CONNECT_PLUGIN_VERSION' ) ) {
			define( 'SMAILY_CONNECT_PLUGIN_VERSION', SMAILY_CONNECT_VERSION );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_trigger_without_opt_in_argument_sends_force_opt_in_false(): void {
		$captured = array();

		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"code":101}' );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ) use ( &$captured ): array {
				$captured[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array();
			}
		);

		$client = new Smaily_Client( 'demo', 'user', 'pass' );
		$client->trigger_automation( 42, array( array( 'email' => 'reader@example.test' ) ) );

		self::assertCount( 1, $captured );
		self::assertSame( 'https://demo.sendsmaily.net/api/autoresponder.php', $captured[0]['url'] );
		self::assertSame( 42, $captured[0]['args']['body']['autoresponder'] );
		self::assertArrayHasKey( 'force_opt_in', $captured[0]['args']['body'] );
		self::assertFalse( $captured[0]['args']['body']['force_opt_in'] );
	}
}
