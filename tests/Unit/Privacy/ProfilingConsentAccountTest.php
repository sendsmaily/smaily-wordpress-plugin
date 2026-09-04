<?php
/**
 * Unit: ProfilingConsentAccount ((a).2) — the checkbox → opt-in/out mapping.
 *
 * @package Smaily\Connect\Tests\Unit\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Privacy\ProfilingConsentAccount;

final class ProfilingConsentAccountTest extends TestCase {

	/** A recording double — ProfilingConsent is non-final by design. */
	private function spy(): ProfilingConsent {
		return new class() extends ProfilingConsent {
			/** @var array<int, array{0: string, 1: string}> */
			public array $calls = array();

			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function __construct() {} // Skip the real deps — we only record.

			public function opt_in( string $email ): void {
				$this->calls[] = array( 'in', $email );
			}

			public function opt_out( string $email ): void {
				$this->calls[] = array( 'out', $email );
			}
		};
	}

	public function test_checked_opts_in(): void {
		$spy = $this->spy();
		( new ProfilingConsentAccount( $spy ) )->apply( 'a@example.com', true );

		self::assertSame( array( array( 'in', 'a@example.com' ) ), $spy->calls );
	}

	public function test_unchecked_opts_out(): void {
		$spy = $this->spy();
		( new ProfilingConsentAccount( $spy ) )->apply( 'a@example.com', false );

		self::assertSame( array( array( 'out', 'a@example.com' ) ), $spy->calls );
	}

	public function test_empty_email_is_a_no_op(): void {
		$spy = $this->spy();
		( new ProfilingConsentAccount( $spy ) )->apply( '', false );

		self::assertSame( array(), $spy->calls );
	}
}
