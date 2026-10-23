<?php
/**
 * The single-use discount code an automation's email carries.
 *
 * @package Smaily\Connect\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Smaily\EventQueue;

/**
 * Gives each welcome, first-order and abandoned-cart email the shopper's own
 * WooCommerce discount code (CON-199, the Magento extension's CON-198).
 *
 * The merchant picks one of the store's coupons per automation as a
 * TEMPLATE. When the queue sends the email — after the workflow is found
 * and, for a reminder, after the consent check, so a skipped send makes no
 * code — issue() clones the template into one new coupon: every setting of
 * the template (discount type and amount, products, categories, sale items,
 * minimum and maximum spend, free shipping, individual use, item limit)
 * except its code, usage limits, expiry and allowed emails, which become a
 * random code, one use, one use per user, the shopper's address only, and
 * the end of the day `valid_days` ahead in the store's timezone — never
 * later than the template's own expiry. The copy carries META_GENERATED_FROM
 * (the template's id), which marks it as the plugin's.
 *
 * Only a template that can be cloned into such a code counts (the picker
 * lists only these, and issue() checks again at send time):
 * a published coupon of one of WooCommerce's own discount types
 * (TEMPLATE_TYPES — another plugin's type, a gift card or store credit say,
 * may carry state a copy must not duplicate), not expired, and not a code
 * the plugin made itself. Settings another plugin keeps in its own coupon
 * meta are not copied.
 *
 * Once per queue row: the code and its last day are written into the row's
 * payload before the post (EventQueue::update_payload()), so a retry posts
 * the same code and makes no second coupon. A template that cannot give a
 * code leaves the code out — the email still goes — and returns the reason,
 * which the flusher stores as the Event Log note.
 *
 * WooCommerce itself refuses a used, foreign or expired code at checkout
 * (WC_Discounts' usage-limit, allowed-email and expiry checks), so the
 * plugin enforces nothing there. The daily janitor deletes the plugin's
 * codes that were never used, DELETE_AFTER_DAYS after their expiry.
 *
 * Not final: the flusher unit tests mock issue().
 */
class AutomationDiscount {

	/** The three automations' settings — see settings(). */
	public const OPTION = 'smly_plus_automation_discounts';

	/** Coupon meta marking a code the plugin made: the template's id. */
	public const META_GENERATED_FROM = '_smaily_connect_generated_from';

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

			$settings[ $trigger ] = array(
				'enabled'     => ! empty( $row['enabled'] ),
				'template_id' => isset( $row['template_id'] ) && is_numeric( $row['template_id'] ) ? max( 0, (int) $row['template_id'] ) : 0,
				'valid_days'  => max( self::VALID_DAYS_MIN, min( self::VALID_DAYS_MAX, $days ) ),
			);
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
			$days = self::whole_days_or_null( $row['validDays'] ?? null );
			if ( $days === null ) {
				return null;
			}

			$settings[ $trigger ] = array(
				'enabled'     => ! empty( $row['enabled'] ),
				'template_id' => isset( $row['templateId'] ) && is_numeric( $row['templateId'] ) ? max( 0, (int) $row['templateId'] ) : 0,
				'valid_days'  => $days,
			);
		}

		return $settings;
	}

	/**
	 * The coupons a code can be cloned from, for the picker: id and a label
	 * of code, discount type and amount, by code.
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
			if ( self::template_problem( $id ) !== '' ) {
				continue;
			}
			$coupon      = new \WC_Coupon( $id );
			$templates[] = array(
				'id'    => $id,
				'label' => sprintf(
					'%1$s — %2$s: %3$s',
					$coupon->get_code(),
					wc_get_coupon_type( $coupon->get_discount_type() ),
					wc_format_localized_decimal( $coupon->get_amount() )
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
		$expires = get_post_meta( $template_id, 'date_expires', true );
		if ( is_numeric( $expires ) && (int) $expires > 0 && (int) $expires <= time() ) {
			return sprintf( 'the WooCommerce coupon #%d has expired', $template_id );
		}

		return '';
	}

	/**
	 * The discount code fields for one queue row about to be posted.
	 *
	 * Nothing when the automation's switch is off, or when the row already
	 * carries its code (a retry — the fields are in its payload). Otherwise a
	 * new code, written into the row's payload before it is returned; or no
	 * code and the reason, when the template cannot give one.
	 *
	 * @param array<string, mixed> $payload The row's decoded payload.
	 *
	 * @return array{fields: array<string, string>, note: string}
	 */
	public function issue( string $trigger, array $payload, EventQueue $queue, int $row_id ): array {
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
		$code    = $problem === '' ? $this->create( $setting['template_id'], $setting['valid_days'], $email ) : null;
		if ( $code === null ) {
			return array(
				'fields' => array(),
				'note'   => sprintf( self::NOTE, $problem !== '' ? $problem : sprintf( 'WooCommerce could not save a code from the coupon #%d', $setting['template_id'] ) ),
			);
		}

		$code_fields = array(
			$names[0] => $code['code'],
			$names[1] => $code['last_day'],
		);

		$payload['fields'] = array_merge( $fields, $code_fields );
		$queue->update_payload( $row_id, $payload );

		return array(
			'fields' => $code_fields,
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
			$last_day = ( new \DateTimeImmutable( '@' . ( $template_expires - 1 ) ) )->setTimezone( $timezone )->format( 'Y-m-d' );
		}

		return array( $expires, $last_day );
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
	 * Clone the template into the shopper's code.
	 *
	 * @return array{code: string, last_day: string}|null Null when WooCommerce refused a setting or the save.
	 */
	private function create( int $template_id, int $days, string $email ): ?array {
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
		if ( is_wp_error( $cloned ) || is_wp_error( $own ) ) {
			return null;
		}
		$coupon->update_meta_data( self::META_GENERATED_FROM, (string) $template_id );

		try {
			$saved = $coupon->save();
		} catch ( \Exception $e ) {
			// A store-side failure sends the email without a code, like a
			// template that gives none — never a queue row that fails for it.
			$saved = 0;
		}
		if ( $saved <= 0 ) {
			return null;
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

	/**
	 * A whole number of days within VALID_DAYS_MIN..MAX, or null.
	 *
	 * @param mixed $days
	 */
	private static function whole_days_or_null( $days ): ?int {
		if ( is_int( $days ) ) {
			$value = $days;
		} elseif ( is_string( $days ) && preg_match( '/^\d+$/', trim( $days ) ) === 1 ) {
			$value = (int) trim( $days );
		} else {
			return null;
		}

		return $value >= self::VALID_DAYS_MIN && $value <= self::VALID_DAYS_MAX ? $value : null;
	}
}
