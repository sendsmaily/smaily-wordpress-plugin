<?php
/**
 * The discount code of the queue row a flusher is posting.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Integrations\WooCommerce\AutomationDiscount;

/**
 * The per-row part of an automation's discount code (CON-199) that Flusher
 * and CartFlusher share.
 *
 * fields() runs as the router's `before_send` — once a workflow is found,
 * just before the post — and gets the row's code from AutomationDiscount (a
 * new one, or the address's unused one sent again). The code is written into
 * the row's payload before it is returned, so a retry carries the code its
 * first attempt got and makes no second coupon.
 * When the email goes without its code, the reason is kept until the
 * flusher stores the row's exchange: noted() adds it to the reply as the
 * Event Log `note`, and forgets it for the next row.
 */
final class DiscountCodeIssuer {

	private EventQueue $queue;
	private AutomationDiscount $discounts;

	/** Why the row being posted went without its discount code, or ''. */
	private string $note = '';

	public function __construct( EventQueue $queue, AutomationDiscount $discounts ) {
		$this->queue     = $queue;
		$this->discounts = $discounts;
	}

	/**
	 * The discount code fields for the row about to be posted.
	 *
	 * @param array<string, mixed> $payload The row's decoded payload.
	 *
	 * @return array<string, string>
	 */
	public function fields( int $row_id, string $trigger, array $payload ): array {
		$issued     = $this->discounts->issue( $trigger, $payload );
		$this->note = $issued['note'];

		if ( $issued['fields'] !== array() ) {
			$fields            = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
			$payload['fields'] = array_merge( $fields, $issued['fields'] );
			$this->queue->update_payload( $row_id, $payload );
		}

		return $issued['fields'];
	}

	/**
	 * The row's reply with the reason it went without its code as `note`
	 * (when there is one and a reply to carry it), and the reason forgotten
	 * for the next row.
	 *
	 * @param mixed $response The reply of the row's exchange, or null.
	 *
	 * @return mixed
	 */
	public function noted( $response ) {
		$note       = $this->note;
		$this->note = '';

		if ( $note !== '' && is_array( $response ) ) {
			$response['note'] = $note;
		}

		return $response;
	}
}
