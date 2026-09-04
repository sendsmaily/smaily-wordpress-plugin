<?php
/**
 * Coordinates multilingual workflow lookup and Smaily API dispatch.
 *
 * @package Smaily\Connect\Smaily
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily;

defined( 'ABSPATH' ) || exit;

/**
 * Single entry point for "fire a welcome / first_order / abandoned_cart
 * automation for this contact". Hides three concerns from the calling
 * hook layer (sub-PR 5):
 *
 *   1. Looking up the right workflow_id for the (trigger, language, mode)
 *      combination — delegated to a WorkflowResolverInterface.
 *   2. Picking the right Smaily credential set for the matched account
 *      key — delegated to a callable factory passed in at construction
 *      time (so Settings, tests, and CLI scaffolding can each supply
 *      their own credentials without coupling AutomationRouter to
 *      OptionsRepository).
 *   3. Mapping contact_data + additional_fields onto the Smaily API
 *      payload shape (`addresses` array with email + custom fields).
 *
 * PLUGIN.md §5/§9 lists the three trigger types this router handles.
 * Multilingual mapping logic lives in Multilingual\Router (sub-PR 4),
 * which implements WorkflowResolverInterface.
 *
 * Error model (refactored for sub-PR 5.C Flusher dispatch):
 *   - Returns true on success (workflow matched and API accepted).
 *   - Returns false when the dispatch is a terminal skip — no workflow
 *     mapped for (trigger, language), or email is missing. The Flusher
 *     marks these events `sent` (logically skipped) rather than `failed`,
 *     because a retry won't change the outcome.
 *   - Throws Smaily\ApiException when the API call itself failed (4xx,
 *     5xx, transport). The Flusher records the attempt and lets the
 *     retry policy take over.
 */
class AutomationRouter {

	/** @var callable(string $account_key): Client */
	private $client_factory;

	private WorkflowResolverInterface $resolver;

	/** The Client used by the last trigger_automation() that reached the API (F3-44). */
	private ?Client $last_client = null;

	/**
	 * @param WorkflowResolverInterface           $resolver       Workflow lookup strategy.
	 * @param callable(string $account_key): Client $client_factory Returns a configured Client
	 *                                                               for the given account key.
	 */
	public function __construct( WorkflowResolverInterface $resolver, callable $client_factory ) {
		$this->resolver       = $resolver;
		$this->client_factory = $client_factory;
	}

	/**
	 * The last HTTP exchange of the most recent trigger_automation() that
	 * reached Smaily, or null when the last call short-circuited before any
	 * request (no email / no workflow mapped). The Flusher records it in the
	 * Event Log (F3-44).
	 *
	 * @return array<string, mixed>|null
	 */
	public function last_exchange(): ?array {
		return $this->last_client !== null ? $this->last_client->last_exchange() : null;
	}

	/**
	 * Fires the workflow that matches the (trigger_type, language) pair.
	 *
	 * Returns true if a workflow was matched and the call to Smaily
	 * succeeded; false if no mapping existed (skip), the email was
	 * missing/invalid, or the API call failed. The hook layer can use
	 * the boolean to decide whether to enqueue a retry — for Phase 1 we
	 * deliberately don't throw, so partial failures don't bubble up into
	 * unrelated WP request paths.
	 *
	 * @param string               $trigger_type      One of "welcome",
	 *                                                "first_order",
	 *                                                "abandoned_cart".
	 * @param array<string, mixed> $contact_data      Must contain "email";
	 *                                                may contain "language"
	 *                                                and any number of
	 *                                                custom fields.
	 * @param array<string, mixed> $additional_fields Merged into the address
	 *                                                row alongside contact_data
	 *                                                (e.g. order context for
	 *                                                first_order).
	 */
	public function trigger_automation(
		string $trigger_type,
		array $contact_data,
		array $additional_fields = array()
	): bool {
		// Reset so last_exchange() reflects THIS call — null if it short-circuits
		// before any HTTP request (no email / no workflow mapped) — F3-44.
		$this->last_client = null;

		$email = isset( $contact_data['email'] ) ? (string) $contact_data['email'] : '';
		if ( $email === '' ) {
			return false;
		}

		$language = isset( $contact_data['language'] ) ? (string) $contact_data['language'] : null;

		$match = $this->resolver->resolve_workflow( $trigger_type, $language );
		if ( $match === null ) {
			return false;
		}

		$address = array_merge(
			array( 'email' => $email ),
			array_diff_key(
				$contact_data,
				array(
					'email'    => true,
					'language' => true,
				)
			),
			$additional_fields
		);

		$client            = ( $this->client_factory )( $match->account_key );
		$this->last_client = $client;

		// force_opt_in is always false, in every contact-sync preset (PRO-1716,
		// narrowing F3-48.4): a trigger enrols someone who is not a contact
		// yet, but it never overrides an unsubscribe the contact made in
		// Smaily. An undocumented Smaily param — see ../re/docs.
		//
		// Let ApiException bubble up — the Flusher (sub-PR 5.C) uses it to
		// distinguish transient failures (retry) from terminal skips
		// (false return).
		$client->trigger_automation( $match->workflow_id, array( $address ), false );

		return true;
	}
}
