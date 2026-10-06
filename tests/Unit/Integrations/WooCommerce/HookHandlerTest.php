<?php
/**
 * Tests for the WC + WP hook callbacks.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\WooCommerce\GuestVisitorToken;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Integrations\WooCommerce\LandingCapture;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\AutomationMarker;
use Smaily\Connect\Smaily\CartFlusher;
use Smaily\Connect\Smaily\ContactReconciler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;
use Smaily\Connect\Tests\Unit\Support\FakeRecEngineSettings;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Integrations\WooCommerce\Profile_Settings;

// The legacy tree is not autoloaded (smaily.class.php require_once's it).
require_once __DIR__ . '/../../../../includes/smaily-options.class.php';
require_once __DIR__ . '/../../../../integrations/woocommerce/profile-settings.class.php';

final class HookHandlerTest extends TestCase {

	/** @var array<int, array{type: string, entity_id: string, payload: array<string, mixed>}> */
	private array $enqueued = array();

	/** @var array<int, string> "{event_type}|{email}" pairs the fake queue reports as delivered. */
	private array $delivered = array();

	/** @var array<int, array{type: string, email: string}> withdraw_pending_for() calls. */
	private array $cancelled = array();

	private EventQueue $queue;

	/** @var array<int, array<string, mixed>> user id => meta key => value, for the PRO-3406 cases. */
	private array $user_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		HookHandler::reset_seen();
		// ContactLanguageResolver caches the active detector via DetectorFactory
		// (a process-global static). Reset so each case resolves through the
		// single-language SiteLocale fallback, independent of other tests.
		DetectorFactory::reset();
		$this->enqueued  = array();
		$this->delivered = array();
		$this->cancelled = array();
		$this->user_meta = array();

		// Fake EventQueue that records enqueue() calls in the local array
		// instead of touching $wpdb / Action Scheduler. The PRO-1723 lookup
		// is recorded/answered the same way — it is one SQL read.
		$enqueued    = &$this->enqueued;
		$delivered   = &$this->delivered;
		$cancelled   = &$this->cancelled;
		$this->queue = new class( $enqueued, $delivered, $cancelled ) extends EventQueue {
			private array $sink;
			private array $delivered;
			private array $cancelled;

			public function __construct( array &$sink, array &$delivered, array &$cancelled ) {
				$this->sink      = &$sink;
				$this->delivered = &$delivered;
				$this->cancelled = &$cancelled;
			}

			public function enqueue( string $event_type, string $entity_id, array $payload ): ?int {
				$this->sink[] = array(
					'type'      => $event_type,
					'entity_id' => $entity_id,
					'payload'   => $payload,
				);
				return count( $this->sink );
			}

			public function withdraw_pending_for( string $event_type, string $email ): bool {
				$this->cancelled[] = array(
					'type'  => $event_type,
					'email' => $email,
				);

				return in_array( $event_type . '|' . $email, $this->delivered, true );
			}
		};

		Functions\when( 'get_user_locale' )->justReturn( 'et_EE' );
		Functions\when( 'get_locale' )->justReturn( 'et_EE' );
		// Users are opted-in by default so the default (consent) contact-sync
		// mode lets contact.sync through (F3-48 audience). No preferred-language
		// meta → the resolver falls through to the SiteLocale default
		// ('et_EE' → 'et'). Audience-gating cases override this stub.
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) {
				return $key === 'user_newsletter' ? '1' : '';
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		// No account behind an order unless a case says so — the order paths
		// look the buyer's account address up (PRO-1723).
		Functions\when( 'get_userdata' )->justReturn( false );

		// Default Settings: subscriber sync on, welcome / first_order off.
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_SYNC_ENABLED ) {
					return true;
				}
				if ( $key === 'smly_plus_welcome_enabled' ) {
					return false;
				}
				if ( $key === 'smly_plus_first_order_enabled' ) {
					return false;
				}
				return $default;
			}
		);
	}

	protected function tearDown(): void {
		HookHandler::reset_seen();
		DetectorFactory::reset();
		Monkey\tearDown();
		parent::tearDown();
		$_COOKIE = array();
		$_POST   = array();
	}

	public function test_gate_closed_suppresses_callbacks_until_setup_completed(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return false;
				}
				if ( $key === ContactSyncMode::OPTION_SYNC_ENABLED ) {
					return true;
				}
				return $default;
			}
		);
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 7, 'gated@example.test', 'G', 'X' ) );

		$handler = new HookHandler( $this->queue );
		$handler->on_user_register( 7 );
		$handler->on_profile_update( 7 );

		self::assertSame( array(), $this->enqueued, 'Closed gate (wizard unfinished) must suppress every new callback so legacy owns sync.' );
	}

	public function test_gate_open_allows_enqueue_after_setup_completed(): void {
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 7, 'open@example.test', 'O', 'X' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 7 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $this->enqueued[0]['type'] );
	}

	public function test_user_register_enqueues_contact_sync(): void {
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'alice@example.test', 'Alice', 'A' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 42 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $this->enqueued[0]['type'] );
		self::assertSame( '42', $this->enqueued[0]['entity_id'] );
		self::assertSame( 'alice@example.test', $this->enqueued[0]['payload']['email'] );
		// Resolver normalises to the short content-language code; with no
		// preferred-language meta it lands on the SiteLocale default ('et').
		self::assertSame( 'et', $this->enqueued[0]['payload']['language'] );
		self::assertSame( 'Alice', $this->enqueued[0]['payload']['fields']['first_name'] );
	}

	public function test_consent_mode_skips_contact_sync_for_non_opted_in_user(): void {
		// Default mode is consent → a user without user_newsletter=1 is not in
		// the audience, so no contact.sync (F3-48).
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 42 );

		self::assertSame( array(), array_column( $this->enqueued, 'type' ) );
	}

	public function test_legitimate_interest_mode_syncs_non_opted_in_user(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_SYNC_ENABLED ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_MODE ) {
					return ContactSyncMode::MODE_LEGITIMATE_INTEREST;
				}
				return $default;
			}
		);
		// Not opted in — legitimate interest syncs everyone anyway.
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 42 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $this->enqueued[0]['type'] );
	}

	public function test_regular_contact_sync_never_carries_is_unsubscribed(): void {
		// Regression lock (F3-48.6): a routine data sync must NOT send
		// is_unsubscribed — only an explicit opt-state transition does — so a
		// profile edit can't resurrect a Smaily unsubscribe between reconciles.
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 42 );

		self::assertCount( 1, $this->enqueued );
		self::assertArrayNotHasKey( 'is_unsubscribed', $this->enqueued[0]['payload'] );
	}

	public function test_newsletter_optout_enqueues_unsubscribe_consent_event(): void {
		// Default consent mode; setUp stubs user_newsletter='1' (opted in) → the
		// pre-write old value; new value 0 → opt-out.
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_newsletter_meta_update( 0, 42, 'user_newsletter', 0 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $this->enqueued[0]['type'] );
		self::assertSame( '42:consent', $this->enqueued[0]['entity_id'] );
		self::assertSame( 1, $this->enqueued[0]['payload']['is_unsubscribed'] );
	}

	public function test_newsletter_optin_enqueues_subscribe_consent_event(): void {
		// Add path → old = 0 (no prior meta); new = 1 → opt-in.
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_newsletter_meta_add( 42, 'user_newsletter', 1 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( 0, $this->enqueued[0]['payload']['is_unsubscribed'] );
	}

	public function test_reconcile_write_does_not_echo_a_consent_change_back(): void {
		// Security re-audit fix: the reconciler's own Smaily→WP user_newsletter
		// write fires this hook; it must NOT echo a contact.sync back to Smaily
		// (a mirrored `delete` would otherwise re-create the deleted contact).
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		ContactReconciler::run_suppressed(
			function (): void {
				( new HookHandler( $this->queue ) )->on_user_newsletter_meta_update( 0, 42, 'user_newsletter', 0 );
			}
		);

		self::assertSame( array(), $this->enqueued, 'A reconcile-driven meta write must not echo back to Smaily.' );
	}

	public function test_newsletter_change_ignored_for_other_meta_keys(): void {
		( new HookHandler( $this->queue ) )->on_user_newsletter_meta_update( 0, 42, 'billing_phone', 0 );

		self::assertSame( array(), $this->enqueued );
	}

	public function test_newsletter_change_ignored_outside_consent_mode(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_SYNC_ENABLED ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_MODE ) {
					return ContactSyncMode::MODE_LEGITIMATE_INTEREST;
				}
				return $default;
			}
		);
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_newsletter_meta_update( 0, 42, 'user_newsletter', 0 );

		self::assertSame( array(), $this->enqueued, 'Legitimate interest leaves consent to Smaily.' );
	}

	public function test_user_register_skips_contact_sync_when_disabled(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 42 );

		self::assertCount( 0, $this->enqueued );
	}

	public function test_switching_contact_sync_off_stops_every_contact_path(): void {
		// PRO-1742: the switch the merchant sees, off — the wizard is finished
		// and the store is otherwise a normal legitimate-interest store that
		// also syncs guests, so only the switch can stop these.
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_SYNC_ENABLED ) {
					return '';
				}
				if ( $key === ContactSyncMode::OPTION_MODE ) {
					return ContactSyncMode::MODE_LEGITIMATE_INTEREST;
				}
				if ( $key === ContactSyncMode::OPTION_INCLUDE_GUESTS ) {
					return '1';
				}
				return $default;
			}
		);
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', 'A', 'B' ) );
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'guest@example.test', 0, 1 ) );

		$handler = new HookHandler( $this->queue );
		$handler->on_user_register( 42 );
		$handler->on_profile_update( 42 );
		$handler->on_user_newsletter_meta_update( 0, 42, 'user_newsletter', 1 );
		$handler->on_checkout_order_processed( 100, array( 'user_newsletter' => 1 ) );

		self::assertSame( array(), $this->enqueued, 'Contact sync switched off means no contact reaches Smaily, from any path.' );
	}

	public function test_bare_user_register_never_fires_welcome_even_when_option_on(): void {
		// PRO-1682: a staff account created in wp-admin, or an account an
		// unrelated plugin creates, arrives on user_register alone — no customer
		// relationship, so no welcome enrolment.
		$this->enable_welcome();
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 7, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_user_register( 7 );

		self::assertSame(
			array( HookHandler::EVENT_CONTACT_SYNC ),
			array_column( $this->enqueued, 'type' ),
			'A bare registration syncs the contact (audience rules unchanged) but must not enrol them in the welcome automation.'
		);
	}

	public function test_created_customer_does_not_fire_welcome_when_option_off(): void {
		// setUp leaves smly_plus_welcome_enabled false — the merchant's toggle.
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 7, 'a@b.c', '', '' ) );

		( new HookHandler( $this->queue ) )->on_woocommerce_created_customer( 7 );

		self::assertSame( array( HookHandler::EVENT_CONTACT_SYNC ), array_column( $this->enqueued, 'type' ) );
	}

	public function test_created_customer_fires_welcome_once_when_option_on(): void {
		$this->enable_welcome();
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 7, 'a@b.c', '', '' ) );

		// WooCommerce created the account (checkout / My Account registration):
		// user_register fires first from inside wp_insert_user, then this hook.
		$handler = new HookHandler( $this->queue );
		$handler->on_user_register( 7 );
		$handler->on_woocommerce_created_customer( 7 );

		$types = array_column( $this->enqueued, 'type' );
		self::assertContains( HookHandler::EVENT_CONTACT_SYNC, $types );
		self::assertSame(
			1,
			count( array_keys( $types, HookHandler::EVENT_AUTOMATION_WELCOME, true ) ),
			'Both hooks fire for one checkout-created account — exactly one welcome may be enqueued.'
		);

		// PRO-1681: the welcome run marks the contact — and ONLY the plain
		// contact sync stays unmarked, so a self-subscribed contact is
		// distinguishable from an automation-enrolled one.
		$by_type = array_combine( $types, array_column( $this->enqueued, 'payload' ) );
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$by_type[ HookHandler::EVENT_AUTOMATION_WELCOME ]['fields']['welcome_automation_at']
		);
		self::assertArrayNotHasKey(
			'welcome_automation_at',
			$by_type[ HookHandler::EVENT_CONTACT_SYNC ]['fields'],
			'A contact sync is not an automation run — it must carry no marker.'
		);
	}

	public function test_welcome_eligibility_is_filterable_per_source(): void {
		$this->enable_welcome();
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 7, 'a@b.c', '', '' ) );
		Monkey\Filters\expectApplied( HookHandler::FILTER_WELCOME_ELIGIBLE )
			->once()
			->with( false, 7, 'user_register' )
			->andReturn( true );

		( new HookHandler( $this->queue ) )->on_user_register( 7 );

		self::assertContains(
			HookHandler::EVENT_AUTOMATION_WELCOME,
			array_column( $this->enqueued, 'type' ),
			'A store must be able to widen the trigger back to a non-WooCommerce registration flow.'
		);
	}

	public function test_user_register_ignores_unknown_user(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		( new HookHandler( $this->queue ) )->on_user_register( 999 );

		self::assertCount( 0, $this->enqueued );
	}

	public function test_per_request_dedupe_collapses_repeated_profile_updates(): void {
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		$h = new HookHandler( $this->queue );
		$h->on_profile_update( 42 );
		$h->on_profile_update( 42 );
		$h->on_profile_update( 42 );

		self::assertCount(
			1,
			$this->enqueued,
			'Three profile_update fires in one request must collapse into a single enqueue.'
		);
	}

	public function test_reset_seen_re_enables_enqueue_for_same_entity(): void {
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'a@b.c', '', '' ) );

		$h = new HookHandler( $this->queue );
		$h->on_profile_update( 42 );

		HookHandler::reset_seen();
		$h->on_profile_update( 42 );

		self::assertCount( 2, $this->enqueued );
	}

	public function test_checkout_order_processed_skips_when_first_order_disabled(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'buyer@example.test', 9, 1 ) );

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertSame(
			array(),
			array_column( $this->enqueued, 'type' ),
			'first_order disabled — no event should be enqueued even on a real first order.'
		);
	}

	public function test_checkout_order_processed_fires_automation_first_order_on_first_paid_order(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $default = null ) =>
				$key === 'smly_plus_setup_completed'
					? true
					: ( $key === 'smly_plus_first_order_enabled' ? true : $default )
		);
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'buyer@example.test', 9, 1 ) );
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 1 );

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_AUTOMATION_FIRST_ORDER, $this->enqueued[0]['type'] );
		self::assertSame( 'buyer@example.test', $this->enqueued[0]['payload']['email'] );
		self::assertSame( '100', $this->enqueued[0]['payload']['fields']['order_id'] );
		// PRO-1681: the run marker rides alongside the order fields, and a
		// trigger only ever marks its own field.
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$this->enqueued[0]['payload']['fields']['first_order_automation_at']
		);
		self::assertArrayNotHasKey( 'welcome_automation_at', $this->enqueued[0]['payload']['fields'] );
	}

	public function test_checkout_order_processed_skips_on_second_order(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $default = null ) =>
				$key === 'smly_plus_setup_completed'
					? true
					: ( $key === 'smly_plus_first_order_enabled' ? true : $default )
		);
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'buyer@example.test', 9, 1 ) );
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 2 ); // already had one

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertCount( 0, $this->enqueued );
	}

	public function test_attribution_cookies_are_saved_to_order_meta(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$order = $this->fake_order( 100, 'buyer@example.test', 9, 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$_COOKIE['smaily_anon_sid'] = 'anon-xyz';
		$_COOKIE['smaily_rec_uid']  = 'visitor-abc';

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertSame( 'anon-xyz', $order->get_meta( '_smaily_anon_session_id' ) );
		self::assertSame( 'visitor-abc', $order->get_meta( '_smaily_visitor_token' ) );
	}

	public function test_tenant_overridden_cookie_names_still_land_on_the_order(): void {
		// PRO-1902: the cookie names are tenant config (the beacon, LandingCapture
		// and the identity merge all resolve them from the engine config). A store
		// whose tenant renamed them must still get attribution stamped — and the
		// engine's DEFAULT names must not be read on such a store.
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === RecEngineSettings::OPTION_CONFIG ) {
					return '{"session_cookie_name":"acme_sid","tracking_cookie_name":"acme_vt","rec_id_cookie_name":"acme_rec","context_cookie_name":"acme_ctx"}';
				}
				return $default;
			}
		);

		$order = $this->fake_order( 100, 'buyer@example.test', 9, 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$_COOKIE['acme_sid']        = 'anon-xyz';
		$_COOKIE['acme_vt']         = 'visitor-abc';
		$_COOKIE['acme_rec']        = 'rec-uuid-123';
		$_COOKIE['acme_ctx']        = 'newsletter';
		$_COOKIE['smaily_rec_uid']  = 'stale-default-name-value';

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertSame( 'anon-xyz', $order->get_meta( '_smaily_anon_session_id' ) );
		self::assertSame( 'visitor-abc', $order->get_meta( '_smaily_visitor_token' ) );
		self::assertSame( 'rec-uuid-123', $order->get_meta( '_smaily_rec_id' ) );
		self::assertSame( 'newsletter', $order->get_meta( '_smaily_rec_ctx' ) );
	}

	public function test_oversized_attribution_cookies_are_not_stamped_onto_the_order(): void {
		// PRO-1896: a cookie planted by the pre-fix permissive JS writer (or a
		// crafted one) outlives the fixed bundle by its 30/365-day TTL, so the
		// order stamp caps it rather than forwarding it to the §5 wire.
		Functions\when( 'get_option' )->justReturn( false );

		$order = $this->fake_order( 100, 'buyer@example.test', 9, 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$_COOKIE['smaily_rec_ctx'] = str_repeat( 'a', 65 );
		$_COOKIE['smaily_rec_uid'] = 'vt_' . str_repeat( 'b', 65 );

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertSame( '', $order->get_meta( '_smaily_rec_ctx' ) );
		self::assertSame( '', $order->get_meta( '_smaily_visitor_token' ) );
	}

	public function test_attribution_cookies_at_the_length_cap_still_ride_the_order(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$order = $this->fake_order( 100, 'buyer@example.test', 9, 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$ctx                       = str_repeat( 'a', 64 );
		$vt                        = 'vt_' . str_repeat( 'b', 64 );
		$_COOKIE['smaily_rec_ctx'] = $ctx;
		$_COOKIE['smaily_rec_uid'] = $vt;

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertSame( $ctx, $order->get_meta( '_smaily_rec_ctx' ) );
		self::assertSame( $vt, $order->get_meta( '_smaily_visitor_token' ) );
	}

	public function test_a_store_created_visitor_token_is_not_stamped_without_marketing_consent(): void {
		// PRO-3857 / contract §5: without marketing consent the store sends no
		// `vs_` token. The unit environment has no WP Consent API = no consent.
		Functions\when( 'get_option' )->justReturn( false );

		$order = $this->fake_order( 100, 'buyer@example.test', 9, 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$_COOKIE['smaily_rec_uid'] = 'vs_0123456789ABCDEFabcdef';
		$_COOKIE['smaily_rec_id']  = 'rec-uuid-123';

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertSame( '', $order->get_meta( '_smaily_visitor_token' ) );
		self::assertSame( 'rec-uuid-123', $order->get_meta( '_smaily_rec_id' ), 'The other attribution still rides the order.' );
	}

	public function test_a_store_created_visitor_token_is_stamped_with_marketing_consent(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$order = $this->fake_order( 100, 'buyer@example.test', 9, 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$_COOKIE['smaily_rec_uid'] = 'vs_0123456789ABCDEFabcdef';

		$handler = new class( $this->queue ) extends HookHandler {
			protected function marketing_consent_given(): bool {
				return true;
			}
		};
		$handler->on_checkout_order_processed( 100 );

		self::assertSame( 'vs_0123456789ABCDEFabcdef', $order->get_meta( '_smaily_visitor_token' ) );
	}

	public function test_an_engine_visitor_token_is_stamped_without_marketing_consent(): void {
		// F3-46: the engine's `vt_` token is attribution, consent-ungated.
		Functions\when( 'get_option' )->justReturn( false );

		$order = $this->fake_order( 100, 'buyer@example.test', 9, 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$_COOKIE['smaily_rec_uid'] = 'vt_8f3k2aBz01';

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100 );

		self::assertSame( 'vt_8f3k2aBz01', $order->get_meta( '_smaily_visitor_token' ) );
	}

	/**
	 * PRO-3863: a guest whose browser holds a malformed visitor cookie gets a
	 * fresh store token at checkout (GuestVisitorToken). The attribution stamp
	 * runs on the same checkout hook, so the two callbacks may run in either
	 * order; the order must end up with the fresh token either way, never the
	 * malformed cookie. It holds because the token is written to `$_COOKIE`
	 * in the same request (a stamp after it copies the token) and the issuer
	 * writes the order meta itself (an issue after the stamp overwrites it).
	 *
	 * @dataProvider checkout_callback_orders
	 */
	public function test_a_fresh_guest_token_wins_over_a_malformed_cookie_in_any_hook_order( bool $classic, bool $stamp_first ): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$order = $this->fake_order( 100, 'guest@example.test', 0, 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$_COOKIE['smaily_rec_uid'] = 'not-a-token';

		$handler = new class( $this->queue ) extends HookHandler {
			protected function marketing_consent_given(): bool {
				return true;
			}
		};
		$cookies = new class( new FakeRecEngineSettings() ) extends LandingCapture {
			protected function headers_already_sent(): bool {
				return false; // PHPUnit's progress output makes the real one true.
			}

			protected function send_cookie( string $name, string $value, int $expires ): void {}
		};
		$issuer = new class( new FakeRecEngineSettings(), $cookies ) extends GuestVisitorToken {
			protected function marketing_consent_given(): bool {
				return true;
			}
		};

		$stamp = $classic
			? static function () use ( $handler ): void {
				$handler->on_checkout_order_processed( 100 );
			}
			: static function () use ( $handler, $order ): void {
				$handler->on_block_checkout_order_processed( $order );
			};
		$issue = $classic
			? static function () use ( $issuer ): void {
				$issuer->on_classic_checkout( 100 );
			}
			: static function () use ( $issuer, $order ): void {
				$issuer->on_block_checkout( $order );
			};

		if ( $stamp_first ) {
			$stamp();
			$issue();
		} else {
			$issue();
			$stamp();
		}

		$token = (string) $order->get_meta( '_smaily_visitor_token' );
		self::assertMatchesRegularExpression( '/^vs_[A-Za-z0-9]{22}$/', $token );
		self::assertSame( $_COOKIE['smaily_rec_uid'], $token, 'The order carries the token the guest received.' );
	}

	/**
	 * @return array<string, array{0: bool, 1: bool}>
	 */
	public static function checkout_callback_orders(): array {
		return array(
			'classic, stamp first' => array( true, true ),
			'classic, issue first' => array( true, false ),
			'block, stamp first'   => array( false, true ),
			'block, issue first'   => array( false, false ),
		);
	}

	public function test_block_checkout_stamps_rec_attribution_onto_order(): void {
		// F3-46 gap fix: block checkout never fires woocommerce_checkout_order_processed,
		// so the smaily_rec cookie must be stamped via the Store-API twin.
		$order                    = $this->fake_order( 100, 'buyer@example.test', 9, 1 );
		$_COOKIE['smaily_rec_id'] = 'rec-uuid-123';

		( new HookHandler( $this->queue ) )->on_block_checkout_order_processed( $order );

		self::assertSame( 'rec-uuid-123', $order->get_meta( '_smaily_rec_id' ) );
	}

	public function test_block_checkout_fires_automation_first_order_on_first_paid_order(): void {
		// PRO-1679: first-order was left on the classic hook when F3-46 carried
		// attribution across, so it never fired on a block-checkout store.
		$this->enable_first_order();
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 1 );

		( new HookHandler( $this->queue ) )->on_block_checkout_order_processed(
			$this->fake_order( 100, 'buyer@example.test', 9, 1 )
		);

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_AUTOMATION_FIRST_ORDER, $this->enqueued[0]['type'] );
		self::assertSame( 'buyer@example.test', $this->enqueued[0]['payload']['email'] );
		self::assertSame( '100', $this->enqueued[0]['payload']['fields']['order_id'] );
	}

	public function test_block_checkout_skips_automation_first_order_on_second_order(): void {
		$this->enable_first_order();
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 2 );

		( new HookHandler( $this->queue ) )->on_block_checkout_order_processed(
			$this->fake_order( 100, 'buyer@example.test', 9, 1 )
		);

		self::assertSame( array(), $this->enqueued );
	}

	public function test_block_checkout_skips_automation_first_order_for_a_guest(): void {
		// Guests have no order history to compare against — unchanged from the
		// classic path (is_first_order() bails on customer_id 0).
		$this->enable_first_order();

		( new HookHandler( $this->queue ) )->on_block_checkout_order_processed(
			$this->fake_order( 100, 'guest@example.test', 0, 1 )
		);

		self::assertSame( array(), $this->enqueued );
	}

	public function test_block_checkout_skips_automation_first_order_when_disabled(): void {
		// Default get_option stub: smly_plus_first_order_enabled = false.
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 1 );

		( new HookHandler( $this->queue ) )->on_block_checkout_order_processed(
			$this->fake_order( 100, 'buyer@example.test', 9, 1 )
		);

		self::assertSame( array(), $this->enqueued );
	}

	public function test_first_order_enqueues_once_when_both_checkout_hooks_fire(): void {
		// A store where both the classic and the Store-API hook run for one
		// order must still enqueue a single automation row — both fire in the
		// same request, so the per-request dedupe caps it.
		$this->enable_first_order();
		$order = $this->fake_order( 100, 'buyer@example.test', 9, 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wc_get_customer_order_count' )->justReturn( 1 );

		$handler = new HookHandler( $this->queue );
		$handler->on_checkout_order_processed( 100 );
		$handler->on_block_checkout_order_processed( $order );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( HookHandler::EVENT_AUTOMATION_FIRST_ORDER, $this->enqueued[0]['type'] );
	}

	public function test_purchase_marks_the_contact_when_the_plugin_reminded_this_shopper(): void {
		// PRO-1723: the marker is what lets the merchant's Smaily workflow
		// stop the follow-up letters once the shopper has bought.
		$this->delivered[] = CartFlusher::EVENT_TYPE . '|guest@example.test';
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'guest@example.test', 0, 1 ) );

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100, array() );

		$marker = $this->find_enqueued( 'order:100:cart-purchase' );
		self::assertNotNull( $marker, 'A reminded shopper who buys must have the purchase written to their contact.' );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $marker['type'], 'The marker is a contact update — it must not trigger an automation.' );
		self::assertSame( 'guest@example.test', $marker['payload']['email'] );
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$marker['payload']['fields'][ AutomationMarker::FIELD_ABANDONED_CART_PURCHASED ]
		);
		self::assertSame(
			array( AutomationMarker::FIELD_ABANDONED_CART_PURCHASED ),
			array_keys( $marker['payload']['fields'] ),
			'Only the purchase marker rides — the reminder\'s product fields belong to the reminder send (PRO-1680).'
		);
	}

	public function test_block_checkout_purchase_marks_the_contact_too(): void {
		$this->delivered[] = CartFlusher::EVENT_TYPE . '|guest@example.test';

		( new HookHandler( $this->queue ) )->on_block_checkout_order_processed(
			$this->fake_order( 100, 'guest@example.test', 0, 1 )
		);

		$marker = $this->find_enqueued( 'order:100:cart-purchase' );
		self::assertNotNull( $marker, 'Block checkout is the WooCommerce default — the marker must fire there too.' );
		self::assertSame( 'guest@example.test', $marker['payload']['email'] );
	}

	public function test_purchase_without_a_reminder_writes_no_marker(): void {
		// Nothing was ever sent to this shopper, so nothing may be written —
		// the feature must never create a contact of its own.
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'guest@example.test', 0, 1 ) );

		$handler = new HookHandler( $this->queue );
		$handler->on_checkout_order_processed( 100, array() );
		$handler->on_block_checkout_order_processed( $this->fake_order( 101, 'guest@example.test', 0, 1 ) );

		self::assertNull( $this->find_enqueued( 'order:100:cart-purchase' ) );
		self::assertNull( $this->find_enqueued( 'order:101:cart-purchase' ) );
	}

	public function test_purchase_cancels_a_reminder_still_queued_for_the_shopper(): void {
		// The sweeper enqueues up to a minute before the CartFlusher drains,
		// so a purchase inside that window must withdraw the queued reminder.
		( new HookHandler( $this->queue ) )->on_block_checkout_order_processed(
			$this->fake_order( 100, 'guest@example.test', 0, 1 )
		);

		self::assertSame(
			array(
				array(
					'type'  => CartFlusher::EVENT_TYPE,
					'email' => 'guest@example.test',
				),
			),
			$this->cancelled
		);
	}

	public function test_purchase_marks_the_account_address_a_registered_shopper_was_reminded_at(): void {
		// The cart tracker records a logged-in shopper's ACCOUNT address, which
		// WooCommerce lets differ from the address they check out with — the
		// marker has to reach the contact the reminder went to.
		$this->delivered[] = CartFlusher::EVENT_TYPE . '|account@example.test';
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 9, 'account@example.test', 'A', 'B' ) );

		( new HookHandler( $this->queue ) )->on_block_checkout_order_processed(
			$this->fake_order( 100, 'billing@example.test', 9, 1 )
		);

		$marker = $this->find_enqueued( 'order:100:cart-purchase' );
		self::assertNotNull( $marker );
		self::assertSame( 'account@example.test', $marker['payload']['email'] );
		self::assertSame(
			array( 'billing@example.test', 'account@example.test' ),
			array_column( $this->cancelled, 'email' ),
			'Both addresses the buyer could have been reminded at are cleared of queued reminders.'
		);
	}

	public function test_checkout_order_syncs_guest_email_in_legitimate_interest(): void {
		// F3-48 F1: the order path is what makes guests + checkout-opt-in work.
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_SYNC_ENABLED ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_MODE ) {
					return ContactSyncMode::MODE_LEGITIMATE_INTEREST;
				}
				if ( $key === ContactSyncMode::OPTION_INCLUDE_GUESTS ) {
					return '1';
				}
				return $default;
			}
		);
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'guest@example.test', 0, 1 ) );

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100, array() );

		$contact = $this->find_enqueued( 'order:100' );
		self::assertNotNull( $contact, 'A guest order must enqueue a contact.sync under legit interest + include_guests.' );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $contact['type'] );
		self::assertSame( 'guest@example.test', $contact['payload']['email'] );
		// Enriched with the order's billing name (not email-only).
		self::assertSame( 'Guest', $contact['payload']['fields']['first_name'] );
		self::assertSame( 'Buyer', $contact['payload']['fields']['last_name'] );
		self::assertArrayNotHasKey( 'is_unsubscribed', $contact['payload'], 'Legit interest leaves consent to Smaily.' );
	}

	public function test_checkout_order_skips_guest_in_default_consent_mode(): void {
		// Default consent + include_guests off → guests are not synced.
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'guest@example.test', 0, 1 ) );

		( new HookHandler( $this->queue ) )->on_checkout_order_processed( 100, array() );

		self::assertNull( $this->find_enqueued( 'order:100' ) );
	}

	public function test_block_checkout_optin_syncs_with_subscribe_in_checkout_mode(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' || $key === ContactSyncMode::OPTION_SYNC_ENABLED ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_MODE ) {
					return ContactSyncMode::MODE_CHECKOUT_OPTIN;
				}
				return $default;
			}
		);

		$order   = $this->fake_order( 100, 'guest@example.test', 0, 1 );
		$request = array( 'extensions' => array( 'smaily-checkout-optin' => array( 'user_newsletter' => true ) ) );

		( new HookHandler( $this->queue ) )->on_checkout_block_optin( $order, $request );

		$contact = $this->find_enqueued( 'order:100' );
		self::assertNotNull( $contact );
		self::assertSame( 0, $contact['payload']['is_unsubscribed'], 'A checkout opt-in subscribes.' );
	}

	// ------------------------------------------------------------------
	// PRO-3406: a registered buyer's newsletter tick becomes the store's
	// consent record (user_newsletter = 1), and writing it sends the contact
	// to Smaily subscribed. Each case drives Profile_Settings' real callbacks
	// and HookHandler's real hooks in the order WooCommerce fires them, with
	// the user-meta store wired to the real consent handler the way WordPress
	// fires `add_user_meta` / `update_user_meta` (see wire_user_meta()).
	// ------------------------------------------------------------------

	public function test_consent_mode_logged_in_classic_checkout_tick_subscribes(): void {
		$this->optin_options( ContactSyncMode::MODE_CONSENT );
		$handler = new HookHandler( $this->queue );
		$this->wire_user_meta( $handler );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 42, 'shopper@example.test', 'Test', 'Shopper' ) );
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 100, 'shopper@example.test', 42, 1 ) );
		$posted = array(
			'billing_email'   => 'shopper@example.test',
			'user_newsletter' => 1,
		);

		// WC_Checkout::process_customer() → woocommerce_checkout_update_user_meta( $customer_id, $data ),
		// then woocommerce_checkout_order_processed( $order_id, $posted_data, $order ).
		( new Profile_Settings() )->smaily_save_checkout_newsletter_optin( 42, $posted );
		$handler->on_checkout_order_processed( 100, $posted );

		self::assertSame( array( 42 => array( 'user_newsletter' => '1' ) ), $this->user_meta, 'The tick is saved as the store\'s consent record.' );
		$this->assert_subscribed_once( '42:consent', 'shopper@example.test' );
	}

	public function test_consent_mode_classic_checkout_account_creation_tick_subscribes(): void {
		$this->optin_options( ContactSyncMode::MODE_CONSENT );
		$handler = new HookHandler( $this->queue );
		$this->wire_user_meta( $handler );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 43, 'new.shopper@example.test', '', '' ) );
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 101, 'new.shopper@example.test', 43, 1 ) );
		// The checkout carries the checkout nonce, not the registration one.
		$_POST    = array(
			'woocommerce-process-checkout-nonce' => 'checkout-nonce',
			'user_newsletter'                    => '1',
		);
		$posted   = array(
			'billing_email'   => 'new.shopper@example.test',
			'user_newsletter' => 1,
		);
		$settings = new Profile_Settings();

		// wc_create_new_customer(): user_register, then woocommerce_created_customer;
		// then process_customer() fires woocommerce_checkout_update_user_meta.
		$handler->on_user_register( 43 );
		$handler->on_woocommerce_created_customer( 43 );
		$settings->smaily_save_registration_newsletter_optin( 43 );
		self::assertSame( array(), $this->user_meta, 'The registration callback must not act on a checkout request.' );

		$settings->smaily_save_checkout_newsletter_optin( 43, $posted );
		$handler->on_checkout_order_processed( 101, $posted );

		self::assertSame( array( 43 => array( 'user_newsletter' => '1' ) ), $this->user_meta );
		$this->assert_subscribed_once( '43:consent', 'new.shopper@example.test' );
	}

	public function test_consent_mode_my_account_registration_tick_subscribes(): void {
		$this->optin_options( ContactSyncMode::MODE_CONSENT );
		$handler = new HookHandler( $this->queue );
		$this->wire_user_meta( $handler );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 44, 'registrant@example.test', '', '' ) );
		$_POST = array(
			'woocommerce-register-nonce' => 'register-nonce',
			'register'                   => 'Register',
			'email'                      => 'registrant@example.test',
			'user_newsletter'            => '1',
		);

		// WC_Form_Handler::process_registration() → wc_create_new_customer().
		$handler->on_user_register( 44 );
		( new Profile_Settings() )->smaily_save_registration_newsletter_optin( 44 );
		$handler->on_woocommerce_created_customer( 44 );

		self::assertSame( array( 44 => array( 'user_newsletter' => '1' ) ), $this->user_meta );
		$this->assert_subscribed_once( '44:consent', 'registrant@example.test' );
	}

	public function test_registration_tick_without_the_registration_nonce_writes_nothing(): void {
		$this->optin_options( ContactSyncMode::MODE_CONSENT );
		$this->wire_user_meta( new HookHandler( $this->queue ) );
		$_POST = array(
			'woocommerce-register-nonce' => 'forged',
			'user_newsletter'            => '1',
		);

		( new Profile_Settings() )->smaily_save_registration_newsletter_optin( 44 );

		self::assertSame( array(), $this->user_meta );
		self::assertSame( array(), $this->enqueued );
	}

	public function test_consent_mode_logged_in_block_checkout_tick_subscribes(): void {
		$this->optin_options( ContactSyncMode::MODE_CONSENT );
		$handler = new HookHandler( $this->queue );
		$this->wire_user_meta( $handler );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 45, 'shopper@example.test', '', '' ) );
		$order = $this->fake_order( 102, 'shopper@example.test', 45, 1 );

		// Store API: update_order_from_request → …_update_order_from_request( $order, $request ),
		// then …_checkout_order_processed( $order ).
		$handler->on_checkout_block_optin( $order, $this->block_request( true ) );
		$handler->on_block_checkout_order_processed( $order );

		self::assertSame( '1', $order->get_meta( HookHandler::ORDER_META_NEWSLETTER_OPTIN ), 'The tick stays on the order as evidence.' );
		self::assertSame( array( 45 => array( 'user_newsletter' => '1' ) ), $this->user_meta );
		$this->assert_subscribed_once( '45:consent', 'shopper@example.test' );
	}

	public function test_consent_mode_block_checkout_account_creation_tick_subscribes(): void {
		$this->optin_options( ContactSyncMode::MODE_CONSENT );
		$handler = new HookHandler( $this->queue );
		$this->wire_user_meta( $handler );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 46, 'new.shopper@example.test', '', '' ) );
		$order = $this->fake_order( 103, 'new.shopper@example.test', 0, 1 );

		// The request hook runs while the order is still a guest's …
		$handler->on_checkout_block_optin( $order, $this->block_request( true ) );
		self::assertSame( array(), $this->user_meta );

		// … process_customer() creates the account and sets the customer id …
		$handler->on_user_register( 46 );
		$handler->on_woocommerce_created_customer( 46 );
		$order->set_customer_id( 46 );

		// … and only then does the processed hook fire.
		$handler->on_block_checkout_order_processed( $order );

		self::assertSame( array( 46 => array( 'user_newsletter' => '1' ) ), $this->user_meta );
		$this->assert_subscribed_once( '46:consent', 'new.shopper@example.test' );
	}

	public function test_an_unticked_box_records_nothing_and_subscribes_no_one(): void {
		$this->optin_options( ContactSyncMode::MODE_CONSENT );
		$handler = new HookHandler( $this->queue );
		$this->wire_user_meta( $handler );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 47, 'shopper@example.test', '', '' ) );
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 104, 'shopper@example.test', 47, 1 ) );
		$settings = new Profile_Settings();

		// Classic checkout: WooCommerce posts '' for an unticked checkbox.
		$posted = array(
			'billing_email'   => 'shopper@example.test',
			'user_newsletter' => '',
		);
		$settings->smaily_save_checkout_newsletter_optin( 47, $posted );
		$handler->on_checkout_order_processed( 104, $posted );

		// My Account registration: an unticked box is simply absent.
		$_POST = array( 'woocommerce-register-nonce' => 'register-nonce' );
		$settings->smaily_save_registration_newsletter_optin( 47 );

		// Block checkout.
		$order = $this->fake_order( 105, 'shopper@example.test', 47, 1 );
		$handler->on_checkout_block_optin( $order, $this->block_request( false ) );
		$handler->on_block_checkout_order_processed( $order );

		self::assertSame( array(), $this->user_meta, 'Nothing is recorded — and never a 0: an unticked box is not an opt-out.' );
		self::assertSame( array(), $this->enqueued, 'No subscription (and no unsubscribe) is sent.' );
		self::assertSame( '', $order->get_meta( HookHandler::ORDER_META_NEWSLETTER_OPTIN ) );
	}

	public function test_an_unticked_resubmission_clears_an_earlier_tick_on_the_order(): void {
		// A failed-payment retry updates the same order: only the final choice counts.
		$this->optin_options( ContactSyncMode::MODE_CONSENT );
		$handler = new HookHandler( $this->queue );
		$this->wire_user_meta( $handler );
		$order = $this->fake_order( 106, 'shopper@example.test', 48, 1 );

		$handler->on_checkout_block_optin( $order, $this->block_request( true ) );
		$handler->on_checkout_block_optin( $order, $this->block_request( false ) );
		$handler->on_block_checkout_order_processed( $order );

		self::assertSame( '', $order->get_meta( HookHandler::ORDER_META_NEWSLETTER_OPTIN ) );
		self::assertSame( array(), $this->user_meta );
	}

	/**
	 * @dataProvider provide_modes_without_a_consent_record
	 */
	public function test_other_modes_are_unaffected_by_the_tick( string $mode ): void {
		$this->optin_options( $mode );
		$handler = new HookHandler( $this->queue );
		$this->wire_user_meta( $handler );
		Functions\when( 'get_userdata' )->justReturn( $this->fake_user( 49, 'shopper@example.test', '', '' ) );
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 107, 'shopper@example.test', 49, 1 ) );
		$settings = new Profile_Settings();
		$posted   = array(
			'billing_email'   => 'shopper@example.test',
			'user_newsletter' => 1,
		);

		$settings->smaily_save_checkout_newsletter_optin( 49, $posted );
		$handler->on_checkout_order_processed( 107, $posted );

		$_POST = array(
			'woocommerce-register-nonce' => 'register-nonce',
			'user_newsletter'            => '1',
		);
		$settings->smaily_save_registration_newsletter_optin( 49 );

		$order = $this->fake_order( 108, 'shopper@example.test', 49, 1 );
		$handler->on_checkout_block_optin( $order, $this->block_request( true ) );
		$handler->on_block_checkout_order_processed( $order );

		self::assertSame( array(), $this->user_meta, 'No consent record is written outside consent mode.' );
		self::assertSame(
			array(),
			array_values( array_filter( array_column( $this->enqueued, 'entity_id' ), static fn ( string $id ): bool => str_ends_with( $id, ':consent' ) ) ),
			'No consent event — the order path answers exactly as it did before PRO-3406.'
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_modes_without_a_consent_record(): array {
		return array(
			'legitimate interest' => array( ContactSyncMode::MODE_LEGITIMATE_INTEREST ),
			'checkout only'       => array( ContactSyncMode::MODE_CHECKOUT_OPTIN ),
		);
	}

	public function test_a_tick_before_the_wizard_is_finished_is_left_to_the_legacy_sync(): void {
		$this->optin_options( ContactSyncMode::MODE_CONSENT, false );
		$this->wire_user_meta( new HookHandler( $this->queue ) );

		( new Profile_Settings() )->smaily_save_checkout_newsletter_optin( 50, array( 'user_newsletter' => 1 ) );

		self::assertSame( array(), $this->user_meta );
	}

	public function test_profile_settings_binds_the_optin_callbacks_to_the_woocommerce_hooks(): void {
		$settings = new Profile_Settings();

		$settings->register_hooks();

		self::assertSame( 10, has_action( 'woocommerce_checkout_update_user_meta', array( $settings, 'smaily_save_checkout_newsletter_optin' ) ) );
		self::assertSame( 10, has_action( 'woocommerce_created_customer', array( $settings, 'smaily_save_registration_newsletter_optin' ) ) );
	}

	/**
	 * Store settings for the PRO-3406 cases: the wizard finished, contact sync
	 * on, the given mode, and the merchant's newsletter checkbox offered.
	 */
	private function optin_options( string $mode, bool $setup_completed = true ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) use ( $mode, $setup_completed ) {
				if ( $key === 'smly_plus_setup_completed' ) {
					return $setup_completed;
				}
				if ( $key === ContactSyncMode::OPTION_SYNC_ENABLED ) {
					return true;
				}
				if ( $key === ContactSyncMode::OPTION_MODE ) {
					return $mode;
				}
				if ( $key === Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION ) {
					return '1';
				}
				return $default;
			}
		);
		Functions\stubTranslationFunctions();
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'wp_verify_nonce' )->alias(
			static fn ( $nonce, $action ) => ( $nonce === 'register-nonce' && $action === 'woocommerce-register' )
				|| ( $nonce === 'checkout-nonce' && $action === 'woocommerce-process_checkout' ) ? 1 : false
		);
	}

	/**
	 * An in-memory user-meta store wired to the handler the way WordPress core
	 * fires the meta actions Hooks::register() binds: `add_user_meta` for the
	 * first write of a key, `update_user_meta` (old value still readable) for
	 * a changed one, nothing for an unchanged one.
	 */
	private function wire_user_meta( HookHandler $handler ): void {
		$meta = &$this->user_meta;
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key = '', bool $single = false ) use ( &$meta ) {
				return $meta[ $user_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, $value ) use ( &$meta, $handler ) {
				if ( ! isset( $meta[ $user_id ][ $key ] ) ) {
					$handler->on_user_newsletter_meta_add( $user_id, $key, $value );
				} elseif ( $meta[ $user_id ][ $key ] !== $value ) {
					$handler->on_user_newsletter_meta_update( 0, $user_id, $key, $value );
				} else {
					return false;
				}
				$meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
	}

	/**
	 * @return array<string, array<string, array<string, bool>>>
	 */
	private function block_request( bool $ticked ): array {
		return array( 'extensions' => array( 'smaily-checkout-optin' => array( 'user_newsletter' => $ticked ) ) );
	}

	private function assert_subscribed_once( string $entity_id, string $email ): void {
		$consent = array_values( array_filter( $this->enqueued, static fn ( array $row ): bool => array_key_exists( 'is_unsubscribed', $row['payload'] ) ) );

		self::assertCount( 1, $consent, 'Exactly one consent change reaches Smaily.' );
		self::assertSame( HookHandler::EVENT_CONTACT_SYNC, $consent[0]['type'] );
		self::assertSame( $entity_id, $consent[0]['entity_id'] );
		self::assertSame( $email, $consent[0]['payload']['email'] );
		self::assertSame( 0, $consent[0]['payload']['is_unsubscribed'], 'Sent to Smaily as subscribed.' );
	}

	/** Wizard finished + subscriber sync + the welcome automation toggled on. */
	private function enable_welcome(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( $key === 'smly_plus_setup_completed' || $key === ContactSyncMode::OPTION_SYNC_ENABLED ) {
					return true;
				}
				if ( $key === 'smly_plus_welcome_enabled' ) {
					return true;
				}
				return $default;
			}
		);
	}

	/** Wizard finished + the first-order automation toggled on. */
	private function enable_first_order(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $default = null ) =>
				$key === 'smly_plus_setup_completed'
					? true
					: ( $key === 'smly_plus_first_order_enabled' ? true : $default )
		);
	}

	/**
	 * @return array{type: string, entity_id: string, payload: array<string, mixed>}|null
	 */
	private function find_enqueued( string $entity_id ): ?array {
		foreach ( $this->enqueued as $row ) {
			if ( $row['entity_id'] === $entity_id ) {
				return $row;
			}
		}
		return null;
	}

	private function fake_user( int $id, string $email, string $first, string $last ): \WP_User {
		// WP_User isn't autoloadable in unit tests; build an object with the
		// fields HookHandler reads.
		$user             = new \stdClass();
		$user->ID         = $id;
		$user->user_email = $email;
		$user->first_name = $first;
		$user->last_name  = $last;

		// Cast through a thin shim that pretends to be a WP_User. PHPUnit's
		// type system accepts an anonymous class as the parent's instance,
		// provided we declare it as such.
		return new class( $id, $email, $first, $last ) extends \WP_User {
			public function __construct( int $id, string $email, string $first, string $last ) {
				$this->ID         = $id;
				$this->user_email = $email;
				$this->first_name = $first;
				$this->last_name  = $last;
			}
		};
	}

	private function fake_order( int $id, string $email, int $customer_id, int $total ): \WC_Order {
		return new class( $id, $email, $customer_id, $total ) extends \WC_Order {
			private int $id;
			private string $email;
			private int $customer_id;
			private int $total;
			private array $meta = array();

			public function __construct( int $id, string $email, int $customer_id, int $total ) {
				$this->id          = $id;
				$this->email       = $email;
				$this->customer_id = $customer_id;
				$this->total       = $total;
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_billing_email( $context = 'view' ): string {
				return $this->email;
			}

			public function get_billing_first_name( $context = 'view' ): string {
				return 'Guest';
			}

			public function get_billing_last_name( $context = 'view' ): string {
				return 'Buyer';
			}

			public function get_customer_id( $context = 'view' ): int {
				return $this->customer_id;
			}

			public function get_total( $context = 'view' ): string {
				return (string) $this->total;
			}

			public function get_currency( $context = 'view' ): string {
				return 'EUR';
			}

			public function update_meta_data( $key, $value, $unique_id = 0 ): void {
				$this->meta[ $key ] = $value;
			}

			public function get_meta( $key = '', $single = true, $context = 'view' ) {
				return $this->meta[ $key ] ?? '';
			}

			public function delete_meta_data( $key ): void {
				unset( $this->meta[ $key ] );
			}

			public function set_customer_id( $value ): void {
				$this->customer_id = (int) $value;
			}

			public function save() {
				return $this->id;
			}
		};
	}
}

