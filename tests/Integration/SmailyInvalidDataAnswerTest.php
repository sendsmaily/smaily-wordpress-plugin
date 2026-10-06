<?php
/**
 * Integration: a Smaily "invalid data" answer (HTTP 200, code 203) fails the
 * queue row on the first attempt (PRO-3750).
 *
 * Drives the REAL flush hook, the REAL Smaily Client and the REAL queue table
 * with only the Smaily transport faked, so the code is read from the exchange
 * the Client actually records — the seam a unit mock cannot prove.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

final class SmailyInvalidDataAnswerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();

		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'testsub',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	protected function tearDown(): void {
		$bootstrap = \Smaily\Connect\Bootstrap::instance();
		$prop      = new \ReflectionProperty( $bootstrap, 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( $bootstrap, array() );

		parent::tearDown();
	}

	public function test_an_invalid_data_answer_fails_the_row_on_the_first_attempt(): void {
		$row = $this->sync_one_contact_answered_with( 203, 'Invalid data' );

		self::assertSame( EventQueue::STATUS_FAILED, $row['status'] );
		self::assertSame( 0, (int) $row['attempts'], 'No retry attempt is spent on data Smaily rejects again.' );
		self::assertSame( 'permanent_envelope_203: Smaily API returned code 203: Invalid data', $row['last_error'] );
		self::assertStringContainsString( '"code":203', (string) $row['last_response'], 'Smaily\'s answer stays readable in the Event Log.' );
	}

	public function test_another_smaily_error_code_keeps_todays_handling(): void {
		$row = $this->sync_one_contact_answered_with( 216, 'Unknown error' );

		self::assertSame( EventQueue::STATUS_SENT, $row['status'], 'Only code 203 changed; other codes are handled as before.' );
		self::assertSame( 0, (int) $row['attempts'] );
	}

	/**
	 * Enqueue one contact sync, flush it against a transport that answers
	 * HTTP 200 with the given Smaily code, and return the stored row.
	 *
	 * @return array<string, mixed>
	 */
	private function sync_one_contact_answered_with( int $code, string $message ): array {
		global $wpdb;

		$id = ( new EventQueue() )->enqueue(
			HookHandler::EVENT_CONTACT_SYNC,
			'pro3750',
			array(
				'email'  => 'invalid-data@example.test',
				'fields' => array( 'first_name' => 'Mari' ),
			)
		);
		self::assertIsInt( $id );

		$fake = static function () use ( $code, $message ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'code'    => $code,
						'message' => $message,
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 0 );
		try {
			do_action( EventQueue::FLUSH_HOOK );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smly_plus_event_queue WHERE id = %d", $id ),
			ARRAY_A
		);
		self::assertIsArray( $row );

		return $row;
	}
}
