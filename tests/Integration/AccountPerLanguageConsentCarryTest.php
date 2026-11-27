<?php
/**
 * Integration: in the account-per-language mode a contact's unsubscribe and
 * profiling objection hold in every Smaily account of the store (CON-480).
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
use Smaily\Connect\Smaily\ContactReconciler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Smaily\UnsubscribeCarryOver;
use Smaily\Connect\Support\ContactLanguageResolver;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

/**
 * The contacts go through the REAL hooks (a new account, a profile change, a
 * newsletter opt-out queue a contact sync), the REAL queue, the REAL Flusher
 * on its Action Scheduler hook, the REAL contact import and the REAL
 * ProfilingConsent, all built by Bootstrap. The language comes from the REAL
 * ContactLanguageResolver — its final filter gives each test user a language
 * by the first part of its address. Only the Smaily transport is faked, at
 * the pre_http_request seam: each account (subdomain) answers its own
 * contact read, and every request is recorded with the account it went to.
 *
 * The store: the main account `mainsub` (where every contact went before
 * CON-446), an Estonian account `eestisub`, an English account `englishsub`
 * (the fallback), and a Latvian account stored with the main account's
 * subdomain — the same Smaily account under a second key.
 */
final class AccountPerLanguageConsentCarryTest extends TestCase {

	private const ACCOUNT_KEYS = array( 'default', 'account_et', 'account_en_gb', 'account_lv' );

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
		$this->store_account( 'smly_plus_credentials_account_lv', 'mainsub' );
		update_option( 'smly_plus_default_fallback_account', 'account_en_gb' );
		$this->forget_clients();

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

		// The caches a send or a read left — EnvScrub does not sweep
		// transients. The import walks every user of the site.
		foreach ( get_users( array( 'fields' => array( 'user_email' ) ) ) as $user ) {
			$this->forget_caches( (string) $user->user_email );
		}

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

	public function test_a_my_account_opt_out_reaches_every_account(): void {
		$this->use_consent_mode();
		$estonian = $this->make_subscriber( 'et' );
		$email    = $this->email_of( $estonian );

		HookHandler::reset_seen();
		update_user_meta( $estonian, ContactAudience::OPTIN_META, 0 );
		$sent = $this->flush( array() );

		$posts = $this->posts_of( $sent, $email );
		$where = array_column( $posts, 'account' );
		sort( $where );
		self::assertSame( array( 'eestisub', 'englishsub', 'mainsub' ), $where, 'Each Smaily account gets the opt-out once — the Latvian key shares the main account.' );
		foreach ( $posts as $post ) {
			self::assertSame( '1', (string) ( $post['contact']['is_unsubscribed'] ?? '' ), $post['account'] . ' records the contact as unsubscribed.' );
		}
		self::assertSame( array(), $this->reads( $sent ), 'An opt-out reads nothing first.' );
	}

	public function test_an_unsubscribe_carries_into_an_account_that_does_not_hold_the_contact(): void {
		$estonian = $this->make_user( 'et' );
		$email    = $this->email_of( $estonian );

		$sent = $this->flush( array( 'mainsub' => $this->contact( $email, '1' ) ) );

		self::assertSame( array( 'eestisub', 'mainsub' ), array_column( $this->reads( $sent ), 'account' ), 'The target account first, then the others until one has the contact unsubscribed.' );
		$posts = $this->posts_of( $sent, $email );
		self::assertSame( array( 'eestisub' ), array_column( $posts, 'account' ) );
		self::assertSame( '1', (string) ( $posts[0]['contact']['is_unsubscribed'] ?? '' ), 'The language account records the contact as unsubscribed.' );

		// The account holds the contact now: a later change reads nothing and
		// sends no status, so the account keeps its own.
		HookHandler::reset_seen();
		wp_update_user(
			array(
				'ID'         => $estonian,
				'first_name' => 'Changed',
			)
		);
		$sent = $this->flush( array( 'mainsub' => $this->contact( $email, '1' ) ) );

		self::assertSame( array(), $this->reads( $sent ), 'A contact the account holds is not read again.' );
		$posts = $this->posts_of( $sent, $email );
		self::assertSame( array( 'eestisub' ), array_column( $posts, 'account' ) );
		self::assertArrayNotHasKey( 'is_unsubscribed', $posts[0]['contact'] );
	}

	public function test_an_account_that_holds_the_contact_keeps_its_own_status(): void {
		$estonian = $this->make_user( 'et' );
		$email    = $this->email_of( $estonian );

		$sent = $this->flush(
			array(
				'eestisub' => $this->contact( $email, '0' ),
				'mainsub'  => $this->contact( $email, '1' ),
			)
		);

		self::assertSame( array( 'eestisub' ), array_column( $this->reads( $sent ), 'account' ), 'No other account is read.' );
		$posts = $this->posts_of( $sent, $email );
		self::assertSame( array( 'eestisub' ), array_column( $posts, 'account' ) );
		self::assertArrayNotHasKey( 'is_unsubscribed', $posts[0]['contact'] );
	}

