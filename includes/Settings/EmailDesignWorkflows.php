<?php
/**
 * The Smaily workflow ids the engine pushed for the plugin's emails.
 *
 * @package Smaily\Connect\Settings
 */

declare(strict_types=1);

namespace Smaily\Connect\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Each email the plugin starts has a design in the engine (contract §22).
 * When the engine has pushed that design to Smaily, its design list (§23)
 * names the Smaily workflow that sends the email and the Smaily account that
 * holds the workflow (`smaily_subdomain`). The plugin starts the email with
 * that workflow in place of the mapped one, but only when it sends the email
 * through that account (CON-218): Smaily numbers workflows per account.
 *
 * The list is read when the email settings open and when the store returns
 * from the design page — never before a send. This class keeps the ids and
 * accounts of the last read, and the send paths read only this copy
 * (Multilingual\Router). The template id is not used (contract v1.18.0 makes
 * it informational).
 */
final class EmailDesignWorkflows {

	/** autoload=false: read at send time only. */
	public const OPTION = 'smly_rec_email_workflows';

	/** The plugin's trigger types and the §22 key of each one's email. */
	public const EMAIL_KEYS = array(
		'welcome'               => 'plugin.welcome',
		'first_order'           => 'plugin.first_order',
		'abandoned_cart'        => 'plugin.abandoned_cart',
		'follow_up'             => 'plugin.follow_up',
		'order_confirmation'    => 'transactional.order_confirmation',
		'shipping_confirmation' => 'transactional.shipping_confirmation',
	);

	/** §23 kinds of the plugin's own emails; an engine email's ids are not the plugin's to use. */
	private const KINDS = array( 'plugin', 'transactional' );

	/** §23 `sent_through` values; both start a Smaily workflow since contract v1.18.0. */
	private const SENT_THROUGH = array( 'workflow', 'transactional' );

	/**
	 * Smaily states whose ids belong to a pushed version. An unknown state
	 * counts as `not_available` (§23), whose ids are null.
	 */
	private const STATES_WITH_IDS = array( 'pending', 'pushed', 'failed' );

	/** §23 `smaily_subdomain`: letters, digits and `-`, at most 64 characters. */
	private const SUBDOMAIN_PATTERN = '/^[a-z0-9-]{1,64}$/';

	/**
	 * Keep the workflow id and the account of each email from one §23 read,
	 * replacing those of the last one: an email whose id or account is now
	 * empty goes back to its mapped workflow. An id that names no account is
	 * not kept — it fits no account the plugin sends through.
	 *
	 * @param array<int|string, mixed> $emails The §23 `emails` list.
	 */
	public function remember( array $emails ): void {
		$workflows = array();
		foreach ( $emails as $item ) {
			if ( ! is_array( $item )
				|| ! in_array( $item['email_key'] ?? null, self::EMAIL_KEYS, true )
				|| ! in_array( $item['kind'] ?? null, self::KINDS, true )
				|| ! in_array( $item['smaily_state'] ?? null, self::STATES_WITH_IDS, true )
				|| ! in_array( $item['sent_through'] ?? null, self::SENT_THROUGH, true ) ) {
				continue;
			}
			$id        = $item['smaily_workflow_id'] ?? null;
			$id        = is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ? (int) $id : 0;
			$subdomain = $item['smaily_subdomain'] ?? null;
			$subdomain = is_string( $subdomain ) ? self::normalise_subdomain( $subdomain ) : '';
			if ( $id > 0 && preg_match( self::SUBDOMAIN_PATTERN, $subdomain ) === 1 ) {
				$workflows[ (string) $item['email_key'] ] = array(
					'workflow_id' => $id,
					'subdomain'   => $subdomain,
				);
			}
		}

		update_option( self::OPTION, $workflows, false );
	}

	/**
	 * The pushed workflow id of a trigger's email when the plugin sends it
	 * through the Smaily account $subdomain, or 0. An id stored before the
	 * plugin kept accounts (a bare number) names no account, so it is 0 too.
	 */
	public function workflow_id( string $trigger_type, string $subdomain ): int {
		$named = $this->account( $trigger_type );
		if ( $named === '' || $named !== self::normalise_subdomain( $subdomain ) ) {
			return 0;
		}

		return (int) ( $this->stored( $trigger_type )['workflow_id'] ?? 0 );
	}

	/**
	 * The subdomain of the Smaily account that holds a trigger's pushed
	 * workflow, or '' when the email has no pushed workflow with an account.
	 */
	public function account( string $trigger_type ): string {
		$subdomain = $this->stored( $trigger_type )['subdomain'] ?? null;

		return is_string( $subdomain ) ? $subdomain : '';
	}

	/**
	 * The stored entry of a trigger's email.
	 *
	 * @return array<string, mixed>
	 */
	private function stored( string $trigger_type ): array {
		$email_key = self::EMAIL_KEYS[ $trigger_type ] ?? '';
		$stored    = get_option( self::OPTION, array() );
		$entry     = is_array( $stored ) ? ( $stored[ $email_key ] ?? null ) : null;

		return is_array( $entry ) ? $entry : array();
	}

	/** A Smaily subdomain's case does not matter (§21, §23): compare it trimmed and lower-case. */
	private static function normalise_subdomain( string $subdomain ): string {
		return strtolower( trim( $subdomain ) );
	}
}
