<?php
/**
 * Profiling-consent enforcement (SMAILY_PROFILING_CONSENT_SPEC.md, (a).0).
 *
 * Smaily owns consent; this resolver reads it back from the contact and enforces
 * it. Per Erkki's decision (DECISIONS F3-31), the model is **opt-out, default-on**:
 * profile UNLESS the contact has explicitly opted out (or left marketing entirely).
 *
 * @package Smaily\Connect\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Privacy;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;
use Smaily\Connect\Smaily\RecEngine\Support\IsoDate;

/**
 * The authority is the read-back from Smaily (covers opt-outs done on either the
 * WP side or the Smaily side). The resolved decision is cached per-email with a
 * daily TTL — a live read on every profiling decision would hammer the Smaily
 * API; a day-stale cache is acceptable for profiling (not real-time-critical),
 * and a WP-side opt-out updates the cache immediately.
 *
 * Hardening (PRO-1194, fail-open GDPR window review, options B+C): a read
 * error no longer defaults straight to "allowed". A durably known opt-out
 * (non-transient option row, immune to cache eviction) always wins; failing
 * that, the last successfully fetched state is served from a no-expiry stale
 * cache; only a genuinely never-seen contact still fails open.
 *
 * When the decision resolves to "do not profile", two actions fire (the spec):
 *   1. engine opt-out (§10 Client::customer_opt_out) — excluded from recs;
 *   2. beacon-stop — the beacon's profiling gate ((a).1 consumes may_profile()).
 *
 * Not final: the beacon + identity-merge tests inject a double overriding
 * may_profile() to drive the gate without standing up Smaily + transients.
 */
class ProfilingConsent {

	private const CACHE_PREFIX       = 'smly_profiling_';
	private const STALE_CACHE_PREFIX = 'smly_profiling_stale_';
	private const OPTION_OPTOUTS     = 'smly_profiling_optouts';
	private const CACHE_TTL          = DAY_IN_SECONDS;
	/**
	 * The stale fallback cache used to be a NO-expiry transient (PRO-1194) —
	 * which WordPress stores as an AUTOLOADED option, one per contact,
	 * forever (PRO-2435). A finite TTL makes it a regular non-autoloaded,
	 * self-expiring row; a year outlives any Smaily outage it is there for.
	 */
	private const STALE_CACHE_TTL    = YEAR_IN_SECONDS;

	private RecEngineSettings $settings;

	/** @var callable():?SmailyClient */
	private $smaily_client_factory;

	/** @var callable():RecEngineClient */
	private $rec_client_factory;

	/**
	 * @param callable():?SmailyClient   $smaily_client_factory Default Smaily client, or null when the email side isn't configured.
	 * @param callable():RecEngineClient $rec_client_factory    Rec-engine client (for the §10 opt-out).
	 */
	public function __construct(
		RecEngineSettings $settings,
		callable $smaily_client_factory,
		callable $rec_client_factory
	) {
		$this->settings              = $settings;
		$this->smaily_client_factory = $smaily_client_factory;
		$this->rec_client_factory    = $rec_client_factory;
	}

	/**
	 * The enforcement rule — pure, so it's unit-testable without I/O. OPT-OUT
	 * model (default-on): profile unless explicitly opted out. The only "do not
	 * profile" conditions are the general unsubscribe (the stronger signal) or an
	 * explicit profiling opt-out. A missing/unknown profiling field means
	 * default-on (the field marks an opt-out, not the default state). Values are
	 * Smaily strings ("0"/"1"), so the comparison is string-based.
	 */
	public static function is_allowed( ?string $is_unsubscribed, ?string $smaily_rec_profiling ): bool {
		if ( $is_unsubscribed === '1' ) {
			return false; // left marketing entirely → also stops profiling.
		}
		if ( $smaily_rec_profiling === '0' ) {
			return false; // explicit profiling opt-out.
		}
		return true; // default-on: '1', missing, or anything else → profile.
	}

	/**
	 * May this contact be profiled? Cached (daily TTL); a miss triggers a
	 * read-back. On a read error, `refresh()` no longer defaults straight to
	 * allowed — see `fallback_on_error()` for the serve-stale / durable-opt-out
	 * hardening (PRO-1194); a genuinely never-seen contact still fails open,
	 * consistent with the opt-out, default-on model (DECISIONS F3-31).
	 */
	public function may_profile( string $email ): bool {
		$cached = get_transient( self::cache_key( $email ) );
		if ( $cached !== false ) {
			return $cached === '1';
		}
		return $this->refresh( $email );
	}