// Stubs for the WP_User / WC_Order classes the anonymous fakes extend.
// Brain Monkey doesn't ship these; we declare the minimum public surface
// our HookHandler relies on.
if ( ! class_exists( \WP_User::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $first_name = '';
	public string $last_name = '';
}
PHP
	);
}

if ( ! class_exists( \WC_Order::class ) ) {
	// phpcs:ignore Squiz.Commenting.ClassComment.Missing -- test shim.
	eval( <<<'PHP'
class WC_Order {
	public function get_id(): int { return 0; }
	public function get_billing_email( $context = 'view' ): string { return ''; }
	public function get_billing_first_name( $context = 'view' ): string { return ''; }
	public function get_billing_last_name( $context = 'view' ): string { return ''; }
	public function get_customer_id( $context = 'view' ): int { return 0; }
	public function get_total( $context = 'view' ): string { return '0'; }
	public function get_currency( $context = 'view' ): string { return ''; }
	public function get_status( $context = 'view' ): string { return ''; }
	public function get_total_discount( $ex_tax = true ): string { return '0'; }
	public function get_date_created( $context = 'view' ) { return null; }
	public function get_items( $types = 'line_item' ): array { return array(); }
	public function update_meta_data( $key, $value, $unique_id = 0 ): void {}
	public function delete_meta_data( $key ): void {}
	public function get_meta( $key = '', $single = true, $context = 'view' ) { return ''; }
	public function save() { return 0; }
}
PHP
	);
}
