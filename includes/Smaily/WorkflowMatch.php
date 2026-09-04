<?php
/**
 * Immutable result returned by WorkflowResolverInterface.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Pairs the Smaily workflow id (the autoresponder to fire) with the
 * account_key identifying which credential set should drive the request.
 *
 * In Mode B/C account_key is always "default" — there's only one Smaily
 * account so the credential lookup is trivial. Mode A introduces several
 * account_keys (one per language); AutomationRouter must use the matched
 * key when constructing the Client instance for the call.
 *
 * Logically immutable: every consumer treats the constructor-set values as
 * final. The `readonly` keyword would enforce that at the language level,
 * but it requires PHP 8.1+ and the plugin's floor is 8.0 (PLUGIN.md
 * "Requires PHP: 8.0"). Once the floor moves, mark the three properties
 * `readonly` and drop this note.
 */
final class WorkflowMatch {

	public int $workflow_id;
	public string $account_key;
	public ?string $matched_language;

	public function __construct( int $workflow_id, string $account_key, ?string $matched_language = null ) {
		$this->workflow_id      = $workflow_id;
		$this->account_key      = $account_key;
		$this->matched_language = $matched_language;
	}
}
