<?php
/**
 * Integration: the admin bundle loads after WordPress core's React (CON-314).
 *
 * dist/admin/admin.js no longer carries React; it reads window.React,
 * window.ReactDOM and window.ReactJSXRuntime, which core's `react`,
 * `react-dom` and `react-jsx-runtime` handles set. This runs the REAL enqueue
 * against the running WordPress, so CI's oldest-stack leg proves that every
 * one of those handles exists on the oldest WordPress the plugin supports.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

final class AdminBundleReactTest extends TestCase {

	private const HANDLE = 'smaily-connect-admin';

	private const REACT_HANDLES = array( 'react', 'react-dom', 'react-jsx-runtime' );

	protected function setUp(): void {
		parent::setUp();
		if ( ! file_exists( SMAILY_CONNECT_PLUGIN_PATH . 'dist/admin/admin.js' ) ) {
			self::markTestSkipped( 'Admin bundle absent (npm run build:admin).' );
		}
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'admin/wizard.php';
	}

	protected function tearDown(): void {
		wp_dequeue_script( self::HANDLE );
		wp_deregister_script( self::HANDLE );
		wp_dequeue_style( self::HANDLE );
		wp_deregister_style( self::HANDLE );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_the_admin_bundle_depends_on_core_react_and_core_registers_it(): void {
		smaily_connect_enqueue_admin_bundle( 'toplevel_page_smaily-connect-wizard' );

		$scripts = wp_scripts();
		self::assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );

		$deps = $scripts->registered[ self::HANDLE ]->deps;
		foreach ( self::REACT_HANDLES as $react_handle ) {
			self::assertContains( $react_handle, $deps, "The admin bundle must load after core's `{$react_handle}`." );
			self::assertTrue( wp_script_is( $react_handle, 'registered' ), "WordPress core must register `{$react_handle}`." );
		}
		self::assertContains( 'wp-i18n', $deps );
	}

	public function test_the_admin_bundle_is_a_classic_footer_script(): void {
		smaily_connect_enqueue_admin_bundle( 'toplevel_page_smaily-connect-wizard' );

		self::assertSame( 1, wp_scripts()->get_data( self::HANDLE, 'group' ), 'The admin bundle must print in the footer, after its mount node.' );

		$tag = '<script src="https://example.test/admin.js" id="smaily-connect-admin-js"></script>';
		self::assertStringNotContainsString( 'type="module"', apply_filters( 'script_loader_tag', $tag, self::HANDLE, 'https://example.test/admin.js' ), 'The IIFE bundle must not be tagged type="module".' );
	}
}
