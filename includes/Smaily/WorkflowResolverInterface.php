<?php
/**
 * Contract for resolving (trigger, language, account) to a Smaily workflow.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Abstracts the multilingual workflow-lookup so AutomationRouter doesn't
 * need to know about Multilingual mode A/B/C, the smly_plus_automation_mapping
 * table layout, or the WPML/Polylang/TranslatePress detector chain.
 *
 * Phase 1 sub-PR 4 provides the production implementation in
 * Smaily\Connect\Multilingual\Router. Tests for this sub-PR substitute a
 * trivial in-memory stub.
 */
interface WorkflowResolverInterface {

	/**
	 * Resolves the Smaily workflow that should fire for a given trigger.
	 *
	 * @param string      $trigger_type One of "welcome", "first_order", "abandoned_cart".
	 *                                  "order_confirmation" and "shipping_confirmation"
	 *                                  rows exist in the mapping table (PRO-1504) but no
	 *                                  caller passes them here yet — the transactional
	 *                                  sender is a later stage.
	 * @param string|null $language     Detected language code (ISO 639-1 / locale slug)
	 *                                  or null for single-language sites.
	 *
	 * @return WorkflowMatch|null The matched workflow + credentials set, or null
	 *                            when no mapping exists (caller treats it as a skip).
	 */
	public function resolve_workflow( string $trigger_type, ?string $language ): ?WorkflowMatch;
}
