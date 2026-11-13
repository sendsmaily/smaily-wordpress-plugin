<?php
/**
 * A $wpdb that answers only Migrator's column check (CON-291).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Support;

/**
 * `SHOW COLUMNS … LIKE 'sent_count'`: the column's name when $present is
 * true, no row when false, a database error when null.
 */
final class SchemaColumnWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** How many times the column was read. */
	public int $reads = 0;

	/** The last query read. */
	public string $last_sql = '';

	public ?bool $present;

	public function __construct( ?bool $present = true ) {
		$this->present = $present;
	}

	/**
	 * @param mixed ...$args
	 */
	public function prepare( string $sql, ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
	}

	public function get_var( string $sql ): ?string {
		++$this->reads;
		$this->last_sql   = $sql;
		$this->last_error = $this->present === null ? 'Lost connection to server' : '';

		return $this->present === true ? 'sent_count' : null;
	}
}
