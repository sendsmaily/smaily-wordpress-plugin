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
 * names the Smaily workflow it created, and the plugin starts the email with
 * that workflow in place of the mapped one (CON-173).
 *
 * The list is read when the email settings open and when the store returns
 * from the design page — never before a send. This class keeps the ids of
 * the last read, and the send paths read only this copy
 * (Multilingual\Router). The template id is not used: how the plugin sends
 * with an engine template is an open question to the engine (INT-146).
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

	/**
	 * Smaily states whose ids belong to a pushed version. An unknown state
	 * counts as `not_available` (§23), whose ids are null.
	 */
	private const STATES_WITH_IDS = array( 'pending', 'pushed', 'failed' );

	/**
	 * Keep the workflow ids of one §23 read, replacing those of the last one:
	 * an email whose id is now empty goes back to its mapped workflow.
	 *
	 * @param array<int|string, mixed> $emails The §23 `emails` list.
	 */
	public function remember( array $emails ): void {
		$workflows = array();
		foreach ( $emails as $item ) {
			if ( ! is_array( $item )
				|| ! in_array( $item['email_key'] ?? null, self::EMAIL_KEYS, true )
				|| ! in_array( $item['kind'] ?? null, self::KINDS, true )
				|| ! in_array( $item['smaily_state'] ?? null, self::STATES_WITH_IDS, true ) ) {
				continue;
			}
			$id = $item['smaily_workflow_id'] ?? null;
			if ( ( is_string( $id ) && ctype_digit( $id ) ) || is_int( $id ) ) {
				if ( (int) $id > 0 ) {
					$workflows[ (string) $item['email_key'] ] = (int) $id;
				}
			}
		}

		update_option( self::OPTION, $workflows, false );
	}

	/** The pushed workflow id of a trigger's email, or 0 when there is none. */
	public function workflow_id( string $trigger_type ): int {
		$email_key = self::EMAIL_KEYS[ $trigger_type ] ?? '';
		$stored    = get_option( self::OPTION, array() );

		return is_array( $stored ) && isset( $stored[ $email_key ] ) ? (int) $stored[ $email_key ] : 0;
	}
}
