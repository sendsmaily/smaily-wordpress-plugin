<?php
/**
 * Single source of truth for WP user → Smaily contact-payload mapping.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Translates a WP_User into the Smaily `/api/contact.php` payload shape
 * documented in spec/FIELD_MAPPING.md.
 *
 * Used by both BackfillJob (bulk-sync via Action Scheduler) and the
 * WooCommerce HookHandler (per-event live-sync). Centralising the
 * mapping here is the only way to guarantee backfill and live-sync
 * agree on what a contact looks like — Erkki's field-mapping audit
 * (sub-PR 2.H.14) caught the BackfillJob shipping a hard-coded
 * three-field subset while the merchant's `syncFields` checkbox
 * choices in Step 2 were ignored entirely.
 *
 * Reads the per-merchant opt-in toggles from
 * `wp_options.smaily_connect_subscriber_sync_fields` (canonical field
 * names per FIELD_MAPPING.md §2/§3). `email` and `store` are sent
 * regardless of the toggle array (§1).
 *
 * Per-field transforms live here, not in the option storage. A field
 * whose source value is empty is OMITTED from the payload — Smaily
 * treats absent and empty differently (absent leaves any existing
 * value intact, empty wipes it).
 */
class SubscriberPayloadBuilder {

	public const OPTION_SYNC_FIELDS = 'smaily_connect_subscriber_sync_fields';

	/**
	 * Cross-channel sync fields per FIELD_MAPPING.md §2/§3. Field names
	 * here are the merchant-facing toggle keys (same as
	 * DEFAULT_SYNC_FIELDS in admin/src/state/types.ts).
	 *
	 * @var array<int, string>
	 */
	private const SUPPORTED_FIELDS = array(
		'first_name',
		'last_name',
		'nickname',
		'user_phone',
		'user_gender',
		'birthday',
		'customer_id',
		'customer_group',
		'first_registered',
		'site_title',
	);

	/**
	 * Pre-PRO-1683 toggle keys the wizard saved → the canonical names above.
	 *
	 * The wizard's checkbox list wrote `phone` / `gender` while this builder
	 * has always read `user_phone` / `user_gender`, so both selections were
	 * discarded by the SUPPORTED_FIELDS intersection and neither field ever
	 * reached Smaily. The wizard now writes the canonical names; this map is
	 * what keeps a store that saved its selection BEFORE the fix working
	 * without a migration — the wire names are untouched either way
	 * (FIELD_MAPPING.md §2: renaming them would break existing segments).
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_FIELD_ALIASES = array(
		'phone'  => 'user_phone',
		'gender' => 'user_gender',
	);

	/**
	 * The pre-wizard option SHAPE — every legacy toggle key → the canonical
	 * name above, or null for a legacy key that is no longer a choice.
	 *
	 * Until F3-45 the merchant's selection was written by the legacy settings
	 * page (`Sanitizer::sanitize_subscriber_sync_fields()`, removed in
	 * 9a02618) as a MAP of every key in `Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS`
	 * → bool — not the list of enabled names the wizard writes. Read as a list
	 * that map yields `'1'`/`''` values, the SUPPORTED_FIELDS intersection
	 * matches nothing, and an upgraded store silently synced nothing but
	 * email + store (PRO-1684).
	 *
	 * `null` = no toggle equivalent: `store_url` and `user_email` are sent
	 * unconditionally (FIELD_MAPPING.md §1) and `language` is resolved by
	 * ContactLanguageResolver (F3-47), so none of the three is a merchant
	 * choice any more. `user_dob` is the one real rename (`birthday`).
	 *
	 * @var array<string, string|null>
	 */
	private const LEGACY_SELECTION_KEYS = array(
		'store_url'        => null,
		'user_email'       => null,
		'language'         => null,
		'customer_group'   => 'customer_group',
		'customer_id'      => 'customer_id',
		'first_name'       => 'first_name',
		'first_registered' => 'first_registered',
		'last_name'        => 'last_name',
		'nickname'         => 'nickname',
		'site_title'       => 'site_title',
		'user_dob'         => 'birthday',
		'user_gender'      => 'user_gender',
		'user_phone'       => 'user_phone',
	);

	/**
	 * Build the Smaily contact payload for a WP user.
	 *
	 * @return array<string, mixed>
	 */
	public function build( \WP_User $user ): array {
		$payload = array(
			'email' => (string) ( $user->user_email ?? '' ),
			// `store` always — template context per FIELD_MAPPING.md §1.
			'store' => function_exists( 'get_site_url' ) ? (string) get_site_url() : '',
		);

		foreach ( $this->enabled() as $field ) {
			$value = $this->resolve_field( $user, $field );
			if ( $value === null || $value === '' ) {
				continue;
			}
			$payload[ $field ] = $value;
		}

		return $payload;
	}

