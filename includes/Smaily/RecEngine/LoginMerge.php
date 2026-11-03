<?php
/**
 * The login's engine calls, sent from a background action (CON-268).
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Support\AttributionShape;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;
use Smaily\Connect\Support\DebugLog;

/**
 * A login never waits on Campaign Intelligence. `IdentityHookHandler` sets
 * the visitor-token cookie in the login request and queues ONE Action
 * Scheduler action here (HOOK, group AS_GROUP) with the user id, the token,
 * the anon session id and whether the shopper gave a marketing yes at that
 * login. No email goes into the action's args: the action reads it from the
 * user id when it runs.
 *
 * The action, while sending is allowed:
 *   1. checks again what it can check in the background — the user and its
 *      email, the token's shape (a `vs_` token only with the login's consent),
 *      and the profiling gate (`ProfilingConsent::may_profile()`, which may
 *      read Smaily — fine here, never in the login);
 *   2. with the login's marketing consent, sends the customer with §4 (the
 *      customer import's payload builder and wire shape). Contract v1.23.0 §4
 *      group (c): a customer who logs in with marketing consent is in scope at
 *      that login, also without an order or a newsletter subscription, so
 *      `CustomerScope` (groups a and b) is not asked. The consent is the
 *      browser's WP Consent API answer (`MarketingConsent::given()`) read in
 *      the login request — the same answer that decides the `vs_` token —
 *      because the action has no browser to ask;
 *   3. sends the §7 merge. A `404 customer_not_found` is retried once, at
 *      once, only when §4 answered 200 for this customer in this run (§7
 *      "Retry after 404").
 *
 * A failed request that may pass later (no answer, 429, 5xx) queues the whole
 * action again after a back-off, at most MAX_ATTEMPTS runs in all; any other
 * 4xx ends it. The client makes one attempt per request
 * (`Bootstrap::login_merge_client()`), so a run never sleeps a Retry-After.
 *
 * Not final: tests subclass it to record what is queued.
 */
class LoginMerge {

	/** The Action Scheduler hook of the action. */
	public const HOOK = 'smly_rec_login_merge';

	/** Its Action Scheduler group (Deactivation::AS_GROUPS). */
	public const AS_GROUP = 'smaily-rec-login-merge';

	/** Runs of one login's action in all, the first one included. */
	public const MAX_ATTEMPTS = 3;

	/** The wait before the second and the third run, in seconds. */
	public const BACKOFF_SECONDS = array( 60, 300 );

	/** The longest a run waits for one engine request, in seconds. */
	public const TIMEOUT_SECONDS = 10;

	/** The §7 answer that the engine does not have the customer yet. */
	private const CUSTOMER_NOT_FOUND = 'customer_not_found';

	/** User meta: the anon session id last merged for the user (F3-27). */
	public const MERGED_META_KEY = '_smaily_rec_merged_anon_sid';

	private RecEngineSettings $settings;

	/** @var callable(): Client */
	private $client_factory;

	private CustomerPayloadBuilder $builder;

	private ?ProfilingConsent $profiling;

