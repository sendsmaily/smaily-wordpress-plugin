<?php
/**
 * Bind an anonymous browse session to a known customer on login (3.7).
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;

/**
 * The engine binds an anonymous session's browse history to a customer
 * AUTOMATICALLY when a browse event carries the customer's email/visitor-token
 * (§6 retroactive binding). That covers "the visitor browses after being
 * identified". It does NOT cover "the visitor logs in but generates no
 * email-carrying browse event after" — so on `wp_login` this handler EXPLICITLY
 * calls /identity/merge (§7) to bind the anon-session cookies to the now-known
 * customer. Complementary to retroactive binding, not a duplicate.
 *
 * Server-side (wp_login) rather than the client-side JS mergeIdentity: login is
 * a reliable server signal, the api_key stays server-side (no new public proxy
 * route), and the anon-session / visitor-token cookies are client-set but NOT
 * HttpOnly, so $_COOKIE has them on the login request. (The JS mergeIdentity
 * stub is reserved for the Milestone-2 platform-agnostic client — Shopify.)
 *
 * Checkout (`email_provided_at_checkout`) is intentionally NOT a trigger here:
 * a guest's customer record is auto-created by the ASYNC order ingest, so it
 * isn't in the engine yet at checkout time — a merge then would 404. A
 * registered user logging in already exists (ingested at registration /
 * backfill via the A-filter), so login timing is sound.
 *
 * Gate: only while a rec-engine tenant is connected. Dedup: the anon-session id
 * last merged for a user is stored in user meta, so repeat logins on the same
 * session don't re-hit the engine (the merge is idempotent there anyway).
 *
 * Not final: tests subclass to inject a Client double + control $_COOKIE.
 */
class IdentityHookHandler {

	public const MERGED_META_KEY = '_smaily_rec_merged_anon_sid';

	private RecEngineSettings $settings;

	/** @var callable(): Client */
	private $client_factory;

	private ?ProfilingConsent $profiling;

	/**
	 * @param callable(): Client $client_factory
	 * @param ?ProfilingConsent  $profiling Profiling-consent gate ((a).1) — when the
	 *        contact has opted out, skip the merge so their anon browse history is
	 *        NOT retroactively bound to their profile. Null = no gate (pre-(a) builds).
	 */
	public function __construct( RecEngineSettings $settings, callable $client_factory, ?ProfilingConsent $profiling = null ) {
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
		$this->profiling      = $profiling;
	}

	public function register(): void {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
	}

	/**
	 * `wp_login` fires after a successful authentication; $user is the WP_User.
	 */
	public function on_login( string $user_login, \WP_User $user ): void {
		if ( ! $this->settings->is_connected() ) {
			return;
		}

		$anon_sid = $this->cookie( $this->session_cookie_name() );
		$token    = $this->cookie( $this->visitor_cookie_name() );
		if ( $anon_sid === '' && $token === '' ) {
			return; // Nothing to merge — no anon session was ever started.
		}

		$email = strtolower( trim( (string) $user->user_email ) );
		if ( $email === '' ) {
			return;
		}

		// (a).1 profiling gate — if the contact has opted out of profiling, do NOT
		// bind their anon browse history to their identity (respect the opt-out
		// retroactively). The anon events stay unattributed rather than building a
		// profile the shopper declined.
		if ( $this->profiling !== null && ! $this->profiling->may_profile( $email ) ) {
			return;
		}

		// Dedup: this anon session was already bound to this user — skip.
		if ( $anon_sid !== '' && (string) get_user_meta( (int) $user->ID, self::MERGED_META_KEY, true ) === $anon_sid ) {
			return;
		}

		$body = array(
			'customer_email'       => $email,
			'customer_external_id' => (string) $user->ID,
			'merge_ts'             => IsoDate::to_z( time() ),
			'merge_reason'         => 'user_logged_in',
		);
		if ( $anon_sid !== '' ) {
			$body['anon_session_id'] = $anon_sid;
		}
		if ( $token !== '' ) {
			$body['smaily_visitor_token'] = $token;
		}

		try {
			( $this->client_factory )()->merge_identity( $body );
			if ( $anon_sid !== '' ) {
				update_user_meta( (int) $user->ID, self::MERGED_META_KEY, $anon_sid );
			}
		} catch ( ApiException $e ) {
			// 404 customer_not_found (rare — A-filter ingests every registered
			// user) and transient failures both log + skip: the next
			// email-carrying browse event binds the session retroactively (§6).
			\Smaily\Connect\Support\DebugLog::write( '[smaily-connect identity.merge] ' . $e->getMessage() );
		}
	}

	private function cookie( string $name ): string {
		if ( $name === '' || ! isset( $_COOKIE[ $name ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
	}

	private function session_cookie_name(): string {
		$config = $this->settings->config();
		return isset( $config['session_cookie_name'] ) ? (string) $config['session_cookie_name'] : 'smaily_anon_sid';
	}

	private function visitor_cookie_name(): string {
		$config = $this->settings->config();
		return isset( $config['tracking_cookie_name'] ) ? (string) $config['tracking_cookie_name'] : 'smaily_rec_uid';
	}
}
