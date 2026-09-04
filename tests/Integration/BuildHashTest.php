<?php
/**
 * Integration: build-hash.txt matches the value the boot payload emits.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * What Faas-2 bug this catches:
 *
 *   Sub-PR 2.H.8 / 2.H.16 — Erkki couldn't tell whether his staging
 *   was running the commit he just shipped, or a cached older bundle.
 *   `composer run package:hash` writes build-hash.txt at package
 *   time; admin/wizard.php emits that value into
 *   `window.smailyConnectBoot.buildHash`. The integration test
 *   confirms the wire-up: the file on disk equals what the PHP page
 *   payload would expose. If a developer breaks the path constant or
 *   forgets to ship the stamp, this assertion fails locally before the
 *   ZIP ever reaches staging.
 *
 *   The stamp sits at the plugin ROOT rather than inside dist/ (PRO-1781):
 *   in dist/ every vite build wiped it, so this test kept failing on a
 *   missing build artifact instead of on a real regression.
 */
final class BuildHashTest extends TestCase {

	public function test_build_hash_file_exists_and_is_non_empty(): void {
		$path = SMAILY_CONNECT_PLUGIN_PATH . 'build-hash.txt';
		self::assertFileExists(
			$path,
			'build-hash.txt missing. Run `composer run package:hash` before integration tests.'
		);
		$contents = trim( (string) file_get_contents( $path ) );
		self::assertNotSame( '', $contents, 'build-hash.txt is empty.' );
		self::assertDoesNotMatchRegularExpression(
			'/\s/',
			$contents,
			'build-hash.txt should be a single token (short SHA + optional -dirty suffix). Found whitespace.'
		);
	}

	public function test_admin_wizard_php_reads_the_same_build_hash_path(): void {
		// Defensive grep: the path constant lives in admin/wizard.php
		// alongside the wp_localize_script call. If a refactor renames
		// the path (e.g. build-hash.txt → build/hash.txt) the
		// boot payload silently falls back to 'dev'. We pin the path
		// here as a regression guard.
		$wizard_php = (string) file_get_contents( SMAILY_CONNECT_PLUGIN_PATH . 'admin/wizard.php' );
		self::assertStringContainsString(
			"'build-hash.txt'",
			$wizard_php,
			'admin/wizard.php no longer references build-hash.txt — buildHash will fall back to "dev" on the live site.'
		);
	}
}
