<?php
/**
 * Builds the installation report body (CON-290).
 *
 * @package Smaily\Connect\Report
 */

declare(strict_types=1);

namespace Smaily\Connect\Report;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Integrations\WooCommerce\HookHandler;
use Smaily\Connect\Multilingual\Router;
use Smaily\Connect\REST\BeaconEndpoint;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Settings\SetupState;
use Smaily\Connect\Smaily\ContactSyncMode;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;
use Smaily\Connect\Smaily\TransactionalGate;

/**
 * The body of `POST /v1/reports` (contract installation-report-v1, product
 * `connect_woocommerce`): the site and its versions, and one connection per
 * Smaily account — the default account, each Mode A language account, and
 * the Smaily Transactional account.
 *
 * The MAIN account authenticates the report: the default account, else the
 * first other marketing account. A Transactional account never does, so a
 * store connected to nothing but a Transactional account reports nothing.
 *
 * What it carries is the contract's list and nothing more — no customer,
 * order, admin or free-text data, no credential (the main account's API user
 * and password go only into the Authorization header), and error CODES only.
 */
class ReportBuilder {

	public const PRODUCT = 'connect_woocommerce';

	public const EVENT_SNAPSHOT     = 'snapshot';
	public const EVENT_DISCONNECTED = 'disconnected';
	public const EVENT_DEACTIVATED  = 'deactivated';

	public const TRIGGER_SETUP_SAVED = 'setup_saved';
	public const TRIGGER_DAILY       = 'daily';

	private const TRANSACTIONAL_KEY = 'transactional';

	/** The contract's subdomain rule. */
	private const SUBDOMAIN_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/';

	private Credentials $credentials;

	private RecEngineSettings $engine;

	private ConnectionHealth $health;

	/**
	 * The trigger types mapped to a workflow, by account key — read once per
	 * build(), on first use.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	private ?array $mapped = null;

	public function __construct( ?Credentials $credentials = null, ?RecEngineSettings $engine = null, ?ConnectionHealth $health = null ) {
		$this->credentials = $credentials ?? new Credentials();
		$this->engine      = $engine ?? new RecEngineSettings();
		$this->health      = $health ?? new ConnectionHealth();
	}

	/**
	 * The main account's credentials, or null when no Smaily marketing
	 * account is connected — then nothing is reported (guideline 7).
	 */
	public function main_credentials(): ?CredentialSet {
		$accounts = $this->marketing_accounts( array() );

		return $accounts === array() ? null : $accounts[0]['set'];
	}

	/**
	 * The report body and the main account's credentials that authenticate
	 * it, or null when no Smaily marketing account is connected.
	 *
	 * @param string             $event                One of the EVENT_* constants.
	 * @param string             $trigger              A TRIGGER_* constant for a snapshot, '' otherwise.
	 * @param array<int, string> $removed_accounts     Account keys a `disconnected` event removes.
	 * @param bool               $engine_removed       True when a `disconnected` event removes Campaign Intelligence.
	 *
	 * @return array{body: array<string, mixed>, credentials: CredentialSet}|null
	 */
	public function build( string $event, string $trigger = '', array $removed_accounts = array(), bool $engine_removed = false ): ?array {
		$all = $this->marketing_accounts( array() );
		if ( $all === array() ) {
			return null;
		}
		$main         = $all[0]['set'];
		$accounts     = $removed_accounts === array() ? $all : $this->marketing_accounts( $removed_accounts );
		$this->mapped = null;

		$connections = array();
		foreach ( $accounts as $index => $account ) {
			$connections[] = $this->marketing_connection( $account, $index === 0 && ! $engine_removed );
		}

		$transactional = $this->complete( self::TRANSACTIONAL_KEY );
		if ( $transactional !== null && ! in_array( self::TRANSACTIONAL_KEY, $removed_accounts, true ) ) {
			$connections[] = $this->connection(
				'transactional',
				$transactional,
				'',
				TransactionalGate::any_enabled() ? array( 'transactional_emails' ) : array()
			);
		}

		$body = array(
			'product'          => self::PRODUCT,
			'product_version'  => self::clip( Constants::version() ),
			'platforms'        => $this->platforms(),
			'php_version'      => self::clip( PHP_VERSION ),
			'installation_url' => $this->site_url(),
			'smaily_subdomain' => self::subdomain( $main ),
			'event'            => $event,
		);
		if ( $event === self::EVENT_SNAPSHOT ) {
			$body['trigger'] = $trigger;
		}
		$body['sent_at']     = IsoDate::to_z( $this->now() );
		$body['connections'] = $connections;

		return array(
			'body'        => $body,
			'credentials' => $main,
		);
	}

	protected function now(): int {
		return time();
	}

	/**
	 * The connected marketing accounts, main account first: the default
	 * account, then — in Mode A only — each language account. A connected
	 * account has a complete credential set whose subdomain passes the
	 * contract's rule.
	 *
	 * @param array<int, string> $removed Account keys left out.
	 *
	 * @return array<int, array{key: string, set: CredentialSet, language: string}>
	 */
	private function marketing_accounts( array $removed ): array {
		$accounts = array();
		$default  = $this->complete( Credentials::DEFAULT_ACCOUNT_KEY );
		if ( $default !== null && ! in_array( Credentials::DEFAULT_ACCOUNT_KEY, $removed, true ) ) {
			$accounts[] = array(
				'key'      => Credentials::DEFAULT_ACCOUNT_KEY,
				'set'      => $default,
				'language' => '',
			);
		}

		if ( ( new Router() )->current_mode() !== Router::MODE_A ) {
			return $accounts;
		}

		$languages = array();
		foreach ( Credentials::option_keys() as $option_key ) {
			if ( strpos( $option_key, Credentials::PHASE2_OPTION_PREFIX ) !== 0 ) {
				continue;
			}
			$key      = substr( $option_key, strlen( Credentials::PHASE2_OPTION_PREFIX ) );
			$language = strtolower( substr( $key, 0, 2 ) );
			$set      = $key === self::TRANSACTIONAL_KEY || in_array( $key, $removed, true ) ? null : $this->complete( $key );
			if ( $set === null || ! preg_match( '/^[a-z]{2}$/', $language ) || isset( $languages[ $language ] ) ) {
				continue;
			}
			$languages[ $language ] = true;
			$accounts[]             = array(
				'key'      => $key,
				'set'      => $set,
				'language' => $language,
			);
		}

		return $accounts;
	}

