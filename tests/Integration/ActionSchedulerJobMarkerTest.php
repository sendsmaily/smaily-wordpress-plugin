<?php
/**
 * The recurring Action Scheduler set is verified at most once an hour (PRO-2437).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\DB\QueueJanitor;
use Smaily\Connect\Deactivation;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

/**
 * `init` runs on every request, and verifying the recurring set costs eleven
 * SELECTs with a group JOIN on a table that had grown to 466k rows on the
 * pilot store. The set only changes on activation, upgrade or deactivation,
 * so the check is cached behind a timestamp marker those three clear — and a
 * job cancelled by anything else heals within the hour.
 */
final class ActionSchedulerJobMarkerTest extends TestCase {

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

	public function test_a_job_cancelled_elsewhere_is_re_armed_once_the_marker_expires(): void {
		Bootstrap::instance()->register_action_scheduler_jobs();
		self::assertTrue( as_has_scheduled_action( QueueJanitor::HOOK, array(), QueueJanitor::AS_GROUP ) );

		as_unschedule_all_actions( QueueJanitor::HOOK, array(), QueueJanitor::AS_GROUP );
		self::assertFalse( as_has_scheduled_action( QueueJanitor::HOOK, array(), QueueJanitor::AS_GROUP ) );

		// Within the hour the registration trusts its own verification.
		Bootstrap::instance()->register_action_scheduler_jobs();
		self::assertFalse(
			as_has_scheduled_action( QueueJanitor::HOOK, array(), QueueJanitor::AS_GROUP ),
			'A fresh marker must skip the existence checks entirely.'
		);

		// Once it expires, the whole set is verified again and the gap closed.
		update_option( Bootstrap::OPTION_AS_JOBS_VERIFIED, time() - Bootstrap::AS_JOBS_VERIFIED_TTL - 1, false );
		Bootstrap::instance()->register_action_scheduler_jobs();

		self::assertTrue( as_has_scheduled_action( QueueJanitor::HOOK, array(), QueueJanitor::AS_GROUP ) );
		self::assertGreaterThan(
			time() - 60,
			(int) get_option( Bootstrap::OPTION_AS_JOBS_VERIFIED, 0 ),
			'A verification pass re-stamps the marker.'
		);
	}

	public function test_activation_and_deactivation_both_drop_the_marker(): void {
		Bootstrap::instance()->register_action_scheduler_jobs();
		self::assertNotSame( 0, (int) get_option( Bootstrap::OPTION_AS_JOBS_VERIFIED, 0 ) );

		Deactivation::run();
		self::assertSame(
			0,
			(int) get_option( Bootstrap::OPTION_AS_JOBS_VERIFIED, 0 ),
			'Deactivation cancelled the actions, so the verification no longer holds.'
		);

		Bootstrap::instance()->register_action_scheduler_jobs();
		self::assertTrue( as_has_scheduled_action( QueueJanitor::HOOK, array(), QueueJanitor::AS_GROUP ) );

		Activation::run();
		self::assertSame(
			0,
			(int) get_option( Bootstrap::OPTION_AS_JOBS_VERIFIED, 0 ),
			'Activation is the upgrade path, so a new recurring job arms on the next request.'
		);
	}
}
