<?php
/**
 * Test-support helper — tails wp-content/debug.log so a test can
 * assert "no PHP Fatal/Warning from our code was logged during this
 * request lifecycle".
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Captures the file-size cursor at construction; methods after that
 * read only the *new* tail of debug.log written since the cursor.
 *
 * Usage:
 *   $log = DebugLogReader::start();
 *   // ... exercise the system under test ...
 *   $errors = $log->fatals_and_warnings_from_plugin();
 *   self::assertEmpty( $errors );
 *
 * The "from_plugin" filter is intentional — third-party plugins (WC,
 * Polylang) emit notices in wp-env that we don't own and shouldn't
 * flag as our regressions.
 */
final class DebugLogReader {

	private string $path;
	private int $cursor;

	public static function start(): self {
		return new self( WP_CONTENT_DIR . '/debug.log' );
	}

	private function __construct( string $path ) {
		$this->path   = $path;
		$this->cursor = file_exists( $path ) ? (int) filesize( $path ) : 0;
	}

	public function new_tail(): string {
		if ( ! file_exists( $this->path ) ) {
			return '';
		}
		$size = (int) filesize( $this->path );
		if ( $size <= $this->cursor ) {
			return '';
		}
		$fh = fopen( $this->path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $fh === false ) {
			return '';
		}
		fseek( $fh, $this->cursor );
		$contents = (string) fread( $fh, $size - $this->cursor );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $contents;
	}

	/**
	 * Returns lines that contain PHP Fatal / Warning AND mention our
	 * plugin's class namespace or file path. Third-party noise is
	 * filtered out so the test doesn't flake on WC / Polylang notices.
	 *
	 * @return array<int, string>
	 */
	public function fatals_and_warnings_from_plugin(): array {
		$tail = $this->new_tail();
		if ( $tail === '' ) {
			return array();
		}
		$out = array();
		foreach ( explode( "\n", $tail ) as $line ) {
			$line_trim = trim( $line );
			if ( $line_trim === '' ) {
				continue;
			}
			$is_severe = (
				stripos( $line_trim, 'PHP Fatal' ) !== false ||
				stripos( $line_trim, 'PHP Parse error' ) !== false ||
				stripos( $line_trim, 'PHP Warning' ) !== false
			);
			if ( ! $is_severe ) {
				continue;
			}
			$mentions_plugin = (
				stripos( $line_trim, 'smaily-connect' ) !== false ||
				stripos( $line_trim, 'Smaily\\Connect' ) !== false ||
				stripos( $line_trim, 'Smaily_Connect' ) !== false
			);
			if ( $mentions_plugin ) {
				$out[] = $line_trim;
			}
		}
		return $out;
	}
}
