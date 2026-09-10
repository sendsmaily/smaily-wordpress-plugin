<?php

namespace Smaily_Connect\Blocks\Landing_Page;

defined( 'ABSPATH' ) || exit;

use Smaily_Connect\Includes\Options;

class Integration {
	/**
	 * Subdomains are a DNS label — the same shape the wizard writes into the
	 * credentials option and the block reads back from /smaily/v1/configuration.
	 */
	const SUBDOMAIN_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/';

	/**
	 * Landing page keys are UUIDs, as the block's own URL parser requires —
	 * checked on their own, and read out of an address as the segment that
	 * follows /landing-pages/ and either ends the path or is followed by more.
	 */
	private const PK_SHAPE        = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
	const PK_PATTERN              = '/^' . self::PK_SHAPE . '$/';
	private const PK_PATH_PATTERN = '#/landing-pages/(' . self::PK_SHAPE . ')(?:/|$)#';

	/**
	 * The embed size the block.json defaults describe, reused by the shortcode
	 * and the Elementor widget so all three surfaces look the same. The copy
	 * is pinned to block.json by tests/Unit/Blocks/LandingPageDefaultSizeTest.php.
	 */
	const DEFAULT_HEIGHT = 450;
	const DEFAULT_WIDTH  = 500;

	/**
	 * Renders the landing page block.
	 *
	 * Server-rendered on purpose: post_content must stay iframe-free.
	 * See docs/DECISIONS.md, PRO-2346 (reopened).
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Block content.
	 * @return string
	 */
	public static function render( $attributes, $content ) {
		$subdomain = $attributes['subdomain'];
		$pk        = $attributes['landingpagePK'];

		// No landing page picked yet, or an address the block never accepted —
		// embed nothing rather than leak the editor's setup instructions to
		// visitors.
		if ( ! preg_match( self::SUBDOMAIN_PATTERN, $subdomain ) || ! preg_match( self::PK_PATTERN, $pk ) ) {
			return '';
		}

		$height = self::size_css( $attributes['height'], self::DEFAULT_HEIGHT );
		$width  = self::size_css( $attributes['width'], self::DEFAULT_WIDTH );

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => 'smaily-connect-landingpage-block-front-wrapper',
				'style' => sprintf( 'height:%s;width:%s', $height, $width ),
			)
		);

		$url = sprintf( 'https://%s.sendsmaily.net/landing-pages/%s/html/', $subdomain, $pk );

		return sprintf(
			'<div %1$s><iframe class="smaily-connect-landingpage-block-front" src="%2$s" title="%3$s" loading="lazy" referrerpolicy="no-referrer"></iframe></div>',
			$wrapper_attributes,
			esc_url( $url ),
			esc_attr__( 'Smaily Landing Page', 'smaily-connect' )
		);
	}

	/**
	 * Renders the [smaily_landing_page] shortcode.
	 *
	 * Page builders replace the post content before the block renderer sees it
	 * (Elementor swaps the whole string), so merchants on those need a
	 * shortcode. It goes through the block's own renderer — same escaping,
	 * same wrapper, same validated URL. See docs/DECISIONS.md, PRO-2440.
	 *
	 * @param array|string $atts Shortcode attributes; an empty string when the
	 *                           shortcode is written without any.
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'url'    => '',
				'height' => self::DEFAULT_HEIGHT,
				'width'  => self::DEFAULT_WIDTH,
			),
			$atts,
			'smaily_landing_page'
		);

		return self::render_embed( $atts['url'], $atts['height'], $atts['width'] );
	}

	/**
	 * Renders an embed from a pasted landing page address.
	 *
	 * Shared by the shortcode and the Elementor widget: the address is checked
	 * against the connected account before anything is built from it, and the
	 * markup then comes from render() so the three surfaces cannot drift.
	 *
	 * @param string     $url    Landing page address as the merchant pasted it.
	 * @param int|string $height Embed height, in pixels or as a percentage.
	 * @param int|string $width  Embed width, in pixels or as a percentage.
	 * @return string Empty when the address is not a landing page of this account.
	 */
	public static function render_embed( $url, $height, $width ) {
		$options   = new Options();
		$subdomain = $options->get_subdomain();

		$pk = self::landing_page_key( $url, $subdomain );
		if ( '' === $pk ) {
			return '';
		}

		// The block's stylesheet is enqueued for us only when WordPress renders
		// the block itself.
		wp_enqueue_style( 'smaily-landingpage-block-style' );

		return self::render(
			array(
				'subdomain'     => $subdomain,
				'landingpagePK' => $pk,
				'height'        => $height,
				'width'         => $width,
			),
			''
		);
	}

	/**
	 * Extracts the landing page key from an address, or refuses it.
	 *
	 * The server-side twin of the block editor's URL parser: https only, the
	 * store's OWN Smaily account, a landing page path, and a UUID key.
	 *
	 * @param string $url       Address to check.
	 * @param string $subdomain Subdomain of the connected Smaily account.
	 * @return string The key, or an empty string when the address is refused.
	 */
	public static function landing_page_key( $url, $subdomain ) {
		$url       = trim( (string) $url );
		$subdomain = trim( (string) $subdomain );

		if ( '' === $url || ! preg_match( self::SUBDOMAIN_PATTERN, $subdomain ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}

		if ( strtolower( $parts['host'] ) !== strtolower( $subdomain ) . '.sendsmaily.net' ) {
			return '';
		}

		return preg_match( self::PK_PATH_PATTERN, $parts['path'] ?? '', $matches ) ? $matches[1] : '';
	}

	/**
	 * Normalises an embed size to a CSS length.
	 *
	 * The block stores numbers (pixels); a shortcode or widget may also be
	 * given a percentage, which is how merchants make an embed full-width.
	 * Anything else falls back to the default rather than reaching the page.
	 *
	 * @param int|string $value    Size as stored or typed.
	 * @param int        $fallback Pixel size to use when the value is unusable.
	 * @return string
	 */
	private static function size_css( $value, $fallback ) {
		$value = trim( (string) $value );

		if ( is_numeric( $value ) ) {
			return absint( $value ) . 'px';
		}

		if ( preg_match( '/^[0-9]+%$/', $value ) ) {
			return $value;
		}

		return $fallback . 'px';
	}
}
