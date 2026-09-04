<?php
/**
 * Public browse-beacon proxy: POST /wp-json/smaily-connect/v1/relay.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The ONE public, unauthenticated route in the plugin.
 *
 * Why public: browse events come from anonymous storefront visitors, so the
 * route cannot be `manage_options`-gated like the rest of the rec-engine
 * surface. The contract (§ "API key must never appear in client-side code — a
 * plugin-side server proxy is required for browse events") mandates this proxy:
 * the browser POSTs same-origin to `/beacon`, the plugin decrypts the
 * tenant api_key server-side and forwards to the engine's `/api/v1/ingest/browse`.
 * The api_key never reaches the browser.
 *
 * Because it is public AND spends the tenant's api_key + engine quota on every
 * call, an unprotected `/beacon` is a real attack surface (spam → polluted
 * engine data + cost on the tenant's account). The abuse model (3.4.0) is part
 * of this endpoint, not a later add-on:
 *
 *   1. HARD GATE (404 when off) — the handler's FIRST act is is_enabled()
 *      (connected engine AND browse-tracking on). Disabled ⇒ an immediate 404
 *      before any work: no api_key decrypt, no engine call, no rate-limit
 *      transient, no validation. To a client a disabled `/beacon` is
 *      indistinguishable from an unregistered route. (The route itself is
 *      registered unconditionally — an earlier design registered it
 *      conditionally, but proving "absent when disabled" needs a fresh
 *      WP_REST_Server, and re-firing rest_api_init mid-suite to rebuild it
 *      segfaults wp-env. Gating in the handler is the same attack surface — a
 *      bare 404 doing zero work — and is testable with a plain dispatch.)
 *   2. RATE LIMIT — per-IP AND per-session (the anon-session cookie). IP alone
 *      collapses behind NAT/mobile; the session counter complements it. Fixed
 *      60s windows via transients.
 *   3. SERVER-SIDE VALIDATION — before forwarding: event_type must be one of
 *      the 9 §6 types, every event must carry an event_id, the batch is capped
 *      at 100, and each event is field-whitelisted to the §6 shape. Our own
 *      JS client never produces an invalid type or an id-less event, so a
 *      violation signals tampering ⇒ hard 400, nothing forwarded. The
 *      whitelist (EVENT_FIELDS) deliberately excludes `customer_email`
 *      (PRO-1486) and the two deprecated attribution hints `smaily_rec_id` /
 *      `smaily_ctx` (PRO-1712) — a client-supplied value is spoofable
 *      (arbitrary attribution, or probing another contact's opt-out state by
 *      guessing emails); the only sanctioned source of an identity hint is the
 *      server-side attach_logged_in_identity() below. This strip is scoped to
 *      THIS browse-event POST handler — see EVENT_FIELDS's docblock for the
 *      caveat a future recommendations-GET proxy must not inherit it blindly.
 *
 * Browse does NOT use the IngestQueue/Flusher pattern (catalog/customers/orders
 * do): it is client-buffered, best-effort telemetry forwarded synchronously
 * (the Client's own layered retry covers transient engine blips). A lost batch
 * is acceptable; durable delivery is not warranted for telemetry.
 *
 * The Client is injected via a closure (like RecEngineEndpoint) so integration
 * tests can stand up a mock engine without monkey-patching wp_remote_post.
 */
class BeaconEndpoint {

	/**
	 * Route is `/relay`, NOT `/beacon`: "beacon" matches EasyPrivacy ad-block
	 * filter lists, which blocked the storefront POST for real users. The proxy
	 * is first-party + consent-gated; only the name that tripped the filter is
	 * neutral now (the script file is `sc-runtime.js` for the same reason, F3-41).
	 */
	public const ROUTE = '/relay';

	/** Option flag (master browse gate) written by SettingsEndpoint. */
	public const OPTION_TRACK_BROWSING = 'smly_plus_rec_track_browsing';

	/** Max events per batch (mirrors the engine wrapper cap, §6 / config batch_size_max). */
	public const MAX_EVENTS = 100;

