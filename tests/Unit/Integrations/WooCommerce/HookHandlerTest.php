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
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\ContactReconciler;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\EventQueue;

final class HookHandlerTest extends TestCase {

	/** @var array<int, array{type: string, entity_id: string, payload: array<string, mixed>}> */
	private array $enqueued = array();

	private EventQueue $queue;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		HookHandler::reset_seen();
		// ContactLanguageResolver caches the active detector via DetectorFactory
		// (a process-global static). Reset so each case resolves through the
		// single-language SiteLocale fallback, independent of other tests.
		DetectorFactory::reset();
		$this->enqueued = array();

		// Fake EventQueue that records enqueue() calls in the local array
		// instead of touching $wpdb / Action Scheduler.
		$enqueued    = &$this->enqueued;
		$this->queue = new class( $enqueued ) extends EventQueue {
			private array $sink;

			public function __construct( array &$sink ) {
				$this->sink = &$sink;
			}

			public function enqueue( string $event_type, string $entity_id, array $payload ): ?int {
				$this->sink[] = array(
					'type'      => $event_type,
					'entity_id' => $entity_id,
					'payload'   => $payload,
				);
				return count( $this->sink );
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
	public function get_meta( $key = '', $single = true, $context = 'view' ) { return ''; }
	public function save() { return 0; }
}
PHP
	);
}
