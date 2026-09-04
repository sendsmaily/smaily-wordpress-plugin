<?php
/**
 * The engine's "this account is deactivated" answer (contract §2:
 * `403 tenant_inactive`) is recognised at the ONE chokepoint every engine
 * call passes through, and recognised from the error CODE alone.
 *
 * The body also carries `tenant_status`, and the contract is explicit that it
 * is a fixed string, never a state discriminator — a suspended tenant and a
 * purged one answer byte-identically. These tests pin that: the same status
 * value appears on a request that must be recorded and on one that must not,
 * so a reader who ever reaches for it breaks a test rather than a merchant.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;

final class ClientTenantInactiveTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_403_tenant_inactive_records_the_refusal(): void {
		$client = $this->client_answering(
			403,
			array(
				'error'         => 'tenant_inactive',
				'message'       => 'This tenant is currently deactivated. Contact engine administrator.',
				'tenant_status' => 'suspended',
			)
		);

		try {
			$client->ping();
			self::fail( 'A 403 must still throw — the caller sees the failure, the plugin also remembers it.' );
		} catch ( ApiException $e ) {
			self::assertSame( 'tenant_inactive', $e->error_code() );
		}

		self::assertSame(
			array( 'tenant_inactive' ),
			$client->refusals,
			'The one chokepoint every engine call passes through must record the refusal.'
		);
	}

	public function test_a_403_with_a_reassuring_tenant_status_is_still_a_refusal(): void {
		// The engine says `suspended` for both a suspension and a GDPR purge,
		// so the value carries no state — but even a body claiming "active"
		// must not talk the plugin out of the code's verdict.
		$client = $this->client_answering(
			403,
			array(
				'error'         => 'tenant_inactive',
				'tenant_status' => 'active',
			)
		);

		try {
			$client->ping();
		} catch ( ApiException $e ) {
			unset( $e );
		}

		self::assertSame(
			array( 'tenant_inactive' ),
			$client->refusals,
			'The decision reads the error code; `tenant_status` is not consulted at all.'
		);
	}

	public function test_a_403_that_is_not_tenant_inactive_records_nothing(): void {
		// Same misleading `tenant_status`, a different error code: a forbidden
		// endpoint is not a deactivated account and must not stop the store's
		// whole sync.
		$client = $this->client_answering(
			403,
			array(
				'error'         => 'forbidden',
				'tenant_status' => 'suspended',
			)
		);

		try {
			$client->ping();
		} catch ( ApiException $e ) {
			unset( $e );
		}

		self::assertSame( array(), $client->refusals );
	}

	public function test_a_401_records_nothing(): void {
		// `401` means "this key is not valid" and has its own remedy
		// (reconnect); only `403 tenant_inactive` means "this account is not".
		$client = $this->client_answering( 401, array( 'error' => 'unauthorized' ) );

		try {
			$client->ping();
		} catch ( ApiException $e ) {
			unset( $e );
		}

		self::assertSame( array(), $client->refusals );
	}

	public function test_a_tenant_inactive_body_on_a_2xx_records_nothing(): void {
		// Defensive: the code alone is not the trigger — it is the code on a
		// 403. A success body that happens to echo the string is still a
		// success.
		$client = $this->client_answering( 200, array( 'ok' => true, 'error' => 'tenant_inactive' ) );

		$client->ping();

		self::assertSame( array(), $client->refusals );
	}

	/**
	 * A Client that answers every request with the given status + body and
	 * captures the refusals it would have persisted, instead of writing them
	 * to wp_options (the write itself is covered in integration, against the
	 * real option store).
	 *
	 * @param array<string, mixed> $body
	 */
	private function client_answering( int $status, array $body ): Client {
		$raw = (string) json_encode( $body );

		Functions\when( 'wp_remote_request' )->justReturn( array( 'body' => $raw ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $raw );

		return new class( 'sk_live', 'https://base.test' ) extends Client {
			/** @var array<int, string> */
			public array $refusals = array();

			protected function record_tenant_refusal( string $error_code ): void {
				$this->refusals[] = $error_code;
			}
		};
	}
}
