<?php
/**
 * Which other sites of a multisite network share this site's Smaily account (CON-467).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Multilingual\Router;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\Subdomain;

/**
 * On a multisite network each site keeps its own settings, so two sites can
 * send contacts to one Smaily account under different contact sync modes —
 * and their rules then contradict each other in one contact list. The
 * contacts step of setup and Settings warn about it; they never block or
 * change anything.
 *
 * Reading another site's options means switching to it, so the network
 * keeps an index instead: one network option, blog id => the Smaily
 * accounts (normalised subdomains) its contact sync can keep a contact in
 * (`ContactAccount::distinct_accounts()`: the main account, plus each
 * language account in the account-per-language mode) and its mode. A site
 * writes only its own entry: when one of its tracked options changes (once,
 * at shutdown), on every update (`Activation::run()`), and when its setup or
 * Settings screen opens; deactivation removes it. A site with contact sync
 * off or no complete account has no entry. The screen reads the one option
 * and looks up at most `MAX_LISTED` sharing sites. Not multisite: nothing is
 * hooked, read or written.
 */
final class NetworkAccounts {

	/** The network option that holds the index. */
	public const SITE_OPTION = 'smly_plus_network_accounts';

	/** The most other sites one screen names — each costs one site lookup. */
	public const MAX_LISTED = 10;

	/** The options whose change can change this site's entry, besides the per-account credentials. */
	private const TRACKED = array(
		Credentials::LEGACY_OPTION_KEY,
		ContactSyncMode::OPTION_MODE,
		ContactSyncMode::OPTION_SYNC_ENABLED,
		Router::OPTION_MODE,
	);

	/** Hooked from Bootstrap::boot() on a multisite network only. */
	public static function register(): void {
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_option_change' ) );
		}
	}

	/**
	 * A tracked option changed: refresh this site's entry once, at the end
	 * of the request, after every option of the save is written.
	 *
	 * @param mixed $option The option name.
	 */
	public static function on_option_change( $option ): void {
		$option = (string) $option;
		if ( in_array( $option, self::TRACKED, true ) || strpos( $option, Credentials::PHASE2_OPTION_PREFIX ) === 0 ) {
			add_action( 'shutdown', array( self::class, 'refresh' ) );
		}
	}

	/** Writes this site's entry when it changed. */
	public static function refresh(): void {
		if ( ! is_multisite() ) {
			return;
		}

		self::store( get_current_blog_id(), self::own_entry() );
	}

	/** Removes this site's entry (deactivation). */
	public static function forget(): void {
		if ( ! is_multisite() ) {
			return;
		}

		self::store( get_current_blog_id(), null );
	}

	/**
	 * The other sites that keep contacts in one of this site's Smaily
	 * accounts, with their mode — never their credentials. The screen warns
	 * about those whose mode differs from the one chosen there. Refreshes
	 * this site's own entry first.
	 *
	 * @return array<int, array{name: string, url: string, mode: string}>
	 */
	public static function sites_sharing_account(): array {
		if ( ! is_multisite() ) {
			return array();
		}

		$blog_id = get_current_blog_id();
		$own     = self::own_entry();
		$index   = self::store( $blog_id, $own );
		if ( $own === null ) {
			return array();
		}

		$sites = array();
		foreach ( $index as $other_id => $entry ) {
			if ( (int) $other_id === $blog_id || ! is_array( $entry ) || ! is_array( $entry['accounts'] ?? null ) ) {
				continue;
			}
			if ( array_intersect( $own['accounts'], $entry['accounts'] ) === array() ) {
				continue;
			}
			// A site removed, archived or marked spam since it wrote its entry.
			$site = get_blog_details( (int) $other_id );
			if ( $site === false || (int) $site->deleted === 1 || (int) $site->archived === 1 || (int) $site->spam === 1 ) {
				continue;
			}
			$sites[] = array(
				'name' => (string) $site->blogname,
				'url'  => (string) $site->home,
				'mode' => (string) ( $entry['mode'] ?? '' ),
			);
			if ( count( $sites ) >= self::MAX_LISTED ) {
				break;
			}
		}

		return $sites;
	}

	/**
	 * This site's entry, or null when contact sync is off or no account
	 * holds a complete credential set.
	 *
	 * @return array{accounts: array<int, string>, mode: string}|null
	 */
	private static function own_entry(): ?array {
		if ( ! ContactSyncMode::sync_enabled() ) {
			return null;
		}

		$credentials = new Credentials();
		$accounts    = array();
		foreach ( ( new ContactAccount() )->distinct_accounts() as $account_key ) {
			$set = $credentials->get( $account_key );
			if ( $set !== null && $set->is_complete() ) {
				$accounts[] = Subdomain::normalize( $set->subdomain );
			}
		}
		if ( $accounts === array() ) {
			return null;
		}

		$accounts = array_values( array_unique( $accounts ) );
		sort( $accounts );

		return array(
			'accounts' => $accounts,
			'mode'     => ( new ContactSyncMode() )->mode(),
		);
	}

	/**
	 * Sets this site's entry (null removes it), writing only on a change,
	 * and returns the index. Two sites saving at the same moment can lose
	 * one write; that site's next refresh puts it back.
	 *
	 * @param array{accounts: array<int, string>, mode: string}|null $entry
	 * @return array<int|string, mixed>
	 */
	private static function store( int $blog_id, ?array $entry ): array {
		$index = get_site_option( self::SITE_OPTION, array() );
		$index = is_array( $index ) ? $index : array();

		if ( ( $index[ $blog_id ] ?? null ) === $entry ) {
			return $index;
		}

		if ( $entry === null ) {
			unset( $index[ $blog_id ] );
		} else {
			$index[ $blog_id ] = $entry;
		}
		update_site_option( self::SITE_OPTION, $index );

		return $index;
	}
}
