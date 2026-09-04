<?php
/**
 * WC + WP hook callbacks for the namespaced Smaily\Connect\* code.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Settings\SetupState;
use Smaily\Connect\Smaily\AutomationMarker;
use Smaily\Connect\Smaily\ContactAudience;
use Smaily\Connect\Smaily\ContactReconciler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Support\ContactLanguageResolver;

/**
 * Hook callbacks that fan WordPress + WooCommerce events into the Smaily
 * event queue (PLUGIN.md §9). Three concerns lived here:
 *
 *   1. Translate WP/WC entities into Smaily event payloads
 *      (event_type + entity_id + key-value array).
 *   2. Skip duplicate fires within one request via a static $seen guard.
 *      profile_update in particular can be called repeatedly during a
 *      single Settings save as WordPress applies the form fields one by
 *      one.
 *   3. Persist the four rec-engine attribution cookies into order meta
 *      as soon as the order is created (PLUGIN.md §9 "Order-meta
 *      attribution"). The cookies aren't reachable from the
 *      woocommerce_order_status_completed hook later — that callback
 *      can fire days later when admin marks the order complete — so
 *      capturing them in woocommerce_checkout_order_processed is the
 *      only safe time. Rec-engine consumption of those values lands in
 *      Phase 3; saving them now means Phase 3 doesn't need to refactor
 *      the hook layer.
 *
 * Settings options gating the actual dispatch (defaults set conservatively
 * so a fresh install doesn't fire workflows until the Settings UI in
 * sub-PR 6 turns them on):
 *
 *   - the "Sync contacts to Smaily" switch — on by default (PLUGIN.md §5
 *     step 2), read through ContactSyncMode::sync_enabled()
 *   - smly_plus_welcome_enabled         — off (opt-in via Settings UI)
 *   - smly_plus_first_order_enabled     — off (opt-in via Settings UI)
 */
class HookHandler {

	public const EVENT_CONTACT_SYNC           = 'contact.sync';
	public const EVENT_AUTOMATION_WELCOME     = 'automation.welcome';
	public const EVENT_AUTOMATION_FIRST_ORDER = 'automation.first_order';

	/**
	 * Filter deciding whether a newly created account is enrolled in the welcome
	 * automation (PRO-1682). Default: true for an account WooCommerce created
	 * (`woocommerce_created_customer` — checkout, My Account registration),
	 * false for a bare `user_register` (wp-admin staff accounts, accounts an
	 * unrelated plugin creates). Receives ( bool $eligible, int $user_id,
	 * string $source ), so a store can widen or narrow it per source — e.g.
	 * return true on `user_register` to welcome accounts a shop-specific
	 * registration plugin creates outside WooCommerce's own flows.
	 */
	public const FILTER_WELCOME_ELIGIBLE = 'smaily_connect_welcome_eligible';

	private const OPTION_WELCOME_ENABLED     = 'smly_plus_welcome_enabled';
	private const OPTION_FIRST_ORDER_ENABLED = 'smly_plus_first_order_enabled';

	/**
	 * Order meta key => the engine-config key naming the cookie it is read from,
	 * plus the default cookie name the engine ships with.
	 *
	 * The META keys are our own order schema and are fixed. The COOKIE names are
	 * tenant configuration: every writer and reader of them — StorefrontBeacon,
	 * LandingCapture, IdentityHookHandler, BeaconEndpoint — resolves them from
	 * `RecEngineSettings::config()`, so a tenant with overridden names would
	 * otherwise have its cookies written under one name and looked up here under
	 * another, stamping no attribution onto any order at all (PRO-1902).
	 */
	private const ORDER_META_COOKIES = array(
		'_smaily_anon_session_id' => array( 'session_cookie_name', 'smaily_anon_sid' ),
		'_smaily_visitor_token'   => array( 'tracking_cookie_name', 'smaily_rec_uid' ),
		'_smaily_rec_id'          => array( 'rec_id_cookie_name', 'smaily_rec_id' ),
		'_smaily_rec_ctx'         => array( 'context_cookie_name', 'smaily_rec_ctx' ),
	);

