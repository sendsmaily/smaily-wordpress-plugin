<?php
/**
 * Which Smaily account a contact goes to (CON-446).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Multilingual\Router;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Support\ContactLanguageResolver;

/**
 * In the account-per-language mode (Mode A) each language has its own Smaily
 * account, stored under the account key `account_<language>` (the setup's
 * per-language blocks). A contact goes to the account of its language — the
 * language ContactLanguageResolver gave it, compared in the short form, as
 * Router::find_mapping() compares a mapping row's language (CON-203) — when
 * that account holds a complete credential set. Any other contact goes to
 * the fallback account the merchant chose ("Default fallback account"),
 * when it holds a complete credential set, else to the `default` account —
 * the main account, which the setup saves from the fallback language
 * account (CON-441).
 *
 * Every other mode answers `default` without reading anything.
 *
 * The one rule for live contact sync (HookHandler stores the answer on the
 * queue row, so a retry goes to the same account), the contact import, the
 * consent reads that look up where contact sync keeps the contact
 * (`for_email()` for a contact known only by address), and the accounts
 * whose unsubscribes reach WordPress (`distinct_accounts()`).
 * Contacts already in another account are not moved; their unsubscribe and
 * profiling objection follow them (`other_accounts()`, CON-480).
 */
final class ContactAccount {

	/** The queue payload key of a `contact.sync` row's account; absent = `default`. */
	public const PAYLOAD_KEY = 'account_key';

	/** The fallback account's key, as the Connection save stores it (sanitize_key()). */
	private const OPTION_FALLBACK = 'smly_plus_default_fallback_account';

	/** The account-key prefix of a language account. */
	private const LANGUAGE_ACCOUNT_PREFIX = 'account_';

	/**
	 * Short language code => account key, read once per instance.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $accounts = null;

	/**
	 * The account key a contact of this language goes to.
	 */
	public function for_language( string $language ): string {
		if ( get_option( Router::OPTION_MODE, Router::MODE_SINGLE ) !== Router::MODE_A ) {
			return Credentials::DEFAULT_ACCOUNT_KEY;
		}

		$accounts = $this->language_accounts();
		$wanted   = ContactLanguageResolver::normalize( $language );
		if ( $wanted !== '' && isset( $accounts[ $wanted ] ) ) {
			return $accounts[ $wanted ];
		}

		$fallback = (string) get_option( self::OPTION_FALLBACK, Credentials::DEFAULT_ACCOUNT_KEY );

		return in_array( $fallback, $accounts, true ) ? $fallback : Credentials::DEFAULT_ACCOUNT_KEY;
	}

	/**
	 * The account key of a contact known only by address (CON-472): the
	 * language ContactLanguageResolver gives the store's user of that
	 * address, else the one it gives a contact known only by email. Every
	 * other mode answers `default` without reading anything.
	 */
	public function for_email( string $email ): string {
		if ( get_option( Router::OPTION_MODE, Router::MODE_SINGLE ) !== Router::MODE_A ) {
			return Credentials::DEFAULT_ACCOUNT_KEY;
		}

		$resolver = new ContactLanguageResolver();
		$user     = get_user_by( 'email', $email );

		return $this->for_language( $user instanceof \WP_User ? $resolver->for_user( $user ) : $resolver->for_guest() );
	}

	/**
	 * Every account contact sync can keep a contact in, each Smaily account
	 * once (CON-471): `default` first, then in Mode A each language account
	 * with a complete credential set whose subdomain no earlier key has —
	 * the fallback language account is usually the main account itself
	 * (CON-441). Every other mode answers `default` without reading anything.
	 *
	 * @return array<int, string>
	 */
	public function distinct_accounts(): array {
		$keys = array( Credentials::DEFAULT_ACCOUNT_KEY );
		if ( get_option( Router::OPTION_MODE, Router::MODE_SINGLE ) !== Router::MODE_A ) {
			return $keys;
		}

		$credentials = new Credentials();
		$main        = $credentials->get();
		$seen        = $main !== null && $main->is_complete() ? array( strtolower( trim( $main->subdomain ) ) => true ) : array();
		$prefix      = Credentials::PHASE2_OPTION_PREFIX . self::LANGUAGE_ACCOUNT_PREFIX;
		foreach ( Credentials::option_keys() as $option_key ) {
			if ( strpos( $option_key, $prefix ) !== 0 ) {
				continue;
			}
			$account_key = substr( $option_key, strlen( Credentials::PHASE2_OPTION_PREFIX ) );
			$set         = $credentials->get( $account_key );
			$subdomain   = $set !== null ? strtolower( trim( $set->subdomain ) ) : '';
			if ( $set !== null && $set->is_complete() && ! isset( $seen[ $subdomain ] ) ) {
				$seen[ $subdomain ] = true;
				$keys[]             = $account_key;
			}
		}

		return $keys;
	}

	/**
	 * Every account of distinct_accounts() but the Smaily account (subdomain)
	 * this key names — where else contact sync can keep a contact (CON-480).
	 * Empty outside Mode A, and in Mode A when every language shares one
	 * Smaily account.
	 *
	 * @return array<int, string>
	 */
	public function other_accounts( string $account_key ): array {
		$accounts = $this->distinct_accounts();
		if ( count( $accounts ) < 2 ) {
			return array();
		}

		$credentials = new Credentials();
		$own         = $credentials->get( $account_key );
		$subdomain   = $own !== null ? strtolower( trim( $own->subdomain ) ) : '';
		$others      = array();
		foreach ( $accounts as $key ) {
			$set = $credentials->get( $key );
			if ( $key !== $account_key && ( $set === null || strtolower( trim( $set->subdomain ) ) !== $subdomain ) ) {
				$others[] = $key;
			}
		}

		return $others;
	}

	/**
	 * Every language account with a complete credential set. Two accounts
	 * of one short language (`en_gb`, `en_us`): the first listed wins.
	 *
	 * @return array<string, string>
	 */
	private function language_accounts(): array {
		if ( $this->accounts !== null ) {
			return $this->accounts;
		}

		$credentials    = new Credentials();
		$prefix         = Credentials::PHASE2_OPTION_PREFIX . self::LANGUAGE_ACCOUNT_PREFIX;
		$this->accounts = array();
		foreach ( Credentials::option_keys() as $option_key ) {
			if ( strpos( $option_key, $prefix ) !== 0 ) {
				continue;
			}
			$account_key = substr( $option_key, strlen( Credentials::PHASE2_OPTION_PREFIX ) );
			$language    = ContactLanguageResolver::normalize( substr( $option_key, strlen( $prefix ) ) );
			$set         = $credentials->get( $account_key );
			if ( $language !== '' && ! isset( $this->accounts[ $language ] ) && $set !== null && $set->is_complete() ) {
				$this->accounts[ $language ] = $account_key;
			}
		}

		return $this->accounts;
	}
}