	/** The account's credentials when it is connected, else null. */
	private function complete( string $account_key ): ?CredentialSet {
		$set = $this->credentials->get( $account_key );
		if ( $set === null || ! $set->is_complete() || ! preg_match( self::SUBDOMAIN_PATTERN, self::subdomain( $set ) ) ) {
			return null;
		}

		return $set;
	}

	/**
	 * @param array{key: string, set: CredentialSet, language: string} $account
	 *
	 * @return array<string, mixed>
	 */
	private function marketing_connection( array $account, bool $with_engine ): array {
		$features = array();
		if ( SetupState::completed() ) {
			if ( ContactSyncMode::sync_enabled() ) {
				$features[] = 'subscriber_sync';
			}
			$features = array_merge( $features, $this->automations_on( $account['key'] ) );
		}

		$engine = $with_engine && $this->engine->is_connected() && $this->engine->tenant_id() !== '';
		if ( $engine ) {
			$features[] = 'campaign_intelligence';
			if ( (bool) get_option( BeaconEndpoint::OPTION_TRACK_BROWSING, false ) ) {
				$features[] = 'browse_tracking';
			}
		}

		$connection = $this->connection( 'marketing', $account['set'], $account['language'], $features );
		if ( $engine ) {
			$health                     = $this->health->engine();
			$connection['intelligence'] = array(
				'tenant_id'       => $this->engine->tenant_id(),
				'connected'       => $this->engine->sending_allowed(),
				'status'          => $health['status'],
				'last_sync_at'    => self::time_or_null( $health['last_sync_at'] ),
				'last_error_code' => $health['last_error_code'],
			);
		}

		return $connection;
	}

	/**
	 * @param array<int, string> $features
	 *
	 * @return array<string, mixed>
	 */
	private function connection( string $role, CredentialSet $set, string $language, array $features ): array {
		$health     = $this->health->smaily( self::subdomain( $set ) );
		$connection = array(
			'role'             => $role,
			'store_url'        => $this->site_url(),
			'smaily_subdomain' => self::subdomain( $set ),
		);
		if ( $language !== '' ) {
			$connection['language'] = $language;
		}
		$connection['features'] = $features;
		$connection['smaily']   = array(
			'status'          => $health['status'],
			'last_sync_at'    => self::time_or_null( $health['last_sync_at'] ),
			'last_error_code' => $health['last_error_code'],
		);

		return $connection;
	}

	/**
	 * The automations switched on AND mapped to a workflow of this account:
	 * welcome, first order, abandoned cart (whose pre-mapping workflow id
	 * still counts on the default account — CartFlusher's fallback).
	 *
	 * @return array<int, string>
	 */
	private function automations_on( string $account_key ): array {
		if ( $this->mapped === null ) {
			$this->mapped = $this->mapped_triggers();
		}
		$mapped = $this->mapped[ $account_key ] ?? array();
		$cart   = \Smaily_Connect\Includes\Options::abandoned_cart_status();

		$on = array();
		if ( (bool) get_option( HookHandler::OPTION_WELCOME_ENABLED, false ) && in_array( 'welcome', $mapped, true ) ) {
			$on[] = 'welcome_automation';
		}
		if ( (bool) get_option( HookHandler::OPTION_FIRST_ORDER_ENABLED, false ) && in_array( 'first_order', $mapped, true ) ) {
			$on[] = 'first_order_automation';
		}
		if ( $cart['enabled'] && ( in_array( 'abandoned_cart', $mapped, true ) || ( $account_key === Credentials::DEFAULT_ACCOUNT_KEY && $cart['autoresponder_id'] > 0 ) ) ) {
			$on[] = 'abandoned_cart';
		}

		return $on;
	}

	/**
	 * The trigger types mapped to a workflow, by account key.
	 *
	 * @return array<string, array<int, string>>
	 */
	protected function mapped_triggers(): array {
		return ( new Router() )->mapped_triggers_by_account();
	}

	/**
	 * @return array<int, array{name: string, version: string}>
	 */
	private function platforms(): array {
		$platforms = array(
			array(
				'name'    => 'wordpress',
				'version' => self::clip( (string) get_bloginfo( 'version' ) ),
			),
		);
		if ( defined( 'WC_VERSION' ) ) {
			$platforms[] = array(
				'name'    => 'woocommerce',
				'version' => self::clip( (string) WC_VERSION ),
			);
		}

		return $platforms;
	}

	/** The WordPress site address, without a trailing slash. */
	private function site_url(): string {
		return untrailingslashit( home_url() );
	}

	private static function subdomain( CredentialSet $set ): string {
		return strtolower( trim( $set->subdomain ) );
	}

	private static function time_or_null( ?int $timestamp ): ?string {
		return $timestamp === null ? null : IsoDate::to_z( $timestamp );
	}

	/** The contract's 32-character limit on a version string. */
	private static function clip( string $value ): string {
		return substr( $value, 0, 32 );
	}
}