	public function test_a_contact_unsubscribed_nowhere_is_sent_as_it_is(): void {
		$estonian = $this->make_user( 'et' );
		$email    = $this->email_of( $estonian );

		$sent = $this->flush( array() );

		self::assertSame( array( 'eestisub', 'mainsub', 'englishsub' ), array_column( $this->reads( $sent ), 'account' ), 'Every account is read once.' );
		$posts = $this->posts_of( $sent, $email );
		self::assertSame( array( 'eestisub' ), array_column( $posts, 'account' ) );
		self::assertArrayNotHasKey( 'is_unsubscribed', $posts[0]['contact'] );
	}

	public function test_a_failed_read_sends_nothing(): void {
		$estonian = $this->make_user( 'et' );
		$email    = $this->email_of( $estonian );

		$sent = $this->flush( array( 'mainsub' => 500 ) );

		self::assertSame( array(), $this->posts_of( $sent, $email ), 'Nothing is sent while another account cannot be read.' );
		$row = $this->row_of( $estonian );
		self::assertSame( EventQueue::STATUS_PENDING, $row['status'], 'The row waits for its retry.' );
		self::assertStringContainsString( 'HTTP 500 for GET contact', (string) $row['last_error'] );

		// A body that is neither a contact nor Smaily's "no such contact".
		$this->make_due( (int) $row['id'] );
		$sent = $this->flush( array( 'mainsub' => array( 'code' => 203 ) ) );
		self::assertSame( array(), $this->posts_of( $sent, $email ) );
		self::assertSame( EventQueue::STATUS_PENDING, $this->row_of( $estonian )['status'] );
	}

	public function test_the_contact_import_carries_the_unsubscribe(): void {
		$estonian = $this->make_user( 'et' );
		$email    = $this->email_of( $estonian );

		$job = Bootstrap::instance()->make_backfill_job( BackfillJob::BACKFILL_TYPE );
		self::assertInstanceOf( BackfillJob::class, $job );

		$sent = $this->record(
			array( 'mainsub' => $this->contact( $email, '1' ) ),
			static function () use ( $job ): void {
				$job->start();
				$guard = 0;
				do {
					$result = $job->process_batch( 200 );
				} while ( ! $result['completed'] && ++$guard < 50 );
			}
		);

		$posts = $this->posts_of( $sent, $email );
		self::assertSame( array( 'eestisub' ), array_column( $posts, 'account' ) );
		self::assertSame( '1', (string) ( $posts[0]['contact']['is_unsubscribed'] ?? '' ) );
		self::assertNotFalse( get_transient( UnsubscribeCarryOver::cache_key( 'account_et', $email ) ), 'The account holds the contact now — the next refresh reads nothing.' );
	}

	public function test_a_profiling_objection_in_another_account_holds(): void {
		$estonian = $this->make_user( 'et' );
		$email    = $this->email_of( $estonian );

		$sent = $this->record(
			array( 'mainsub' => $this->contact( $email, '0', '0' ) ),
			static function () use ( $email, &$allowed ): void {
				$allowed = Bootstrap::instance()->profiling_consent()->may_profile( $email );
			}
		);

		self::assertFalse( $allowed, 'The contact objected in the main account, which still holds them.' );
		self::assertSame( array( 'eestisub', 'mainsub' ), array_column( $sent, 'account' ) );
	}

