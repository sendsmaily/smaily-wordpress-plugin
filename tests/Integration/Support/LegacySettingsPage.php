<?php
/**
 * Test-support helper — writes the subscriber-sync options exactly the way the
 * pre-3.x legacy settings page did, so an "upgraded store" fixture is the real
 * shape rather than a hand-typed guess.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

defined( 'ABSPATH' ) || exit;

use Smaily_Connect\Includes\Options;

/**
 * The legacy WP Settings API page registered
 * `Options::SUBSCRIBER_SYNC_FIELDS_OPTION` with
 * `Sanitizer::sanitize_subscriber_sync_fields()` as its sanitize_callback, so
 * whatever that method returned IS what every store configured before the
 * wizard has on disk. The class was deleted with the rest of the legacy
 * settings-page view layer (F3-45, commit 9a02618) — `save()` below is its
 * method body copied verbatim out of `9a02618^:admin/smaily-admin-sanitizer
 * .class.php`, still reading the same `Options` const (which is untouched and
 * has held the same 13 keys since the plugin's first release).
 *
 * Verified before use: both were run side by side over the shapes a merchant
 * could post (nothing ticked, some ticked, all ticked, a box posted as '0')
 * and returned identical arrays.
 */
final class LegacySettingsPage {

	/**
	 * Save a legacy settings-page submission and return the stored value.
	 *
	 * @param array<string, string> $posted The ticked checkboxes, as the legacy
	 *                                      form posted them (`'on'` per field).
	 *
	 * @return array<string, bool> The option value now on disk.
	 */
	public static function save_subscriber_sync_fields( array $posted ): array {
		$stored = self::sanitize( $posted );
		update_option( Options::SUBSCRIBER_SYNC_FIELDS_OPTION, $stored );

		return $stored;
	}

	/**
	 * Save the legacy "Enable Subscriber Synchronization" checkbox and return
	 * the stored value. The page registered
	 * `Options::SUBSCRIBER_SYNC_ENABLED_OPTION` with `rest_sanitize_boolean` as
	 * its sanitize_callback and the checkbox posted `'1'` when ticked, nothing
	 * at all when not — which the Settings API hands the callback as null.
	 *
	 * @param string|null $posted The checkbox as the legacy form posted it.
	 *
	 * @return bool The option value now on disk.
	 */
	public static function save_subscriber_sync_enabled( ?string $posted ): bool {
		$stored = rest_sanitize_boolean( $posted );
		update_option( Options::SUBSCRIBER_SYNC_ENABLED_OPTION, $stored );

		return $stored;
	}

	/**
	 * @param array<string, string> $input
	 *
	 * @return array<string, bool>
	 */
	private static function sanitize( array $input ): array {
		$default_fields = Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS;

		$sanitized = array();
		foreach ( $default_fields as $field => $default_value ) {
			if ( $default_value === true ) {
				$sanitized[ $field ] = true;
				continue;
			}

			$sanitized[ $field ] = ! empty( $input[ $field ] ) && $input[ $field ] !== '0';
		}

		return $sanitized;
	}
}
