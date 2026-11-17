<?php
/**
 * The payment reminder's wait in the integration suite (CON-328): find the
 * Action Scheduler actions a checkout scheduled, run them as if the wait
 * were over, and clear them.
 *
 * @package Smaily\Connect\Tests\Integration\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

use Smaily\Connect\Smaily\PaymentReminderCheck;

/**
 * Runs each waiting action the way Action Scheduler's own runner does
 * (`process_action()`), so the plugin's registered callback
 * (`Bootstrap::on_payment_reminder_check()`) does the work — the same pattern
 * as LoginMergeQueue.
 */
final class PaymentReminderQueue {

	/**
	 * The pending actions, oldest first.
	 *
	 * @return array<int, \ActionScheduler_Action>
	 */
	public static function pending(): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => PaymentReminderCheck::HOOK,
				'group'    => PaymentReminderCheck::AS_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'orderby'  => 'date',
				'order'    => 'ASC',
				'per_page' => -1,
			)
		);
		return array_values( array_filter( $actions, static fn ( $a ): bool => $a instanceof \ActionScheduler_Action ) );
	}

	/**
	 * Run every pending action now, whenever it is scheduled for — the test
	 * does not wait the merchant's minutes. Returns how many ran.
	 */
	public static function run_all(): int {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => PaymentReminderCheck::HOOK,
				'group'    => PaymentReminderCheck::AS_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		foreach ( $ids as $id ) {
			\ActionScheduler::runner()->process_action( (int) $id, 'Smaily Connect integration test' );
		}
		return count( $ids );
	}

	/** Cancel every waiting action. */
	public static function forget(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// By group: AS reads empty args with a hook as "args == []".
			as_unschedule_all_actions( '', array(), PaymentReminderCheck::AS_GROUP );
		}
	}

	private function __construct() {
		// Static-only helper.
	}
}
