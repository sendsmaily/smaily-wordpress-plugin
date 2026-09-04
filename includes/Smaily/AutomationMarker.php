<?php
/**
 * Marks a Smaily contact with when a store automation last ran for them.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * PRO-1681: a contact Smaily holds because a store automation enrolled them
 * was indistinguishable from someone who subscribed themselves. Only the
 * abandoned-cart trigger carried anything at all (`is_abandoned_cart`), and
 * that is a template flag, not a record of a run; welcome and first-order
 * carried nothing.
 *
 * Each trigger now writes its OWN contact field, whose VALUE is the moment
 * that automation last ran for the contact — so a merchant can segment on
 * "the welcome automation has touched this contact" and on "…and it last ran
 * before <date>".
 *
 * Rules that are load-bearing:
 *   - The field names below are MERCHANT-VISIBLE and permanent: merchants
 *     build Smaily segments and templates on them, and a rename silently
 *     breaks every one of those. Add names here, never repurpose one.
 *   - Written on EVERY run, last-writer-wins (Erkki, 2026-08-04). The
 *     semantics are "this automation ran, most recently at T" — NOT how the
 *     contact entered the list; an already-subscribed contact gets it too.
 *   - Stamped when the trigger FIRES (the payload is built at enqueue), not
 *     when the queue row is POSTed. A retry therefore resends the moment the
 *     store event happened, which is the moment a merchant means.
 *   - Format `Y-m-d H:i:s` in UTC — the shape of the only other date+time
 *     value already on the Smaily contact wire (`first_registered`), and
 *     lexicographically ordered, which is what lets a Smaily segment compare
 *     it against a date.
 *   - A trigger writes only its own field. A marker is never emitted for an
 *     automation that did not fire: absent leaves whatever Smaily already
 *     holds intact, `''` would wipe it (F3-47 rule 2).
 *   - `is_abandoned_cart` is untouched (PRO-1195 legacy template parity) —
 *     the abandoned-cart marker rides alongside it in the same payload.
 */
final class AutomationMarker {

	/**
	 * Trigger slug (the AutomationRouter / Settings vocabulary) → the contact
	 * field that records its last run.
	 *
	 * @var array<string, string>
	 */
	private const FIELDS = array(
		'welcome'        => 'welcome_automation_at',
		'first_order'    => 'first_order_automation_at',
		'abandoned_cart' => 'abandoned_cart_automation_at',
	);

	/**
	 * The contact field a trigger marks, or '' for a trigger that has none
	 * (the transactional triggers deliberately don't — they're a receipt for
	 * an order, not an enrolment into marketing).
	 */
	public static function field( string $trigger ): string {
		return self::FIELDS[ $trigger ] ?? '';
	}

	/**
	 * The marker to merge into a trigger payload's `fields` bag, stamped now.
	 * Empty for an unmarked trigger — the caller then sends no marker at all,
	 * which is the omit-never-empty rule.
	 *
	 * @return array<string, string>
	 */
	public static function stamp( string $trigger ): array {
		$field = self::field( $trigger );
		if ( $field === '' ) {
			return array();
		}

		return array( $field => gmdate( 'Y-m-d H:i:s' ) );
	}
}
