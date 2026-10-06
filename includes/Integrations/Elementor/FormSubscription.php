<?php
/**
 * Turns one Elementor Pro form submission into a Smaily subscriber.
 *
 * @package Smaily\Connect\Integrations\Elementor
 */

declare(strict_types=1);

namespace Smaily\Connect\Integrations\Elementor;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\REST\WorkflowsEndpoint;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\Client;
use Smaily\Connect\Support\DebugLog;

/**
 * The logic behind the "Smaily" action under Elementor Pro's Actions After
 * Submit (PRO-3806). The action class itself extends an Elementor Pro class
 * and only adapts Elementor's record/ajax-handler objects to this one, so
 * everything that decides WHAT reaches Smaily lives here, testable without
 * Elementor.
 *
 * Rules that are load-bearing (DECISIONS PRO-3806):
 *   - Only explicitly mapped fields are sent (email, optional name, the
 *     custom mappings) plus the source fields below — never the whole
 *     submission.
 *   - A signup through the form is fresh explicit consent: the contact is
 *     sent with `is_unsubscribed = 0`, so a contact who unsubscribed earlier
 *     is subscribed again. In contact mode nothing is sent unless the consent
 *     field is configured AND ticked.
 *   - The workflow is triggered with `force_opt_in = false`: the subscriber
 *     write before it already subscribed the contact.
 *   - The subscriber write decides the visitor's outcome. A failed workflow
 *     trigger after a successful write is logged, not shown to the visitor.
 *   - A double submit of the same form + email inside DEDUPE_TTL triggers the
 *     workflow once (finite-TTL transient).
 */
final class FormSubscription {

	public const MODE_NEWSLETTER = 'newsletter';
	public const MODE_CONTACT    = 'contact';

	/**
	 * Contact fields recording where and when the contact signed up. They
	 * are MERCHANT-VISIBLE and permanent (segments and templates are built on
	 * them) — add names, never rename one. The time uses the shape of the
	 * automation markers (`Y-m-d H:i:s`, UTC), so a segment can compare them.
	 */
	public const FIELD_FORM_NAME    = 'elementor_form_name';
	public const FIELD_FORM_URL     = 'elementor_form_url';
	public const FIELD_SUBMITTED_AT = 'elementor_form_submitted_at';

	public const OUTCOME_SKIPPED       = 'skipped';
	public const OUTCOME_INVALID_EMAIL = 'invalid_email';
	public const OUTCOME_FAILED        = 'failed';
	public const OUTCOME_SUBSCRIBED    = 'subscribed';

	/** How long a form + email pair counts as already triggered. */
	public const DEDUPE_TTL = 5 * MINUTE_IN_SECONDS;

	/** How long the editor's workflow dropdown reuses Smaily's list. */
	public const WORKFLOWS_TTL = 5 * MINUTE_IN_SECONDS;

	private const DEDUPE_PREFIX       = 'smaily_connect_elementor_';
	private const WORKFLOWS_TRANSIENT = 'smaily_connect_elementor_workflows';

	/** Contact fields a custom mapping may not write. */
	private const RESERVED_FIELDS = array(
		'email',
		'is_unsubscribed',
		self::FIELD_FORM_NAME,
		self::FIELD_FORM_URL,
		self::FIELD_SUBMITTED_AT,
	);

	/** @var callable(): Client */
	private $client_factory;

	private string $error = '';

	/**
	 * @param callable(): Client $client_factory Builds the Smaily client from the saved credentials;
	 *                                           throws \RuntimeException when none are saved.
	 */
	public function __construct( callable $client_factory ) {
		$this->client_factory = $client_factory;
	}

