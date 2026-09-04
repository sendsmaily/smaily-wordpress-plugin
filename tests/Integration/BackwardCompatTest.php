<?php
/**
 * Integration: pilot upgrade (Smaily Connect 1.x → 2.0) backward-compat
 * guarantees — the two P1 fixes from the migration audit.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Activation;
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Constants;
use Smaily\Connect\Integrations\WooCommerce\LegacyHookBridge;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;

/**
 * The BETA fork loads the legacy Smaily_Connect plugin verbatim alongside
 * the new namespaced code (same slug, in-place upgrade). This test pins
 * the two pilot-blocking findings:
 *
 *   P1 #2 — new-table migrations were activation-only, so a `wp plugin
 *           update` / `--force` file-overwrite (which never fires
 *           register_activation_hook) left the tables missing. The
 *           admin_init version-check must run them on a version bump.
 *
 *   P1 #1 — both the legacy subscriber-sync and the new HookHandler bind
 *           woocommerce_created_customer et al. Once the wizard is
 *           finished the legacy hooks must be stripped so the contact
 *           syncs exactly once.
 *
 * Plus the credential carry-over the new path relies on: Settings\
 * Credentials must decrypt the legacy smaily_connect_api_credentials row
 * (same Cypher) so the new sync has working auth without re-running setup.
 *
 * tearDown restores a fully-migrated, version-stamped state via
 * Activation::run() so a test that rewinds smly_plus_schema_version can't
 * leak that into SchemaMigrationTest.
 */
