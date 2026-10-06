<?php

namespace Smaily_Connect\Blocks\Recommendations;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Bootstrap;

class Integration {
	/**
	 * Renders the recommendations block: an empty container, the same for
	 * every visitor, that the storefront script fills with the shopper's own
	 * Smaily product recommendations after the page has loaded (PRO-3835).
	 *
	 * The page itself carries no per-shopper data and asks the engine nothing,
	 * so it stays fast and may be kept in a full-page cache. The script asks
	 * the store — never the engine, whose API key stays on the server
	 * (RECENGINE_API_CONTRACT.md §15) — and only with the shopper's marketing
	 * consent. No answer, an empty one or an error shows nothing — never an
	 * error to the shopper.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Block content.
	 * @return string
	 */
	public static function render( $attributes, $content ) {
		return Bootstrap::instance()->storefront_recommendations()->placeholder();
	}

	/**
	 * Renders the [smaily_recommendations] shortcode — the block's other
	 * surface, for page builders that discard blocks (the PRO-2440 pattern).
	 * It goes through the block's own renderer.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		return self::render( array(), '' );
	}
}