	/** Rate-limit fixed window, seconds. */
	public const RL_WINDOW_SECONDS = 60;

	/** Default per-session request ceiling per window (filterable). */
	public const RL_MAX_PER_SESSION = 30;

	/** Default per-IP request ceiling per window (filterable). */
	public const RL_MAX_PER_IP = 120;

	/**
	 * The full §6 event_type enum — exactly these 9. Any other value is junk
	 * our client never emits ⇒ the whole request is rejected.
	 *
	 * @var array<int, string>
	 */
	public const EVENT_TYPES = array(
		'product_view',
		'category_view',
		'search',
		'cart_add',
		'cart_remove',
		'wishlist_add',
		'wishlist_remove',
		'checkout_start',
		'checkout_complete',
	);

	/**
	 * The §6 per-event field whitelist for a CLIENT-SUPPLIED browse event.
	 * Anything outside this set is dropped before forwarding (caps payload
	 * bloat + blocks key injection).
	 *
	 * PRO-1486: `customer_email` is deliberately NOT in this list — a client
	 * could attach an arbitrary email to anonymous browsing (spoofed
	 * attribution) or probe another contact's profiling opt-out state by
	 * guessing emails. No legitimate producer sends it client-side (the JS
	 * client — `rec-engine-client.ts` `enrich()` — never has, F3-49); the
	 * ONLY sanctioned source is the server-side `attach_logged_in_identity()`
	 * (PRO-1389), which assigns it directly onto the already-whitelisted
	 * event array AFTER validate_batch() runs, bypassing this whitelist
	 * entirely. Scope caveat: this whitelist (and the strip it enacts) is for
	 * the `/relay` BROWSE-EVENT POST path only — a future storefront-
	 * recommendations GET proxy that legitimately takes a `customer_email`
	 * query param is a different call shape and must not inherit this
	 * whitelist unmodified; see the DECISIONS.md PRO-1486 entry.
	 *
	 * PRO-1712: `smaily_rec_id` and `smaily_ctx` are out for the same reason,
	 * now that the last argument for keeping them is gone. Contract v1.7.0
	 * deprecated both as browse-event attribution hints to accept-and-ignore —
	 * the engine dropped the columns and the 4th-priority fallback they fed, so
	 * forwarding a client-supplied value buys nothing and leaves the same
	 * spoofing surface PRO-1486 closed for `customer_email`. Our own JS client
	 * never sent them (`rec-engine-client.ts` `enrich()`, F3-49), so normal
	 * browse traffic is byte-identical. Real rec attribution is unaffected: it
	 * rides the ORDER path (`smaily_rec_id` on §5, from the cookies
	 * `LandingCapture` writes), never the browse event.
	 *
	 * @var array<int, string>
	 */
	private const EVENT_FIELDS = array(
		'event_id',
		'session_id',
		'event_type',
		'sku',
		'category_path',
		'search_query',
		'dwell_seconds',
		'event_ts',
		'source',
		'smaily_visitor_token',
		'external_id',
	);

	private RecEngineSettings $settings;

	/** @var callable(string $api_key, string $base_url): Client */
	private $client_factory;

	private ?ProfilingConsent $profiling;

	/**
	 * @param callable(string $api_key, string $base_url): Client $client_factory
	 * @param ?ProfilingConsent $profiling The beacon's second gate ((a).1) — drops
	 *        browse events carrying an opted-out contact's email before forwarding.
	 *        Also consulted by attach_logged_in_identity() (PRO-1389) before it
	 *        attaches a resolved logged-in email — an opted-out contact's events
	 *        stay anonymous instead. Null = no profiling gate.
	 */
	public function __construct( RecEngineSettings $settings, callable $client_factory, ?ProfilingConsent $profiling = null ) {
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
		$this->profiling      = $profiling;
	}

