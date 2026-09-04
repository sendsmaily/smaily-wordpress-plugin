<?php
/**
 * Pins uninstall.php's cleanup coverage for the ProfilingConsent state
 * (PRO-1336) and the rec-engine connection state (PRO-1337) against the
 * actual class constants — so a rename of the option/transient prefixes,
 * a new flush hook, or a stripped cleanup line fails loudly.
 *
 * uninstall.php itself is NOT executed here: it DROPs the plugin's custom
 * tables and bulk-deletes wp_options rows, which is destructive to run
 * inside the shared test process (see tests/Integration/Support/EnvScrub.php,
 * built specifically as a non-destructive sibling for test isolation). A
 * source-level pin is the safe, cheap alternative.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\Privacy\ProfilingConsent;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\CatalogRemoveFlusher;
use Smaily\Connect\Smaily\RecEngine\CustomerFlusher;
use Smaily\Connect\Smaily\RecEngine\IngestQueue;
use Smaily\Connect\Smaily\RecEngine\OrderFlusher;

final class UninstallCleanupTest extends TestCase {

	private function uninstall_source(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
	}

	public function test_removes_the_durable_profiling_optout_registry_option(): void {
		$option = ( new ReflectionClass( ProfilingConsent::class ) )->getConstant( 'OPTION_OPTOUTS' );

		self::assertIsString( $option );
		self::assertStringContainsString(
			"'{$option}'",
			$this->uninstall_source(),
			'uninstall.php must delete the ProfilingConsent durable opt-out registry option (PRO-1336).'
		);
	}

	public function test_sweeps_the_profiling_consent_cache_transients_by_prefix(): void {
		$reflection   = new ReflectionClass( ProfilingConsent::class );
		$cache_prefix = $reflection->getConstant( 'CACHE_PREFIX' );
		$stale_prefix = $reflection->getConstant( 'STALE_CACHE_PREFIX' );

		self::assertIsString( $cache_prefix );
		self::assertIsString( $stale_prefix );
		// The stale-cache prefix nests inside the fresh-cache prefix, so a
		// single LIKE sweep on the fresh prefix must catch both transients.
		self::assertStringStartsWith( $cache_prefix, $stale_prefix );

		$source = $this->uninstall_source();
		self::assertStringContainsString(
			"_transient_{$cache_prefix}",
			$source,
			'uninstall.php must sweep the ProfilingConsent transient VALUE rows by prefix (PRO-1336).'
		);
		self::assertStringContainsString(
			"_transient_timeout_{$cache_prefix}",
			$source,
			'uninstall.php must sweep the ProfilingConsent transient TIMEOUT rows by prefix (PRO-1336).'
		);
	}

	/**
	 * uninstall.php sweeps smly_rec_* by LIKE-prefix (like the smly_plus_*
	 * sweep) rather than an explicit key list, so a future RecEngineSettings
	 * option needs no new uninstall.php line — but the prefix it sweeps must
	 * actually cover every option constant the class defines today, plus
	 * NotificationManager's rec-health option (a `smly_rec_*` option that
	 * lives outside RecEngineSettings).
	 */
	public function test_sweeps_every_rec_engine_option_by_the_smly_rec_prefix(): void {
		$option_constants = array();
		foreach ( ( new ReflectionClass( RecEngineSettings::class ) )->getConstants() as $name => $value ) {
			if ( strpos( $name, 'OPTION_' ) === 0 ) {
				$option_constants[] = $value;
			}
		}
		self::assertNotEmpty( $option_constants, 'RecEngineSettings must still define its OPTION_* constants.' );

		$option_constants[] = ( new ReflectionClass( NotificationManager::class ) )->getConstant( 'OPTION_DOWN_SINCE' );

		$source = $this->uninstall_source();
		self::assertStringContainsString(
			"esc_like( 'smly_rec_' )",
			$source,
			'uninstall.php must LIKE-sweep the smly_rec_ option prefix (PRO-1337).'
		);
		foreach ( $option_constants as $option ) {
			self::assertIsString( $option );
			self::assertStringStartsWith(
				'smly_rec_',
				$option,
				"'{$option}' must live under the smly_rec_ prefix uninstall.php sweeps, or it silently survives an uninstall (PRO-1337)."
			);
		}
	}

	/**
	 * The four rec-engine recurring Action Scheduler flush hooks
	 * (Bootstrap.php) must fall under the same smly_rec_ prefix uninstall.php
	 * purges from actionscheduler_actions — else they keep firing after
	 * uninstall against a class that no longer exists (PRO-1337).
	 */
	public function test_sweeps_the_rec_engine_flusher_action_scheduler_hooks_by_prefix(): void {
		$hooks = array(
			( new ReflectionClass( IngestQueue::class ) )->getConstant( 'FLUSH_HOOK' ),
			( new ReflectionClass( CustomerFlusher::class ) )->getConstant( 'FLUSH_HOOK' ),
			( new ReflectionClass( OrderFlusher::class ) )->getConstant( 'FLUSH_HOOK' ),
			( new ReflectionClass( CatalogRemoveFlusher::class ) )->getConstant( 'FLUSH_HOOK' ),
		);

		$source = $this->uninstall_source();
		self::assertStringContainsString(
			'hook LIKE %s OR hook LIKE %s',
			$source,
			'uninstall.php must OR a second hook-prefix clause onto the Action Scheduler purge for smly_rec_* flush hooks (PRO-1337).'
		);
		self::assertStringContainsString(
			"esc_like( 'smly_rec_' )",
			$source,
			'uninstall.php must LIKE-sweep the smly_rec_ Action Scheduler hook prefix (PRO-1337).'
		);
		foreach ( $hooks as $hook ) {
			self::assertIsString( $hook );
			self::assertStringStartsWith(
				'smly_rec_',
				$hook,
				"Rec-engine flush hook '{$hook}' must live under the smly_rec_ prefix uninstall.php purges from actionscheduler_actions."
			);
		}
	}
}