	/**
	 * Build the nested-`fields` payload shape used by the WC HookHandler.
	 *
	 * Same field set as build(), but wrapped under a `fields` key alongside
	 * `email` and `language` so the EventQueue row keeps its existing
	 * envelope. `store` joins the `fields` bag (templating context applies
	 * to automation triggers too).
	 *
	 * @return array<string, mixed>
	 */
	public function build_fields( \WP_User $user ): array {
		$fields = array(
			'store' => function_exists( 'get_site_url' ) ? (string) get_site_url() : '',
		);

		foreach ( $this->enabled() as $field ) {
			$value = $this->resolve_field( $user, $field );
			if ( $value === null || $value === '' ) {
				continue;
			}
			$fields[ $field ] = $value;
		}

		return $fields;
	}

	/**
	 * The merchant's selection, read fresh per payload: a handler registered
	 * at `init` outlives a settings save in a long-running process, and
	 * `get_option()` on this autoloaded key is an in-memory lookup anyway.
	 *
	 * @return array<int, string>
	 */
	private function enabled(): array {
		return self::effective_selection();
	}

	/**
	 * The field selection the sync is ACTUALLY using right now.
	 *
	 * Single source for both readers: the payload builders and the wizard's
	 * checkbox hydration (`EnvDetector::saved_settings()`), so a tick the
	 * merchant sees always means "this field is being sent" and vice versa.
	 *
	 * A selection that can't be read at all — never saved, or a shape no
	 * writer of this plugin produces — falls back to the documented
	 * merchant-friendly default (every cross-channel field on) rather than
	 * to the bare minimum. `selection_unreadable()` is what tells the
	 * merchant when that fallback is in effect for the second reason.
	 *
	 * @return array<int, string>
	 */
	public static function effective_selection(): array {
		return self::interpret_selection( get_option( self::OPTION_SYNC_FIELDS, null ) )
			?? self::SUPPORTED_FIELDS;
	}

	/**
	 * The same effective selection, expressed in the LEGACY option key names.
	 *
	 * The legacy `Smaily_Connect\*` readers that build the merchant's own
	 * profile/account/checkout inputs speak the pre-wizard key names
	 * throughout (`user_dob`, not `birthday`), and each used to read the
	 * option itself as a map — so on a wizard-configured store they matched
	 * nothing and the store could no longer COLLECT the very meta this
	 * builder sends (PRO-1743). Handing them the interpreted selection in
	 * their own vocabulary keeps every reader on this one interpreter
	 * without a second translation table to drift.
	 *
	 * `store_url`, `user_email` and `language` are always present: the legacy
	 * settings page forced all three on (`Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS`
	 * defaults them true and its sanitizer never let them off), and in the new
	 * namespace they are not a merchant choice at all — email and store are
	 * sent unconditionally (FIELD_MAPPING.md §1) and language is resolved by
	 * ContactLanguageResolver (F3-47).
	 *
	 * @return array<int, string>
	 */
	public static function effective_selection_legacy_keys(): array {
		$enabled = self::effective_selection();

		$keys = array();
		foreach ( self::LEGACY_SELECTION_KEYS as $legacy_key => $canonical ) {
			if ( $canonical === null || in_array( $canonical, $enabled, true ) ) {
				$keys[] = $legacy_key;
			}
		}

		return $keys;
	}

	/**
	 * True when the option holds a value this plugin cannot read as a
	 * selection, so the merchant's real choice is unknown and the fallback
	 * above is silently in effect. A never-saved option is NOT unreadable —
	 * that is a fresh install, where the fallback IS the documented default.
	 */
	public static function selection_unreadable(): bool {
		$stored = get_option( self::OPTION_SYNC_FIELDS, null );

		return $stored !== null && self::interpret_selection( $stored ) === null;
	}

	/**
	 * Read a stored selection — either shape — as a canonical list of enabled
	 * field names, or null when it is neither.
	 *
	 * Unknown names inside a shape we DO recognise are dropped, not treated
	 * as unreadable: a stale name from another plugin version shouldn't drag
	 * bogus keys into the payload, and it says nothing about the rest of the
	 * merchant's choice.
	 *
	 * @param mixed $stored Raw option value.
	 *
	 * @return array<int, string>|null
	 */
	public static function interpret_selection( $stored ): ?array {
		if ( ! is_array( $stored ) ) {
			return null;
		}

		$names = self::has_string_key( $stored )
			? self::names_from_legacy_map( $stored )
			: self::names_from_list( $stored );

		if ( $names === null ) {
			return null;
		}

		return array_values( array_intersect( self::SUPPORTED_FIELDS, $names ) );
	}

