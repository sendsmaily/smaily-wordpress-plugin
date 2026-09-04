<?php
/**
 * ApiException tests — HTTP status in the code, canonical error_code,
 * request_id, and the F3-18 / D6 details + errors body preservation.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\RecEngine\ApiException;

final class ApiExceptionTest extends TestCase {

	public function test_carries_status_error_code_and_request_id(): void {
		$e = new ApiException(
			400,
			'validation_failed',
			'One or more fields failed validation',
			array( 'request_id' => 'req_abc' )
		);

		self::assertSame( 400, $e->getCode() );
		self::assertSame( 'validation_failed', $e->error_code() );
		self::assertSame( 'req_abc', $e->request_id() );
	}

	public function test_preserves_details_from_validation_body(): void {
		// A wrapper-level 400 returns details.fieldErrors explaining the reject.
		$body = array(
			'error'   => 'validation_failed',
			'details' => array(
				'formErrors'  => array(),
				'fieldErrors' => array( 'customers' => array( 'Required' ) ),
			),
		);

		$e = new ApiException( 400, 'validation_failed', 'bad', $body );

		self::assertSame(
			array( 'customers' => array( 'Required' ) ),
			$e->details()['fieldErrors'],
			'details must be preserved so callers can surface why the batch was rejected (F3-18).'
		);
	}

	public function test_preserves_errors_array_when_present(): void {
		$body = array(
			'errors' => array(
				array( 'index' => 3, 'email' => 'bad', 'field' => 'email', 'message' => 'Invalid email' ),
			),
		);

		$e = new ApiException( 400, 'validation_failed', 'bad', $body );

		self::assertCount( 1, $e->errors() );
		self::assertSame( 3, $e->errors()[0]['index'] );
	}

	public function test_details_and_errors_default_to_empty_when_absent(): void {
		$e = new ApiException( 500, 'server_error', 'boom' );

		self::assertSame( array(), $e->details() );
		self::assertSame( array(), $e->errors() );
		self::assertNull( $e->request_id() );
	}

	public function test_non_array_details_or_errors_is_ignored(): void {
		$e = new ApiException(
			400,
			'validation_failed',
			'bad',
			array( 'details' => 'oops-a-string', 'errors' => 'also-not-an-array' )
		);

		self::assertSame( array(), $e->details() );
		self::assertSame( array(), $e->errors() );
	}
}
