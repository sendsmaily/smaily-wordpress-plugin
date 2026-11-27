<?php
/**
 * Integration: the "Smaily Connect" admin menu reads Dashboard, Initial setup,
 * Settings, Log (CON-464); it opens the Initial setup until setup is
 * finished, and the Dashboard after that.
 *
 * These cases run the REAL menu registration against WordPress's own menu
 * globals.
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

	/** The same menu before and after setup (CON-464): Dashboard, Initial setup, Settings, Log. */
	public function test_the_menu_reads_dashboard_initial_setup_settings_log(): void {
		foreach ( array( false, true ) as $completed ) {
			$this->reset_menu_globals();
			update_option( SetupState::OPTION_SETUP_COMPLETED, $completed );

			smaily_connect_register_admin_pages();

			self::assertSame( 'smaily-connect-dashboard', $this->top_level_slug() );
			self::assertSame(
				array( 'smaily-connect-dashboard', 'smaily-connect-wizard', 'smaily-connect-settings', 'smaily-connect-log' ),
				$this->submenu_slugs( 'smaily-connect-dashboard' )
			);
			self::assertSame(
				array( 'Dashboard', 'Initial setup', 'Settings', 'Log' ),
				array_values( array_column( $GLOBALS['submenu']['smaily-connect-dashboard'], 0 ) )
			);
		}

		// Bookmarks and notice links to the older pages still resolve.
		self::assertNotSame( '', menu_page_url( 'smaily-connect-wizard', false ) );
		self::assertNotSame( '', menu_page_url( 'smaily-connect-settings', false ) );
	}

	/** Until setup is finished the top-level item, Settings and the Log open the Initial setup. */
	public function test_before_setup_is_finished_the_dashboard_settings_and_log_open_the_initial_setup(): void {
		delete_option( SetupState::OPTION_SETUP_COMPLETED );

		smaily_connect_register_admin_pages();

		foreach ( array( 'toplevel_page_smaily-connect-dashboard', 'smaily-connect_page_smaily-connect-settings', 'smaily-connect_page_smaily-connect-log' ) as $hook_suffix ) {
			self::assertNotFalse( has_action( 'load-' . $hook_suffix, 'smaily_connect_require_finished_setup' ), $hook_suffix );
		}
		self::assertFalse( has_action( 'load-smaily-connect_page_smaily-connect-wizard', 'smaily_connect_require_finished_setup' ) );

		self::assertStringContainsString( 'page=smaily-connect-wizard', (string) $this->redirect_of_gate() );
	}

	public function test_after_setup_is_finished_the_dashboard_opens(): void {
		update_option( SetupState::OPTION_SETUP_COMPLETED, true );

		self::assertNull( $this->redirect_of_gate() );
	}

	/** Where the setup gate sends the browser, or null when it lets the page open. */
	private function redirect_of_gate(): ?string {
		$catch = static function ( string $location ): string {
			throw new \RuntimeException( $location );
		};
		add_filter( 'wp_redirect', $catch );
		try {
			smaily_connect_require_finished_setup();
			return null;
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $catch );
		}
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
		remove_all_actions( 'load-toplevel_page_smaily-connect-dashboard' );
		remove_all_actions( 'load-smaily-connect_page_smaily-connect-settings' );
		remove_all_actions( 'load-smaily-connect_page_smaily-connect-log' );
	}
}