final class BackwardCompatTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
	}

	protected function tearDown(): void {
		global $wpdb;
		// A test may drop a table or rewind the schema pointer. Force a clean,
		// CACHE-INDEPENDENT full re-migration so the rest of the suite
		// (SchemaMigrationTest) sees every table back: delete the pointer
		// straight from the row, flush the object cache so the migrator can't
		// read a stale value, then re-run from zero.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Constants::OPTION_SCHEMA_VERSION ) );
		wp_cache_flush();
		Activation::run();
		parent::tearDown();
	}

	public function test_upgrade_trigger_runs_migrations_when_stored_version_trails(): void {
		// Simulate a file-overwrite upgrade: the code is at the current
		// version but the stamp is older, and the schema pointer is rewound
		// so we can observe the migrator actually re-run. dbDelta is
		// idempotent, so the tables themselves are never at risk.
		update_option( Activation::OPTION_PLUGIN_VERSION, '0.0.0-pre' );
		update_option( Constants::OPTION_SCHEMA_VERSION, 0 );

		Bootstrap::instance()->maybe_run_upgrade();

		self::assertSame(
			(string) SMAILY_CONNECT_VERSION,
			(string) get_option( Activation::OPTION_PLUGIN_VERSION ),
			'Upgrade-detect must stamp the running version once it has migrated.'
		);
		self::assertGreaterThan(
			0,
			(int) get_option( Constants::OPTION_SCHEMA_VERSION ),
			'The migrator must have re-run — the schema pointer advanced from the rewound sentinel.'
		);

		global $wpdb;
		$table = $wpdb->prefix . 'smly_rec_event_queue';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		self::assertSame(
			$table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
			'The new rec-engine table must exist after an upgrade-detect run.'
		);
	}

	public function test_upgrade_trigger_is_noop_when_version_matches(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'smly_rec_visitor';

		update_option( Activation::OPTION_PLUGIN_VERSION, (string) SMAILY_CONNECT_VERSION );
		// Drop a table directly (bypasses option caches) so we can observe
		// whether the migrator runs: a matching version must leave it absent.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		Bootstrap::instance()->maybe_run_upgrade();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		self::assertNotSame(
			$table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
			'A matching version must early-return — the migrator must NOT recreate the dropped table.'
		);
		// tearDown forces a full re-migration that recreates it.
	}

	public function test_upgrade_trigger_deletes_the_retired_force_opt_in_option(): void {
		// PRO-1716 retired the "Force opt-in on automation triggers" setting
		// and removed every reader; a store that had enabled it kept a truthy
		// option nothing reads and the merchant can no longer see or change.
		// The upgrade sweep must clear it (PRO-1897). Driven end-to-end through
		// the real entry point — a trailing version stamp, then the same
		// admin_init call a file-overwrite upgrade makes. This used to have to
		// call Activation::run() directly: EnvScrub left a stale per-key cache
		// on the autoload=false version stamp, so re-arming the upgrade-detect
		// mid-suite was impossible (PRO-1943).
		$retired = 'smly_plus_contact_sync_automation_force_opt_in';
		update_option( $retired, '1' );
		update_option( Activation::OPTION_PLUGIN_VERSION, '0.0.0-pre' );

		Bootstrap::instance()->maybe_run_upgrade();

		self::assertSame(
			(string) SMAILY_CONNECT_VERSION,
			(string) get_option( Activation::OPTION_PLUGIN_VERSION ),
			'The upgrade-detect must have re-armed and run — regardless of what ran before it (PRO-1943).'
		);
		self::assertFalse(
			get_option( $retired ),
			'The retired force-opt-in option must not survive an upgrade run (PRO-1897).'
		);
	}

	public function test_legacy_subscriber_sync_is_stripped_after_wizard_finish(): void {
		if ( ! $this->legacy_sync_present( 'woocommerce_created_customer' ) ) {
			self::markTestSkipped(
				'Legacy Subscriber_Synchronization is not registered on woocommerce_created_customer ' .
				'in this environment — the double-sync premise does not apply here.'
			);
		}

		$removed = LegacyHookBridge::deregister_subscriber_sync();

		self::assertNotEmpty( $removed, 'deregister() must report the hooks it stripped.' );
		self::assertContains(
			'woocommerce_created_customer:11',
			$removed,
			'The legacy contact-sync binding (priority 11) must be among the stripped hooks.'
		);
		self::assertFalse(
			$this->legacy_sync_present( 'woocommerce_created_customer' ),
			'After Finish the legacy subscriber-sync must be gone so only the new path syncs.'
		);
	}

	public function test_new_credentials_decrypt_legacy_encrypted_option(): void {
		if ( ! class_exists( '\\Smaily_Connect\\Includes\\Cypher' ) ) {
			self::markTestSkipped( 'Legacy Cypher class unavailable.' );
		}

		$encrypted = \Smaily_Connect\Includes\Cypher::encrypt( 'sk-legacy-secret' );

		// AUDIT FINDING: the legacy plugin guards its credential option with a
		// pre_update_option_smaily_connect_api_credentials filter that drops
		// foreign writes — so a plain update_option() here is silently
		// ignored. Strip it for the seed; production writes go through the
		// legacy's own settings handler, which is what the filter expects.
		remove_all_filters( 'pre_update_option_' . Credentials::LEGACY_OPTION_KEY );
		update_option(
			Credentials::LEGACY_OPTION_KEY,
			array(
				'subdomain' => 'miumjau',
				'username'  => 'api-user',
				'password'  => $encrypted,
			)
		);

		$cred = ( new Credentials() )->get( 'default' );

		self::assertInstanceOf( CredentialSet::class, $cred );
		self::assertTrue( $cred->is_complete() );
		self::assertSame( 'miumjau', $cred->subdomain );
		self::assertSame( 'api-user', $cred->username );
		self::assertSame(
			'sk-legacy-secret',
			$cred->password,
			'The legacy-encrypted password must decrypt through the new Credentials reader (Cypher compatibility).'
		);
	}

	private function legacy_sync_present( string $hook ): bool {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && isset( $function[0] ) && is_object( $function[0] )
					&& is_a( $function[0], LegacyHookBridge::LEGACY_SUBSCRIBER_SYNC ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