	/**
	 * Longest value each attribution cookie can legitimately hold, from the
	 * shapes LandingCapture accepts: a UUID rec_id (Support\RecId), `vt_` + up
	 * to 64 alphanumerics, a context slug of up to 64. The anonymous session id
	 * is a UUID in every producer we ship but is shape-checked nowhere, so it
	 * gets the same generous 64 bound rather than an exact one.
	 *
	 * A cookie over its cap cannot be a real signal, so it is dropped rather
	 * than truncated — a trimmed token would be a plausible-looking wrong value
	 * on the §5 orders wire. This is the backstop for a value planted by the
	 * pre-PRO-1896 permissive JS writer, which outlives the fixed bundle by the
	 * cookie's 30/365-day TTL.
	 */
	private const ORDER_META_MAX_LENGTH = array(
		'_smaily_anon_session_id' => 64,
		'_smaily_visitor_token'   => 67,
		'_smaily_rec_id'          => 36,
		'_smaily_rec_ctx'         => 64,
	);

	/** @var array<string, bool> per-request dedupe set keyed by "{event}:{entity_id}". */
	private static array $seen = array();

	/** @var bool Per-request guard so the closed-gate notice logs at most once. */
	private static bool $gate_logged = false;

	private EventQueue $queue;

	private ?\Smaily\Connect\Smaily\SubscriberPayloadBuilder $builder = null;

	private ?ContactLanguageResolver $language_resolver = null;

	private ?ContactAudience $audience = null;

	private ?ContactSyncMode $mode = null;

	private ?RecEngineSettings $rec_settings = null;

	public function __construct( EventQueue $queue ) {
		$this->queue = $queue;
	}

