<?php
/**
 * Unit: RefusalReason — the classification table behind every "why did Smaily
 * refuse?" message (PRO-1686). The cases mirror the live probe of 2026-08-04.
 *
 * @package Smaily\Connect\Tests\Unit\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\RefusalReason;

final class RefusalReasonTest extends TestCase {

	public function test_smaily_code_227_is_the_package(): void {
		// The freemium answer, on every endpoint the plugin uses.
		self::assertSame(
			RefusalReason::PLAN_BLOCKED,
			RefusalReason::classify( new ApiException( 'HTTP 403 (Smaily code 227)', 403, null, 227 ) )
		);
	}

	public function test_a_4xx_without_the_package_code_is_the_credentials(): void {
		foreach ( array( 401, 403, 404, 422 ) as $status ) {
			self::assertSame(
				RefusalReason::CREDENTIALS_REJECTED,
				RefusalReason::classify( new ApiException( 'refused', $status ) ),
				'HTTP ' . $status . ' says the request was refused, not that Smaily is down.'
			);
		}
	}

	public function test_a_transport_error_a_429_and_a_5xx_are_the_only_unreachable_cases(): void {
		foreach ( array( 0, 429, 500, 502, 503 ) as $status ) {
			self::assertSame(
				RefusalReason::UNREACHABLE,
				RefusalReason::classify( new ApiException( 'no answer', $status ) ),
				'HTTP ' . $status . ' may pass on its own — the only case worth telling a merchant to wait out.'
			);
		}
	}

	public function test_the_package_code_wins_over_the_status_it_arrived_with(): void {
		// Whatever HTTP status Smaily wraps it in, 227 means the package.
		self::assertSame(
			RefusalReason::PLAN_BLOCKED,
			RefusalReason::classify( new ApiException( 'refused', 200, null, 227 ) )
		);
	}
}