	/**
	 * Why the last submit() failed — a technical, credential-free line for the
	 * site editor, never for the visitor.
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * Send one submission to Smaily.
	 *
	 * @param array<string, mixed>  $settings The form's settings: the action's `smaily_*` controls
	 *                                        plus Elementor's `id` and `form_name`.
	 * @param array<string, string> $values   Submitted value per form field ID.
	 * @param string                $page_url The page the form was submitted on.
	 *
	 * @return string One of the OUTCOME_* constants.
	 */
	public function submit( array $settings, array $values, string $page_url ): string {
		$this->error = '';

		if ( self::setting( $settings, 'smaily_mode' ) === self::MODE_CONTACT ) {
			$consent_field = self::setting( $settings, 'smaily_consent_field' );
			if ( $consent_field === '' || trim( $values[ $consent_field ] ?? '' ) === '' ) {
				return self::OUTCOME_SKIPPED;
			}
		}

		$email_field = self::setting( $settings, 'smaily_email_field' );
		$email       = strtolower( trim( $values[ $email_field ] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return self::OUTCOME_INVALID_EMAIL;
		}

		$contact = $this->contact( $settings, $values, $email, $page_url );

		try {
			$client = ( $this->client_factory )();
			$reply  = $client->upsert_subscribers( array( $contact ) );
		} catch ( ApiException | \RuntimeException $e ) {
			$this->error = $e->getMessage();
			return self::OUTCOME_FAILED;
		}

		$code = (int) ( $reply['code'] ?? 0 );
		if ( $code !== Client::CODE_OK ) {
			$this->error = sprintf( 'Smaily API returned code %d for POST contact', $code );
			return self::OUTCOME_FAILED;
		}

		$this->trigger_workflow( $client, $settings, $email );

		return self::OUTCOME_SUBSCRIBED;
	}

	/**
	 * The account's workflows as id => name, cached for WORKFLOWS_TTL; empty
	 * when Smaily cannot be read.
	 *
	 * @return array<string, string>
	 */
	public function workflows(): array {
		$workflows = get_transient( self::WORKFLOWS_TRANSIENT );
		if ( is_array( $workflows ) ) {
			return $workflows;
		}

		try {
			$workflows = array_column( WorkflowsEndpoint::normalise( ( $this->client_factory )()->list_autoresponders() ), 'name', 'id' );
		} catch ( ApiException | \RuntimeException $e ) {
			return array();
		}
		set_transient( self::WORKFLOWS_TRANSIENT, $workflows, self::WORKFLOWS_TTL );

		return $workflows;
	}

	/**
	 * @param array<string, mixed>  $settings
	 * @param array<string, string> $values
	 *
	 * @return array<string, mixed>
	 */
	private function contact( array $settings, array $values, string $email, string $page_url ): array {
		$contact = array(
			'email'           => $email,
			'is_unsubscribed' => 0,
		);

		$mappings = array();
		$name     = self::setting( $settings, 'smaily_name_field' );
		if ( $name !== '' ) {
			$mappings['name'] = $name;
		}
		$rows = isset( $settings['smaily_fields'] ) && is_array( $settings['smaily_fields'] ) ? $settings['smaily_fields'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$smaily_field = strtolower( self::setting( $row, 'smaily_field' ) );
			$form_field   = self::setting( $row, 'form_field' );
			if ( $form_field === '' || preg_match( '/^[a-z0-9_]{1,64}$/', $smaily_field ) !== 1 || in_array( $smaily_field, self::RESERVED_FIELDS, true ) ) {
				continue;
			}
			$mappings[ $smaily_field ] = $form_field;
		}

		// An empty value is left out: Smaily reads an empty field as "wipe it".
		foreach ( $mappings as $smaily_field => $form_field ) {
			$value = sanitize_text_field( $values[ $form_field ] ?? '' );
			if ( $value !== '' ) {
				$contact[ $smaily_field ] = $value;
			}
		}

		$form_name = sanitize_text_field( self::setting( $settings, 'form_name' ) );
		if ( $form_name !== '' ) {
			$contact[ self::FIELD_FORM_NAME ] = $form_name;
		}
		$url = esc_url_raw( $page_url );
		if ( $url !== '' ) {
			$contact[ self::FIELD_FORM_URL ] = $url;
		}
		$contact[ self::FIELD_SUBMITTED_AT ] = gmdate( 'Y-m-d H:i:s' );

		return $contact;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function trigger_workflow( Client $client, array $settings, string $email ): void {
		$workflow_id = (int) self::setting( $settings, 'smaily_workflow_id' );
		if ( $workflow_id <= 0 ) {
			return;
		}

		$key = self::DEDUPE_PREFIX . md5( self::setting( $settings, 'id' ) . '|' . self::setting( $settings, 'form_name' ) . '|' . $email );
		if ( get_transient( $key ) !== false ) {
			return;
		}
		set_transient( $key, 1, self::DEDUPE_TTL );

		try {
			$reply = $client->trigger_automation( $workflow_id, array( array( 'email' => $email ) ), false );
			$code  = (int) ( $reply['code'] ?? 0 );
			if ( $code !== Client::CODE_OK ) {
				DebugLog::write( sprintf( '[smaily-connect elementor-form] workflow %d not triggered: Smaily code %d', $workflow_id, $code ) );
			}
		} catch ( ApiException $e ) {
			DebugLog::write( sprintf( '[smaily-connect elementor-form] workflow %d not triggered: %s', $workflow_id, $e->getMessage() ) );
		}
	}

	/**
	 * A setting as a trimmed string ('' when absent or not scalar).
	 *
	 * @param array<mixed, mixed> $settings
	 */
	private static function setting( array $settings, string $key ): string {
		return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}
}
