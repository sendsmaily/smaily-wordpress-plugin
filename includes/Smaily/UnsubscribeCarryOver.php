<?php
/**
 * An unsubscribe follows a contact into another Smaily account (CON-480).
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Multilingual\Router;

/**
 * In the account-per-language mode a contact can be kept in more than one
 * Smaily account: every contact synced before CON-446 is in the main
 * account, and a contact whose language changes stays in the old one.
 * Contact sync sends a contact without a status where it does not change
 * one, and Smaily creates an address it does not have as SUBSCRIBED — so a
 * contact who unsubscribed in one account would be subscribed in the next.
 *
 * Before such a send this rule asks the account the row goes to whether it
 * holds the contact; when it does not, it asks every other account of the
 * store (ContactAccount::other_accounts()) and the send carries
 * `is_unsubscribed = 1` when any of them has the contact unsubscribed. An
 * opt-in is never carried: an account that holds the contact keeps its own
 * status.
 *
 * Cost: nothing outside Mode A, nor in Mode A when every language shares one
 * Smaily account. Otherwise one read of the target account per contact, and
 * one read of each other account for a contact the target does not hold —
 * then the target holds it, which is remembered per account and address for
 * `CACHE_TTL` (a finite, non-autoloaded transient), so the next sends of that
 * contact to that account read nothing.
 *
 * A read that fails throws, so nothing is sent: a subscribed state nobody
 * asked for is never sent on doubt. A contact read that finds no contact
 * counts as an answer only with Smaily's 206 ("no such contact"); any other
 * reply without a contact is a failed read.
 *
 * The one rule for the live contact sync (Flusher) and the contact import
 * (BackfillJob), and — through unsubscribed_in_other_accounts() — for the
 * first-order email's and the cart reminder's consent read
 * (AutomationConsent, CON-482). Never copy it.
 */
final class UnsubscribeCarryOver {

	/** The transient prefix of "this account holds this contact". */
	public const CACHE_PREFIX = 'smly_held_';

	/** How long a known contact is not read again in that account. */
	public const CACHE_TTL = 30 * DAY_IN_SECONDS;

	/** Smaily's body code for a contact read of an address it does not have. */
	private const CODE_NOT_FOUND = 206;

	/** @var callable(string $account_key): Client */
	private $client_factory;

	/** The error of the read that failed last. */
	private ?ApiException $failed = null;

	/**
	 * The exchange of that read, for the Event Log (F3-44).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $failed_exchange = null;

	/**
	 * @param callable(string $account_key): Client $client_factory
	 */
	public function __construct( callable $client_factory ) {
		$this->client_factory = $client_factory;
	}

	/**
	 * Whether a send without a status to this account must carry an
	 * unsubscribe: true when the account does not hold the contact and
	 * another account has it unsubscribed, false when the send goes as it
	 * is, null when nothing was read (not Mode A, one Smaily account for
	 * every language, or the account is known to hold the contact). After a
	 * send that Smaily accepted, the caller calls remember() unless this
	 * answered null.
	 *
	 * @param Client $client The client of $account_key.
	 *
	 * @throws ApiException When an account cannot be read — the caller sends nothing.
	 */
	public function unsubscribed_elsewhere( Client $client, string $account_key, string $email ): ?bool {
		if ( get_option( Router::OPTION_MODE, Router::MODE_SINGLE ) !== Router::MODE_A
			|| get_transient( self::cache_key( $account_key, $email ) ) !== false
		) {
			return null;
		}

		$others = ( new ContactAccount() )->other_accounts( $account_key );
		if ( $others === array() ) {
			return null;
		}

		if ( $this->read( $client, $email )['found'] ) {
			$this->remember( $account_key, $email );
			return null;
		}

		return $this->unsubscribed_in( $others, $email );
	}

	/**
	 * Whether an account other than $account_key has the contact
	 * unsubscribed — asked by AutomationConsent for a contact its own
	 * account does not hold (CON-482). False without a read outside Mode A
	 * and when every language shares one Smaily account.
	 *
	 * @throws ApiException When an account cannot be read — the caller sends nothing.
	 */
	public function unsubscribed_in_other_accounts( string $account_key, string $email ): bool {
		return $this->unsubscribed_in( ( new ContactAccount() )->other_accounts( $account_key ), $email );
	}

	/** The account holds the contact now. */
	public function remember( string $account_key, string $email ): void {
		set_transient( self::cache_key( $account_key, $email ), '1', self::CACHE_TTL );
	}

	/**
	 * The exchange of the read that threw $e, or null when this rule did not
	 * throw it — e.g. the caller's own read failed first.
	 *
	 * @return array<string, mixed>|null
	 */
	public function exchange_of( ApiException $e ): ?array {
		return $e === $this->failed ? $this->failed_exchange : null;
	}

	public static function cache_key( string $account_key, string $email ): string {
		return self::CACHE_PREFIX . md5( $account_key . ':' . md5( strtolower( trim( $email ) ) ) );
	}

	/**
	 * @param array<int, string> $accounts
	 *
	 * @throws ApiException When an account cannot be read.
	 */
	private function unsubscribed_in( array $accounts, string $email ): bool {
		foreach ( $accounts as $account_key ) {
			if ( $this->read( ( $this->client_factory )( $account_key ), $email )['is_unsubscribed'] === '1' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{found: bool, is_unsubscribed: ?string, smaily_rec_profiling: ?string, smaily_rec_profiling_ts: ?string}
	 *
	 * @throws ApiException When Smaily cannot be read, or answers a code but 206.
	 */
	private function read( Client $client, string $email ): array {
		try {
			$contact = $client->get_contact_consent( $email );
		} catch ( ApiException $e ) {
			$this->failed          = $e;
			$this->failed_exchange = $client->last_exchange();
			throw $e;
		}

		$code = $contact['found'] ? null : RetryPolicy::envelope_refusal( $client->last_exchange() );
		if ( ! $contact['found'] && $code !== self::CODE_NOT_FOUND ) {
			$this->failed_exchange = $client->last_exchange();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plugin-built text, stored as plain text in the Event Log, never echoed to a browser.
			$this->failed = new ApiException( sprintf( 'Smaily API answered a contact read with code %d', (int) $code ), 0, null, $code );
			throw $this->failed;
		}

		return $contact;
	}
}
