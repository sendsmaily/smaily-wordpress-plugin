<?php
/**
 * Queues a deliberate second transactional confirmation (PRO-2324).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * The Event Log's "Send again" action, one step removed from the REST
 * route so the route stays a read model with a thin write on top.
 *
 * Everything a first confirmation goes through, this goes through too —
 * TransactionalGate (the feature and its trigger must still be on, the
 * mapping must still resolve, the credentials must still be complete) and
 * TransactionalPayloadBuilder (the merge tags are rebuilt from the order as
 * it is NOW, which is the whole point: a corrected tracking number is what
 * the merchant wants the shopper to receive). The one thing it skips is the
 * once-per-order-per-type meta guard — see enqueue_resend().
 *
 * Not final: tests inject gate/builder/flusher doubles.
 */
class TransactionalResend {

	/** The order behind the row is gone — there is nothing to rebuild. */
	public const ERROR_ORDER_MISSING = 'order_missing';

	/** The trigger is no longer sending (toggle off, mapping or credentials gone). */
	public const ERROR_SENDING_DISABLED = 'transactional_sending_disabled';

	/** The queue insert failed, or the order has no recipient address. */
	public const ERROR_ENQUEUE_FAILED = 'resend_enqueue_failed';

	private TransactionalGate $gate;
	private TransactionalPayloadBuilder $builder;
	private TransactionalFlusher $flusher;

	public function __construct( TransactionalGate $gate, TransactionalPayloadBuilder $builder, TransactionalFlusher $flusher ) {
		$this->gate    = $gate;
		$this->builder = $builder;
		$this->flusher = $flusher;
	}

	/**
	 * @param string $to_status The status carried by the row being repeated.
	 *
	 * @return array{id: ?int, error: string} The new queue row's id, or an
	 *                                        ERROR_* code saying why not.
	 */
	public function resend( int $order_id, string $event_type, string $to_status ): array {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return array(
				'id'    => null,
				'error' => self::ERROR_ORDER_MISSING,
			);
		}

		$trigger_type = TransactionalGate::trigger_type_for_event( $event_type );
		$match        = $this->gate->resolve_if_open( $trigger_type );
		if ( $match === null ) {
			return array(
				'id'    => null,
				'error' => self::ERROR_SENDING_DISABLED,
			);
		}

		$id = $this->flusher->enqueue_resend( $trigger_type, $order, $match, $this->builder->build( $order ), $to_status );

		return array(
			'id'    => $id,
			'error' => $id === null ? self::ERROR_ENQUEUE_FAILED : '',
		);
	}

	/**
	 * The merchant-readable reason a "Send again" was turned down (PRO-2369),
	 * worded here beside the ERROR_* codes it maps — the same way
	 * TransactionalRetryGuard keeps the retry refusal's wording beside its
	 * reasons. The admin banner has nothing else to show: without a sentence
	 * it falls back to the raw transport failure ("POST … → 409"), which says
	 * nothing about why the plugin turned the request down.
	 */
	public static function message( string $error ): string {
		if ( $error === self::ERROR_SENDING_DISABLED ) {
			return __( 'Transactional emails are switched off for this confirmation, or its Smaily workflow is no longer mapped — so nothing can be sent.', 'smaily-connect' );
		}

		if ( $error === self::ERROR_ENQUEUE_FAILED ) {
			return __( 'The second confirmation could not be queued. Please try again.', 'smaily-connect' );
		}

		return __( 'This confirmation can no longer be sent again. Refresh the event log to see the row as it is now.', 'smaily-connect' );
	}
}
