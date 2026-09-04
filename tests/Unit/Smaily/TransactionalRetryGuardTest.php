<?php
/**
 * TransactionalRetryGuard tests (PRO-1733) — which failed transactional rows
 * the Event Log may re-drive, and why the rest are refused.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\TransactionalFlusher;
use Smaily\Connect\Smaily\TransactionalRetryGuard;

final class TransactionalRetryGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_marketing_row_is_never_refused(): void {
		self::assertSame( '', TransactionalRetryGuard::refusal_reason( 'automation.abandoned_cart', '{}', true ) );
		self::assertSame( '', TransactionalRetryGuard::refusal_reason( 'contact.sync', '{}', false ) );
	}

	public function test_an_order_confirmation_is_refused_because_the_wc_email_went_out(): void {
		self::assertSame(
			TransactionalRetryGuard::REASON_WC_EMAIL_SENT,
			TransactionalRetryGuard::refusal_reason(
				TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
				'{"to_status":""}',
				true
			)
		);
	}

	public function test_a_shipping_confirmation_into_completed_is_refused(): void {
		// `completed` is the one shipped status WooCommerce has a native email
		// for, so fail-open re-fired it — a retry would be the second one.
		self::assertSame(
			TransactionalRetryGuard::REASON_WC_EMAIL_SENT,
			TransactionalRetryGuard::refusal_reason(
				TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION,
				'{"to_status":"completed"}',
				true
			)
		);
	}

	public function test_a_shipping_confirmation_on_a_merchant_defined_status_may_be_retried(): void {
		// No native WooCommerce email exists for a custom shipped status, so
		// nothing was suppressed and fail-open sent nothing — the shopper has
		// no confirmation at all and the retry is the only way to one.
		self::assertSame(
			'',
			TransactionalRetryGuard::refusal_reason(
				TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION,
				'{"to_status":"shipped"}',
				true
			)
		);
	}

	public function test_a_shipping_row_with_no_recorded_status_is_refused(): void {
		// An old row (or an undecodable payload) can't prove nothing was sent,
		// so it takes the safe side: never risk a second confirmation.
		self::assertSame(
			TransactionalRetryGuard::REASON_WC_EMAIL_SENT,
			TransactionalRetryGuard::refusal_reason( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION, '', true )
		);
		self::assertSame(
			TransactionalRetryGuard::REASON_WC_EMAIL_SENT,
			TransactionalRetryGuard::refusal_reason( TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION, 'not-json', true )
		);
	}

	public function test_a_row_whose_order_is_gone_is_refused_with_its_own_reason(): void {
		// Existence is resolved by the caller (one batched lookup) and handed
		// in — the guard itself does no I/O.
		self::assertSame(
			TransactionalRetryGuard::REASON_ORDER_MISSING,
			TransactionalRetryGuard::refusal_reason(
				TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION,
				'{"to_status":"shipped"}',
				false
			)
		);
	}

	public function test_each_refusal_carries_its_own_merchant_message(): void {
		self::assertStringContainsString(
			'standard WooCommerce email',
			TransactionalRetryGuard::message( TransactionalRetryGuard::REASON_WC_EMAIL_SENT )
		);
		self::assertStringContainsString(
			'no longer exists',
			TransactionalRetryGuard::message( TransactionalRetryGuard::REASON_ORDER_MISSING )
		);
	}
}
