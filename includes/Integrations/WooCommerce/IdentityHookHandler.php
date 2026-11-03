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
use Smaily\Connect\Smaily\RecEngine\LoginMerge;
use Smaily\Connect\Smaily\RecEngine\Support\AttributionShape;
use Smaily\Connect\Support\MarketingConsent;

/**
 * The engine binds an anonymous session's browse history to a customer
 * AUTOMATICALLY when a browse event carries the customer's email/visitor-token
 * (§6 retroactive binding). That covers "the visitor browses after being
 * identified". It does NOT cover "the visitor logs in but generates no
 * email-carrying browse event after" — so on `wp_login` this handler has the
 * plugin EXPLICITLY call /identity/merge (§7) to bind the anon-session cookies
 * to the now-known customer. Complementary to retroactive binding, not a
 * duplicate.
 *
 * Server-side (wp_login) rather than the client-side JS mergeIdentity: login is
 * a reliable server signal, the api_key stays server-side (no new public proxy
 * route), and the anon-session / visitor-token cookies are client-set but NOT
 * HttpOnly, so $_COOKIE has them on the login request. (The JS mergeIdentity
 * stub is reserved for the Milestone-2 platform-agnostic client — Shopify.)
 *
 * The login request never calls Campaign Intelligence (CON-268). It reads the
 * cookies and the consent, sets or renews the visitor-token cookie, and queues
 * one background action (LoginMerge) that sends the customer (§4, with
 * marketing consent) and then the merge (§7). A slow engine therefore never
 * holds a login. The profiling gate here reads only what the store already
 * knows (`ProfilingConsent::known_opt_out()`): a fresh read can call Smaily
 * and the engine, so the full gate runs in the action.
 *
 * Store-created token at login (CON-251, contract v1.22.0 §7): a shopper who
 * logs in with marketing consent and has no visitor-token cookie gets a new
 * store `vs_` token, written by the server through
 * LandingCapture::issue_visitor_token() (the cookie name and lifetime of the
 * engine config), and the merge sends it, so the engine creates it for this
 * customer. The cookie stays after logout (Erkki, 2026-11-02): the browser is
 * still recognised, and the storefront recommendations ask for it by the
 * token. Without consent no token is created and no `vs_` token is sent; an
 * engine `vt_` token from an email link is sent as before. An existing token
 * is never replaced; with consent, a login writes its cookie again with the
 * same value and a fresh lifetime (Erkki, 2026-11-02).
 *
 * Gate: only while sending to the engine is allowed. One action per login:
 * every login that carries a visitor token queues the merge, because each
 * merge renews the token's 14 months in the engine (§7 "call the merge at each
 * login"), and every login with marketing consent queues the §4 send (§4
 * group c). The anon-session id last merged for a user is stored in user
 * meta; a login on an already merged session with neither a token nor
 * consent queues nothing, and a login with a token leaves the merged session
 * out.
 *
 * Not final: tests subclass to answer the consent + control $_COOKIE.
 */
class IdentityHookHandler {

	/** The anon session last merged for a user — one spelling, LoginMerge's. */
	public const MERGED_META_KEY = LoginMerge::MERGED_META_KEY;

	private RecEngineSettings $settings;

	/** Queues the background action with the login's engine calls (CON-268). */
	private LoginMerge $merge;

	private ?ProfilingConsent $profiling;

	/** Reads the visitor-token cookie and writes a new store token (CON-251). */
	private LandingCapture $cookies;

	/**
	 * @param LoginMerge        $merge     The background action the login queues.
	 * @param ?ProfilingConsent $profiling Profiling-consent gate ((a).1) — when the
	 *        store knows the contact has opted out, queue nothing and create no
	 *        token, so their anon browse history is NOT retroactively bound to
	 *        their profile. The action checks it again. Null = no gate.
	 * @param ?LandingCapture   $cookies   The visitor-token cookie reader and writer;
	 *        null = a LandingCapture on the same settings. Tests pass a recorder.
	 */
	public function __construct( RecEngineSettings $settings, LoginMerge $merge, ?ProfilingConsent $profiling = null, ?LandingCapture $cookies = null ) {
		$this->settings  = $settings;
		$this->merge     = $merge;
		$this->profiling = $profiling;
		$this->cookies   = $cookies ?? new LandingCapture( $settings );
	}

	public function register(): void {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
	}

	/**
	 * `wp_login` fires after a successful authentication; $user is the WP_User.
	 */
	public function on_login( string $user_login, \WP_User $user ): void {
		if ( ! $this->settings->sending_allowed() ) {
			return;
		}

		$anon_sid     = $this->cookie( $this->session_cookie_name() );
		$cookie_token = $this->cookies->current_visitor_token();
		$token        = $cookie_token;
		$consent      = $this->marketing_consent_given();
		// A store-created `vs_` token is consent-based (contract §5, §7: without
		// marketing consent, send none); the engine's `vt_` token from an email
		// link is attribution and stays ungated (F3-46). PRO-3860.
		if ( AttributionShape::is_store_visitor_token( $token ) && ! $consent ) {
			$token = '';
		}
		// No token in this browser: a shopper with marketing consent gets a new
		// store token at this login (CON-251, §7). Never in cron or WP-CLI.
		$create = '' === $cookie_token && $this->may_create_token() && $consent;
		if ( $anon_sid === '' && $token === '' && ! $consent ) {
			return; // Nothing to send — no anon session, no token, no consent.
		}

		$email = strtolower( trim( (string) $user->user_email ) );
		if ( $email === '' ) {
			return;
		}

		// (a).1 profiling gate — if the store knows the contact opted out of
		// profiling, do NOT bind their anon browse history to their identity
		// (respect the opt-out retroactively). No token is created for them
		// either: the engine would not bind it (§7). Only what the store already
		// knows: a fresh read can call the engine, which a login never does.
		if ( $this->profiling !== null && $this->profiling->known_opt_out( $email ) ) {
			return;
		}

		if ( $create ) {
			// '' when the response headers are already sent (no cookie, no token).
			$token = $this->cookies->issue_visitor_token();
		} elseif ( $token !== '' && $this->may_create_token() && $consent ) {
			// The browser keeps its token: a consenting login renews the cookie's
			// lifetime from now, same value (Erkki, 2026-11-02). A `vt_` cookie too —
			// the merge renews a `vt_` token of the same customer in the engine as
			// well (§7) — but only with consent, because a renewal keeps a marketing
			// identifier in the browser longer than the shopper's last choice did.
			$this->cookies->renew_visitor_token( $token );
		}

		// This anon session was already bound to this user: leave it out. A token
		// still goes, because each merge renews it (§7).
		if ( $anon_sid !== '' && (string) get_user_meta( (int) $user->ID, self::MERGED_META_KEY, true ) === $anon_sid ) {
			$anon_sid = '';
		}
		if ( $anon_sid === '' && $token === '' && ! $consent ) {
			return;
		}

		// The engine calls run in the background (CON-268): §4 with consent,
		// then §7. The args carry no email — the action reads it from the id.
		$this->merge->enqueue( (int) $user->ID, $token, $anon_sid, $consent );
	}

	/**
	 * Whether this request may create a visitor token: a shopper's own login,
	 * never cron or WP-CLI (GuestVisitorToken's rule).
	 */
	private function may_create_token(): bool {
		return ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Whether the shopper gave an explicit marketing yes. A seam so tests can
	 * answer it: defining the WP Consent API functions in a test would leak
	 * into every later test of the process.
	 */
	protected function marketing_consent_given(): bool {
		return MarketingConsent::given();
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
}
