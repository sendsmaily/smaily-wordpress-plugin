<?php

namespace Smaily_Connect\Blocks\Recommendations;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Bootstrap;

class Integration {
	/**
	 * Renders the recommendations block: the logged-in shopper's own Smaily
	 * product recommendations, or nothing.
	 *
	 * Server-rendered on purpose: the engine is asked with the store's API key,
	 * which never reaches the browser (RECENGINE_API_CONTRACT.md §15). A
	 * visitor who is not logged in, a store without a usable engine
	 * connection, a shopper who opted out of profiling, an engine error and an
	 * empty answer all render nothing — never an error to the shopper.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Block content.
	 * @return string
	 */
	public static function render( $attributes, $content ) {
		return Bootstrap::instance()->storefront_recommendations()->render( get_current_user_id() );
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
