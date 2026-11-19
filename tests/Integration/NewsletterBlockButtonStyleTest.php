<?php
/**
 * Integration: the newsletter block's button style takes only CSS values of
 * the right kind (CON-346).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily_Connect\Blocks\Newsletter_Signup\Integration;

/**
 * The button colours, width and corner radius go into the block wrapper's
 * `style` attribute, where esc_attr() stops HTML but not CSS. A colour
 * attribute takes only a colour (in the forms the block's editor saves),
 * the width only a size, the radius only a number — anything else falls
 * back to the default. Rendered with WP_Block_Supports::$block_to_render
 * set, as render_block() does, so get_block_wrapper_attributes() prints the
 * style; the attributes are passed as stored, before block.json types them.
 */
final class NewsletterBlockButtonStyleTest extends TestCase {

	public function test_a_value_that_is_not_a_colour_or_size_falls_back_to_the_default(): void {
		$style = $this->style(
			array(
				'subscribeButtonBackgroundColor' => 'red;position:fixed;inset:0',
				'subscribeButtonTextColor'       => 'var(--x);position:fixed',
				'subscribeButtonWidth'           => '100%;position:fixed;inset:0',
				'subscribeButtonBorderRadius'    => '4;position:fixed',
			)
		);

		self::assertStringNotContainsString( 'position', $style );
		self::assertStringNotContainsString( 'inset', $style );
		self::assertStringContainsString( '--smaily-subscribe-button-bg-color: #000;', $style );
		self::assertStringContainsString( '--smaily-subscribe-button-text-color: #fff;', $style );
		self::assertStringContainsString( '--smaily-subscribe-button-width: auto;', $style );
		self::assertStringContainsString( '--smaily-subscribe-button-border-radius: 0px', $style );
	}

	public function test_the_values_the_editor_saves_are_kept(): void {
		$cases = array(
			array( 'var(--wp-admin-theme-color, #007cba)', 'var(--wp-components-color-accent-inverted, #ffffff)' ),
			array( 'var(--wp-admin-theme-color)', 'var(--wp-components-color-accent-inverted,#fff)' ),
			array( 'var(--wp--preset--color--vivid-red)', '#ff000080' ),
			array( '#1e1e1e', '#FFF' ),
		);
		foreach ( $cases as list( $background, $text ) ) {
			$style = $this->style(
				array(
					'subscribeButtonBackgroundColor' => $background,
					'subscribeButtonTextColor'       => $text,
					'subscribeButtonWidth'           => '100%',
					'subscribeButtonBorderRadius'    => 8,
				)
			);

			self::assertStringContainsString( '--smaily-subscribe-button-bg-color: ' . $background . ';', $style );
			self::assertStringContainsString( '--smaily-subscribe-button-text-color: ' . $text . ';', $style );
			self::assertStringContainsString( '--smaily-subscribe-button-width: 100%;', $style );
			self::assertStringContainsString( '--smaily-subscribe-button-border-radius: 8px', $style );
		}
	}

	public function test_no_corner_radius_raises_no_warning(): void {
		$warnings = array();
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
				$warnings[] = $errstr;
				return true;
			}
		);
		try {
			$style = $this->style( array() );
		} finally {
			restore_error_handler();
		}

		self::assertSame( array(), $warnings );
		self::assertStringContainsString( '--smaily-subscribe-button-border-radius: 0px', $style );
	}

	public function test_the_block_markup_renders_through_do_blocks(): void {
		$rendered = do_blocks( '<!-- wp:smaily/newsletter-block {"subdomain":"demostore","subscribeButtonWidth":"100%","subscribeButtonBorderRadius":6} /-->' );

		self::assertStringContainsString( '--smaily-subscribe-button-width: 100%;', $rendered );
		self::assertStringContainsString( '--smaily-subscribe-button-border-radius: 6px', $rendered );
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function style( array $attributes ): string {
		$previous                               = \WP_Block_Supports::$block_to_render;
		\WP_Block_Supports::$block_to_render = array(
			'blockName' => 'smaily/newsletter-block',
			'attrs'     => array(),
		);
		try {
			$html = Integration::render( $attributes + array( 'subdomain' => 'demostore' ), '' );
		} finally {
			\WP_Block_Supports::$block_to_render = $previous;
		}

		self::assertSame( 1, preg_match( '/style="([^"]*)"/', (string) $html, $match ), (string) $html );
		return preg_replace( '/\s+/', ' ', html_entity_decode( $match[1], ENT_QUOTES ) );
	}
}