	public function on_user_register( int $user_id ): void {
		if ( $this->gate_closed() ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( $user === false ) {
			return;
		}

		if ( ContactSyncMode::sync_enabled() && $this->audience()->should_sync_user( $user ) ) {
			$this->maybe_enqueue(
				self::EVENT_CONTACT_SYNC,
				(string) $user_id,
				$this->build_contact_payload( $user )
			);
		}

		// A bare user_register is NOT a shopper signal — see maybe_enqueue_welcome.
		$this->maybe_enqueue_welcome( $user, 'user_register', false );
	}

	public function on_profile_update( int $user_id ): void {
		if ( $this->gate_closed() ) {
			return;
		}

		if ( ! ContactSyncMode::sync_enabled() ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( $user === false ) {
			return;
		}

		if ( ! $this->audience()->should_sync_user( $user ) ) {
			return;
		}

		$this->maybe_enqueue(
			self::EVENT_CONTACT_SYNC,
			(string) $user_id,
			$this->build_contact_payload( $user )
		);
	}

	/**
	 * Propagate a WP marketing opt-in / opt-out to Smaily (F3-48.6, consent mode
	 * only). Bound to `update_user_meta` (existing meta changes) — the action
	 * fires BEFORE the write, so get_user_meta still returns the OLD value.
	 *
	 * @param int|string $meta_id
	 * @param mixed      $meta_value
	 */
	public function on_user_newsletter_meta_update( $meta_id, int $object_id, string $meta_key, $meta_value ): void {
		if ( $meta_key !== ContactAudience::OPTIN_META ) {
			return;
		}
		$old = function_exists( 'get_user_meta' ) ? (int) get_user_meta( $object_id, ContactAudience::OPTIN_META, true ) : 0;
		$this->handle_newsletter_change( $object_id, $old, (int) $meta_value );
	}

	/**
	 * Same as on_user_newsletter_meta_update but for the FIRST set of the meta
	 * (WordPress routes a never-seen key through `add_user_meta`, not update).
	 * No prior value → old = 0.
	 *
	 * @param mixed $meta_value
	 */
	public function on_user_newsletter_meta_add( int $object_id, string $meta_key, $meta_value ): void {
		if ( $meta_key !== ContactAudience::OPTIN_META ) {
			return;
		}
		$this->handle_newsletter_change( $object_id, 0, (int) $meta_value );
	}

	public function on_woocommerce_created_customer( int $customer_id ): void {
		// Contact sync is the same for any new account, so reuse that path —
		// user_register already fired inside wp_insert_user and maybe_enqueue
		// dedupes, so this doesn't double-enqueue.
		$this->on_user_register( $customer_id );

		// The welcome, however, fires ONLY from here: this hook is WooCommerce's
		// own "a shopper got an account" signal (PRO-1682).
		if ( $this->gate_closed() ) {
			return;
		}

		$user = get_userdata( $customer_id );
		if ( $user === false ) {
			return;
		}

		$this->maybe_enqueue_welcome( $user, 'woocommerce_created_customer', true );
	}

	/**
	 * Enrol a newly created account in the welcome automation — when the account
	 * is a shopper's (PRO-1682).
	 *
	 * The welcome trigger used to fire on any `user_register`, so a staff account
	 * created in wp-admin or an account an unrelated plugin created (membership,
	 * forum) became an opted-in marketing contact — with no customer relationship
	 * to rest a legitimate-interest basis on. Enrolment can't be undone once the
	 * trigger fires, so WHO triggers is the only lever.
	 *
	 * The signal is the FLOW, not the role: `woocommerce_created_customer` fires
	 * only for an account WooCommerce itself created — checkout, My Account
	 * registration, order-confirmation "create an account" — and never for
	 * wp-admin's Add New User or a plain wp_insert_user(). Keying on the flow
	 * also leaves custom shopper roles (wholesale, VIP) working: a plugin can
	 * swap the role through the `woocommerce_new_customer_data` filter and the
	 * hook still fires, so a role check is never consulted.
	 *
	 * @param string $source           Hook the account arrived through.
	 * @param bool   $eligible_default Whether that hook is a shopper signal.
	 */
	private function maybe_enqueue_welcome( \WP_User $user, string $source, bool $eligible_default ): void {
		if ( ! $this->is_enabled( self::OPTION_WELCOME_ENABLED, false ) ) {
			return;
		}

		/** This filter is documented on HookHandler::FILTER_WELCOME_ELIGIBLE. */
		if ( ! (bool) apply_filters( self::FILTER_WELCOME_ELIGIBLE, $eligible_default, (int) $user->ID, $source ) ) {
			return;
		}

		$this->maybe_enqueue(
			self::EVENT_AUTOMATION_WELCOME,
			(string) $user->ID,
			$this->build_automation_payload( $user, 'welcome' )
		);
	}

	/**
	 * @param array<string, mixed> $posted_data Classic-checkout POSTed fields (3-arg hook).
	 */
	public function on_checkout_order_processed( int $order_id, array $posted_data = array() ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Attribution capture is order-meta only — no Smaily call — so it runs
		// regardless of the wizard gate; the rec-engine reads these later.
		$this->save_attribution_cookies_to_order( $order );

		// Gate the sync path (P1 #1): only fire first-order automation once
		// the wizard is finished, so it never doubles the legacy sync.
		if ( $this->gate_closed() ) {
			return;
		}

		// F3-48 F1: order-path contact sync — guests + the checkout-opt-in preset.
		// Classic checkout carries the newsletter checkbox in $posted_data; block
		// checkout uses on_checkout_block_optin instead.
		$opted_in = isset( $posted_data['user_newsletter'] ) && (int) $posted_data['user_newsletter'] === 1;
		$this->sync_order_contact( $order, $opted_in );

		$this->maybe_enqueue_first_order( $order );
	}

	/**
	 * Block-checkout opt-in (F3-48 F1). The WC Blocks Store API carries the
	 * subscription checkbox in the request extensions, not in classic POST data.
	 *
	 * @param mixed $request The store-API request (array-accessible extensions bag).
	 */
	public function on_checkout_block_optin( \WC_Order $order, $request ): void {
		$opted_in = isset( $request['extensions']['smaily-checkout-optin']['user_newsletter'] )
			&& true === $request['extensions']['smaily-checkout-optin']['user_newsletter'];

		$this->sync_order_contact( $order, $opted_in );
	}

	/**
	 * Stamp the rec-attribution cookies onto a BLOCK-checkout order
	 * (`woocommerce_store_api_checkout_order_processed`). The classic-checkout
	 * stamping in on_checkout_order_processed never fires for Store-API orders,
	 * so a block-checkout store captured the `smaily_rec` cookie but the order
	 * never carried `_smaily_rec_id` → `smaily_rec_id` was absent from every
	 * order payload (the F3-46 "classic checkout only" gap; MiuMjau field
	 * regression 2026-06-30). Attribution is rec-engine, not contact sync, so it
	 * runs ungated — exactly like the classic path.
	 *
	 * The first-order automation rides the same twin (PRO-1679): it was left on
	 * the classic hook when F3-46 carried attribution across, so on a
	 * block-checkout store — the WooCommerce default — it never fired for
	 * anyone. Contact sync is NOT repeated here; block checkout syncs the
	 * contact from `on_checkout_block_optin`, which is the only place the
	 * Store-API opt-in flag is readable.
	 */
	public function on_block_checkout_order_processed( \WC_Order $order ): void {
		$this->save_attribution_cookies_to_order( $order );
		$this->maybe_enqueue_first_order( $order );
	}

	/**
	 * Enqueue the first-order automation for an order, if enabled and this is
	 * the customer's first one. Shared by the classic and block checkout hooks;
	 * a store where both fire for one order still enqueues once — the hooks fire
	 * in the same request and maybe_enqueue() dedupes on
	 * "automation.first_order:{order_id}".
	 */
	private function maybe_enqueue_first_order( \WC_Order $order ): void {
		if ( $this->gate_closed() ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( $email === '' ) {
			return;
		}

		if ( ! $this->is_enabled( self::OPTION_FIRST_ORDER_ENABLED, false ) ) {
			return;
		}

		if ( ! $this->is_first_order( $order ) ) {
			return;
		}

		$order_id = (string) $order->get_id();
		$payload  = array(
			'email'  => $email,
			'fields' => array_merge(
				array(
					'order_id'       => $order_id,
					'order_total'    => (string) $order->get_total(),
					'order_currency' => $order->get_currency(),
				),
				AutomationMarker::stamp( 'first_order' )
			),
		);

		$language = $this->detect_language_for_order( $order );
		if ( $language !== '' ) {
			$payload['language'] = $language;
		}

		$this->maybe_enqueue( self::EVENT_AUTOMATION_FIRST_ORDER, $order_id, $payload );
	}

	/**
	 * Enqueue a contact.sync for an order's billing email when the mode's
	 * audience says so (F3-48 F1 — the guest / checkout-opt-in path the account
	 * hooks don't cover). Registered customers in consent / legitimate interest
	 * are handled by the account hooks; checkout-only routes everyone here.
	 */
	private function sync_order_contact( \WC_Order $order, bool $opted_in ): void {
		if ( $this->gate_closed() || ! ContactSyncMode::sync_enabled() ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( ! $this->audience()->should_sync_order_email( $customer_id, $opted_in ) ) {
			return;
		}

		$email = (string) $order->get_billing_email();
		if ( $email === '' ) {
			return;
		}

		$user = $customer_id > 0 ? get_userdata( $customer_id ) : false;
		if ( $user instanceof \WP_User ) {
			$payload = $this->build_contact_payload( $user );
		} else {
			// Guest (no WP_User): a minimal payload, enriched with the billing
			// name the order already carries so the contact isn't email-only.
			$fields = array( 'store' => function_exists( 'get_site_url' ) ? (string) get_site_url() : '' );
			$first  = trim( (string) $order->get_billing_first_name() );
			$last   = trim( (string) $order->get_billing_last_name() );
			if ( $first !== '' ) {
				$fields['first_name'] = $first;
			}
			if ( $last !== '' ) {
				$fields['last_name'] = $last;
			}

			$payload  = array(
				'email'  => $email,
				'fields' => $fields,
			);
			$language = $this->detect_language_for_order( $order );
			if ( $language !== '' ) {
				$payload['language'] = $language;
			}
		}

		// An explicit checkout opt-in subscribes (consent / checkout-only); under
		// legitimate interest Smaily owns consent, so is_unsubscribed is omitted.
		if ( $opted_in && $this->mode()->mode() !== ContactSyncMode::MODE_LEGITIMATE_INTEREST ) {
			$payload['is_unsubscribed'] = 0;
		}

		$this->maybe_enqueue( self::EVENT_CONTACT_SYNC, 'order:' . $order->get_id(), $payload );
	}

	/**
	 * Reset the per-request dedupe set. Tests use this between cases;
	 * production code never calls it — the static is request-scoped and
	 * PHP discards it at request end.
	 */
	public static function reset_seen(): void {
		self::$seen        = array();
		self::$gate_logged = false;
	}

	/**
	 * Master gate (P1 #1). Returns true — callback must no-op — until the
	 * setup wizard is finished. Before Finish the legacy subscriber-sync
	 * owns WooCommerce events; this handler firing too would double-send
	 * the contact (two API calls, risk of double automation). Logs the
	 * first closed-gate touch per request when WP_DEBUG so the dormancy is
	 * visible without spamming production debug.log.
	 */
	private function gate_closed(): bool {
		if ( SetupState::completed() ) {
			return false;
		}

		if ( ! self::$gate_logged && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			self::$gate_logged = true;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\Smaily\Connect\Support\DebugLog::write( '[smaily-connect] Live sync deferred: smly_plus_setup_completed is false; legacy Smaily sync owns WooCommerce events until the setup wizard is finished.' );
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function maybe_enqueue( string $event_type, string $entity_id, array $payload ): void {
		$key = $event_type . ':' . $entity_id;
		if ( isset( self::$seen[ $key ] ) ) {
			return;
		}
		self::$seen[ $key ] = true;

		$this->queue->enqueue( $event_type, $entity_id, $payload );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_contact_payload( \WP_User $user ): array {
		$payload = array(
			'email'  => (string) $user->user_email,
			'fields' => $this->payload_builder()->build_fields( $user ),
		);

		// Omit `language` when unresolved — Smaily treats absent as
		// "leave existing intact", empty as "wipe". Never wipe.
		$language = $this->detect_language_for_user( $user );
		if ( $language !== '' ) {
			$payload['language'] = $language;
		}

		return $payload;
	}

	/**
	 * @param string $trigger AutomationRouter trigger slug — also picks the
	 *                        PRO-1681 marker field recording this run.
	 *
	 * @return array<string, mixed>
	 */
	private function build_automation_payload( \WP_User $user, string $trigger ): array {
		$payload = array(
			'email'  => (string) $user->user_email,
			'fields' => array_merge(
				$this->payload_builder()->build_fields( $user ),
				AutomationMarker::stamp( $trigger )
			),
		);

		$language = $this->detect_language_for_user( $user );
		if ( $language !== '' ) {
			$payload['language'] = $language;
		}

		return $payload;
	}

	private function payload_builder(): \Smaily\Connect\Smaily\SubscriberPayloadBuilder {
		if ( $this->builder === null ) {
			$this->builder = new \Smaily\Connect\Smaily\SubscriberPayloadBuilder();
		}
		return $this->builder;
	}

	private function language_resolver(): ContactLanguageResolver {
		if ( $this->language_resolver === null ) {
			$this->language_resolver = new ContactLanguageResolver();
		}
		return $this->language_resolver;
	}

	private function audience(): ContactAudience {
		if ( $this->audience === null ) {
			$this->audience = new ContactAudience();
		}
		return $this->audience;
	}

	private function mode(): ContactSyncMode {
		if ( $this->mode === null ) {
			$this->mode = new ContactSyncMode();
		}
		return $this->mode;
	}

	private function rec_settings(): RecEngineSettings {
		if ( $this->rec_settings === null ) {
			$this->rec_settings = new RecEngineSettings();
		}
		return $this->rec_settings;
	}

	/**
	 * Turn a user_newsletter transition into a Smaily consent change. Opt-in
	 * (→1) sends is_unsubscribed=0 (subscribe — an explicit re-grant overrides a
	 * prior Smaily unsubscribe); opt-out (1→0) sends is_unsubscribed=1. Only in
	 * consent mode: legitimate interest leaves consent to Smaily, checkout-only
	 * has no accounts. The regular data sync (on_profile_update) never sends
	 * is_unsubscribed — so a routine profile edit can't resurrect a Smaily
	 * unsubscribe between reconciles; only an actual opt-state transition does.
	 */
	private function handle_newsletter_change( int $user_id, int $old_value, int $new_value ): void {
		// The reconciler's own Smaily→WP write fires this hook — don't echo it
		// back to Smaily (a mirrored `delete` would re-create the contact).
		if ( ContactReconciler::is_applying() ) {
			return;
		}
		if ( $old_value === $new_value || $this->gate_closed() ) {
			return;
		}
		if ( ! ContactSyncMode::sync_enabled() ) {
			return;
		}
		if ( $this->mode()->mode() !== ContactSyncMode::MODE_CONSENT ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( $user === false ) {
			return;
		}

		if ( $new_value === 1 ) {
			$this->enqueue_consent_change( $user, 0 );
		} elseif ( $old_value === 1 ) {
			$this->enqueue_consent_change( $user, 1 );
		}
	}

	private function enqueue_consent_change( \WP_User $user, int $is_unsubscribed ): void {
		$payload                    = $this->build_contact_payload( $user );
		$payload['is_unsubscribed'] = $is_unsubscribed;

		// Distinct entity id so this consent event is a SEPARATE queue row from
		// any same-request data sync (which omits is_unsubscribed = preserve) —
		// the per-request dedupe never swallows the consent change.
		$this->maybe_enqueue( self::EVENT_CONTACT_SYNC, $user->ID . ':consent', $payload );
	}

	private function detect_language_for_user( \WP_User $user ): string {
		return $this->language_resolver()->for_user( $user );
	}

	private function detect_language_for_order( \WC_Order $order ): string {
		return $this->language_resolver()->for_order( $order );
	}

	/**
	 * True when the order is the customer's first paid order. Anonymous
	 * checkouts (guest, no account) never qualify — there's no prior
	 * order history to compare against, so we conservatively skip.
	 */
	private function is_first_order( \WC_Order $order ): bool {
		$customer_id = $order->get_customer_id();
		if ( $customer_id <= 0 || ! function_exists( 'wc_get_customer_order_count' ) ) {
			return false;
		}

		return (int) wc_get_customer_order_count( $customer_id ) === 1;
	}

	private function save_attribution_cookies_to_order( \WC_Order $order ): void {
		$wrote_any = false;
		$config    = $this->rec_settings()->config();

		foreach ( self::ORDER_META_COOKIES as $meta_key => $spec ) {
			list( $config_key, $default_name ) = $spec;

			$cookie_name = isset( $config[ $config_key ] ) ? (string) $config[ $config_key ] : '';
			if ( '' === $cookie_name ) {
				$cookie_name = $default_name;
			}

			if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
			if ( $value === '' ) {
				continue;
			}

			if ( strlen( $value ) > self::ORDER_META_MAX_LENGTH[ $meta_key ] ) {
				// Shape-only log: the value itself is untrusted request input.
				\Smaily\Connect\Support\DebugLog::write(
					sprintf(
						'[smaily-connect] order %d: dropped an oversized %s cookie (%d chars, max %d) — not stamped onto the order.',
						$order->get_id(),
						$cookie_name,
						strlen( $value ),
						self::ORDER_META_MAX_LENGTH[ $meta_key ]
					)
				);
				continue;
			}

			$order->update_meta_data( $meta_key, $value );
			$wrote_any = true;
		}

		if ( $wrote_any ) {
			$order->save();
		}
	}

	private function is_enabled( string $option_key, bool $fallback ): bool {
		$value = get_option( $option_key, $fallback );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return $value === 1 || $value === '1' || $value === 'yes' || $value === 'true';
	}
}
