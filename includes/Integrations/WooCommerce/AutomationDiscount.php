<?php
/**
 * The single-use discount code an automation's email carries.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Privacy\AddressMatch;
use Smaily\Connect\Support\NumberInput;

/**
 * Gives each welcome, first-order and abandoned-cart email the shopper's own
 * WooCommerce discount code (CON-199, the Magento extension's CON-198).
 *
 * The merchant picks one of the store's coupons per automation as a
 * TEMPLATE. When the queue sends the email — after the workflow is found
 * and, for a reminder, after the consent check, so a skipped send makes no
 * code — issue() clones the template into one new coupon: every setting of
 * the template (discount type and amount, products, categories, sale items,
 * minimum and maximum spend, free shipping, individual use, item limit, and
 * WooCommerce's own brand restriction, CLONED_META) except its code, usage
 * limits, expiry and allowed emails, which become a
 * random code, one use, one use per user, the shopper's address only, and
 * the end of the day `valid_days` ahead in the store's timezone — never
 * later than the template's own expiry. The copy carries META_GENERATED_FROM
 * (the template's id), which marks it as the plugin's, and META_TRIGGER (the
 * automation).
 *
 * Only a template that can be cloned into such a code counts (the picker
 * lists only these, and issue() checks again at send time):
 * a published coupon of one of WooCommerce's own discount types
 * (TEMPLATE_TYPES — another plugin's type, a gift card or store credit say,
 * may carry state a copy must not duplicate), not expired, and not a code
 * the plugin made itself. Settings another plugin keeps in its own coupon
 * meta are not copied.
 *
 * One code per shopper while it works: when the address already has an
 * unused, published, unexpired code the same automation made from the same
 * template, issue() returns that code and its last day again and makes no
 * coupon (unused_code(); audit M1, 3.20.0). A code made before the plugin
 * recorded the automation has no META_TRIGGER and is never sent again.
 *
 * Once per queue row: issue() returns the code and its last day, and the
 * flusher writes them into the row's payload before the post
 * (Smaily\DiscountCodeIssuer), so a retry posts the same code and makes no
 * second coupon. A template that cannot give a code leaves the code out —
 * the email still goes — and returns the reason, which the flusher stores
 * as the Event Log note. So does an address
 * WooCommerce would store otherwise (CON-214, restricts_to_exactly()): a
 * code is never restricted to an address other than the shopper's.
 *
 * WooCommerce itself refuses a used, foreign or expired code at checkout
 * (WC_Discounts' usage-limit, allowed-email and expiry checks), so the
 * plugin enforces nothing there. The daily janitor deletes the plugin's
 * codes that were never used, DELETE_AFTER_DAYS after their expiry. A
 * personal-data export lists an address's codes, and an erasure deletes
 * its unused ones and takes the address off the used ones
 * (codes_for_address(), erase_for_address(); CON-213).
 *
 * Not final: the flusher unit tests mock issue().
 */
class AutomationDiscount {

	/** The three automations' settings — see settings(). */
	public const OPTION = 'smly_plus_automation_discounts';

	/** Coupon meta marking a code the plugin made: the template's id. */
	public const META_GENERATED_FROM = '_smaily_connect_generated_from';

	/** Coupon meta: the automation (a FIELDS key) the plugin made the code for. */
	public const META_TRIGGER = '_smaily_connect_trigger';

	public const VALID_DAYS_MIN     = 1;
	public const VALID_DAYS_MAX     = 365;
	public const VALID_DAYS_DEFAULT = 7;

	/** Days after its expiry an unused code of the plugin is deleted. */
	public const DELETE_AFTER_DAYS = 30;

	/**
	 * Trigger => the Smaily contact fields of its code and the code's last
	 * valid day (`Y-m-d`, store timezone). The same names as the Magento
	 * extension's: merchants build templates on them, so they never change.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	public const FIELDS = array(
		'welcome'        => array( 'welcome_coupon_code', 'welcome_coupon_expires_at' ),
		'first_order'    => array( 'first_order_coupon_code', 'first_order_coupon_expires_at' ),
		'abandoned_cart' => array( 'abandoned_cart_coupon_code', 'abandoned_cart_coupon_expires_at' ),
	);

	/** WooCommerce's own discount types — the only ones a template may have. */
	public const TEMPLATE_TYPES = array( 'percent', 'fixed_cart', 'fixed_product' );