	/**
	 * @param array<int|string, mixed> $stored
	 */
	private static function has_string_key( array $stored ): bool {
		foreach ( array_keys( $stored ) as $key ) {
			if ( is_string( $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The wizard shape: a list of enabled names. An empty array is a valid
	 * selection (the merchant unticked everything), not an unreadable one.
	 *
	 * @param array<int|string, mixed> $stored
	 *
	 * @return array<int, string>|null
	 */
	private static function names_from_list( array $stored ): ?array {
		foreach ( $stored as $value ) {
			if ( ! is_string( $value ) ) {
				return null;
			}
		}

		return array_values( array_map( 'strval', self::canonical_fields( $stored ) ) );
	}

	/**
	 * The legacy shape: name => enabled. Honours the VALUES — a `false` there
	 * is a real "don't send this", the same answer the legacy sync gave
	 * (`array_keys( array_filter( $options ) )`, subscriber-synchronization.class.php).
	 *
	 * Null when the map isn't recognisably the legacy one: no known legacy key
	 * at all, or a value that isn't a scalar the legacy writer could have
	 * produced.
	 *
	 * @param array<int|string, mixed> $stored
	 *
	 * @return array<int, string>|null
	 */
	private static function names_from_legacy_map( array $stored ): ?array {
		$names   = array();
		$matched = false;

		foreach ( $stored as $key => $enabled ) {
			if ( is_array( $enabled ) || is_object( $enabled ) ) {
				return null;
			}
			if ( ! is_string( $key ) || ! array_key_exists( $key, self::LEGACY_SELECTION_KEYS ) ) {
				continue;
			}

			$matched = true;
			// Truthiness matches the legacy writer, which stored false for
			// an unticked box and never a truthy string for one ('0' is
			// falsy here exactly as it was there).
			if ( ! $enabled ) {
				continue;
			}

			$canonical = self::LEGACY_SELECTION_KEYS[ $key ];
			if ( $canonical !== null ) {
				$names[] = $canonical;
			}
		}

		return $matched ? $names : null;
	}

	/**
	 * Translate the names in a wizard-shaped selection to the canonical
	 * §2/§3 spelling. Only string VALUES are rewritten; the legacy MAP shape
	 * is read by names_from_legacy_map(), not here.
	 *
	 * @param array<int|string, mixed> $stored Raw option value.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function canonical_fields( array $stored ): array {
		$canonical = array();
		foreach ( $stored as $key => $value ) {
			$canonical[ $key ] = is_string( $value ) && isset( self::LEGACY_FIELD_ALIASES[ $value ] )
				? self::LEGACY_FIELD_ALIASES[ $value ]
				: $value;
		}

		return $canonical;
	}

	/**
	 * Resolve a single canonical field-name to its transformed source
	 * value. Returns null when the source is absent — caller drops
	 * absent fields from the payload.
	 *
	 * @return string|null
	 */
	private function resolve_field( \WP_User $user, string $field ): ?string {
		switch ( $field ) {
			case 'first_name':
				return $this->trim_or_null( (string) ( $user->first_name ?? '' ) );
			case 'last_name':
				return $this->trim_or_null( (string) ( $user->last_name ?? '' ) );
			case 'nickname':
				return $this->trim_or_null( (string) ( $user->nickname ?? '' ) );
			case 'user_phone':
				return $this->trim_or_null( $this->user_meta( $user, 'user_phone' ) );
			case 'user_gender':
				$raw = $this->user_meta( $user, 'user_gender' );
				if ( $raw === '' ) {
					return null;
				}
				// Legacy enum convention (data-handler.class.php):
				// '0' → Female, anything else → Male. Documented in
				// FIELD_MAPPING.md §2 so future readers see the
				// non-obvious mapping.
				return $raw === '0' ? 'Female' : 'Male';
			case 'birthday':
				$raw = $this->user_meta( $user, 'user_dob' );
				if ( $raw === '' ) {
					return null;
				}
				$timestamp = strtotime( $raw );
				if ( $timestamp === false ) {
					return null;
				}
				return gmdate( 'Y-m-d', $timestamp );
			case 'customer_id':
				$id = (int) $user->ID;
				return $id > 0 ? (string) $id : null;
			case 'customer_group':
				$role = isset( $user->roles[0] ) ? (string) $user->roles[0] : '';
				return $role !== '' ? $role : null;
			case 'first_registered':
				$registered = (string) ( $user->user_registered ?? '' );
				return $registered !== '' ? $registered : null;
			case 'site_title':
				if ( ! function_exists( 'get_bloginfo' ) ) {
					return null;
				}
				$title = (string) get_bloginfo( 'name' );
				return $title !== '' ? $title : null;
			default:
				return null;
		}
	}

	private function user_meta( \WP_User $user, string $key ): string {
		if ( ! function_exists( 'get_user_meta' ) ) {
			return '';
		}
		$value = get_user_meta( (int) $user->ID, $key, true );
		return is_string( $value ) ? $value : '';
	}

	private function trim_or_null( string $value ): ?string {
		$trimmed = trim( $value );
		return $trimmed === '' ? null : $trimmed;
	}
}
