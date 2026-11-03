<?php
/**
 * The login's background action in the integration suite (CON-268): find
 * what a login queued, run it through Action Scheduler, and clear it.
 *
 * @package Smaily\Connect\Tests\Integration\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

use Smaily\Connect\Smaily\RecEngine\LoginMerge;

/**
 * Runs the queued action the way Action Scheduler's own runner does
 * (`process_action()`), so the plugin's registered callback
 * (`Bootstrap::on_login_merge()`, its real client, profiling gate and payload
 * builder) does the sending.
 */
final class LoginMergeQueue {

	/**
	 * The args of every pending login action, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function pending_args(): array {
		$args = array();
		foreach ( self::pending_actions() as $action ) {
			$args[] = $action->get_args();
		}
		return $args;
	}

	/**
	 * Run every login action that is due now (a retry queued for later stays
	 * pending). Returns how many ran.
	 */
	public static function run_due(): int {
		$ran = 0;
		$ids = as_get_scheduled_actions(
			array(
				'hook'         => LoginMerge::HOOK,
				'group'        => LoginMerge::AS_GROUP,
				'status'       => \ActionScheduler_Store::STATUS_PENDING,
				'date'         => as_get_datetime_object( time() + 1 ), // AS reads only a DateTime here.
				'date_compare' => '<=',
				'per_page'     => -1,
			),
			'ids'
		);
		foreach ( $ids as $id ) {
			\ActionScheduler::runner()->process_action( (int) $id, 'Smaily Connect integration test' );
			++$ran;
		}
		return $ran;
	}

	/** Cancel every login action, pending or due. */
	public static function forget(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// By group: AS reads empty args with a hook as "args == []".
			as_unschedule_all_actions( '', array(), LoginMerge::AS_GROUP );
		}
	}

	/**
	 * @return array<int, \ActionScheduler_Action>
	 */
	private static function pending_actions(): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => LoginMerge::HOOK,
				'group'    => LoginMerge::AS_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'orderby'  => 'date',
				'order'    => 'ASC',
				'per_page' => -1,
			)
		);
		return array_values( array_filter( $actions, static fn ( $a ): bool => $a instanceof \ActionScheduler_Action ) );
	}

	private function __construct() {
		// Static-only helper.
	}
}