	/**
	 * Registered unconditionally (so it is in EndpointRegistry::expected_routes
	 * and testable with a plain dispatch). The gate lives in the handler, which
	 * 404s immediately when disabled — see the class docblock for why this beats
	 * conditional registration.
	 */
	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The gate: connected engine + browse-tracking enabled.
	 */
	public function is_enabled(): bool {
		return $this->settings->is_connected() && (bool) get_option( self::OPTION_TRACK_BROWSING, false );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		// HARD GATE — disabled ⇒ a bare 404, indistinguishable from a route
		// that isn't there, BEFORE any work (no api_key, no engine, no rate
		// limit, no validation).
		if ( ! $this->is_enabled() ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'not_found',
				),
				404
			);
		}

		$session = $this->session_id();

		if ( $this->rate_limited( $this->client_ip(), $session ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'rate_limited',
				),
				429
			);
		}

		$raw = $request->get_param( 'events' );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		// PRO-1446: the size cap must gate BEFORE resolve_cart_product_skus()
		// — that step is WP/DB-backed (wc_get_product() per cart event) and
		// must never run over an attacker-controlled, unbounded raw array.
		// size_guard() is the same cheap, pure check validate_batch() opens
		// with, so a genuinely oversized batch gets the identical 400 it
		// always got — only the ordering (before vs. after resolution) changed.
		$size_error = self::size_guard( $raw );
		if ( $size_error !== null ) {
			return self::invalid_response( $size_error );
		}

		$raw = $this->resolve_cart_product_skus( $raw );

		$validation = self::validate_batch( $raw );
		if ( ! $validation['valid'] ) {
			return self::invalid_response( $validation );
		}

		// PRO-1389: attach the resolved logged-in user's email (ongoing-session
		// identity, server-side) BEFORE the profiling gate below — attach_
		// logged_in_identity() itself checks the same gate, so an opted-out
		// contact never gets an email attached in the first place.
		$identity = $this->attach_logged_in_identity( $validation['events'] );

		// SECOND GATE (a).1 — drop browse events carrying an OPTED-OUT contact's
		// email before they leave the building. Anon events (no email) have no
		// contact to check and pass on the cookie-consent gate alone. The
		// verified email/decision from the attach step above lets this skip a
		// redundant may_profile() re-check per event (PRO-1389 follow-up).
		$events = $this->filter_by_profiling( $identity['events'], $identity['verified_allowed'] );
		if ( $events === array() ) {
			// Everything was for opted-out contacts — nothing to forward.
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'processed'    => 0,
					'deduplicated' => 0,
					'errors'       => array(),
				),
				200
			);
		}

		$api_key  = $this->settings->api_key();
		$base_url = $this->settings->base_url();
		if ( $api_key === '' || $base_url === '' ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'configuration_incomplete',
				),
				503
			);
		}

		$client = ( $this->client_factory )( $api_key, $base_url );
		try {
			$result = $client->ingest_browse( $events );
		} catch ( ApiException $e ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'error'   => $e->error_code(),
					'message' => $e->getMessage(),
				),
				502
			);
		}

		// Pass the engine's D6 body straight back (it never contains the
		// api_key); the client logs counts and otherwise fires-and-forgets.
		return new WP_REST_Response(
			array(
				'ok'           => isset( $result['ok'] ) ? (bool) $result['ok'] : true,
				'processed'    => isset( $result['processed'] ) ? (int) $result['processed'] : 0,
				'deduplicated' => isset( $result['deduplicated'] ) ? (int) $result['deduplicated'] : 0,
				'errors'       => isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array(),
			),
			200
		);
	}

	/**
	 * PRO-1389: the ongoing-session identity signal `StorefrontBeacon`'s docblock
	 * deferred. `IdentityHookHandler` only merges identity on `wp_login`, so a
	 * customer who stays logged in browses forever without identity attaching —
	 * this closes that gap, server-side, on every /relay batch.
	 *
	 * Deliberately NOT a page-embedded REST nonce: WP's cookie-auth REST
	 * middleware only populates the current user when a valid X-WP-Nonce
	 * accompanies the request, the beacon sends none, and a page-embedded nonce
	 * would be stale/shared under full-page caching (the MiuMjau reality, see
	 * PRO-1388). Validating the `logged_in` auth cookie directly works regardless
	 * of nonce or caching. An anonymous visitor, or an invalid/expired cookie, is
	 * treated as anonymous — never an error.
	 *
	 * The client NEVER sends `customer_email` — F3-49's client-side data-
	 * minimization, and since PRO-1486 enforced server-side too: validate_batch()
	 * drops any client-supplied `customer_email` before this method ever runs
	 * (it is not in EVENT_FIELDS). This is the ONE sanctioned source of
	 * `customer_email` on a forwarded event, injected purely on the outbound
	 * engine request; the JS blob and the /relay response never see it.
	 *
	 * Consent: event EXISTENCE is still gated solely by the JS marketing-consent
	 * gate (unchanged). This ADDITIONALLY checks the (a).1 profiling gate for the
	 * resolved email before attaching it — an opted-out contact's event is
	 * forwarded UNCHANGED (stays anonymous), never dropped: we simply never add
	 * the identity hint, mirroring the §6 "sender-side anonymous mode".
	 *
	 * Also returns the (a).1 decision so `filter_by_profiling()` doesn't need to
	 * re-check the same email per event (PRO-1389 follow-up; still true after
	 * PRO-1486 — see that method's docblock for why the decision alone is now
	 * sufficient without carrying the email itself).
	 *
	 * @param array<int, array<string, mixed>> $events
	 *
	 * @return array{events: array<int, array<string, mixed>>, verified_allowed: bool}
	 */
	private function attach_logged_in_identity( array $events ): array {
		$email = $this->resolve_logged_in_email();
		if ( $email === '' ) {
			return array(
				'events'           => $events,
				'verified_allowed' => false,
			);
		}

		$allowed = $this->profiling === null || $this->profiling->may_profile( $email );
		if ( ! $allowed ) {
			return array(
				'events'           => $events,
				'verified_allowed' => false,
			);
		}

		foreach ( $events as $index => $event ) {
			$event['customer_email'] = $email;
			$events[ $index ]        = $event;
		}
		return array(
			'events'           => $events,
			'verified_allowed' => true,
		);
	}

	/**
	 * Validate the WP `logged_in` auth cookie directly (not current-user state —
	 * the REST dispatch never authenticates this public route). Protected so
	 * tests can double it without standing up a real auth cookie.
	 */
	protected function resolve_logged_in_email(): string {
		$user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( ! $user_id ) {
			return '';
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User || $user->user_email === '' ) {
			return '';
		}
		return strtolower( trim( (string) $user->user_email ) );
	}

	/**
	 * Drop events whose `customer_email` belongs to a contact that has opted out
	 * of profiling. Anon events (no email) are kept — there's no contact to check,
	 * so only the cookie-consent gate (client-side) applies to them. A
	 * profiling-opt-out drop is a CONSCIOUS drop (the opt-out working), not an
	 * error: aggregated into a 24h counter + logged ONCE per batch (never per
	 * event, so a heavy opted-out browser can't flood the log).
	 *
	 * PRO-1486: this is a defense-in-depth backstop, not the primary gate —
	 * `attach_logged_in_identity()` already checks the SAME profiling decision
	 * (`$verified_allowed`) before ever attaching `customer_email` to an event,
	 * so in the current single-producer graph no event reaching here can carry
	 * an opted-out email. `$verified_allowed` is passed through (rather than
	 * re-deriving it) so a bug elsewhere that attaches an email without its own
	 * consent check still gets caught here. The former per-event
	 * `may_profile()` re-check for a client-supplied email DIFFERING from the
	 * server-resolved one is gone: since validate_batch() now strips any
	 * client-supplied `customer_email` before this point, that scenario can no
	 * longer occur — keeping the extra lookup would have been untestable dead
	 * code.
	 *
	 * @param array<int, array<string, mixed>> $events
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_by_profiling( array $events, bool $verified_allowed ): array {
		if ( $this->profiling === null ) {
			return $events;
		}

		$kept    = array();
		$dropped = 0;
		foreach ( $events as $event ) {
			$has_email = isset( $event['customer_email'] ) && (string) $event['customer_email'] !== '';
			if ( $has_email && ! $verified_allowed ) {
				++$dropped;
				continue;
			}
			$kept[] = $event;
		}

		if ( $dropped > 0 ) {
			$key = 'smly_profiling_dropped_24h';
			set_transient( $key, (int) get_transient( $key ) + $dropped, DAY_IN_SECONDS );
			\Smaily\Connect\Support\DebugLog::write(
				sprintf( '[smaily-connect profiling] dropped %d browse event(s) for opted-out contact(s)', $dropped )
			);
		}

		return $kept;
	}

	/**
	 * Resolve the canonical engine `sku` for `cart_add`/`cart_remove` events
	 * (PRO-1390/PRO-1224). The JS cart listener only has the clicked button's
	 * `data-product_id` (the WC platform id) to work with — it cannot do the
	 * multilingual canonicalization `Support\SkuResolver` does, so it sends the
	 * raw id under `product_id` instead of a client-guessed `sku`. This resolves
	 * it here, server-side, through the SAME resolver catalog/orders use, so a
	 * cart event joins the same catalog row a product_view or order line would.
	 * `product_id` is proxy-internal: it is not in EVENT_FIELDS, so
	 * validate_batch() drops it and it never reaches the engine.
	 *
	 * `product_view` needs no such step — StorefrontBeacon::page_context()
	 * already resolves it server-side (PRO-1224) before the JS ever sees it.
	 *
	 * When the id doesn't resolve to a loadable product (e.g. deleted between
	 * the click and the batch flush), `sku` is left unset rather than forwarding
	 * a guessed value — the event still ingests without a catalog join, logged
	 * once per batch (observable, not a silent wrong-key send).
	 *
	 * WP/WC-dependent (wc_get_product) — integration-tested only, unlike the
	 * pure validate_batch() below.
	 *
	 * @param array<int|string, mixed> $events Raw (pre-whitelist) request events.
	 *
	 * @return array<int|string, mixed>
	 */
	private function resolve_cart_product_skus( array $events ): array {
		$dropped = 0;
		foreach ( $events as $index => $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$event_type = isset( $event['event_type'] ) ? (string) $event['event_type'] : '';
			if ( $event_type !== 'cart_add' && $event_type !== 'cart_remove' ) {
				continue;
			}
			if ( ! isset( $event['product_id'] ) ) {
				continue;
			}

			$product_id = (int) $event['product_id'];
			$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;
			if ( $product instanceof \WC_Product ) {
				$event['sku'] = \Smaily\Connect\Smaily\RecEngine\Support\SkuResolver::resolve( $product );
			} else {
				unset( $event['sku'] );
				++$dropped;
			}
			$events[ $index ] = $event;
		}

		if ( $dropped > 0 ) {
			\Smaily\Connect\Support\DebugLog::write(
				sprintf(
					'[smaily-connect beacon] dropped sku on %d cart event(s) — product_id did not resolve to a loadable product',
					$dropped
				)
			);
		}

		return $events;
	}

	/**
	 * Validate + sanitize a raw events batch. PURE (no WP calls) so it is
	 * unit-testable without a WordPress bootstrap.
	 *
	 * Rules (the abuse filter): non-empty, ≤ MAX_EVENTS, every event is an
	 * array with a non-empty string event_id and an event_type in the §6
	 * allowlist. On the first violation the whole batch is rejected (our own
	 * client never produces one). Valid events are field-whitelisted to the
	 * §6 shape before forwarding.
	 *
	 * @param array<int|string, mixed> $events
	 *
	 * @return array{valid: bool, field: string, message: string, events: array<int, array<string, mixed>>}
	 */
	public static function validate_batch( array $events ): array {
		$size_error = self::size_guard( $events );
		if ( $size_error !== null ) {
			return $size_error;
		}

		$clean = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				return self::invalid( 'events', 'Each event must be an object.' );
			}

			$event_id = isset( $event['event_id'] ) ? (string) $event['event_id'] : '';
			if ( trim( $event_id ) === '' ) {
				return self::invalid( 'event_id', 'Every event needs an event_id (browse has no natural key).' );
			}

			$event_type = isset( $event['event_type'] ) ? (string) $event['event_type'] : '';
			if ( ! in_array( $event_type, self::EVENT_TYPES, true ) ) {
				return self::invalid( 'event_type', 'Unknown event_type.' );
			}

			$row = array();
			foreach ( self::EVENT_FIELDS as $field ) {
				if ( array_key_exists( $field, $event ) ) {
					$row[ $field ] = $event[ $field ];
				}
			}
			$clean[] = $row;
		}

		return array(
			'valid'   => true,
			'field'   => '',
			'message' => '',
			'events'  => $clean,
		);
	}

	/**
	 * @return array{valid: bool, field: string, message: string, events: array<int, array<string, mixed>>}
	 */
	private static function invalid( string $field, string $message ): array {
		return array(
			'valid'   => false,
			'field'   => $field,
			'message' => $message,
			'events'  => array(),
		);
	}

	/**
	 * The cheap, pure non-empty / MAX_EVENTS check — the part of validate_batch()
	 * that must run BEFORE any per-event work (PRO-1446: specifically before
	 * resolve_cart_product_skus()'s wc_get_product() loop). Pulled out so
	 * handle() can gate on it ahead of resolution while validate_batch() still
	 * opens with the same check for a raw (non-proxy) caller.
	 *
	 * @param array<int|string, mixed> $events
	 *
	 * @return array{valid: bool, field: string, message: string, events: array<int, array<string, mixed>>}|null Null when the size is within bounds.
	 */
	private static function size_guard( array $events ): ?array {
		if ( $events === array() ) {
			return self::invalid( 'events', 'No events in the batch.' );
		}
		if ( count( $events ) > self::MAX_EVENTS ) {
			return self::invalid( 'events', 'Batch exceeds the 100-event cap.' );
		}
		return null;
	}

	/**
	 * @param array{valid: bool, field: string, message: string, events: array<int, array<string, mixed>>} $validation
	 */
	private static function invalid_response( array $validation ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'      => false,
				'error'   => 'invalid_events',
				'field'   => $validation['field'],
				'message' => $validation['message'],
			),
			400
		);
	}

	/**
	 * Fixed-window per-IP + per-session throttle via transients. Approximate
	 * (transients aren't atomic) — fine for rate-limiting. Returns true when
	 * EITHER counter is over its ceiling for the current window.
	 */
	private function rate_limited( string $ip, string $session ): bool {
		$over = false;

		if ( $ip !== '' ) {
			$max = (int) apply_filters( 'smaily_connect_beacon_rate_limit_ip', self::RL_MAX_PER_IP );
			if ( $this->bump( 'smly_beacon_rl_ip_' . md5( $ip ), $max ) ) {
				$over = true;
			}
		}

		if ( $session !== '' ) {
			$max = (int) apply_filters( 'smaily_connect_beacon_rate_limit_session', self::RL_MAX_PER_SESSION );
			if ( $this->bump( 'smly_beacon_rl_s_' . md5( $session ), $max ) ) {
				$over = true;
			}
		}

		return $over;
	}

	/**
	 * Increment a window counter; return true once it exceeds $max.
	 */
	private function bump( string $key, int $max ): bool {
		$count = (int) get_transient( $key );
		++$count;
		set_transient( $key, $count, self::RL_WINDOW_SECONDS );
		return $count > $max;
	}

	/**
	 * REMOTE_ADDR only — X-Forwarded-For is attacker-spoofable, so trusting it
	 * would let one client masquerade as many IPs and defeat the throttle.
	 */
	private function client_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$raw = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP );
		return is_string( $ip ) ? $ip : '';
	}

	/**
	 * The anonymous-session cookie value (name comes from the engine config,
	 * default smaily_anon_sid). Empty before the client sets it on first visit.
	 */
	private function session_id(): string {
		$config = $this->settings->config();
		$name   = isset( $config['session_cookie_name'] ) ? (string) $config['session_cookie_name'] : 'smaily_anon_sid';
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) );
	}
}
