<?php
/**
 * Integration: a clean plugin activate + REST round-trip leaves no
 * PHP Fatal/Warning lines from our code in wp-content/debug.log.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Tests\Integration\Support\DebugLogReader;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;

/**
 * What Faas-2 bug this catches:
 *
 *   Sub-PR 2.H.4 / 2.H.5 — REST endpoint dispatch triggered a legacy
 *   hook that called wp-admin-only functions (add_settings_error,
 *   get_current_screen), throwing "Call to undefined function" fatals
 *   at request time. Unit tests passed because the legacy hook was
 *   never wired into them; only a real WP request lifecycle surfaced
 *   the crash.
 *
 *   This test captures the debug.log cursor BEFORE exercising every
 *   REST endpoint, then asserts no PHP Fatal / Warning line that
 *   mentions OUR plugin namespace was appended. Third-party noise
 *   (WC translation warnings, Polylang notices) is filtered out so
 *   the assertion doesn't flake on env quirks.
 */
final class DebugLogCleanTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();
	}

	public function test_no_plugin_errors_during_full_settings_cycle(): void {
		$log = DebugLogReader::start();

		// Hit every functional tab once. We don't care about response
		// shape here — SettingsRoundTripTest covers that. We only care
		// that the dispatch didn't trip a PHP Fatal / Warning that
		// mentions our namespace.
		RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'connection',
				'data' => array(
					'smailyCredentials' => array(
						'subdomain' => 'debugclean',
						'username'  => 'alice',
						'password'  => 'plain',
					),
					'multilingualMode'  => 'single',
				),
			)
		);
		RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'subscribers',
				'data' => array(
					'subscriberSyncEnabled'         => true,
					'syncFields'                    => array( 'first_name' ),
					'wordpressSubscriptionCheckbox' => true,
					'checkoutSubscriptionCheckbox'  => false,
				),
			)
		);
		RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'woocommerce',
				'data' => array(
					'welcomeEnabled'             => true,
					'firstOrderEnabled'          => false,
					'abandonedCartEnabled'       => false,
					'abandonedCartCutoffMinutes' => 30,
					'automationMappings'         => array(),
				),
			)
		);
		RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'recommendations',
				'data' => array(
					// 3.9: browse tracking is the only Step-4 preference; the
					// per-domain sync toggles were removed (connect => sync all).
					'recEngineFeatures' => array(
						'trackBrowsing' => false,
					),
				),
			)
		);
		RestRequestHelper::get( '/backfill/status', array( 'job_type' => 'contacts' ) );
		RestRequestHelper::get( '/workflows', array( 'account_key' => 'default' ) );

		$errors = $log->fatals_and_warnings_from_plugin();

		self::assertEmpty(
			$errors,
			"REST round-trip wrote " . count( $errors ) . " plugin-attributed errors to debug.log:\n" .
			implode( "\n", $errors )
		);
	}
}
