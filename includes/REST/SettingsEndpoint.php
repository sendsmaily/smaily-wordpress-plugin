<?php
/**
 * REST endpoint persisting Settings tab payloads.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

use Smaily\Connect\Constants;
use Smaily\Connect\Integrations\WooCommerce\LegacyHookBridge;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\SetupState;
use Smaily\Connect\Smaily\ContactSyncMode;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST /wp-json/smaily-connect/v1/settings`
 *
 * Body:
 *   {
 *     "tab":  "connection" | "subscribers" | "woocommerce" | "recommendations",
 *     "data": { ...tab-specific payload }
 *   }
 *
 * Response:
 *   200 OK
 *   {
 *     "saved":  true,
 *     "errors": []
 *   }
 * — or —
 *   400 Bad Request
 *   {
 *     "saved":  false,
 *     "errors": [ { "field": "subdomain", "message": "Required" } ]
 *   }
 *
 * Each tab has its own validate + persist pair. Connection routes
 * credentials through the legacy Smaily_Connect\Includes\Options
 * writer (which handles encryption) so legacy code paths (CF7, Gutenberg
 * blocks) still see the same values; new BETA options live under the
 * smly_plus_* prefix and write straight via update_option.
 *
 * Auth: nonce + manage_options. Per-tab field sanitisation happens
 * inside each handler — we don't trust WP's args sanitize_callback alone
 * because the payload shape varies per tab.
 */
class SettingsEndpoint {

	public const ROUTE = '/settings';

	private const VALID_TABS = array( 'connection', 'subscribers', 'woocommerce', 'recommendations', 'finish' );

	private const LEGACY_OPTION_API_CREDENTIALS = 'smaily_connect_api_credentials';
	private const LEGACY_OPTION_SYNC_ENABLED    = ContactSyncMode::OPTION_SYNC_ENABLED;
	private const LEGACY_OPTION_SYNC_FIELDS     = 'smaily_connect_subscriber_sync_fields';
	private const LEGACY_OPTION_CHECKOUT_OPTIN  = 'smaily_connect_checkout_subscription_enabled';
	private const LEGACY_OPTION_CART_CUTOFF     = 'smaily_connect_abandoned_cart_cutoff';
	private const LEGACY_OPTION_CART_STATUS     = 'smaily_connect_abandoned_cart_status';

