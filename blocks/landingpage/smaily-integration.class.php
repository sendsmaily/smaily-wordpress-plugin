<?php

namespace Smaily_Connect\Blocks\Landing_Page;

defined( 'ABSPATH' ) || exit;

class Integration {
	/**
	 * Subdomains are a DNS label — the same shape the wizard writes into the
	 * credentials option and the block reads back from /smaily/v1/configuration.
	 */
	const SUBDOMAIN_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/';

	/**
	 * Landing page keys are UUIDs, as the block's own URL parser requires.
	 */
	const PK_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

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

		$height = absint( $attributes['height'] );
		$width  = absint( $attributes['width'] );

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => 'smaily-connect-landingpage-block-front-wrapper',
				'style' => sprintf( 'height:%dpx;width:%dpx', $height, $width ),
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
}