	/** The template's settings a code copies (WooCommerce's coupon screen, less code/usage/expiry/emails). */
	private const CLONED_PROPS = array(
		'discount_type',
		'amount',
		'individual_use',
		'product_ids',
		'excluded_product_ids',
		'limit_usage_to_x_items',
		'free_shipping',
		'product_categories',
		'excluded_product_categories',
		'exclude_sale_items',
		'minimum_amount',
		'maximum_amount',
	);

	/**
	 * The template's meta a code copies: WooCommerce's own brand restriction
	 * ("Brands" / "Exclude brands", core since WooCommerce 9.6), two lists of
	 * brand term ids that WC_Brands_Coupons reads from the coupon's meta —
	 * not WC_Coupon props, so get_data() leaves them out.
	 */
	private const CLONED_META = array( 'product_brands', 'exclude_product_brands' );

	/** Upper case and digits, without the look-alikes 0/O and 1/I. */
	private const CODE_CHARACTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	private const CODE_LENGTH     = 10;

	private const NOTE = 'Sent without a discount code: %s.';

	private const TEMPLATE_LIST_LIMIT = 200;
	private const DELETE_BATCH        = 200;
	private const DELETE_MAX_BATCHES  = 10;

	/**
	 * Each automation's "Send a discount code" switch, template coupon id
	 * (0: none picked) and days a code is valid. A missing or damaged stored
	 * value reads as off, with the days clamped into range.
	 *
	 * @return array<string, array{enabled: bool, template_id: int, valid_days: int}>
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$settings = array();
		foreach ( array_keys( self::FIELDS ) as $trigger ) {
			$row  = isset( $stored[ $trigger ] ) && is_array( $stored[ $trigger ] ) ? $stored[ $trigger ] : array();
			$days = isset( $row['valid_days'] ) && is_numeric( $row['valid_days'] ) ? (int) $row['valid_days'] : self::VALID_DAYS_DEFAULT;

			$settings[ $trigger ] = self::normalize_row( ! empty( $row['enabled'] ), $row['template_id'] ?? null, $days );
		}

		return $settings;
	}

	/**
	 * The Settings payload (`{trigger: {enabled, templateId, validDays}}`) as
	 * the stored option, or null when a day count is not a whole number from
	 * VALID_DAYS_MIN to VALID_DAYS_MAX. An automation the payload leaves out
	 * keeps its stored settings.
	 *
	 * @param mixed $raw
	 *
	 * @return array<string, array{enabled: bool, template_id: int, valid_days: int}>|null
	 */
	public static function from_request( $raw ): ?array {
		$raw      = is_array( $raw ) ? $raw : array();
		$settings = self::settings();

		foreach ( array_keys( self::FIELDS ) as $trigger ) {
			if ( ! isset( $raw[ $trigger ] ) || ! is_array( $raw[ $trigger ] ) ) {
				continue;
			}
			$row  = $raw[ $trigger ];
			$days = NumberInput::whole_number_in_range( $row['validDays'] ?? null, self::VALID_DAYS_MIN, self::VALID_DAYS_MAX );
			if ( $days === null ) {
				return null;
			}

			$settings[ $trigger ] = self::normalize_row( ! empty( $row['enabled'] ), $row['templateId'] ?? null, $days );
		}

		return $settings;
	}

	/**
	 * One automation's settings row: a template id that is not a number, or
	 * is below 0, is none (0), and the days are clamped into range.
	 *
	 * @param mixed $template_id
	 *
	 * @return array{enabled: bool, template_id: int, valid_days: int}
	 */
	private static function normalize_row( bool $enabled, $template_id, int $days ): array {
		return array(
			'enabled'     => $enabled,
			'template_id' => is_numeric( $template_id ) ? max( 0, (int) $template_id ) : 0,
			'valid_days'  => max( self::VALID_DAYS_MIN, min( self::VALID_DAYS_MAX, $days ) ),
		);
	}