	/**
	 * Read the consent back from Smaily, remember the decision, and — if it
	 * resolves to "do not profile" — fire the engine opt-out. On a read error
	 * (or no Smaily client configured), falls through to `fallback_on_error()`
	 * instead of defaulting to allowed. Returns the decision.
	 */
	public function refresh( string $email ): bool {
		$client = ( $this->smaily_client_factory )();

		if ( $client instanceof SmailyClient ) {
			try {
				$consent    = $client->get_contact_consent( $email );
				$allowed    = self::is_allowed( $consent['is_unsubscribed'], $consent['smaily_rec_profiling'] );
				$carried_at = null;
				if ( $allowed && $this->is_durably_opted_out( $email ) && ! $this->is_newer_opt_in( $email, $consent ) ) {
					// PRO-3191: a durable store-side opt-out holds until an
					// EXPLICIT opt-in ('1' here, or opt_in()). A contact with no
					// preference (or no contact at all) is not one — it is an
					// opt-out whose Smaily write never landed. PRO-3192: nor is
					// a '1' older than the opt-out, or one with no timestamp.
					$allowed   = false;
					$profiling = $consent['smaily_rec_profiling'] ?? '';
					if ( $consent['found'] && ( $profiling === '' || $profiling === '1' ) ) {
						// Carry the opt-out to the contact that lacks it or holds
						// the older answer. Never for a not-found contact: the
						// upsert would create one.
						$carried_at = $this->write( $email, false );
					}
				}
				$this->remember( $email, $allowed, $carried_at );
				if ( ! $allowed ) {
					$this->engine_opt_out( $email );
				}
				return $allowed;
			} catch ( \Throwable $e ) {
				// Fall through to the shared error/unconfigured fallback below.
			}
		}

		$allowed = $this->fallback_on_error( $email );
		$this->cache( $email, $allowed );
		return $allowed;
	}

	/**
	 * PRO-3192: does the contact carry an explicit opt-in ('1') made AFTER the
	 * durable opt-out? Only then does the read-back lift it. A '1' with no
	 * (parseable) timestamp counts as older; so does any '1' against an entry
	 * recorded before PRO-3192, whose moment is unknown (the safe reading).
	 *
	 * @param array{found: bool, is_unsubscribed: ?string, smaily_rec_profiling: ?string, smaily_rec_profiling_ts: ?string} $consent
	 */
	private function is_newer_opt_in( string $email, array $consent ): bool {
		if ( $consent['smaily_rec_profiling'] !== '1' ) {
			return false;
		}
		$moment = $this->optout_moment( $email );
		if ( $moment === null ) {
			return false;
		}
		$given = strtotime( $consent['smaily_rec_profiling_ts'] ?? '' );
		return $given !== false && $given > $moment;
	}

	/**
	 * Best available answer when a fresh read couldn't be completed. A
	 * durably known opt-out always wins (never re-allowed by an error);
	 * otherwise serve the last successfully fetched state (stale cache, no
	 * expiry); otherwise a genuinely never-seen contact fails open
	 * (DECISIONS F3-31 — the merchant's accepted residual risk).
	 */
	private function fallback_on_error( string $email ): bool {
		return $this->stored_preference( $email ) ?? true;
	}

	/**
	 * The preference as far as it is actually KNOWN — for display only (the
	 * My Account section, PRO-2513). Null means nothing reliable is known:
	 * exactly the case where `may_profile()` fails open, and where the daily
	 * cache may hold that fail-open `true`, so the cache can't be trusted for
	 * display. Calls `may_profile()` first so a cache miss still reads back
	 * from Smaily as before; the gate's answer and its caches are unchanged.
	 */
	public function known_preference( string $email ): ?bool {
		$this->may_profile( $email );
		return $this->stored_preference( $email );
	}

	/**
	 * What we durably know without a fresh read: a durable opt-out, else the
	 * last successfully fetched (or WP-side set) state, else null.
	 */
	private function stored_preference( string $email ): ?bool {
		if ( $this->is_durably_opted_out( $email ) ) {
			return false;
		}

		$stale = get_transient( self::stale_cache_key( $email ) );
		if ( $stale !== false ) {
			return $stale === '1';
		}

		return null;
	}

	/**
	 * WP-side opt-out: write `smaily_rec_profiling = 0` + the timestamp to Smaily,
	 * update the cache immediately, and opt the customer out of the engine. The
	 * working opt-out path the opt-out model requires (transparency + a real way
	 * to say no).
	 */
	public function opt_out( string $email ): void {
		$this->remember( $email, false, $this->write( $email, false ) );
		$this->engine_opt_out( $email );
	}

	/** WP-side opt back in: write `1` + timestamp, cache, re-include in the engine. */
	public function opt_in( string $email ): void {
		$this->write( $email, true );
		$this->remember( $email, true );
		$this->engine_opt_in( $email );
	}

