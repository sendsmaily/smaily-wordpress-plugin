<?php
/**
 * Multilingual workflow router — implementation of WorkflowResolverInterface.
 *
 * @package Smaily\Connect\Multilingual
 */

declare(strict_types=1);

namespace Smaily\Connect\Multilingual;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables: interpolated values are $wpdb->prepare()d (dynamic IN() lists build placeholder strings); object-cache is N/A for a write-through queue / cleanup / DDL path.

use Smaily\Connect\Smaily\WorkflowMatch;
use Smaily\Connect\Smaily\WorkflowResolverInterface;

/**
 * Looks up Smaily workflow_id + account_key for a given (trigger, language)
 * pair, applying the site's multilingual mode (PLUGIN.md §4):
 *
 *   - Mode A — per-language Smaily accounts. account_key on each row
 *     identifies which credential set drives the API call. Language is
 *     part of the lookup key.
 *   - Mode B — single account, per-language workflows. account_key is
 *     always 'default'. Language is part of the lookup key.
 *   - Mode C — single workflow with conditional branching inside Smaily.
 *     Language is irrelevant to the lookup; account_key is 'default'.
 *     The plugin passes the contact's language as a custom field for
 *     Smaily's branching engine to consume.
 *   - 'single' — single-language site (no multilingual plugin detected).
 *     Effectively Mode C without branching.
 *
 * Mapping rows live in {prefix}smly_plus_automation_mapping (migration
 * 003-create-smly-plus-automation-mapping.sql). The `is_default_fallback`
 * column marks the row to use when no exact (trigger, language) match
 * exists — that handles users whose detected language is configured but
 * not yet mapped (PLUGIN.md §4 Variant 1: "Default-fallback valitakse
 * radio-button'iga ühe keele-rea peal").
 *
 * Reads the mode from the smly_plus_multilingual_mode option, defaulting
 * to 'single' until Settings UI (sub-PR 6) populates it explicitly.
 */
final class Router implements WorkflowResolverInterface {

	public const OPTION_MODE  = 'smly_plus_multilingual_mode';
	public const TABLE_SUFFIX = 'smly_plus_automation_mapping';

	public const MODE_A      = 'A';
	public const MODE_B      = 'B';
	public const MODE_C      = 'C';
	public const MODE_SINGLE = 'single';

	public const LANGUAGE_DEFAULT = 'default';

	public function resolve_workflow( string $trigger_type, ?string $language ): ?WorkflowMatch {
		$mode  = $this->current_mode();
		$lang  = $this->effective_language( $mode, $language );
		$match = $this->find_mapping( $trigger_type, $lang );

		if ( $match !== null ) {
			return $match;
		}

		// No exact match — try the row marked as default fallback for the trigger.
		return $this->find_fallback_mapping( $trigger_type );
	}

	/**
	 * Returns the multilingual mode currently configured for this site.
	 */
	public function current_mode(): string {
		$mode = get_option( self::OPTION_MODE, self::MODE_SINGLE );
		$mode = is_string( $mode ) ? $mode : self::MODE_SINGLE;

		return in_array(
			$mode,
			array( self::MODE_A, self::MODE_B, self::MODE_C, self::MODE_SINGLE ),
			true
		) ? $mode : self::MODE_SINGLE;
	}

	/**
	 * Picks the language slug the lookup should use. Modes C and 'single'
	 * collapse every language into the 'default' bucket because the
	 * mapping has a single row per trigger.
	 */
	private function effective_language( string $mode, ?string $language ): string {
		if ( $mode === self::MODE_C || $mode === self::MODE_SINGLE ) {
			return self::LANGUAGE_DEFAULT;
		}

		return $language !== null && $language !== '' ? $language : self::LANGUAGE_DEFAULT;
	}

	private function find_mapping( string $trigger_type, string $language ): ?WorkflowMatch {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// MySQL forbids parameterising table names in prepared statements;
		// $table is composed from $wpdb->prefix + a private const so the
		// interpolation is safe. Real arguments use %s placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT workflow_id, account_key, language FROM {$table} WHERE trigger_type = %s AND language = %s LIMIT 1",
				$trigger_type,
				$language
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return $this->row_to_match( $row );
	}

	private function find_fallback_mapping( string $trigger_type ): ?WorkflowMatch {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// See find_mapping() — table-name interpolation rationale.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT workflow_id, account_key, language FROM {$table} WHERE trigger_type = %s AND is_default_fallback = 1 LIMIT 1",
				$trigger_type
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return $this->row_to_match( $row );
	}

	/**
	 * Converts a DB row into a WorkflowMatch, or null when no row was found.
	 *
	 * @param array<string, mixed>|null $row
	 */
	private function row_to_match( $row ): ?WorkflowMatch {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$workflow_id = isset( $row['workflow_id'] ) ? (int) $row['workflow_id'] : 0;
		if ( $workflow_id <= 0 ) {
			return null;
		}

		return new WorkflowMatch(
			$workflow_id,
			isset( $row['account_key'] ) ? (string) $row['account_key'] : 'default',
			isset( $row['language'] ) ? (string) $row['language'] : null
		);
	}
}
