<?php
/**
 * Integration: the "Smaily Connect" admin menu opens the wizard until setup
 * is finished, and Settings after that (PRO-3873).
 *
 * A merchant who had finished the setup still landed in the wizard on every
 * click of the top-level menu, so the store looked unfinished. These cases run
 * the REAL menu registration against WordPress's own menu globals.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\SetupState;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class AdminMenuTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'admin/wizard.php';

		$this->reset_menu_globals();
	}

	protected function tearDown(): void {
		$this->reset_menu_globals();
		delete_option( SetupState::OPTION_SETUP_COMPLETED );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_before_setup_is_finished_the_menu_opens_the_wizard(): void {
		delete_option( SetupState::OPTION_SETUP_COMPLETED );

		smaily_connect_register_admin_pages();

		self::assertSame( 'smaily-connect-wizard', $this->top_level_slug() );
		self::assertSame(
			array( 'smaily-connect-wizard', 'smaily-connect-settings' ),
			$this->submenu_slugs( 'smaily-connect-wizard' )
		);
	}

	public function test_after_setup_is_finished_the_menu_opens_settings_and_keeps_the_wizard_reachable(): void {
		update_option( SetupState::OPTION_SETUP_COMPLETED, true );

		smaily_connect_register_admin_pages();

		self::assertSame( 'smaily-connect-settings', $this->top_level_slug() );
		self::assertSame(
			array( 'smaily-connect-settings', 'smaily-connect-wizard' ),
			$this->submenu_slugs( 'smaily-connect-settings' )
		);
		self::assertSame( 'Run setup again', $GLOBALS['submenu']['smaily-connect-settings'][1][0] );

		// Bookmarks and notice links to either page still resolve.
		self::assertNotSame( '', menu_page_url( 'smaily-connect-wizard', false ) );
		self::assertNotSame( '', menu_page_url( 'smaily-connect-settings', false ) );
	}

	private function top_level_slug(): string {
		$slugs = array();
		foreach ( (array) $GLOBALS['menu'] as $item ) {
			if ( isset( $item[0] ) && $item[0] === 'Smaily Connect' ) {
				$slugs[] = $item[2];
			}
		}
		self::assertCount( 1, $slugs, 'Exactly one "Smaily Connect" top-level menu item.' );
		return $slugs[0];
	}

	/**
	 * @return array<int, string>
	 */
	private function submenu_slugs( string $parent ): array {
		self::assertArrayHasKey( $parent, (array) $GLOBALS['submenu'] );
		return array_values( array_column( $GLOBALS['submenu'][ $parent ], 2 ) );
	}

	private function reset_menu_globals(): void {
		$GLOBALS['menu']              = array();
		$GLOBALS['submenu']           = array();
		$GLOBALS['admin_page_hooks']  = array();
		$GLOBALS['_registered_pages'] = array();
		$GLOBALS['_parent_pages']     = array();
	}
}