	/**
	 * A single account and one account for all languages: no extra read, the
	 * opt-out goes to the main account alone, the profiling read too.
	 *
	 * @dataProvider other_modes
	 */
	public function test_other_modes_are_unchanged( string $mode ): void {
		update_option( Router::OPTION_MODE, $mode );
		$estonian = $this->make_user( 'et' );
		$email    = $this->email_of( $estonian );

		$sent = $this->flush( array( 'eestisub' => $this->contact( $email, '1' ) ) );
		self::assertSame( array(), $this->reads( $sent ), 'A contact sync reads nothing.' );
		self::assertSame( array( 'mainsub' ), array_column( $this->posts_of( $sent, $email ), 'account' ) );

		$this->use_consent_mode();
		$subscriber = $this->make_subscriber( 'et' );
		HookHandler::reset_seen();
		update_user_meta( $subscriber, ContactAudience::OPTIN_META, 0 );
		$sent = $this->flush( array() );
		self::assertSame( array( 'mainsub' ), array_column( $this->posts_of( $sent, $this->email_of( $subscriber ) ), 'account' ) );

		$sent = $this->record(
			array( 'eestisub' => $this->contact( $email, '0', '0' ) ),
			static function () use ( $email, &$allowed ): void {
				$allowed = Bootstrap::instance()->profiling_consent()->may_profile( $email );
			}
		);
		self::assertTrue( $allowed );
		self::assertSame( array( 'mainsub' ), array_column( $sent, 'account' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function other_modes(): array {
		return array(
			'single account'                => array( Router::MODE_SINGLE ),
			'one account for all languages' => array( Router::MODE_B ),
		);
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * A Smaily contact as a read answers it.
	 *
	 * @return array<string, string>
	 */
	private function contact( string $email, string $unsubscribed, string $profiling = '' ): array {
		$contact = array(
			'email'           => $email,
			'is_unsubscribed' => $unsubscribed,
		);
		if ( $profiling !== '' ) {
			$contact['smaily_rec_profiling'] = $profiling;
		}
		return $contact;
	}

	private function use_consent_mode(): void {
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
		$this->forget_mode_memo();
	}

	private function forget_caches( string $email ): void {
		foreach ( self::ACCOUNT_KEYS as $account ) {
			delete_transient( UnsubscribeCarryOver::cache_key( $account, $email ) );
			$hash = md5( $email );
			$key  = $account === 'default' ? $hash : md5( $account . ':' . $hash );
			delete_transient( 'smly_profiling_' . $key );
			delete_transient( 'smly_profiling_stale_' . $key );
		}
	}

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
				'user_login' => 'smly_carry_' . wp_generate_password( 6, false ),
				'user_email' => $language . '-' . strtolower( wp_generate_password( 8, false ) ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		return $user_id;
	}

	/** A shopper subscribed in the store, stored as the reconciler stores it, so no subscribe is queued. */
	private function make_subscriber( string $language ): int {
		$user_id = $this->make_user( $language );
		ContactReconciler::run_suppressed(
			static function () use ( $user_id ): void {
				update_user_meta( $user_id, ContactAudience::OPTIN_META, 1 );
			}
		);

		return $user_id;
	}

	private function email_of( int $user_id ): string {
		return (string) get_userdata( $user_id )->user_email;
	}

	/**
	 * Run the real flush hook.
	 *
	 * @param array<string, array<string, string>|int> $contacts
	 *
	 * @return array<int, array{account: string, method: string, path: string, contact: array<string, mixed>}>
	 */
	private function flush( array $contacts ): array {
		return $this->record(
			$contacts,
			static function (): void {
				do_action( EventQueue::FLUSH_HOOK );
			}
		);
	}

	/**
	 * The contact reads among $requests.
	 *
	 * @param array<int, array{account: string, method: string, path: string, contact: array<string, mixed>}> $requests
	 *
	 * @return array<int, array{account: string, method: string, path: string, contact: array<string, mixed>}>
	 */
	private function reads( array $requests ): array {
		return array_values( array_filter( $requests, static fn ( array $request ): bool => $request['method'] === 'GET' ) );
	}

	/**
	 * The POSTs that carried this address.
	 *
	 * @param array<int, array{account: string, method: string, path: string, contact: array<string, mixed>}> $requests
	 *
	 * @return array<int, array{account: string, method: string, path: string, contact: array<string, mixed>}>
	 */
	private function posts_of( array $requests, string $email ): array {
		return array_values(
			array_filter(
				$requests,
				static fn ( array $request ): bool => $request['method'] === 'POST' && ( $request['contact']['email'] ?? '' ) === $email
			)
		);
	}

	/**
	 * Every Smaily request $run makes. A contact read answers that account's
	 * contact from $contacts — an array is the body, an int the HTTP status
	 * it fails with; none: Smaily's 206 "no such contact". Any other request
	 * answers success.
	 *
	 * @param array<string, array<string, string|int>|int> $contacts
	 *
	 * @return array<int, array{account: string, method: string, path: string, contact: array<string, mixed>}>
	 */
	private function record( array $contacts, callable $run ): array {
		$requests = array();
		$fake     = static function ( $pre, $args, $url ) use ( &$requests, $contacts ) {
			$host    = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
			$account = (string) strstr( $host, '.', true );
			$path    = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
			$method  = (string) ( $args['method'] ?? 'GET' );
			$body    = $args['body'] ?? null;
			$body    = is_string( $body ) ? json_decode( $body, true ) : $body;

			$requests[] = array(
				'account' => $account,
				'method'  => $method,
				'path'    => $path,
				'contact' => is_array( $body ) && isset( $body[0] ) && is_array( $body[0] ) ? $body[0] : ( is_array( $body ) ? $body : array() ),
			);

			$status = 200;
			$answer = array( 'code' => 101 );
			if ( $method === 'GET' && strpos( $path, 'contact' ) !== false ) {
				$contact = $contacts[ $account ] ?? array(
					'code'    => 206,
					'message' => '',
				);
				$status  = is_int( $contact ) ? $contact : 200;
				$answer  = is_int( $contact ) ? array( 'code' => 0 ) : $contact;
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $answer ),
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
	 * The latest contact sync row of this user.
	 *
	 * @return array<string, mixed>
	 */
	private function row_of( int $user_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smly_plus_event_queue WHERE event_type = %s AND entity_id = %s ORDER BY id DESC LIMIT 1",
				HookHandler::EVENT_CONTACT_SYNC,
				(string) $user_id
			),
			ARRAY_A
		);
		self::assertIsArray( $row, 'The new account queued a contact sync.' );

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
