<?php
/**
 * TransactionalRetryGuard tests — which failed transactional rows the Event
 * Log may re-drive and why the rest are refused (PRO-1733), and which sent
 * ones it may deliberately send again (PRO-2324).
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

	public function test_a_sent_confirmation_may_be_sent_again(): void {
		// PRO-2324: `sent` is written only after Smaily replied {code:101},
		// so this row IS the proof the shopper got a Smaily-sent email — the
		// merchant may deliberately follow it with a second one.
		self::assertTrue(
			TransactionalRetryGuard::resendable(
				TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION,
				'sent',
				true
			)
		);
		self::assertTrue(
			TransactionalRetryGuard::resendable(
				TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
				'sent',
				true
			)
		);
	}

	public function test_only_a_sent_transactional_row_on_a_live_order_may_be_sent_again(): void {
		// A failed row never qualifies — including the fail-open case, where
		// WooCommerce's own email is what went out; that row is `failed`, and
		// whether it may be RE-driven is refusal_reason()'s question.
		self::assertFalse(
			TransactionalRetryGuard::resendable(
				TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
				'failed',
				true
			),
			'A failed confirmation keeps Retry; "Send again" is for one that reached the shopper.'
		);
		self::assertFalse(
			TransactionalRetryGuard::resendable(
				TransactionalFlusher::EVENT_TYPE_ORDER_CONFIRMATION,
				'pending',
				true
			)
		);
		self::assertFalse(
			TransactionalRetryGuard::resendable(
				TransactionalFlusher::EVENT_TYPE_SHIPPING_CONFIRMATION,
				'sent',
				false
			),
			'A deleted order has nothing to rebuild the email from.'
		);
		// Ingest / contact-sync / cart rows are not confirmations at all.
		self::assertFalse( TransactionalRetryGuard::resendable( 'contact.sync', 'sent', true ) );
		self::assertFalse( TransactionalRetryGuard::resendable( 'automation.abandoned_cart', 'sent', true ) );
		self::assertFalse( TransactionalRetryGuard::resendable( 'order.upsert', 'sent', true ) );
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
