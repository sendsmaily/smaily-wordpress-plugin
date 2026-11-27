<?php
/**
 * Integration: in the account-per-language mode each contact reaches its own
 * language's Smaily account (CON-446).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Multilingual\Router;
use Smaily\Connect\Smaily\BackfillJob;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\StaleSubscription;
use Smaily\Connect\Support\ContactLanguageResolver;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

/**
 * The contacts go through the REAL hooks (a new account queues a contact
 * sync), the REAL queue, the REAL Flusher on its Action Scheduler hook and
 * the REAL contact import built by Bootstrap. The language comes from the
 * REAL ContactLanguageResolver — its final filter gives each test user a
 * language by the first part of its address. Only the Smaily transport is
 * faked, at the pre_http_request seam, and every request is recorded with
 * the Smaily account (subdomain) it went to.
 *
 * The store: an Estonian account `eestisub`, an English account
 * `englishsub` stored as TranslatePress lists English (`en_GB`) and chosen
 * as the fallback account, and a main account `mainsub` saved before the
 * fallback was chosen (since CON-441 the setup saves the fallback there).
 * Latvian has no account of its own.
 */
final class AccountPerLanguageContactSyncTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	/** @var callable */
	private $language_filter;

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		HookHandler::reset_seen();

		update_option( 'smly_plus_setup_completed', true );
		update_option( ContactSyncMode::OPTION_SYNC_ENABLED, '1' );
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_LEGITIMATE_INTEREST );
		update_option( Router::OPTION_MODE, Router::MODE_A );
		$this->forget_mode_memo();

		$this->store_account( 'smaily_connect_api_credentials', 'mainsub' );
		$this->store_account( 'smly_plus_credentials_account_et', 'eestisub' );
		$this->store_account( 'smly_plus_credentials_account_en_gb', 'englishsub' );
		update_option( 'smly_plus_default_fallback_account', 'account_en_gb' );

		$this->language_filter = static function ( string $language, $context ): string {
			if ( $context instanceof \WP_User ) {
				return strstr( (string) $context->user_email, '-', true ) ?: $language;
			}
			return $language;
		};
		add_filter( ContactLanguageResolver::FILTER_LANGUAGE, $this->language_filter, 10, 2 );
	}

	protected function tearDown(): void {
		remove_filter( ContactLanguageResolver::FILTER_LANGUAGE, $this->language_filter, 10 );

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();

		HookHandler::reset_seen();
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::DEFAULT_MODE );
		$this->forget_mode_memo();
		$this->forget_clients();
		EnvScrub::reset();

		parent::tearDown();
	}

	public function test_live_sync_sends_each_contact_to_its_languages_account(): void {
		$estonian = $this->make_user( 'et' );
		$english  = $this->make_user( 'en' );
		$latvian  = $this->make_user( 'lv' );

		$sent = $this->flush_against( 200 );

		self::assertSame( 'eestisub', $this->account_of( $sent, $estonian ), 'An Estonian contact reaches the Estonian account.' );
		self::assertSame( 'englishsub', $this->account_of( $sent, $english ), 'An English contact reaches the English account.' );
		self::assertSame( 'englishsub', $this->account_of( $sent, $latvian ), 'A language with no account of its own reaches the fallback account.' );

		// The row records the account it goes to; the Smaily row never carries it.
		self::assertSame( 'account_et', $this->payload_of( $estonian )['account_key'] ?? null );
		self::assertSame( 'account_en_gb', $this->payload_of( $english )['account_key'] ?? null );
		self::assertSame( 'account_en_gb', $this->payload_of( $latvian )['account_key'] ?? null, 'The fallback account is the one the merchant chose.' );
		foreach ( $sent as $request ) {
			self::assertArrayNotHasKey( 'account_key', $request['contact'] );
		}
	}

	public function test_a_retry_goes_to_the_account_the_row_records(): void {
		$estonian = $this->make_user( 'et' );

		$this->flush_against( 500 );
		$row = $this->row_of( $estonian );
		self::assertSame( EventQueue::STATUS_PENDING, $row['status'], 'The failed send waits for its retry.' );

		// The store leaves the account-per-language mode before the retry: a
		// new decision would pick the main account, the retry still goes
		// where the row was queued for.
		update_option( Router::OPTION_MODE, Router::MODE_B );
		$this->make_due( (int) $row['id'] );
		$sent = $this->flush_against( 200 );

		self::assertSame( 'eestisub', $this->account_of( $sent, $estonian ) );
		self::assertSame( EventQueue::STATUS_SENT, $this->row_of( $estonian )['status'] );
	}

	public function test_a_row_whose_account_was_removed_fails_with_a_plain_reason(): void {
		$estonian = $this->make_user( 'et' );
		delete_option( 'smly_plus_credentials_account_et' );
		$this->forget_clients();

		$sent = $this->flush_against( 200 );

		self::assertSame( array(), $sent, 'Nothing is sent to another account.' );
		$row = $this->row_of( $estonian );
		self::assertSame( EventQueue::STATUS_FAILED, $row['status'] );
		self::assertSame( 'account_not_configured: account_et', $row['last_error'] );
	}

	public function test_a_stale_subscribe_is_still_skipped_in_its_language_account(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		$this->forget_mode_memo();
		$estonian = $this->make_user( 'et' );

		$subscribe = $this->change_subscription( $estonian, 1 );
		$this->flush_against( 500 );
		$unsubscribe = $this->change_subscription( $estonian, 0 );
		$sent        = $this->flush_against( 200 );
		self::assertSame( array( 'eestisub' ), array_column( $sent, 'account' ) );
		self::assertSame( EventQueue::STATUS_SENT, $this->row( $unsubscribe )['status'] );

		$this->make_due( $subscribe );
		self::assertSame( array(), $this->flush_against( 200 ), 'The retry may not mark the contact subscribed again.' );
		self::assertSame( StaleSubscription::REASON, EventQueue::skip_reason( (string) $this->row( $subscribe )['last_response'] ) );
	}

	public function test_the_first_order_emails_consent_read_asks_the_contacts_account(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		( new EventQueue() )->enqueue(
			HookHandler::EVENT_AUTOMATION_FIRST_ORDER,
			'order:1',
			array(
				'email'    => 'et-reader@example.test',
				'language' => 'et',
			)
		);

		$sent = $this->flush_against( 200 );

		$reads = array_values( array_filter( $sent, static fn ( array $request ): bool => $request['method'] === 'GET' ) );
		self::assertNotSame( array(), $reads, 'The consent rule reads Smaily first.' );
		self::assertSame( 'eestisub', $reads[0]['account'], 'It reads the account contact sync keeps the contact in.' );
	}

	public function test_the_contact_import_sends_each_contact_to_its_languages_account(): void {
		$estonian = $this->make_user( 'et' );
		$latvian  = $this->make_user( 'lv' );

		$job = Bootstrap::instance()->make_backfill_job( BackfillJob::BACKFILL_TYPE );
		self::assertInstanceOf( BackfillJob::class, $job );

		$sent = $this->record(
			200,
			static function () use ( $job ): void {
				$job->start();
				$guard = 0;
				do {
					$result = $job->process_batch( 200 );
				} while ( ! $result['completed'] && ++$guard < 50 );
			}
		);

		self::assertSame( 'eestisub', $this->account_of( $sent, $estonian ) );
		self::assertSame( 'englishsub', $this->account_of( $sent, $latvian ) );
	}

	public function test_with_no_fallback_account_chosen_the_main_account_takes_the_rest(): void {
		update_option( 'smly_plus_default_fallback_account', 'default' );
		$latvian = $this->make_user( 'lv' );

		$sent = $this->flush_against( 200 );

		self::assertSame( 'mainsub', $this->account_of( $sent, $latvian ) );
		self::assertArrayNotHasKey( 'account_key', $this->payload_of( $latvian ), 'The main account is the default — the row records nothing.' );
	}

	public function test_one_account_for_all_languages_sends_everyone_to_the_main_account(): void {
		update_option( Router::OPTION_MODE, Router::MODE_B );
		$estonian = $this->make_user( 'et' );

		$sent = $this->flush_against( 200 );

		self::assertSame( 'mainsub', $this->account_of( $sent, $estonian ) );
		self::assertArrayNotHasKey( 'account_key', $this->payload_of( $estonian ), 'The row is as it was before.' );
	}

	// --- helpers -------------------------------------------------------------

	private function store_account( string $option, string $subdomain ): void {
		update_option(
			$option,
			array(
				'subdomain' => $subdomain,
				'username'  => 'tester',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 'test-password' ),
			)
		);
	}

	/** A shopper account whose address starts with its language — the language filter reads it. */
	private function make_user( string $language ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_lang_' . wp_generate_password( 6, false ),
				'user_email' => $language . '-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		return $user_id;
	}

	private function change_subscription( int $user_id, int $subscribed ): int {
		global $wpdb;

		HookHandler::reset_seen();
		update_user_meta( $user_id, ContactAudience::OPTIN_META, $subscribed );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s AND entity_id = %s",
				HookHandler::EVENT_CONTACT_SYNC,
				$user_id . ':consent'
			)
		);
	}

	/**
	 * Run the real flush hook against a Smaily transport answering $status.
	 *
	 * @return array<int, array{account: string, method: string, contact: array<string, mixed>}>
	 */
	private function flush_against( int $status ): array {
		return $this->record(
			$status,
			static function (): void {
				do_action( EventQueue::FLUSH_HOOK );
			}
		);
	}

	/**
	 * Every Smaily request $run makes: the account (subdomain) it went to,
	 * its method, and the contact a POST carried (a GET carries none).
	 *
	 * @return array<int, array{account: string, method: string, contact: array<string, mixed>}>
	 */
	private function record( int $status, callable $run ): array {
		$requests = array();
		$fake     = static function ( $pre, $args, $url ) use ( &$requests, $status ) {
			$host   = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
			$method = (string) ( $args['method'] ?? 'GET' );
			$body   = $args['body'] ?? null;
			$body   = is_string( $body ) ? json_decode( $body, true ) : $body;
			$rows   = is_array( $body ) && isset( $body[0] ) ? $body : array( is_array( $body ) ? $body : array() );
			foreach ( $rows as $contact ) {
				$requests[] = array(
					'account' => (string) strstr( $host, '.', true ),
					'method'  => $method,
					'contact' => is_array( $contact ) ? $contact : array(),
				);
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					$method === 'GET'
						? array(
							'email'           => 'et-reader@example.test',
							'is_unsubscribed' => '0',
						)
						: array( 'code' => $status === 200 ? 101 : 0 )
				),
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			$run();
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}

		return $requests;
	}

	/**
	 * The account the contact of this user was POSTed to.
	 *
	 * @param array<int, array{account: string, method: string, contact: array<string, mixed>}> $sent
	 */
	private function account_of( array $sent, int $user_id ): string {
		$email = (string) get_userdata( $user_id )->user_email;
		foreach ( $sent as $request ) {
			if ( $request['method'] === 'POST' && ( $request['contact']['email'] ?? '' ) === $email ) {
				return $request['account'];
			}
		}
		self::fail( 'The contact was not sent.' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_of( int $user_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s AND entity_id = %s",
				HookHandler::EVENT_CONTACT_SYNC,
				(string) $user_id
			)
		);
		self::assertGreaterThan( 0, $id, 'The new account queued a contact sync.' );

		return $this->row( $id );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload_of( int $user_id ): array {
		$payload = json_decode( (string) $this->row_of( $user_id )['payload'], true );
		self::assertIsArray( $payload );

		return $payload;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smly_plus_event_queue WHERE id = %d", $id ),
			ARRAY_A
		);
		self::assertIsArray( $row );

		return $row;
	}

	private function make_due( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'smly_plus_event_queue', array( 'next_retry_at' => null ), array( 'id' => $id ) );
	}

	/** Bootstrap keeps one Smaily client per account key for the process. */
	private function forget_clients(): void {
		$prop = new \ReflectionProperty( Bootstrap::instance(), 'smaily_clients' );
		$prop->setAccessible( true );
		$prop->setValue( Bootstrap::instance(), array() );
	}

	/** Clear the registered HookHandler's contact-sync mode memo (PRO-4093). */
	private function forget_mode_memo(): void {
		global $wp_filter;

		foreach ( $wp_filter['woocommerce_created_customer']->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && $function[0] instanceof HookHandler ) {
					foreach ( array( 'mode', 'audience' ) as $memo ) {
						$prop = new \ReflectionProperty( HookHandler::class, $memo );
						$prop->setAccessible( true );
						$prop->setValue( $function[0], null );
					}
				}
			}
		}
	}
}
