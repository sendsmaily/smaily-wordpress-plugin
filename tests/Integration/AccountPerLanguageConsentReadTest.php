<?php
/**
 * Integration: in the account-per-language mode the plugin reads a contact's
 * consent from every language's Smaily account, not from the main account
 * alone (CON-471).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Multilingual\Router;
use Smaily\Connect\Smaily\BackfillJobInterface;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactReconciler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Support\ContactLanguageResolver;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

/**
 * The unsubscribe sync-back runs through the REAL daily contact-sync tick on
 * its Action Scheduler hook, the REAL ContactReconciler and the REAL hooks
 * and Flusher for the later profile change. The language comes from the
 * REAL ContactLanguageResolver — its final filter gives each test user a
 * language by the first part of its address. Only the Smaily transport is
 * faked, at the pre_http_request seam: each account (subdomain) answers its
 * own action log, and every request is recorded with the account it went to.
 *
 * The store, in "Subscribers only (consent)": the main account `mainsub`,
 * an Estonian account `eestisub`, an English account `englishsub` (the
 * fallback), and a Latvian account stored with the main account's
 * subdomain — the same Smaily account under a second key.
 */
final class AccountPerLanguageConsentReadTest extends TestCase {

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
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::MODE_CONSENT );
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

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();

		// The tick also starts the daily contact refresh.
		as_unschedule_all_actions( BackfillJobInterface::TICK_HOOK, array(), EventQueue::AS_GROUP );

		HookHandler::reset_seen();
		update_option( ContactSyncMode::OPTION_MODE, ContactSyncMode::DEFAULT_MODE );
		$this->forget_mode_memo();
		$this->forget_clients();
		EnvScrub::reset();

		parent::tearDown();
	}

	public function test_an_unsubscribe_in_a_language_account_reaches_wordpress(): void {
		$estonian = $this->make_subscriber( 'et' );

		$requests = $this->tick( array( 'eestisub' => $this->optout( 41, $estonian ) ) );

		self::assertSame( 0, (int) get_user_meta( $estonian, ContactAudience::OPTIN_META, true ), 'WordPress records the contact as unsubscribed.' );

		$walked = array_column( $this->history_reads( $requests ), 'account' );
		sort( $walked );
		self::assertSame( array( 'eestisub', 'englishsub', 'mainsub' ), $walked, 'Every account is read once — the Latvian key shares the main account.' );

		self::assertSame( 0, (int) get_option( ContactReconciler::OPTION_CURSOR ), 'The main account keeps its own cursor.' );
		self::assertSame( 41, get_option( ContactReconciler::OPTION_ACCOUNT_CURSORS )['account_et'] ?? null, 'The Estonian account keeps its own cursor.' );

		// The next tick reads each account from where it stopped.
		$requests = $this->tick( array() );
		foreach ( $this->history_reads( $requests ) as $read ) {
			self::assertSame( $read['account'] === 'eestisub' ? '41' : '0', $read['since'], $read['account'] . ' resumes from its cursor.' );
		}
	}

	public function test_a_later_profile_change_keeps_the_contact_unsubscribed(): void {
		$estonian = $this->make_subscriber( 'et' );
		$this->tick( array( 'eestisub' => $this->optout( 41, $estonian ) ) );

		HookHandler::reset_seen();
		wp_update_user(
			array(
				'ID'         => $estonian,
				'first_name' => 'Changed',
			)
		);
		$sent = $this->record(
			array(),
			static function (): void {
				do_action( EventQueue::FLUSH_HOOK );
			}
		);

		$email = (string) get_userdata( $estonian )->user_email;
		foreach ( $sent as $request ) {
			self::assertNotSame( $email, $request['contact']['email'] ?? '', 'Nothing about the unsubscribed contact goes to Smaily.' );
		}
	}

	public function test_a_failing_account_does_not_stop_the_others(): void {
		$estonian = $this->make_subscriber( 'et' );

		$this->tick(
			array(
				'mainsub'  => 500,
				'eestisub' => $this->optout( 41, $estonian ),
			)
		);

		self::assertSame( 0, (int) get_user_meta( $estonian, ContactAudience::OPTIN_META, true ) );
		self::assertSame( 41, get_option( ContactReconciler::OPTION_ACCOUNT_CURSORS )['account_et'] ?? null );
	}

	public function test_one_account_for_all_languages_reads_the_main_account_only(): void {
		update_option( Router::OPTION_MODE, Router::MODE_B );
		$estonian = $this->make_subscriber( 'et' );

		$requests = $this->tick( array( 'mainsub' => $this->optout( 7, $estonian ) ) );

		self::assertSame( array( 'mainsub' ), array_column( $this->history_reads( $requests ), 'account' ) );
		self::assertSame( 0, (int) get_user_meta( $estonian, ContactAudience::OPTIN_META, true ) );
		self::assertSame( 7, (int) get_option( ContactReconciler::OPTION_CURSOR ) );
		self::assertFalse( get_option( ContactReconciler::OPTION_ACCOUNT_CURSORS ), 'No language cursor is written.' );
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

	/** A shopper subscribed in the store, whose address starts with its language. */
	private function make_subscriber( string $language ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_lang_' . wp_generate_password( 6, false ),
				'user_email' => $language . '-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		// Stored as the reconciler stores it, so no subscribe is queued.
		ContactReconciler::run_suppressed(
			static function () use ( $user_id ): void {
				update_user_meta( $user_id, ContactAudience::OPTIN_META, 1 );
			}
		);

		return $user_id;
	}

	/**
	 * One action-log row: the contact unsubscribed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function optout( int $seq_id, int $user_id ): array {
		return array(
			array(
				'seq_id' => $seq_id,
				'email'  => (string) get_userdata( $user_id )->user_email,
				'action' => 'optout',
			),
		);
	}

	/**
	 * Run the real daily contact-sync tick.
	 *
	 * @param array<string, array<int, array<string, mixed>>|int> $logs Subdomain => its action log, or an HTTP status it fails with.
	 *
	 * @return array<int, array{account: string, method: string, path: string, query: array<string, mixed>, contact: array<string, mixed>}>
	 */
	private function tick( array $logs ): array {
		return $this->record(
			$logs,
			static function (): void {
				do_action( 'smly_plus_contact_sync' );
			}
		);
	}

	/**
	 * The action-log reads among $requests, with the cursor each sent.
	 *
	 * @param array<int, array{account: string, method: string, path: string, query: array<string, mixed>, contact: array<string, mixed>}> $requests
	 *
	 * @return array<int, array{account: string, since: string}>
	 */
	private function history_reads( array $requests ): array {
		$reads = array();
		foreach ( $requests as $request ) {
			if ( strpos( $request['path'], 'history' ) !== false ) {
				$reads[] = array(
					'account' => $request['account'],
					'since'   => (string) ( $request['query']['since_seq_id'] ?? '' ),
				);
			}
		}

		return $reads;
	}

	/**
	 * Every Smaily request $run makes. An action-log read answers that
	 * account's log from $logs (none: an empty log); any other request
	 * answers success.
	 *
	 * @param array<string, array<int, array<string, mixed>>|int> $logs
	 *
	 * @return array<int, array{account: string, method: string, path: string, query: array<string, mixed>, contact: array<string, mixed>}>
	 */
	private function record( array $logs, callable $run ): array {
		$requests = array();
		$fake     = static function ( $pre, $args, $url ) use ( &$requests, $logs ) {
			$host    = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
			$account = (string) strstr( $host, '.', true );
			$path    = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
			$query   = array();
			wp_parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
			$method = (string) ( $args['method'] ?? 'GET' );
			$body   = $args['body'] ?? null;
			$body   = is_string( $body ) ? json_decode( $body, true ) : $body;

			$requests[] = array(
				'account' => $account,
				'method'  => $method,
				'path'    => $path,
				'query'   => $query,
				'contact' => is_array( $body ) && isset( $body[0] ) && is_array( $body[0] ) ? $body[0] : ( is_array( $body ) ? $body : array() ),
			);

			$status = 200;
			$answer = array( 'code' => 101 );
			if ( strpos( $path, 'history' ) !== false ) {
				$log    = $logs[ $account ] ?? array();
				$status = is_int( $log ) ? $log : 200;
				$answer = is_int( $log ) ? array( 'code' => 0 ) : $log;
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