	/**
	 * Returns the moment stamped on the write (also when no write happened),
	 * so the caller records the same moment Smaily was sent.
	 */
	private function write( string $email, bool $may_profile ): int {
		$now    = time();
		$client = ( $this->smaily_client_factory )();
		if ( ! $client instanceof SmailyClient ) {
			return $now;
		}
		try {
			$client->write_profiling_consent( $email, $may_profile, IsoDate::to_z( $now ) );
		} catch ( \Throwable $e ) {
			// A failed write is non-fatal here; the cache still reflects the WP
			// intent, and the next read-back reconciles against Smaily.
			\Smaily\Connect\Support\DebugLog::write( '[smaily-connect profiling-consent] write failed: ' . $e->getMessage() );
		}
		return $now;
	}

	private function engine_opt_out( string $email ): void {
		if ( ! $this->settings->sending_allowed() ) {
			return;
		}
		try {
			( $this->rec_client_factory )()->customer_opt_out(
				$email,
				array(
					'opt_out'      => true,
					'reason'       => 'profiling_consent',
					'opted_out_at' => IsoDate::to_z( time() ),
				)
			);
		} catch ( \Throwable $e ) {
			\Smaily\Connect\Support\DebugLog::write( '[smaily-connect profiling-consent] engine opt-out failed: ' . $e->getMessage() );
		}
	}

	private function engine_opt_in( string $email ): void {
		if ( ! $this->settings->sending_allowed() ) {
			return;
		}
		try {
			( $this->rec_client_factory )()->customer_opt_out(
				$email,
				array(
					'opt_out' => false,
					'reason'  => 'profiling_consent',
				)
			);
		} catch ( \Throwable $e ) {
			\Smaily\Connect\Support\DebugLog::write( '[smaily-connect profiling-consent] engine opt-in failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Record a definitively known decision (a successful read-back, or a
	 * WP-side opt-out/opt-in) across all three storage layers: the fresh
	 * daily-TTL cache, the no-expiry stale cache, and the durable opt-out
	 * registry (added/removed as appropriate). `$optout_moment` is set when
	 * the store itself makes the opt-out (PRO-3192).
	 */
	private function remember( string $email, bool $allowed, ?int $optout_moment = null ): void {
		$this->cache( $email, $allowed );
		set_transient( self::stale_cache_key( $email ), $allowed ? '1' : '0', self::STALE_CACHE_TTL );
		$this->remember_optout( $email, $allowed, $optout_moment );
	}

	private function cache( string $email, bool $allowed ): void {
		set_transient( self::cache_key( $email ), $allowed ? '1' : '0', self::CACHE_TTL );
	}

	/**
	 * Durable opt-out registry (autoload=false option, keyed by hashed email —
	 * stores only opt-outs, so it stays bounded to the merchant's actual
	 * opt-out count, not the whole contact base). Only an explicit opt-in
	 * removes the entry — a read-back of `smaily_rec_profiling = 1` made after
	 * the opt-out, or a WP-side opt_in() (PRO-3191/PRO-3192); a contact with no
	 * preference never does.
	 *
	 * The value is the opt-out's moment (PRO-3192): the Unix time the store
	 * made it (My Account, or carrying it to the contact), `0` for an entry
	 * that only mirrors a Smaily read-back (any timestamped '1' is newer), or
	 * `true` for an entry recorded before PRO-3192 (moment unknown).
	 */
	private function remember_optout( string $email, bool $allowed, ?int $moment ): void {
		$key       = self::email_hash( $email );
		$optouts   = (array) get_option( self::OPTION_OPTOUTS, array() );
		$has_entry = isset( $optouts[ $key ] );

		if ( $allowed && $has_entry ) {
			unset( $optouts[ $key ] );
		} elseif ( ! $allowed && ( $moment !== null || ! $has_entry ) ) {
			$optouts[ $key ] = $moment ?? 0;
		} else {
			return; // already in the right state — no write.
		}

		update_option( self::OPTION_OPTOUTS, $optouts, false );
	}

	private function is_durably_opted_out( string $email ): bool {
		return $this->optout_entry( $email ) !== null;
	}

	/** The durable opt-out's recorded moment; null when absent or unknown. */
	private function optout_moment( string $email ): ?int {
		$moment = $this->optout_entry( $email );
		return is_int( $moment ) ? $moment : null;
	}

	/**
	 * The raw durable opt-out entry (an int moment, or `true` for a
	 * pre-PRO-3192 entry); null when the contact has none.
	 *
	 * @return int|bool|null
	 */
	private function optout_entry( string $email ) {
		$optouts = (array) get_option( self::OPTION_OPTOUTS, array() );
		return $optouts[ self::email_hash( $email ) ] ?? null;
	}

	private static function email_hash( string $email ): string {
		return md5( strtolower( trim( $email ) ) );
	}

	private static function cache_key( string $email ): string {
		return self::CACHE_PREFIX . self::email_hash( $email );
	}

	private static function stale_cache_key( string $email ): string {
		return self::STALE_CACHE_PREFIX . self::email_hash( $email );
	}
}
