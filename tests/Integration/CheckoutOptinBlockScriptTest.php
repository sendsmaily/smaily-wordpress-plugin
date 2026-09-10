<?php
/**
 * The checkout opt-in block's editor script is a footer script (PRO-2436).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * register_block_type() registers block.json's editorScript in the header;
 * its wc-settings / wc-blocks-checkout dependencies must load in the footer,
 * and WooCommerce otherwise moves the handle itself and prints
 * "smaily-checkout-optin-editor-script was registered to load in the header"
 * in every merchant's console.
 */
final class CheckoutOptinBlockScriptTest extends TestCase {

	public function test_editor_script_handle_is_in_the_footer_group(): void {
		if ( ! file_exists( SMAILY_CONNECT_PLUGIN_PATH . 'blocks/checkout-optin/build/block.json' ) ) {
			self::markTestSkipped( 'Block build artifacts absent (composer run build).' );
		}

		$scripts = wp_scripts();

		self::assertArrayHasKey( 'smaily-checkout-optin-editor-script', $scripts->registered, 'The block must be registered on init.' );
		self::assertSame( 1, $scripts->get_data( 'smaily-checkout-optin-editor-script', 'group' ) );
	}
}