	/**
	 * The coupons a code can be cloned from, for the picker: id and a label
	 * of code, discount type and amount, by code. The query already keeps
	 * to template_problem()'s post type, status, plugin-made and discount
	 * type rules, so only the expiry is left to check; the label is read
	 * from the post and its meta (primed by the query) the way WC_Coupon
	 * reads them, without loading a coupon object per post.
	 *
	 * @return array<int, array{id: int, label: string}>
	 */
	public static function templates(): array {
		if ( ! class_exists( '\WC_Coupon' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'     => 'shop_coupon',
				'post_status'   => 'publish',
				'numberposts'   => self::TEMPLATE_LIST_LIMIT,
				'orderby'       => 'title',
				'order'         => 'ASC',
				'no_found_rows' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query -- settings screen only, bounded by TEMPLATE_LIST_LIMIT.
				'meta_query'    => array(
					array(
						'key'     => self::META_GENERATED_FROM,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'discount_type',
						'value'   => self::TEMPLATE_TYPES,
						'compare' => 'IN',
					),
				),
			)
		);

		$templates = array();
		foreach ( $posts as $post ) {
			$id = $post->ID;
			if ( self::expired( $id ) ) {
				continue;
			}
			$templates[] = array(
				'id'    => $id,
				'label' => sprintf(
					'%1$s — %2$s: %3$s',
					wc_format_coupon_code( $post->post_title ),
					wc_get_coupon_type( (string) get_post_meta( $id, 'discount_type', true ) ),
					wc_format_localized_decimal( wc_format_decimal( get_post_meta( $id, 'coupon_amount', true ) ) )
				),
			);
		}

		return $templates;
	}

	/**
	 * Why no code can be cloned from this coupon now, or '' when one can.
	 * Read from the stored post and meta, not through WC_Coupon, which
	 * reads an unknown discount type as `fixed_cart`.
	 */
	public static function template_problem( int $template_id ): string {
		if ( $template_id <= 0 ) {
			return 'no WooCommerce coupon is picked';
		}

		$post = get_post( $template_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'shop_coupon' || $post->post_status !== 'publish' ) {
			return sprintf( 'the WooCommerce coupon #%d is deleted or not published', $template_id );
		}
		if ( (string) get_post_meta( $template_id, self::META_GENERATED_FROM, true ) !== '' ) {
			return sprintf( 'the WooCommerce coupon #%d is a code the plugin made for one shopper', $template_id );
		}
		if ( ! in_array( (string) get_post_meta( $template_id, 'discount_type', true ), self::TEMPLATE_TYPES, true ) ) {
			return sprintf( 'the WooCommerce coupon #%d has a discount type the plugin cannot copy', $template_id );
		}
		if ( self::expired( $template_id ) ) {
			return sprintf( 'the WooCommerce coupon #%d has expired', $template_id );
		}

		return '';
	}

	/** Whether the coupon's stored expiry (`date_expires`) has passed. */
	private static function expired( int $coupon_id ): bool {
		$expires = get_post_meta( $coupon_id, 'date_expires', true );
		return is_numeric( $expires ) && (int) $expires > 0 && (int) $expires <= time();
	}

	/**
	 * The discount code fields for one queue row about to be posted.
	 *
	 * Nothing when the automation's switch is off, or when the row already
	 * carries its code (a retry — the fields are in its payload). Otherwise
	 * the address's unused code of this automation and template when it has
	 * one (unused_code()), else a new code — which the caller writes into
	 * the row's payload before the post (DiscountCodeIssuer); or no code and
	 * the reason, when the template cannot give one.
	 *
	 * @param array<string, mixed> $payload The row's decoded payload.
	 *
	 * @return array{fields: array<string, string>, note: string}
	 */
	public function issue( string $trigger, array $payload ): array {
		$none   = array(
			'fields' => array(),
			'note'   => '',
		);
		$names  = self::FIELDS[ $trigger ] ?? null;
		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		$email  = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
		if ( $names === null || $email === '' || (string) ( $fields[ $names[0] ] ?? '' ) !== '' ) {
			return $none;
		}

		$setting = self::settings()[ $trigger ];
		if ( ! $setting['enabled'] ) {
			return $none;
		}

		$problem = class_exists( '\WC_Coupon' ) ? self::template_problem( $setting['template_id'] ) : 'WooCommerce is not active';
		$code    = $problem;
		if ( $problem === '' ) {
			$code = self::unused_code( $trigger, $setting['template_id'], $email )
				?? $this->create( $trigger, $setting['template_id'], $setting['valid_days'], $email );
		}
		if ( is_string( $code ) ) {
			return array(
				'fields' => array(),
				'note'   => sprintf( self::NOTE, $code ),
			);
		}

		return array(
			'fields' => array(
				$names[0] => $code['code'],
				$names[1] => $code['last_day'],
			),
			'note'   => '',
		);
	}

