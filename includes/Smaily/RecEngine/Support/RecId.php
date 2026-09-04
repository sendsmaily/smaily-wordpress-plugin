<?php
/**
 * Single source of truth for the rec-engine's `smaily_rec_id` wire shape.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a recommendation id against the shape the engine actually enforces:
 * `smaily_rec_id: z.string().uuid().optional()` on the orders route (§5) — a
 * UUID, not a free-form token. The contract's §5 field table types it as a
 * plain `string`; the live route is the authority (PRO-1713 asks the engine to
 * type it in the doc).
 *
 * Why this exists (PRO-1710): orders are validated PER ORDER (D6), so ONE order
 * carrying a non-UUID `smaily_rec_id` is rejected permanently with an `errors[]`
 * entry while its batch mates go through — that order never lands. The landing
 * capture used to accept any bounded id token, so a visitor arriving with a
 * hand-typed / truncated / crafted `?smaily_rec=` value got it cookied, stamped
 * onto their order at checkout, and the order was silently lost to ingest. One
 * definition, used at BOTH ends of that path: capture (LandingCapture) and send
 * (OrderPayloadBuilder).
 *
 * The pattern is the engine's zod (v3) `uuid()` regex verbatim — 8-4-4-4-12 hex,
 * with NO version/variant nibble constraint. Deliberately not stricter than the
 * engine: a value the engine would accept must never be dropped here (that would
 * lose real attribution, the F3-46 problem in reverse).
 */
final class RecId {

	private const PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

	public static function is_valid( string $value ): bool {
		return 1 === preg_match( self::PATTERN, $value );
	}
}
