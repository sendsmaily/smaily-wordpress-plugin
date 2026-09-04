<?php
/**
 * BeaconEndpoint::validate_batch — the pure abuse-filter logic (no WP).
 *
 * The rate-limit + gate + engine-forward paths need a live WP REST stack and
 * are covered by the integration suite (RecEngineBrowseProxyTest). This unit
 * test pins the validation rules that decide which batches even reach the
 * engine: the §6 event_type allowlist, the event_id requirement (browse has
 * no natural key), the 100-event cap, and the field whitelist.
 *
 * @package Smaily\Connect\Tests\Unit\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\REST;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\REST\BeaconEndpoint;

final class BeaconEndpointTest extends TestCase {

	public function test_valid_batch_passes_and_is_field_whitelisted(): void {
		$result = BeaconEndpoint::validate_batch(
			array(
				array(
					'event_id'   => 'e1',
					'event_type' => 'product_view',
					'sku'        => 'ACA-1',
					'event_ts'   => '2026-06-06T10:00:00Z',
					'session_id' => 'sess-1',
					'evil_field' => 'DROP ME',
				),
			)
		);

		self::assertTrue( $result['valid'] );
		// Output follows the EVENT_FIELDS whitelist order, not the input order.
		self::assertSame(
			array(
				array(
					'event_id'   => 'e1',
					'session_id' => 'sess-1',
					'event_type' => 'product_view',
					'sku'        => 'ACA-1',
					'event_ts'   => '2026-06-06T10:00:00Z',
				),
			),
			$result['events'],
			'Unknown keys (evil_field) are dropped; the §6 whitelist is preserved.'
		);
	}

	public function test_client_supplied_customer_email_is_stripped(): void {
		// PRO-1486: a client-supplied customer_email is spoofable (arbitrary
		// attribution on anonymous browsing, or probing another contact's
		// profiling opt-out state by guessing emails) — it must never survive
		// the whitelist. The only sanctioned source is the server-side
		// attach_logged_in_identity() injection, which runs AFTER this filter.
		$result = BeaconEndpoint::validate_batch(
			array(
				array(
					'event_id'       => 'e1',
					'event_type'     => 'product_view',
					'customer_email' => 'spoofed@example.com',
				),
			)
		);

		self::assertTrue( $result['valid'] );
		self::assertArrayNotHasKey( 'customer_email', $result['events'][0] );
	}

	public function test_deprecated_attribution_hints_are_stripped(): void {
		// PRO-1712: contract v1.7.0 deprecated smaily_rec_id / smaily_ctx as
		// browse-event attribution hints to accept-and-ignore (the engine dropped
		// the columns and the fallback they fed), so forwarding a client-supplied
		// value buys nothing and leaves the PRO-1486 spoofing surface open. Real
		// attribution rides the ORDER path, not the browse event.
		$result = BeaconEndpoint::validate_batch(
			array(
				array(
					'event_id'      => 'e1',
					'event_type'    => 'product_view',
					'smaily_rec_id' => 'aa11bb22-cc33-dd44-ee55-ff6677889900',
					'smaily_ctx'    => 'spoofed_campaign',
				),
			)
		);

		self::assertTrue( $result['valid'] );
		self::assertArrayNotHasKey( 'smaily_rec_id', $result['events'][0] );
		self::assertArrayNotHasKey( 'smaily_ctx', $result['events'][0] );
	}

	public function test_product_id_is_dropped_by_the_whitelist(): void {
		// product_id (PRO-1390) is a proxy-internal field resolve_cart_product_skus()
		// consumes BEFORE validate_batch runs; it must never reach the engine even
		// if a hand-crafted request still carries it at this point.
		$result = BeaconEndpoint::validate_batch(
			array(
				array(
					'event_id'   => 'e1',
					'event_type' => 'cart_add',
					'sku'        => 'woo-42',
					'product_id' => '42',
				),
			)
		);

		self::assertTrue( $result['valid'] );
		self::assertArrayNotHasKey( 'product_id', $result['events'][0] );
	}

	public function test_all_nine_event_types_are_accepted(): void {
		foreach ( BeaconEndpoint::EVENT_TYPES as $type ) {
			$result = BeaconEndpoint::validate_batch(
				array( array( 'event_id' => 'e-' . $type, 'event_type' => $type ) )
			);
			self::assertTrue( $result['valid'], "Event type {$type} must be accepted." );
		}
		self::assertCount( 9, BeaconEndpoint::EVENT_TYPES, 'The §6 enum is exactly 9 types.' );
	}

	public function test_empty_batch_is_rejected(): void {
		$result = BeaconEndpoint::validate_batch( array() );
		self::assertFalse( $result['valid'] );
		self::assertSame( 'events', $result['field'] );
	}

	public function test_batch_over_one_hundred_is_rejected(): void {
		$events = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$events[] = array( 'event_id' => 'e' . $i, 'event_type' => 'product_view' );
		}
		$result = BeaconEndpoint::validate_batch( $events );
		self::assertFalse( $result['valid'] );
		self::assertSame( 'events', $result['field'] );
	}

	public function test_exactly_one_hundred_is_allowed(): void {
		$events = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$events[] = array( 'event_id' => 'e' . $i, 'event_type' => 'product_view' );
		}
		$result = BeaconEndpoint::validate_batch( $events );
		self::assertTrue( $result['valid'] );
		self::assertCount( 100, $result['events'] );
	}

	public function test_missing_event_id_rejects_the_whole_batch(): void {
		$result = BeaconEndpoint::validate_batch(
			array(
				array( 'event_id' => 'e1', 'event_type' => 'product_view' ),
				array( 'event_type' => 'cart_add' ), // no event_id
			)
		);
		self::assertFalse( $result['valid'] );
		self::assertSame( 'event_id', $result['field'], 'Browse has no natural key — every event needs an id.' );
		self::assertSame( array(), $result['events'] );
	}

	public function test_blank_event_id_is_rejected(): void {
		$result = BeaconEndpoint::validate_batch(
			array( array( 'event_id' => '   ', 'event_type' => 'product_view' ) )
		);
		self::assertFalse( $result['valid'] );
		self::assertSame( 'event_id', $result['field'] );
	}

	public function test_unknown_event_type_rejects_the_whole_batch(): void {
		$result = BeaconEndpoint::validate_batch(
			array(
				array( 'event_id' => 'e1', 'event_type' => 'product_view' ),
				array( 'event_id' => 'e2', 'event_type' => 'hack_attempt' ),
			)
		);
		self::assertFalse( $result['valid'] );
		self::assertSame( 'event_type', $result['field'], 'Our client never emits a non-§6 type → tampering → 400.' );
	}

	public function test_non_array_event_is_rejected(): void {
		$result = BeaconEndpoint::validate_batch( array( 'not-an-object' ) );
		self::assertFalse( $result['valid'] );
		self::assertSame( 'events', $result['field'] );
	}
}
