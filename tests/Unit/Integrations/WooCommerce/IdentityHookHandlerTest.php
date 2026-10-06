<?php
/**
 * PRO-3860: the login identity merge sends a store-created `vs_` visitor
 * token only with the shopper's marketing consent; the engine's `vt_` token
 * from an email link is attribution and stays ungated.
 *
 * The real WP user / engine round trip is integration-tested
 * (RecEngineIdentityMergeTest). Here the consent answer is the protected
 * marketing_consent_given() seam, and the Client double records the §7 body.
 *
 * @package Smaily\Connect\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\IdentityHookHandler;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;

final class IdentityHookHandlerTest extends TestCase {

	private const STORE_TOKEN  = 'vs_0123456789ABCDEFabcdef';
	private const ENGINE_TOKEN = 'vt_fromemaillink123';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'update_user_meta' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_COOKIE = array();
		parent::tearDown();
	}

	public function test_a_store_token_is_not_sent_without_marketing_consent(): void {
		$_COOKIE['smaily_anon_sid'] = 'anon-1';
		$_COOKIE['smaily_rec_uid']  = self::STORE_TOKEN;
		$client                     = $this->recording_client();

		$this->handler( $client, false )->on_login( 'mari', $this->user() );

		self::assertCount( 1, $client->merges, 'The anon session is still merged.' );
		self::assertArrayNotHasKey( 'smaily_visitor_token', $client->merges[0] );
	}

	public function test_a_store_token_alone_without_consent_means_nothing_to_merge(): void {
		$_COOKIE['smaily_rec_uid'] = self::STORE_TOKEN;
		$client                    = $this->recording_client();

		$this->handler( $client, false )->on_login( 'mari', $this->user() );

		self::assertSame( array(), $client->merges );
	}

	public function test_a_store_token_is_sent_with_marketing_consent(): void {
		$_COOKIE['smaily_rec_uid'] = self::STORE_TOKEN;
		$client                    = $this->recording_client();

		$this->handler( $client, true )->on_login( 'mari', $this->user() );

		self::assertSame( self::STORE_TOKEN, $client->merges[0]['smaily_visitor_token'] ?? null );
	}

	public function test_an_engine_token_is_sent_without_marketing_consent(): void {
		$_COOKIE['smaily_rec_uid'] = self::ENGINE_TOKEN;
		$client                    = $this->recording_client();

		$this->handler( $client, false )->on_login( 'mari', $this->user() );

		self::assertSame( self::ENGINE_TOKEN, $client->merges[0]['smaily_visitor_token'] ?? null );
	}

	public function test_a_cookie_value_in_neither_token_format_is_not_sent(): void {
		$_COOKIE['smaily_anon_sid'] = 'anon-1';
		$_COOKIE['smaily_rec_uid']  = 'vs_short';
		$client                     = $this->recording_client();

		$this->handler( $client, true )->on_login( 'mari', $this->user() );

		self::assertArrayNotHasKey( 'smaily_visitor_token', $client->merges[0] ?? array() );
	}

	// --- doubles ----------------------------------------------------------

	private function handler( Client $client, bool $consent ): IdentityHookHandler {
		return new class( new FakeRecEngineSettings(), $client, $consent ) extends IdentityHookHandler {
			private bool $test_consent;

			public function __construct( RecEngineSettings $settings, Client $client, bool $consent ) {
				parent::__construct(
					$settings,
					static function () use ( $client ): Client {
						return $client;
					}
				);
				$this->test_consent = $consent;
			}

			protected function marketing_consent_given(): bool {
				return $this->test_consent;
			}
		};
	}

	private function user(): \WP_User {
		$user             = new \WP_User();
		$user->ID         = 7;
		$user->user_email = 'mari@example.com';
		return $user;
	}

	/**
	 * A Client double that records every §7 merge body it was asked to send.
	 */
	private function recording_client(): Client {
		return new class( 'sk_unit', 'https://engine.unit' ) extends Client {
			/** @var array<int, array<string, mixed>> */
			public array $merges = array();

			public function merge_identity( array $merge ): array {
				$this->merges[] = $merge;
				return array( 'ok' => true );
			}
		};
	}
}

// Stub for the WP_User class (shared shim pattern — see HookHandlerTest;
// declared conditionally, another file may load first).
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
