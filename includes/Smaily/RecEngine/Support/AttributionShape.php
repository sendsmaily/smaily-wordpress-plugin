<?php
/**
 * Single source of truth for the non-uuid attribution signals' wire shapes.
 *
 * @package Smaily\Connect\Smaily\RecEngine\Support
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The shapes the attribution signals other than `smaily_rec_id` are accepted in
 * — the visitor token, the context slug and the anonymous session id.
 *
 * Why this exists (PRO-1942): the shapes were written once in the CAPTURE path
 * (LandingCapture) and nowhere else, so the SEND path forwarded whatever sat on
 * order meta. That gap matters for the same reason RecId's does: a value cookied
 * by a producer that never checked it (the pre-PRO-1896 JS writer, a crafted
 * URL) outlives the fix by the cookie's TTL, and an order carrying it retries
 * through the flusher for the queue's lifetime. One definition, used at BOTH
 * ends — capture (LandingCapture) and send (OrderPayloadBuilder).
 *
 * `session_id` is generated as a UUID by every producer we ship, but nothing has
 * ever enforced that, so it keeps the deliberately generous bound PRO-1896's
 * order-meta cap chose (the context charset, 64 chars) rather than an exact
 * shape — same reasoning as RecId's "never stricter than the consumer": a value
 * the engine would accept must not be dropped here.
 */
final class AttributionShape {

	/** Engine visitor-token format: `vt_` + alphanumerics (re: visitor-tokens/manager.ts). */
	private const VISITOR_TOKEN_PATTERN = '/^vt_[A-Za-z0-9]{1,64}$/';

	/** Intent slug (welcome / cart_abandoned / cross_sell / …), also the session-id bound. */
	private const CONTEXT_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

	public static function is_visitor_token( string $value ): bool {
		return 1 === preg_match( self::VISITOR_TOKEN_PATTERN, $value );
	}

	public static function is_context( string $value ): bool {
		return 1 === preg_match( self::CONTEXT_PATTERN, $value );
	}

	public static function is_session_id( string $value ): bool {
		return 1 === preg_match( self::CONTEXT_PATTERN, $value );
	}
}