	/**
	 * Automation-mapping trigger_type allowlist (PRO-1504). Rows outside
	 * this list are silently dropped from the replace, same as a row
	 * missing a required field — a garbage trigger_type can't otherwise
	 * reach {prefix}smly_plus_automation_mapping.
	 */
	private const VALID_TRIGGER_TYPES = array(
		'welcome',
		'first_order',
		'abandoned_cart',
		'order_confirmation',
		'shipping_confirmation',
	);

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'tab'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'data' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ) {
		if ( ! current_user_can( Constants::CAPABILITY ) ) {
			return new WP_Error(
				'smaily_connect_forbidden',
				__( 'You do not have permission to save Smaily Connect settings.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$tab  = is_string( $request->get_param( 'tab' ) ) ? $request->get_param( 'tab' ) : '';
		$data = $request->get_param( 'data' );

		if ( ! in_array( $tab, self::VALID_TABS, true ) ) {
			return $this->error_response(
				array(
					array(
						'field'   => 'tab',
						'message' => __( 'Unknown settings tab.', 'smaily-connect' ),
					),
				),
				400
			);
		}

		if ( ! is_array( $data ) ) {
			return $this->error_response(
				array(
					array(
						'field'   => 'data',
						'message' => __( 'Payload must be an object.', 'smaily-connect' ),
					),
				),
				400
			);
		}

		switch ( $tab ) {
			case 'connection':
				return $this->save_connection( $data );
			case 'subscribers':
				return $this->save_subscribers( $data );
			case 'woocommerce':
				return $this->save_woocommerce( $data );
			case 'recommendations':
				return $this->save_recommendations( $data );
			case 'finish':
				return $this->save_finish();
			default:
				// Defensive — in_array() above forbids reaching here, but the
				// switch needs a terminal arm for return-type narrowing.
				return $this->error_response( array(), 400 );
		}
	}

	/**
	 * Wizard-only pseudo-tab — flips the setup-completed flag after the
	 * merchant clicks Finish on Step 6.
	 *
	 * Every earlier step already persisted its own slice via Continue
	 * (sub-PR 2.H.18 save-on-Continue flow). This call carries no
	 * payload; the only side-effect is the option write that the React
	 * boot reads next time the merchant lands on /wizard.
	 */
	private function save_finish(): WP_REST_Response {
		update_option( SetupState::OPTION_SETUP_COMPLETED, true );

		// P1 #1: hand contact sync to the new path. The gate (read on every
		// WooCommerce event) opens the moment the option above flips; strip
		// the legacy subscriber-sync hooks now so they're gone for the rest
		// of THIS request too. Bootstrap re-applies the strip on every later
		// request's `init` — the option, not this call, is what makes it
		// persist.
		LegacyHookBridge::deregister_subscriber_sync();

		return $this->success_response();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_connection( array $data ): WP_REST_Response {
		$errors = array();

		$smaily       = isset( $data['smailyCredentials'] ) && is_array( $data['smailyCredentials'] )
			? $data['smailyCredentials']
			: array();
		$subdomain    = isset( $smaily['subdomain'] ) ? sanitize_text_field( (string) $smaily['subdomain'] ) : '';
		$username     = isset( $smaily['username'] ) ? sanitize_text_field( (string) $smaily['username'] ) : '';
		$password     = isset( $smaily['password'] ) ? (string) $smaily['password'] : '';
		$mode_raw     = isset( $data['multilingualMode'] ) ? (string) $data['multilingualMode'] : 'single';
		$valid_modes  = array( 'single', 'A', 'B', 'C' );
		$multilingual = in_array( $mode_raw, $valid_modes, true ) ? $mode_raw : 'single';

		if ( $subdomain === '' || $username === '' ) {
			$errors[] = array(
				'field'   => 'subdomain',
				'message' => __( 'Subdomain + username are required.', 'smaily-connect' ),
			);
		}

		if ( ! empty( $errors ) ) {
			return $this->error_response( $errors, 400 );
		}

		$this->persist_credentials( self::LEGACY_OPTION_API_CREDENTIALS, $subdomain, $username, $password );

		// Sub-PR 2.H.15 — mark the default account as verified.
		//
		// The Settings UI only fires Save after a successful "Test
		// connection" round-trip; reaching this code path implies the
		// merchant just demonstrated that these credentials authenticate.
		// We persist that fact so hydrate.ts can re-render the
		// "✓ Connected" view on next page load and skip the forced
		// password re-entry that previously blocked second wizard
		// passes (Erkki couldn't even complete a re-walkthrough without
		// minting a fresh Smaily API user).
		update_option( 'smly_plus_default_connection_verified', true );

		update_option( 'smly_plus_multilingual_mode', $multilingual );

		// Mode A per-language credentials.
		$per_language = isset( $data['perLanguageAccounts'] ) && is_array( $data['perLanguageAccounts'] )
			? $data['perLanguageAccounts']
			: array();
		foreach ( $per_language as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			$account_key   = isset( $account['accountKey'] ) ? sanitize_key( (string) $account['accountKey'] ) : '';
			$creds         = isset( $account['credentials'] ) && is_array( $account['credentials'] )
				? $account['credentials']
				: array();
			$acc_subdomain = isset( $creds['subdomain'] ) ? sanitize_text_field( (string) $creds['subdomain'] ) : '';
			$acc_username  = isset( $creds['username'] ) ? sanitize_text_field( (string) $creds['username'] ) : '';
			$acc_password  = isset( $creds['password'] ) ? (string) $creds['password'] : '';

			if ( $account_key === '' ) {
				continue;
			}

			$this->persist_credentials(
				Credentials::PHASE2_OPTION_PREFIX . $account_key,
				$acc_subdomain,
				$acc_username,
				$acc_password
			);
		}

		$fallback_key = isset( $data['defaultFallbackAccountKey'] )
			? sanitize_key( (string) $data['defaultFallbackAccountKey'] )
			: 'default';
		update_option( 'smly_plus_default_fallback_account', $fallback_key );

		$this->save_transactional_account( $data );

		return $this->success_response();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_subscribers( array $data ): WP_REST_Response {
		$sync_enabled   = ! empty( $data['subscriberSyncEnabled'] );
		$fields_raw     = isset( $data['syncFields'] ) && is_array( $data['syncFields'] ) ? $data['syncFields'] : array();
		$fields         = array_values(
			array_filter(
				array_map(
					static fn ( $f ): string => sanitize_key( (string) $f ),
					$fields_raw
				)
			)
		);
		$wp_optin       = ! empty( $data['wordpressSubscriptionCheckbox'] );
		$checkout_optin = ! empty( $data['checkoutSubscriptionCheckbox'] );

		// Contact-sync mode (F3-48) — validate the preset; an unknown value
		// falls back to the lawful-safe default rather than being stored.
		$mode = isset( $data['contactSyncMode'] ) ? sanitize_key( (string) $data['contactSyncMode'] ) : ContactSyncMode::DEFAULT_MODE;
		if ( ! ContactSyncMode::is_valid_mode( $mode ) ) {
			$mode = ContactSyncMode::DEFAULT_MODE;
		}
		$include_guests = ! empty( $data['includeGuests'] );
		// A browser holding a pre-PRO-1716 admin bundle still posts
		// `automationForceOptIn`. Nothing reads it: the save succeeds and the
		// retired option is never written again.

		/*
		 * Stored as '1' / '' — the on-disk shape the legacy settings page left
		 * behind, and the only one that survives being saved OFF on a store
		 * that never saved it before: a missing option reads as false, so
		 * update_option( …, false ) concludes nothing changed and writes
		 * nothing at all, while this switch defaults to ON (PRO-1742). Every
		 * other flag here defaults to off, where losing the write is harmless.
		 */
		update_option( self::LEGACY_OPTION_SYNC_ENABLED, $sync_enabled ? '1' : '' );
		update_option( self::LEGACY_OPTION_SYNC_FIELDS, $fields );
		update_option( 'smly_plus_wordpress_subscription_checkbox', $wp_optin );
		update_option( self::LEGACY_OPTION_CHECKOUT_OPTIN, $checkout_optin );
		update_option( ContactSyncMode::OPTION_MODE, $mode );
		update_option( ContactSyncMode::OPTION_INCLUDE_GUESTS, $include_guests );

		return $this->success_response();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_woocommerce( array $data ): WP_REST_Response {
		$welcome  = ! empty( $data['welcomeEnabled'] );
		$first    = ! empty( $data['firstOrderEnabled'] );
		$cart     = ! empty( $data['abandonedCartEnabled'] );
		$cutoff   = isset( $data['abandonedCartCutoffMinutes'] ) ? (int) $data['abandonedCartCutoffMinutes'] : 30;
		$cutoff   = max( 10, $cutoff );
		$mappings = isset( $data['automationMappings'] ) && is_array( $data['automationMappings'] )
			? $data['automationMappings']
			: array();

		update_option( 'smly_plus_welcome_enabled', $welcome );
		update_option( 'smly_plus_first_order_enabled', $first );

		/*
		 * The legacy cron pass reads this option as an ARRAY
		 * {enabled, autoresponder_id}. Until 3.4.3 this wrote the bare
		 * boolean — WordPress stores it as the string '1'/'' and every
		 * array-offset consumer fataled on PHP 8, on every 15-minute tick
		 * (F3-54, the Prike crash loop). Write the array shape and keep
		 * whatever autoresponder id an upgraded store still carries — the
		 * legacy pass uses it as the fallback when no automation-mapping
		 * row exists yet.
		 */
		$existing = \Smaily_Connect\Includes\Options::abandoned_cart_status();
		update_option(
			self::LEGACY_OPTION_CART_STATUS,
			array(
				'enabled'          => $cart,
				'autoresponder_id' => $existing['autoresponder_id'],
			)
		);
		update_option( self::LEGACY_OPTION_CART_CUTOFF, $cutoff );

		$this->save_transactional_triggers( $data );

		// Replace the mapping table contents in one shot — Settings always
		// sends the full list it wants persisted, no partial updates.
		$this->replace_automation_mappings( $mappings );

		return $this->success_response();
	}

	/**
	 * Transactional emails — the account connection (PRO-1504 stage 1;
	 * relocated to the Connection tab's payload by PRO-1540). A separate
	 * Smaily account bound under account_key='transactional', mirroring how
	 * the default account's credentials are persisted above: empty inbound
	 * password = "leave the stored secret as-is", non-empty = "rotate to
	 * this".
	 *
	 * @param array<string, mixed> $data
	 */
	private function save_transactional_account( array $data ): void {
		update_option( 'smly_plus_transactional_emails_enabled', ! empty( $data['transactionalEmailsEnabled'] ) );

		$creds     = isset( $data['transactionalCredentials'] ) && is_array( $data['transactionalCredentials'] )
			? $data['transactionalCredentials']
			: array();
		$subdomain = isset( $creds['subdomain'] ) ? sanitize_text_field( (string) $creds['subdomain'] ) : '';
		$username  = isset( $creds['username'] ) ? sanitize_text_field( (string) $creds['username'] ) : '';
		$password  = isset( $creds['password'] ) ? (string) $creds['password'] : '';

		$this->persist_credentials( Credentials::PHASE2_OPTION_PREFIX . 'transactional', $subdomain, $username, $password );

		// Mirrors save_connection()'s default-account verified flag, but
		// scoped to whether this save actually carries a usable credential
		// pair — transactional credentials are optional (only relevant once
		// the merchant turns the feature on), so an incomplete/cleared pair
		// must not keep showing a stale "✓ Connected" view.
		update_option( 'smly_plus_transactional_connection_verified', $subdomain !== '' && $username !== '' );
	}

	/**
	 * Transactional emails — the two trigger toggles + the "counts as
	 * shipped" order-status set (PRO-1504 stage 1). Rendered on the
	 * WooCommerce tab (PRO-1540: gated there on the transactional
	 * connection above being established), so persisted alongside the
	 * other WooCommerce-tab automations.
	 *
	 * @param array<string, mixed> $data
	 */
	private function save_transactional_triggers( array $data ): void {
		update_option( 'smly_plus_order_confirmation_enabled', ! empty( $data['orderConfirmationEnabled'] ) );
		update_option( 'smly_plus_shipping_confirmation_enabled', ! empty( $data['shippingConfirmationEnabled'] ) );

		$statuses_raw = isset( $data['shippedOrderStatuses'] ) && is_array( $data['shippedOrderStatuses'] )
			? $data['shippedOrderStatuses']
			: array();
		$statuses     = array_values(
			array_filter(
				array_map(
					static fn ( $s ): string => sanitize_key( (string) $s ),
					$statuses_raw
				)
			)
		);
		update_option( 'smly_plus_shipped_order_statuses', $statuses );
	}

	/**
	 * Persist one Smaily credential set (default, per-language, or
	 * transactional account — all three save paths share this rule).
	 *
	 * Preserves the stored password when the inbound payload doesn't carry
	 * one. EnvDetector::saved_settings() deliberately omits the password
	 * from the boot payload (security gate), so on second wizard pass
	 * the React state has password=''. Continue on Step 1 would then
	 * overwrite the encrypted secret with an empty string, and every
	 * downstream API call (Workflows fetch, Backfill push) would fail
	 * with "Credentials not configured" the next time the merchant
	 * loaded the wizard. Empty incoming password === "leave as-is";
	 * a non-empty value === "rotate to this".
	 */
	private function persist_credentials( string $option_key, string $subdomain, string $username, string $password ): void {
		if ( $password === '' ) {
			$existing  = get_option( $option_key, array() );
			$encrypted = is_array( $existing ) && isset( $existing['password'] ) ? (string) $existing['password'] : '';
		} else {
			$encrypted = $this->encrypt_password( $password );
		}

		update_option(
			$option_key,
			array(
				'subdomain' => $subdomain,
				'username'  => $username,
				'password'  => $encrypted,
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function replace_automation_mappings( array $rows ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'smly_plus_automation_mapping';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$trigger     = isset( $row['triggerType'] ) ? sanitize_key( (string) $row['triggerType'] ) : '';
			$language    = isset( $row['language'] ) ? sanitize_text_field( (string) $row['language'] ) : '';
			$account_key = isset( $row['accountKey'] ) ? sanitize_key( (string) $row['accountKey'] ) : 'default';
			$workflow_id = isset( $row['workflowId'] ) ? (string) $row['workflowId'] : '';
			$is_fallback = ! empty( $row['isDefaultFallback'] ) ? 1 : 0;

			// The allowlist check subsumes the old `$trigger === ''` guard —
			// '' is never a member of VALID_TRIGGER_TYPES.
			if ( ! in_array( $trigger, self::VALID_TRIGGER_TYPES, true ) ) {
				continue;
			}

			if ( $language === '' || $workflow_id === '' ) {
				continue;
			}

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (trigger_type, language, account_key, workflow_id, is_default_fallback) VALUES (%s, %s, %s, %s, %d)",
					$trigger,
					$language,
					$account_key,
					$workflow_id,
					$is_fallback
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function save_recommendations( array $data ): WP_REST_Response {
		$features = isset( $data['recEngineFeatures'] ) && is_array( $data['recEngineFeatures'] )
			? $data['recEngineFeatures']
			: array();

		// 3.9: connecting the rec-engine syncs all domains unconditionally
		// (products/customers/orders fire while is_connected()). The per-domain
		// sync toggles were removed — only the browse-tracking preference (a
		// legal-consent gate, opt-in default-off) is persisted here. The four
		// removed option keys are cleaned up in Activation::cleanup_retired_options().
		update_option( 'smly_plus_rec_track_browsing', ! empty( $features['trackBrowsing'] ) );

		return $this->success_response();
	}

	private function encrypt_password( string $plain ): string {
		if ( $plain === '' ) {
			return '';
		}
		if ( class_exists( '\\Smaily_Connect\\Includes\\Cypher' ) ) {
			return (string) \Smaily_Connect\Includes\Cypher::encrypt( $plain );
		}
		return $plain; // Defensive fallback — shouldn't happen post-bootstrap.
	}

	private function success_response(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'saved'  => true,
				'errors' => array(),
			),
			200
		);
	}

	/**
	 * @param array<int, array{field: string, message: string}> $errors
	 */
	private function error_response( array $errors, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'saved'  => false,
				'errors' => $errors,
			),
			$status
		);
	}
}
