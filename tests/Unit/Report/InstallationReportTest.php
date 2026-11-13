<?php
/**
 * InstallationReport — when the installation report goes, how it is sent,
 * and what each answer does (CON-290, contract installation-report-v1). No
 * request leaves the test: wp_remote_post is a stub.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Report;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Report\InstallationReport;
use Smaily\Connect\Report\ReportBuilder;
use Smaily\Connect\Settings\CredentialSet;

final class InstallationReportTest extends TestCase {

	private const NOW = 1794571200; // 2026-11-13 12:00:00 UTC.

	/** @var array<int, array{at: int, args: array<int, mixed>}> pending report actions */
	private array $scheduled = array();

	/** @var array<int, array{url: string, args: array<string, mixed>}> */
	private array $posts = array();

	/** @var array<string, mixed>|\WP_Error the stubbed answer */
	private $answer;

	private bool $connected = true;

	private bool $daily_exists = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->answer = self::answer( 200, '{"installation_id":"3f8a1c52-7b9e-4d1a-9c3e-2a6f5b8d0e17"}' );

		$scheduled = &$this->scheduled;
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( int $at, string $hook, array $args ) use ( &$scheduled ): int {
				$scheduled[] = array(
					'at'   => $at,
					'args' => $args,
				);
				return count( $scheduled );
			}
		);
		Functions\when( 'as_get_scheduled_actions' )->alias(
			static function ( array $query ) use ( &$scheduled ): array {
				return array_keys( array_filter( $scheduled, static fn ( array $action ): bool => $action['args'] === $query['args'] ) );
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( string $hook, array $args ) use ( &$scheduled ): void {
				$scheduled = array_values( array_filter( $scheduled, static fn ( array $action ): bool => $action['args'] !== $args ) );
			}
		);
		$daily_exists = &$this->daily_exists;
		Functions\when( 'as_has_scheduled_action' )->alias(
			static function () use ( &$daily_exists ): bool {
				return $daily_exists;
			}
		);

		$posts  = &$this->posts;
		$answer = &$this->answer;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ) use ( &$posts, &$answer ) {
				$posts[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $answer;
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( array $r ): int => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $r ): string => $r['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn ( array $r, string $name ) => $r['headers'][ $name ] ?? '' );
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_settings_save_schedules_one_report_five_minutes_on(): void {
		$report = $this->report();
		$report->setup_saved();
		$report->setup_saved();

		self::assertSame(
			array(
				array(
					'at'   => self::NOW + 300,
					'args' => array( 'setup_saved', 0 ),
				),
			),
			$this->scheduled,
			'Several saves within five minutes send one report.'
		);
		self::assertSame( array(), $this->posts, 'Never sent from the save request itself.' );
	}

	public function test_nothing_is_scheduled_or_sent_without_a_connected_smaily_account(): void {
		$this->connected = false;
		$report          = $this->report();

		$report->setup_saved();
		$report->ensure_scheduled();
		$report->run( 'daily', 0 );
		$report->send_event( ReportBuilder::EVENT_DEACTIVATED );

		self::assertSame( array(), $this->scheduled );
		self::assertSame( array(), $this->posts );
	}

	public function test_the_first_daily_report_is_at_a_random_moment_within_a_day(): void {
		$this->report( 4321 )->ensure_scheduled();

		self::assertSame(
			array(
				array(
					'at'   => self::NOW + 4321,
					'args' => array( 'daily', 0 ),
				),
			),
			$this->scheduled
		);

		$this->scheduled    = array();
		$this->daily_exists = true;
		$this->report()->ensure_scheduled();
		self::assertSame( array(), $this->scheduled, 'A store that has one gets no second.' );
	}

	public function test_a_daily_report_is_posted_once_as_the_contract_says_and_sets_the_next_a_day_on(): void {
		$this->scheduled[] = array(
			'at'   => self::NOW,
			'args' => array( 'daily', 0 ),
		);

		$this->report()->run( 'daily', 0 );

		self::assertCount( 1, $this->posts );
		$post = $this->posts[0];
		self::assertSame( 'https://subscription.smaily.com/v1/reports', $post['url'] );
		self::assertSame( 'Basic ' . base64_encode( 'api-user:api-pass' ), $post['args']['headers']['Authorization'] );
		self::assertSame( 'application/json; charset=utf-8', $post['args']['headers']['Content-Type'] );
		self::assertSame( 5, $post['args']['timeout'] );
		self::assertSame( 0, $post['args']['redirection'], 'A redirect is never followed.' );
		self::assertTrue( $post['args']['sslverify'] );
		self::assertSame( array( 'event' => 'snapshot', 'trigger' => 'daily' ), json_decode( $post['args']['body'], true ) );
		self::assertStringNotContainsString( 'api-pass', $post['args']['body'] );

		self::assertSame(
			array(
				array(
					'at'   => self::NOW + DAY_IN_SECONDS,
					'args' => array( 'daily', 0 ),
				),
			),
			$this->scheduled,
			'The next daily report is 24 hours after this attempt; a 200 is not retried.'
		);
	}

	public function test_a_setup_saved_report_moves_a_waiting_daily_report_to_a_day_after_it(): void {
		$this->scheduled[] = array(
			'at'   => self::NOW + 600,
			'args' => array( 'daily', 0 ),
		);

		$this->report()->run( 'setup_saved', 0 );

		self::assertSame( array( array( 'at' => self::NOW + DAY_IN_SECONDS, 'args' => array( 'daily', 0 ) ) ), $this->scheduled );
	}

	public function test_a_store_whose_first_report_is_a_setup_save_gets_its_first_daily_at_random(): void {
		$this->report( 777 )->run( 'setup_saved', 0 );

		self::assertSame( array( array( 'at' => self::NOW + 777, 'args' => array( 'daily', 0 ) ) ), $this->scheduled );
	}

	/**
	 * @dataProvider cannot_take_in_now
	 *
	 * @param array<string, mixed>|\WP_Error $answer
	 */
	public function test_an_answer_that_cannot_be_taken_in_now_is_retried_after_60_120_and_240_seconds( $answer ): void {
		$this->answer = $answer;

		foreach ( array( 0 => 60, 1 => 120, 2 => 240 ) as $attempt => $wait ) {
			$this->scheduled = array();
			$this->report( 0, 30 )->run( 'setup_saved', $attempt );
			self::assertContains(
				array(
					'at'   => self::NOW + $wait + 30,
					'args' => array( 'setup_saved', $attempt + 1 ),
				),
				$this->scheduled,
				"Attempt {$attempt} is retried after {$wait} s plus up to 30 s."
			);
		}

		$this->scheduled = array();
		$this->report()->run( 'setup_saved', 3 );
		self::assertNotContains( 'setup_saved', array_column( array_column( $this->scheduled, 'args' ), 0 ), 'After the third retry it gives up.' );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|\WP_Error}>
	 */
	public static function cannot_take_in_now(): array {
		return array(
			'429'           => array( self::answer( 429, '{"error":"rate_limited"}' ) ),
			'503'           => array( self::answer( 503, '{"error":"smaily_unreachable"}' ) ),
			'500'           => array( self::answer( 500, '' ) ),
			'timeout'       => array( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ),
		);
	}

	public function test_a_longer_retry_after_is_waited_and_one_over_an_hour_ends_the_try(): void {
		$this->answer = self::answer( 503, '{"error":"smaily_unreachable"}', array( 'retry-after' => '900' ) );
		$this->report()->run( 'daily', 0 );
		self::assertContains( array( 'at' => self::NOW + 900, 'args' => array( 'daily', 1 ) ), $this->scheduled );

		$this->scheduled = array();
		$this->answer    = self::answer( 429, '{"error":"rate_limited"}', array( 'retry-after' => '3601' ) );
		$this->report()->run( 'daily', 0 );
		self::assertSame( array( array( 'at' => self::NOW + DAY_IN_SECONDS, 'args' => array( 'daily', 0 ) ) ), $this->scheduled, 'Only the next daily report waits.' );
	}

	/**
	 * @dataProvider refused
	 *
	 * @param array<string, mixed> $answer
	 */
	public function test_a_refused_report_or_a_redirect_is_not_retried( array $answer ): void {
		$this->answer = $answer;

		$this->report()->run( 'setup_saved', 0 );

		self::assertCount( 1, $this->posts );
		self::assertSame( array( 'daily' ), array_column( array_column( $this->scheduled, 'args' ), 0 ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function refused(): array {
		return array(
			'400' => array( self::answer( 400, '{"error":"validation_failed"}' ) ),
			'401' => array( self::answer( 401, '{"error":"invalid_smaily_credentials"}' ) ),
			'413' => array( self::answer( 413, '{"error":"body_too_large"}' ) ),
			'308' => array( self::answer( 308, '', array( 'location' => 'https://elsewhere.test/' ) ) ),
		);
	}

	public function test_an_event_is_sent_once_inline_and_never_retried(): void {
		$this->answer = self::answer( 503, '{"error":"unavailable"}' );

		$this->report()->send_event( ReportBuilder::EVENT_DISCONNECTED, array( 'transactional' ), true );

		self::assertCount( 1, $this->posts );
		self::assertSame( array( 'event' => 'disconnected', 'removed' => array( 'transactional' ), 'engine_removed' => true ), json_decode( $this->posts[0]['args']['body'], true ) );
		self::assertSame( array(), $this->scheduled, 'No retry and no background action.' );
	}

	public function test_an_event_never_throws_into_the_disconnect_or_the_deactivation(): void {
		$builder = $this->createMock( ReportBuilder::class );
		$builder->method( 'build' )->willThrowException( new \RuntimeException( 'database gone' ) );

		( new InstallationReport( $builder ) )->send_event( ReportBuilder::EVENT_DEACTIVATED );

		self::assertSame( array(), $this->posts );
	}

	public function test_an_unknown_trigger_sends_nothing(): void {
		$this->report()->run( 'weekly', 0 );

		self::assertSame( array(), $this->posts );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_log_line_carries_the_status_and_the_code_never_a_credential_or_a_message(): void {
		define( 'WP_DEBUG', true );
		$log = (string) tempnam( sys_get_temp_dir(), 'con290' );
		$old = ini_set( 'error_log', $log );
		try {
			$this->answer = self::answer( 401, '{"error":"invalid_smaily_credentials","message":"Smaily refused api-user for jane@example.com"}' );
			$this->report()->run( 'daily', 0 );
			$this->answer = self::answer( 400, '{"error":"Not a code, jane@example.com"}' );
			$this->report()->run( 'daily', 0 );
			$lines = (string) file_get_contents( $log );
		} finally {
			ini_set( 'error_log', (string) $old );
			unlink( $log );
		}

		self::assertStringContainsString( 'event=snapshot trigger=daily http=401 error=invalid_smaily_credentials', $lines );
		self::assertStringContainsString( 'http=400 error=-', $lines );
		foreach ( array( 'api-user', 'api-pass', base64_encode( 'api-user:api-pass' ), 'jane@example.com', 'Smaily refused' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $lines );
		}
	}

	/**
	 * @param array<string, string> $headers
	 *
	 * @return array<string, mixed>
	 */
	private static function answer( int $status, string $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
			'headers'  => $headers,
		);
	}

	private function report( int $random = 0, ?int $jitter = null ): InstallationReport {
		$builder = $this->createMock( ReportBuilder::class );
		$builder->method( 'main_credentials' )->willReturn( $this->connected ? new CredentialSet( 'example', 'api-user', 'api-pass' ) : null );
		$builder->method( 'build' )->willReturnCallback(
			function ( string $event, string $trigger = '', array $removed = array(), bool $engine_removed = false ): ?array {
				if ( ! $this->connected ) {
					return null;
				}
				$body = $event === ReportBuilder::EVENT_SNAPSHOT
					? array(
						'event'   => $event,
						'trigger' => $trigger,
					)
					: array(
						'event'          => $event,
						'removed'        => $removed,
						'engine_removed' => $engine_removed,
					);
				return array(
					'body'        => $body,
					'credentials' => new CredentialSet( 'example', 'api-user', 'api-pass' ),
				);
			}
		);

		return new class( $builder, $random, $jitter ?? $random ) extends InstallationReport {
			private int $day_offset;
			private int $jitter;

			public function __construct( ReportBuilder $builder, int $day_offset, int $jitter ) {
				parent::__construct( $builder );
				$this->day_offset = $day_offset;
				$this->jitter     = $jitter;
			}

			protected function now(): int {
				return 1794571200;
			}

			protected function random( int $min, int $max ): int {
				return $max === InstallationReport::RETRY_JITTER_SECONDS ? $this->jitter : $this->day_offset;
			}
		};
	}
}