	/**
	 * @param callable(): Client $client_factory One attempt per request (TIMEOUT_SECONDS).
	 * @param ?ProfilingConsent  $profiling      The profiling gate; null = none.
	 */
	public function __construct( RecEngineSettings $settings, callable $client_factory, ?CustomerPayloadBuilder $builder = null, ?ProfilingConsent $profiling = null ) {
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
		$this->builder        = $builder ?? new CustomerPayloadBuilder();
		$this->profiling      = $profiling;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 5 );
	}

	/**
	 * Queue the login's action, unless one for the same login values is
	 * already waiting (any attempt). Returns whether one waits now.
	 */
	public function enqueue( int $user_id, string $token, string $anon_session_id, bool $consent ): bool {
		if ( $user_id <= 0 || ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			if ( as_has_scheduled_action( self::HOOK, self::args( $user_id, $token, $anon_session_id, $consent, $attempt ), self::AS_GROUP ) ) {
				return true;
			}
		}

		as_enqueue_async_action( self::HOOK, self::args( $user_id, $token, $anon_session_id, $consent, 1 ), self::AS_GROUP );

		return true;
	}

	/**
	 * Cancel every waiting run of the user's login action, whatever its
	 * token, session, consent or run number (CON-275). The personal-data
	 * eraser calls it before the engine's §9 delete, so no later run sends
	 * the erased customer (§4) or binds a token to them (§7).
	 *
	 * Action Scheduler cancels by exact args only, so the waiting actions of
	 * the hook are read and each one of this user is cancelled with its own
	 * stored args. A run that has already started cannot be cancelled.
	 * Returns how many were cancelled.
	 */
	public static function forget_user( int $user_id ): int {
		if ( $user_id <= 0 || ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::AS_GROUP,
				'status'   => 'pending', // ActionScheduler_Store::STATUS_PENDING.
				'per_page' => -1,
			)
		);

		$cancelled = 0;
		foreach ( (array) $actions as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
				continue;
			}
			$args = $action->get_args();
			if ( ! is_array( $args ) || (int) ( $args['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			as_unschedule_all_actions( self::HOOK, $args, self::AS_GROUP );
			++$cancelled;
		}

		return $cancelled;
	}

	/**
	 * The action's args, in the order run() takes them. One function builds
	 * them, so the queued and the looked-up args are the same JSON.
	 *
	 * @return array{user_id: int, token: string, anon_session_id: string, consent: int, attempt: int}
	 */
	public static function args( int $user_id, string $token, string $anon_session_id, bool $consent, int $attempt ): array {
		return array(
			'user_id'         => $user_id,
			'token'           => $token,
			'anon_session_id' => $anon_session_id,
			'consent'         => $consent ? 1 : 0,
			'attempt'         => $attempt,
		);
	}

	/**
	 * The Action Scheduler callback.
	 *
	 * @param mixed $user_id         The WP user id.
	 * @param mixed $token           The visitor token sent in the merge ('' = none).
	 * @param mixed $anon_session_id The anon session id ('' = none).
	 * @param mixed $consent         1 when the shopper gave a marketing yes at the login.
	 * @param mixed $attempt         This run's number, from 1.
	 */
	public function run( $user_id = 0, $token = '', $anon_session_id = '', $consent = 0, $attempt = 1 ): void {
		$user_id         = (int) $user_id;
		$token           = is_string( $token ) ? trim( $token ) : '';
		$anon_session_id = is_string( $anon_session_id ) ? trim( $anon_session_id ) : '';
		$consent         = (bool) $consent;
		$attempt         = max( 1, (int) $attempt );

		if ( ! $this->settings->sending_allowed() ) {
			return;
		}

		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$email = strtolower( trim( (string) $user->user_email ) );
		if ( $email === '' ) {
			return;
		}

		// The token as the login decided it, checked again: the contract shapes
		// only, and a store `vs_` token only with marketing consent (§5, §7).
		if ( $token !== '' && ! AttributionShape::is_visitor_token( $token ) ) {
			$token = '';
		}
		if ( AttributionShape::is_store_visitor_token( $token ) && ! $consent ) {
			$token = '';
		}
		if ( $anon_session_id !== '' && ! AttributionShape::is_session_id( $anon_session_id ) ) {
			$anon_session_id = '';
		}

		// (a).1: a contact who objected to profiling is neither sent nor merged.
		if ( $this->profiling !== null && ! $this->profiling->may_profile( $email ) ) {
			return;
		}

		if ( $anon_session_id !== '' && (string) get_user_meta( $user_id, self::MERGED_META_KEY, true ) === $anon_session_id ) {
			$anon_session_id = '';
		}

		$client        = ( $this->client_factory )();
		$customer_sent = false;
		if ( $consent ) {
			try {
				$customer_sent = $this->send_customer( $client, $user );
			} catch ( ApiException $e ) {
				if ( self::may_pass_later( $e ) ) {
					$this->again( $e, $user_id, $token, $anon_session_id, $consent, $attempt );
					return;
				}
				DebugLog::write( '[smaily-connect login-merge] customer not sent: ' . self::describe( $e ) );
			}
		}

		if ( $anon_session_id === '' && $token === '' ) {
			return; // Nothing to merge: the §4 send was all this login needed.
		}

		$body = array(
			'customer_email'       => $email,
			'customer_external_id' => (string) $user_id,
			'merge_ts'             => IsoDate::to_z( time() ),
			'merge_reason'         => 'user_logged_in',
		);
		if ( $anon_session_id !== '' ) {
			$body['anon_session_id'] = $anon_session_id;
		}
		if ( $token !== '' ) {
			$body['smaily_visitor_token'] = $token;
		}

		try {
			try {
				$client->merge_identity( $body );
			} catch ( ApiException $e ) {
				// §7 v1.23.0: once, and only after §4 answered 200 for the customer.
				if ( ! $customer_sent || ! self::is_customer_not_found( $e ) ) {
					throw $e;
				}
				$client->merge_identity( $body );
			}
		} catch ( ApiException $e ) {
			if ( self::may_pass_later( $e ) ) {
				$this->again( $e, $user_id, $token, $anon_session_id, $consent, $attempt );
				return;
			}
			// A 404 here is a customer the engine does not have (outside the §4
			// scope, CON-246, or a failed §4 send): the next email-carrying
			// browse event binds the session retroactively (§6), and the next
			// login sends the token again.
			DebugLog::write( '[smaily-connect login-merge] merge not sent: ' . self::describe( $e ) );
			return;
		}

		if ( $anon_session_id !== '' ) {
			update_user_meta( $user_id, self::MERGED_META_KEY, $anon_session_id );
		}
	}

	/**
	 * Send the customer with §4. True when the engine took it (no per-item
	 * error for it).
	 *
	 * @throws ApiException When the request fails as a whole.
	 */
	private function send_customer( Client $client, \WP_User $user ): bool {
		$response = $client->ingest_customers( array( $this->builder->build( $user, wp_generate_uuid4() ) ) );
		$errors   = isset( $response['errors'] ) && is_array( $response['errors'] ) ? $response['errors'] : array();
		if ( $errors !== array() ) {
			DebugLog::write( '[smaily-connect login-merge] customer refused by the engine (per-item error)' );
			return false;
		}

		return true;
	}

	/**
	 * Queue the next run after the back-off, or give up after MAX_ATTEMPTS.
	 */
	private function again( ApiException $e, int $user_id, string $token, string $anon_session_id, bool $consent, int $attempt ): void {
		if ( $attempt >= self::MAX_ATTEMPTS || ! function_exists( 'as_schedule_single_action' ) ) {
			DebugLog::write( sprintf( '[smaily-connect login-merge] gave up after %d runs: %s', $attempt, self::describe( $e ) ) );
			return;
		}

		$wait = self::BACKOFF_SECONDS[ min( $attempt - 1, count( self::BACKOFF_SECONDS ) - 1 ) ];
		$wait = max( $wait, (int) $e->retry_after() );
		as_schedule_single_action( time() + $wait, self::HOOK, self::args( $user_id, $token, $anon_session_id, $consent, $attempt + 1 ), self::AS_GROUP );
	}

	/** No answer, a 429 or a 5xx: the same request may pass later. */
	private static function may_pass_later( ApiException $e ): bool {
		$status = (int) $e->getCode();

		return $status === 0 || $status === 429 || $status >= 500;
	}

	private static function is_customer_not_found( ApiException $e ): bool {
		return (int) $e->getCode() === 404 && $e->error_code() === self::CUSTOMER_NOT_FOUND;
	}

	/** The failure without the engine's message, which can name the customer. */
	private static function describe( ApiException $e ): string {
		return sprintf( 'http=%d error=%s', (int) $e->getCode(), $e->error_code() );
	}
}
