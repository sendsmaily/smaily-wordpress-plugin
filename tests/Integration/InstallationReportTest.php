<?php
/**
 * Integration: the installation report to Smaily's subscription service
 * (CON-290) — a settings save queues one Action Scheduler report, the daily
 * one runs a day after the last, a disconnect and a deactivation each send
 * one event, and a store with no connected Smaily account sends nothing.
 *
 * Nothing here reaches subscription.smaily.com: every report request is
 * answered on `pre_http_request` below, and the suite's guard
 * (Fixtures/mu-plugins) refuses any one this test did not answer.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Deactivation;
use Smaily\Connect\Report\InstallationReport;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\EnvSeed;
use Smaily\Connect\Tests\Integration\Support\PipelineFixture;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class InstallationReportTest extends TestCase {

	/** @var array<int, array{body: array<string, mixed>, authorization: string, raw: string, key_stored: bool}> */
	private array $reports = array();

	/** @var array{response: array{code: int, message: string}, body: string, headers: array<string, string>, cookies: array<int, mixed>} */
	private array $answer;

	/** @var callable */
	private $interceptor;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			self::markTestSkipped( 'Action Scheduler not loaded.' );
		}
		EnvScrub::reset();
		as_unschedule_all_actions( '', array(), InstallationReport::AS_GROUP );
		RestRequestHelper::login_as_admin();

		$this->reports     = array();
		$this->answer      = self::answer( 200, '{"installation_id":"3f8a1c52-7b9e-4d1a-9c3e-2a6f5b8d0e17"}' );
		$this->interceptor = function ( $preempt, $args, $url ) {
			if ( strpos( (string) $url, InstallationReport::ENDPOINT ) === 0 ) {
				$this->reports[] = array(
					'body'          => (array) json_decode( (string) $args['body'], true ),
					'raw'           => (string) $args['body'],
					'authorization' => (string) $args['headers']['Authorization'],
					'key_stored'    => get_option( RecEngineSettings::OPTION_API_KEY, '' ) !== '',
				);
				return $this->answer;
			}
			// The fixture engine's §16 disconnect (EnvSeed's endpoints map).
			if ( strpos( (string) $url, EnvSeed::FIXTURE_BASE_URL ) === 0 ) {
				return self::answer( 200, '{"ok":true,"status":"store_paused","other_connections_remain":false}' );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $this->interceptor, 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->interceptor, 10 );
		as_unschedule_all_actions( '', array(), InstallationReport::AS_GROUP );
		EnvScrub::reset();
		parent::tearDown();
	}

	public function test_a_settings_save_queues_one_report_that_the_action_sends(): void {
		PipelineFixture::seed_credentials();

		self::assertSame( 200, $this->save_subscribers()->get_status() );
		self::assertSame( 200, $this->save_subscribers()->get_status() );

		self::assertCount( 1, $this->pending( array( 'setup_saved', 0 ) ), 'Several saves within five minutes queue one report.' );
		self::assertSame( array(), $this->reports, 'Nothing is sent from the save request.' );

		do_action( InstallationReport::HOOK, 'setup_saved', 0 );

		self::assertCount( 1, $this->reports );
		$report = $this->reports[0];
		self::assertSame( 'Basic ' . base64_encode( 'tester:test-password' ), $report['authorization'] );
		self::assertSame( 'connect_woocommerce', $report['body']['product'] );
		self::assertSame( 'snapshot', $report['body']['event'] );
		self::assertSame( 'setup_saved', $report['body']['trigger'] );
		self::assertSame( 'testsub', $report['body']['smaily_subdomain'] );
		self::assertSame( untrailingslashit( home_url() ), $report['body']['installation_url'] );
		self::assertSame( array( 'wordpress', 'woocommerce' ), array_column( $report['body']['platforms'], 'name' ) );
		self::assertSame( 'marketing', $report['body']['connections'][0]['role'] );
		self::assertSame( 'testsub', $report['body']['connections'][0]['smaily_subdomain'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $report['body']['sent_at'] );

		foreach ( array( 'tester', 'test-password', (string) get_option( 'admin_email' ) ) as $private ) {
			self::assertStringNotContainsString( $private, $report['raw'], 'No credential or admin data in the body.' );
		}

		self::assertCount( 1, $this->pending( array( 'daily', 0 ) ), 'The first report arms the daily one.' );
	}

	public function test_the_daily_report_runs_and_sets_the_next_a_day_later(): void {
		PipelineFixture::seed_credentials();

		( new InstallationReport() )->ensure_scheduled();
		$first = $this->pending( array( 'daily', 0 ) );
		self::assertCount( 1, $first );
		self::assertLessThanOrEqual( time() + DAY_IN_SECONDS, $this->scheduled_at( $first[0] ), 'The first daily report is within 24 hours.' );

		do_action( InstallationReport::HOOK, 'daily', 0 );

		self::assertCount( 1, $this->reports );
		self::assertSame( 'daily', $this->reports[0]['body']['trigger'] );
		$next = $this->pending( array( 'daily', 0 ) );
		self::assertCount( 1, $next );
		self::assertGreaterThanOrEqual( time() + DAY_IN_SECONDS - 60, $this->scheduled_at( $next[0] ) );
	}

	public function test_a_report_the_service_cannot_take_in_now_is_retried_in_the_background(): void {
		PipelineFixture::seed_credentials();
		$this->answer = self::answer( 503, '{"error":"smaily_unreachable","message":"Smaily did not answer."}' );

		do_action( InstallationReport::HOOK, 'daily', 0 );

		self::assertCount( 1, $this->reports );
		self::assertCount( 1, $this->pending( array( 'daily', 1 ) ), 'Retried after 60 s plus jitter.' );
	}

	public function test_a_store_without_a_connected_smaily_account_sends_nothing(): void {
		// A Smaily Transactional account alone never authenticates a report.
		update_option(
			'smly_plus_credentials_transactional',
			array(
				'subdomain' => 'testsub-transactional',
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);

		self::assertSame( 200, $this->save_subscribers()->get_status() );
		( new InstallationReport() )->ensure_scheduled();
		do_action( InstallationReport::HOOK, 'daily', 0 );
		Deactivation::run();
		Activation::run();
		Bootstrap::instance()->register_action_scheduler_jobs();

		self::assertSame( array(), $this->pending( array( 'setup_saved', 0 ) ) );
		self::assertSame( array(), $this->pending( array( 'daily', 0 ) ) );
		self::assertSame( array(), $this->reports );
	}

	public function test_a_campaign_intelligence_disconnect_reports_before_the_key_goes(): void {
		PipelineFixture::seed_credentials();
		EnvSeed::connect();

		$response = RestRequestHelper::post( '/rec-engine/disconnect' );

		self::assertSame( array( 'disconnected' => true ), $response->get_data() );
		self::assertCount( 1, $this->reports, 'One event, no retry.' );
		$report = $this->reports[0];
		self::assertTrue( $report['key_stored'], 'Sent while the engine key is still stored.' );
		self::assertSame( 'disconnected', $report['body']['event'] );
		self::assertArrayNotHasKey( 'trigger', $report['body'] );
		self::assertSame( array( 'testsub' ), array_column( $report['body']['connections'], 'smaily_subdomain' ), 'The Smaily connection stays.' );
		self::assertArrayNotHasKey( 'intelligence', $report['body']['connections'][0] );
		self::assertStringNotContainsString( EnvSeed::FIXTURE_API_KEY, $report['raw'] );
		self::assertSame( '', (string) get_option( RecEngineSettings::OPTION_API_KEY, '' ), 'The key is deleted afterwards.' );
		self::assertSame( array(), $this->pending( array( 'setup_saved', 0 ) ) );
	}

	public function test_deactivating_the_plugin_sends_one_deactivated_event_whatever_the_answer(): void {
		PipelineFixture::seed_credentials();
		EnvSeed::connect();
		$this->answer = self::answer( 500, '{"error":"internal_error"}' );

		try {
			Deactivation::run();
		} finally {
			// Leave the suite the recurring actions it expects.
			Activation::run();
			Bootstrap::instance()->register_action_scheduler_jobs();
		}

		self::assertCount( 1, $this->reports );
		self::assertSame( 'deactivated', $this->reports[0]['body']['event'] );
		self::assertSame( EnvSeed::FIXTURE_TENANT_ID, $this->reports[0]['body']['connections'][0]['intelligence']['tenant_id'] );
		self::assertStringNotContainsString( EnvSeed::FIXTURE_API_KEY, $this->reports[0]['raw'] );
		self::assertSame( array(), $this->pending( array( 'deactivated', 1 ) ), 'An event is never retried.' );
	}

	private function save_subscribers(): \WP_REST_Response {
		return RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'subscribers',
				'data' => array( 'subscriberSyncEnabled' => true ),
			)
		);
	}

	/**
	 * @param array<int, mixed> $args
	 *
	 * @return array<int, int>
	 */
	private function pending( array $args ): array {
		return array_values(
			array_map(
				'intval',
				as_get_scheduled_actions(
					array(
						'hook'     => InstallationReport::HOOK,
						'args'     => $args,
						'group'    => InstallationReport::AS_GROUP,
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'per_page' => 10,
					),
					'ids'
				)
			)
		);
	}

	private function scheduled_at( int $action_id ): int {
		$date = \ActionScheduler::store()->get_date( $action_id );

		return $date->getTimestamp();
	}

	/**
	 * @return array{response: array{code: int, message: string}, body: string, headers: array<string, string>, cookies: array<int, mixed>}
	 */
	private static function answer( int $status, string $body ): array {
		return array(
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'body'     => $body,
			'headers'  => array(),
			'cookies'  => array(),
		);
	}
}