	/**
	 * When a code made at $now for $days days stops working: the timestamp
	 * for WooCommerce (end of the last day in $timezone, or the template's
	 * own expiry when that comes first) and the last valid day, `Y-m-d`.
	 * WooCommerce refuses a coupon once the time is past its expiry, and its
	 * own expiry date means 00:00 of that day — so a template's last valid
	 * day is the day before.
	 *
	 * @return array{0: int, 1: string}
	 */
	public static function expiry( int $now, int $days, \DateTimeZone $timezone, ?int $template_expires ): array {
		$last_day = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $timezone )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		$expires  = ( new \DateTimeImmutable( $last_day . ' 23:59:59', $timezone ) )->getTimestamp();

		if ( $template_expires !== null && $template_expires < $expires ) {
			$expires  = $template_expires;
			$last_day = self::last_day( $template_expires, $timezone );
		}

		return array( $expires, $last_day );
	}

	/**
	 * The last valid day (`Y-m-d` in $timezone) of a coupon WooCommerce
	 * refuses from $expires on: the day of the second before it.
	 */
	public static function last_day( int $expires, \DateTimeZone $timezone ): string {
		return ( new \DateTimeImmutable( '@' . ( $expires - 1 ) ) )->setTimezone( $timezone )->format( 'Y-m-d' );
	}

	/**
	 * The address's code this automation made from this template that still
	 * works — published, unused, not expired, restricted to exactly the
	 * address — with its own last day; the newest when there are several;
	 * null when there is none. The address matches as codes_for_address()
	 * matches it. A code without META_TRIGGER (made before the plugin
	 * recorded the automation) never counts.
	 *
	 * @return array{code: string, last_day: string}|null
	 */
	private static function unused_code( string $trigger, int $template_id, string $email ): ?array {
		$now = time();
		foreach ( array_reverse( self::codes_for_address( $email ) ) as $coupon ) {
			$expires = $coupon->get_date_expires( 'edit' );
			if ( (string) $coupon->get_meta( self::META_TRIGGER, true, 'edit' ) !== $trigger
				|| (string) $coupon->get_meta( self::META_GENERATED_FROM, true, 'edit' ) !== (string) $template_id
				|| get_post_status( $coupon->get_id() ) !== 'publish'
				|| $coupon->get_usage_count( 'edit' ) > 0
				|| $expires === null
				|| $expires->getTimestamp() <= $now
				|| ! self::restricts_to_exactly( $coupon->get_email_restrictions( 'edit' ), $email ) ) {
				continue;
			}

			return array(
				'code'     => $coupon->get_code( 'edit' ),
				'last_day' => self::last_day( $expires->getTimestamp(), wp_timezone() ),
			);
		}

		return null;
	}

	/**
	 * Delete the plugin's codes that were never used and expired more than
	 * DELETE_AFTER_DAYS ago (the daily janitor). A used code stays: it is
	 * part of an order's history. A coupon the plugin did not make is never
	 * touched.
	 *
	 * @return int Coupons deleted.
	 */
	public function delete_expired_unused(): int {
		if ( ! class_exists( '\WC_Coupon' ) ) {
			return 0;
		}

		$cutoff  = time() - self::DELETE_AFTER_DAYS * DAY_IN_SECONDS;
		$deleted = 0;
		for ( $batch = 0; $batch < self::DELETE_MAX_BATCHES; $batch++ ) {
			$ids = get_posts(
				array(
					'post_type'     => 'shop_coupon',
					'post_status'   => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
					'numberposts'   => self::DELETE_BATCH,
					'fields'        => 'ids',
					'no_found_rows' => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query -- daily janitor, bounded batches.
					'meta_query'    => array(
						array(
							'key'     => self::META_GENERATED_FROM,
							'compare' => 'EXISTS',
						),
						array(
							'key'     => 'date_expires',
							'value'   => array( 1, $cutoff ),
							'compare' => 'BETWEEN',
							'type'    => 'NUMERIC',
						),
						array(
							'relation' => 'OR',
							array(
								'key'     => 'usage_count',
								'value'   => 0,
								'compare' => '<=',
								'type'    => 'NUMERIC',
							),
							array(
								'key'     => 'usage_count',
								'compare' => 'NOT EXISTS',
							),
						),
					),
				)
			);

			foreach ( $ids as $id ) {
				( new \WC_Coupon( (int) $id ) )->delete( true );
				++$deleted;
			}
			if ( count( $ids ) < self::DELETE_BATCH ) {
				break;
			}
		}

		return $deleted;
	}

	/**
	 * The codes the plugin made for one address, oldest first, in any post
	 * status (CON-213) — for the personal-data exporter and eraser. The
	 * address is matched in the coupon's allowed emails (`customer_email`
	 * meta) the way the other erasure paths match it (AddressMatch): letter
	 * case does not matter, an accent does, so a code for `jäne@…` is not
	 * `jane@…`'s. Each candidate is checked once more entry by entry. A
	 * coupon without META_GENERATED_FROM — the merchant's — is never one.
	 *
	 * @return \WC_Coupon[]
	 */
	public static function codes_for_address( string $email ): array {
		global $wpdb;
		if ( ! class_exists( '\WC_Coupon' ) ) {
			return array();
		}

		$match = AddressMatch::json_string( 'LOWER( e.meta_value )', $email );
		if ( $match === null ) {
			return array();
		}
		[ $condition, $patterns ] = $match;

		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p"
			. " INNER JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s"
			. " INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = 'customer_email'"
			. " WHERE p.post_type = 'shop_coupon' AND {$condition} ORDER BY p.ID ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The SQL holds table names and placeholders only; every value is prepare()d.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( self::META_GENERATED_FROM ), $patterns ) ) );

		$coupons = array();
		foreach ( $ids as $id ) {
			$coupon  = new \WC_Coupon( (int) $id );
			$allowed = array_filter( $coupon->get_email_restrictions( 'edit' ), 'is_string' );
			if ( $coupon->get_id() > 0 && count( self::restrictions_without( $allowed, $email ) ) < count( $allowed ) ) {
				$coupons[] = $coupon;
			}
		}

		return $coupons;
	}

	/**
	 * Erase one address from the codes the plugin made for it (CON-213). An
	 * unused code is deleted. A used one stays — its order's coupon line
	 * still names it — and loses the address: from its allowed emails, where
	 * WordPress's anonymised address takes its place when no other is left
	 * (an empty list would let anyone use the code once an order that used
	 * it is cancelled), and from WooCommerce's `_used_by` record. Both are
	 * coupon post meta in every storage, written directly: the WooCommerce
	 * setter rejects or rewrites an address it does not like.
	 *
	 * @return array{deleted: int, anonymised: int}
	 */
	public static function erase_for_address( string $email ): array {
		$result = array(
			'deleted'    => 0,
			'anonymised' => 0,
		);

		foreach ( self::codes_for_address( $email ) as $coupon ) {
			$id = $coupon->get_id();
			if ( $coupon->get_usage_count( 'edit' ) <= 0 ) {
				$coupon->delete( true );
				++$result['deleted'];
				continue;
			}

			foreach ( get_post_meta( $id, '_used_by' ) as $used_by ) {
				if ( is_string( $used_by ) && self::same_address( $used_by, $email ) ) {
					delete_post_meta( $id, '_used_by', $used_by );
				}
			}

			$kept = self::restrictions_without( $coupon->get_email_restrictions( 'edit' ), $email );
			update_post_meta( $id, 'customer_email', $kept !== array() ? $kept : array( wp_privacy_anonymize_data( 'email' ) ) );
			++$result['anonymised'];
		}

		return $result;
	}

	/**
	 * A coupon's allowed emails without one address (any letter case; an
	 * address that differs by an accent, or a wildcard entry, stays).
	 *
	 * @param array<int|string, mixed> $restrictions
	 *
	 * @return array<int, string>
	 */
	public static function restrictions_without( array $restrictions, string $email ): array {
		$kept = array();
		foreach ( $restrictions as $restriction ) {
			if ( ! is_string( $restriction ) ) {
				continue;
			}
			if ( ! self::same_address( $restriction, $email ) ) {
				$kept[] = $restriction;
			}
		}
		return $kept;
	}

	/**
	 * Whether a coupon's allowed emails, as WooCommerce stored them, are
	 * exactly this one address and nothing else (CON-214). WooCommerce
	 * passes each allowed email through sanitize_email(), which drops
	 * non-ASCII letters (`jäne@…` becomes `jne@…`, someone else's address)
	 * and can leave nothing (an empty list: anyone may use the code); and it
	 * reads `*` as "any characters", so an address with one matches others.
	 *
	 * @param array<int|string, mixed> $restrictions
	 */
	public static function restricts_to_exactly( array $restrictions, string $email ): bool {
		return $email !== '' && strpos( $email, '*' ) === false && array_values( $restrictions ) === array( $email );
	}

	/** The same address in any letter case — bytes after lowering, so no accent folding. */
	private static function same_address( string $a, string $b ): bool {
		return strtolower( trim( $a ) ) === strtolower( trim( $b ) );
	}

	/**
	 * Clone the template into the shopper's code.
	 *
	 * The reason no code was made instead, when WooCommerce refused a setting
	 * or the save, or would restrict the code to anything but the shopper's
	 * address.
	 *
	 * @return array{code: string, last_day: string}|string
	 */
	private function create( string $trigger, int $template_id, int $days, string $email ): array|string {
		$template         = new \WC_Coupon( $template_id );
		$template_expires = $template->get_date_expires( 'edit' );

		[ $expires, $last_day ] = self::expiry(
			time(),
			$days,
			wp_timezone(),
			$template_expires !== null ? $template_expires->getTimestamp() : null
		);

		$coupon = new \WC_Coupon();
		$cloned = $coupon->set_props( array_intersect_key( $template->get_data(), array_flip( self::CLONED_PROPS ) ) );
		$own    = $coupon->set_props(
			array(
				'code'                 => self::unique_code(),
				'description'          => sprintf(
					/* translators: %s: the code of the coupon the discount code was copied from. */
					__( 'A single-use code Smaily Connect made from the coupon %s for one shopper.', 'smaily-connect' ),
					$template->get_code()
				),
				'usage_limit'          => 1,
				'usage_limit_per_user' => 1,
				'email_restrictions'   => array( $email ),
				'date_expires'         => $expires,
			)
		);
		// A setting WooCommerce refused is not set — a code without its
		// email restriction would work for anyone, so none is made.
		$cannot_save = sprintf( 'WooCommerce could not save a code from the coupon #%d', $template_id );
		if ( is_wp_error( $cloned ) || is_wp_error( $own ) ) {
			return $cannot_save;
		}
		// WooCommerce rewrites an allowed email it does not like instead of
		// refusing it — a code restricted to anything but the shopper's own
		// address is someone else's, so none is made.
		if ( ! self::restricts_to_exactly( $coupon->get_email_restrictions( 'edit' ), $email ) ) {
			return 'WooCommerce cannot restrict a code to exactly this email address';
		}
		foreach ( self::CLONED_META as $key ) {
			$value = get_post_meta( $template_id, $key, true );
			if ( is_array( $value ) && $value !== array() ) {
				$coupon->update_meta_data( $key, $value );
			}
		}
		$coupon->update_meta_data( self::META_GENERATED_FROM, (string) $template_id );
		$coupon->update_meta_data( self::META_TRIGGER, $trigger );

		try {
			$saved = $coupon->save();
		} catch ( \Exception $e ) {
			// A store-side failure sends the email without a code, like a
			// template that gives none — never a queue row that fails for it.
			$saved = 0;
		}
		if ( $saved <= 0 ) {
			return $cannot_save;
		}

		return array(
			'code'     => $coupon->get_code(),
			'last_day' => $last_day,
		);
	}

	/**
	 * A random code no coupon of the store has.
	 */
	private static function unique_code(): string {
		$last = strlen( self::CODE_CHARACTERS ) - 1;
		do {
			$code = '';
			for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
				$code .= self::CODE_CHARACTERS[ random_int( 0, $last ) ];
			}
		} while ( wc_get_coupon_id_by_code( $code ) > 0 );

		return $code;
	}
}
