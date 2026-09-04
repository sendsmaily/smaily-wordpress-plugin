<?php
/**
 * Migrator unit tests — exercise discovery + version-gating logic without
 * standing up a real WP install. The dbDelta() call inside apply() is the
 * one bit we can't test here; the integration suite (added later) covers
 * that path against a real test database.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\DB;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Constants;
use Smaily\Connect\DB\Migrator;

final class MigratorTest extends TestCase {

	private string $tmp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->tmp_dir = sys_get_temp_dir() . '/smaily-migrator-' . uniqid();
		mkdir( $this->tmp_dir, 0777, true );
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->tmp_dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_discover_returns_empty_array_when_directory_is_missing(): void {
		$migrator = new Migrator( $this->tmp_dir . '/does-not-exist' );
		self::assertSame( array(), $migrator->discover() );
	}

	public function test_discover_picks_up_files_matching_nnn_dash_slug_sql(): void {
		$this->seed_file( '001-create-event-queue.sql', 'CREATE TABLE foo;' );
		$this->seed_file( '002-create-backfill-job.sql', 'CREATE TABLE bar;' );

		$migrator = new Migrator( $this->tmp_dir );
		$found    = $migrator->discover();

		self::assertSame( array( 1, 2 ), array_keys( $found ) );
		self::assertStringEndsWith( '001-create-event-queue.sql', $found[1] );
		self::assertStringEndsWith( '002-create-backfill-job.sql', $found[2] );
	}

	public function test_discover_ignores_legacy_php_files_and_unnumbered_sql(): void {
		$this->seed_file( '001-create-event-queue.sql', 'CREATE TABLE foo;' );
		$this->seed_file( 'upgrade-1-3-0.php', '<?php // legacy' );
		$this->seed_file( 'create-something.sql', 'CREATE TABLE no_number;' );
		$this->seed_file( 'index.php', '<?php' );

		$migrator = new Migrator( $this->tmp_dir );
		$found    = $migrator->discover();

		self::assertSame( array( 1 ), array_keys( $found ) );
	}

	public function test_discover_sorts_numerically_not_lexically(): void {
		$this->seed_file( '001-a.sql', 'x' );
		$this->seed_file( '010-b.sql', 'x' );
		$this->seed_file( '002-c.sql', 'x' );
		$this->seed_file( '011-d.sql', 'x' );

		$migrator = new Migrator( $this->tmp_dir );
		$found    = $migrator->discover();

		self::assertSame(
			array( 1, 2, 10, 11 ),
			array_keys( $found ),
			'Migrations must sort numerically — lexical sort would put 010/011 before 002.'
		);
	}

	public function test_migrate_does_nothing_when_no_migrations_exist(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Constants::OPTION_SCHEMA_VERSION, 0 )
			->andReturn( 0 );

		Functions\expect( 'update_option' )->never();

		$migrator = new Migrator( $this->tmp_dir );
		self::assertSame( array(), $migrator->migrate() );
	}

	public function test_migrate_skips_versions_at_or_below_current(): void {
		$this->seed_file( '001-a.sql', 'CREATE TABLE foo;' );
		$this->seed_file( '002-b.sql', 'CREATE TABLE bar;' );

		Functions\expect( 'get_option' )
			->once()
			->with( Constants::OPTION_SCHEMA_VERSION, 0 )
			->andReturn( 2 );

		Functions\expect( 'update_option' )->never();

		$migrator = new Migrator( $this->tmp_dir );
		self::assertSame( array(), $migrator->migrate() );
	}

	public function test_discover_skips_zero_or_negative_version_prefix(): void {
		$this->seed_file( '000-zero.sql', 'x' );
		$this->seed_file( '001-real.sql', 'x' );

		$migrator = new Migrator( $this->tmp_dir );
		$found    = $migrator->discover();

		self::assertSame( array( 1 ), array_keys( $found ) );
	}

	public function test_migrate_applies_new_versions_and_records_each_in_option(): void {
		$this->seed_file(
			'001-create-foo.sql',
			'CREATE TABLE {prefix}smly_plus_foo (id BIGINT) {charset_collate};'
		);
		$this->seed_file(
			'002-create-bar.sql',
			'CREATE TABLE {prefix}smly_plus_bar (id BIGINT) {charset_collate};'
		);

		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public function get_charset_collate(): string {
				return 'DEFAULT CHARSET=utf8mb4';
			}
		};

		Functions\expect( 'get_option' )
			->once()
			->with( Constants::OPTION_SCHEMA_VERSION, 0 )
			->andReturn( 0 );

		$option_writes = array();
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value, $autoload = null ) use ( &$option_writes ): bool {
				$option_writes[] = compact( 'key', 'value' );
				return true;
			}
		);

		// dbDelta() is a WP-Admin upgrade.php helper. Brain Monkey doesn't
		// auto-load wp-admin/includes; stub the function so apply() doesn't
		// crash on require_once. The test asserts only that apply() reached
		// this stub with the substituted SQL.
		$dbdelta_calls = array();
		Functions\when( 'dbDelta' )->alias(
			static function ( string $sql ) use ( &$dbdelta_calls ): array {
				$dbdelta_calls[] = $sql;
				return array();
			}
		);

		$migrator = new Migrator( $this->tmp_dir );
		$applied  = $migrator->migrate();

		self::assertSame( array( 1, 2 ), $applied );

		self::assertCount( 2, $dbdelta_calls );
		// {prefix} → wp_ + {charset_collate} → DEFAULT CHARSET=utf8mb4
		self::assertStringContainsString( 'CREATE TABLE wp_smly_plus_foo', $dbdelta_calls[0] );
		self::assertStringContainsString( 'DEFAULT CHARSET=utf8mb4', $dbdelta_calls[0] );
		self::assertStringContainsString( 'CREATE TABLE wp_smly_plus_bar', $dbdelta_calls[1] );

		// Schema-version option was updated after each migration, not just
		// at the end — so a crash on file N+1 doesn't re-run file N.
		self::assertCount( 2, $option_writes );
		self::assertSame( 1, $option_writes[0]['value'] );
		self::assertSame( 2, $option_writes[1]['value'] );
		self::assertSame( Constants::OPTION_SCHEMA_VERSION, $option_writes[0]['key'] );
	}

	public function test_migrate_resumes_from_partial_state(): void {
		$this->seed_file( '001-a.sql', 'CREATE TABLE {prefix}foo (id BIGINT);' );
		$this->seed_file( '002-b.sql', 'CREATE TABLE {prefix}bar (id BIGINT);' );
		$this->seed_file( '003-c.sql', 'CREATE TABLE {prefix}baz (id BIGINT);' );

		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public function get_charset_collate(): string {
				return '';
			}
		};

		// Migration 1 already applied (schema_version = 1) — migrate() should
		// pick up at 2 and apply 2 + 3 only.
		Functions\when( 'get_option' )->justReturn( 1 );

		$option_writes = array();
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$option_writes ): bool {
				$option_writes[] = $value;
				return true;
			}
		);

		$dbdelta_calls = 0;
		Functions\when( 'dbDelta' )->alias(
			static function () use ( &$dbdelta_calls ): array {
				++$dbdelta_calls;
				return array();
			}
		);

		$applied = ( new Migrator( $this->tmp_dir ) )->migrate();

		self::assertSame( array( 2, 3 ), $applied );
		self::assertSame( 2, $dbdelta_calls );
		self::assertSame( array( 2, 3 ), $option_writes );
	}

	public function test_migrate_skips_empty_files_without_calling_dbdelta(): void {
		$this->seed_file( '001-empty.sql', '' );

		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public function get_charset_collate(): string {
				return '';
			}
		};

		Functions\when( 'get_option' )->justReturn( 0 );

		$option_writes = array();
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$option_writes ): bool {
				$option_writes[] = $value;
				return true;
			}
		);

		$dbdelta_calls = 0;
		Functions\when( 'dbDelta' )->alias(
			static function () use ( &$dbdelta_calls ) {
				++$dbdelta_calls;
				return array();
			}
		);

		$applied = ( new Migrator( $this->tmp_dir ) )->migrate();

		self::assertSame( array( 1 ), $applied, 'Empty file still counts as applied (idempotent no-op).' );
		self::assertSame( 0, $dbdelta_calls, 'No dbDelta call for empty SQL content.' );
		self::assertSame( array( 1 ), $option_writes );
	}

	private function seed_file( string $name, string $contents ): void {
		file_put_contents( $this->tmp_dir . '/' . $name, $contents );
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $entry ) {
			is_dir( $entry ) ? $this->remove_dir( $entry ) : unlink( $entry );
		}

		rmdir( $dir );
	}
}
