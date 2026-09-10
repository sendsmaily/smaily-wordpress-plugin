<?php
/**
 * Deactivation cancels the plugin's Action Scheduler actions (PRO-2433).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Deactivation;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

/**
 * Action Scheduler re-arms a recurring action after every run whether or not
 * the hook still has a listener, so a deactivated store used to keep executing
 * the plugin's 60-second flushers forever (MiuMjau, 2026-09-10: 466k rows in
 * actionscheduler_actions). Deactivation now cancels every action in the
 * plugin's groups; re-activation's init registration brings the recurring
 * ones back and the queue rows they drain were never touched.
 */
final class DeactivationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			self::markTestSkipped( 'Action Scheduler not loaded.' );
		}
	}

	protected function tearDown(): void {
		// Leave the suite the recurring actions it expects.
		Activation::run();
		Bootstrap::instance()->register_action_scheduler_jobs();
		parent::tearDown();
	}

	public function test_deactivation_cancels_every_pending_action_and_reactivation_rearms_them(): void {
		Activation::run();
		Bootstrap::instance()->register_action_scheduler_jobs();
		// A one-off in the same group, the shape an enqueue leaves behind.
		as_enqueue_async_action( CartFlusher::FLUSH_HOOK, array(), CartFlusher::AS_GROUP );

		self::assertTrue( as_has_scheduled_action( EventQueue::FLUSH_HOOK, array(), EventQueue::AS_GROUP ) );
		self::assertTrue( as_has_scheduled_action( 'smly_plus_abandoned_cart', array(), EventQueue::AS_GROUP ) );
		self::assertTrue( as_has_scheduled_action( IngestQueue::FLUSH_HOOK, array(), IngestQueue::AS_GROUP ) );

		Deactivation::run();

		foreach ( Deactivation::AS_GROUPS as $group ) {
			self::assertSame(
				array(),
				as_get_scheduled_actions(
					array(
						'group'  => $group,
						'status' => array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ),
					),
					'ids'
				),
				"No pending action may survive deactivation in group {$group}."
			);
		}
		self::assertFalse( as_has_scheduled_action( CartFlusher::FLUSH_HOOK, array(), CartFlusher::AS_GROUP ), 'One-off async kicks are cancelled too.' );

		// Re-activation: the init registration recreates the recurring set.
		Activation::run();
		Bootstrap::instance()->register_action_scheduler_jobs();

		self::assertTrue( as_has_scheduled_action( EventQueue::FLUSH_HOOK, array(), EventQueue::AS_GROUP ) );
		self::assertTrue( as_has_scheduled_action( 'smly_plus_contact_sync', array(), EventQueue::AS_GROUP ) );
		self::assertTrue( as_has_scheduled_action( OrderFlusher::FLUSH_HOOK, array(), OrderFlusher::AS_GROUP ) );
	}
}
