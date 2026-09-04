<?php
/**
 * REST endpoint listing Smaily autoresponders for a given account key.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\Client;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /wp-json/smaily-connect/v1/workflows?account_key=default`
 *
 * Returns:
 *   200 OK
 *   {
 *     "workflows": [
 *       { "id": "42", "name": "Welcome series", "status": "ACTIVE" },
 *       ...
 *     ]
 *   }
 *
 * Used by Step 3 (WC automation mappings) so admins pick from the actual
 * Smaily autoresponder list rather than typing IDs by hand. Mode A
 * passes the language-keyed account_key (e.g. "et") so each language
 * row sees its own credential set's workflow list.
 *
 * Auth: nonce (wp_rest) + manage_options capability. The endpoint
 * does NOT take credentials as a request body — those are pulled from
 * Settings\Credentials. Callers who need to validate fresh credentials
 * before saving use POST /test-smaily first.
 *
 * The Smaily API responds with a list whose shape evolves over time;
 * we normalise here so the React layer sees a stable contract.
 */
class WorkflowsEndpoint {

	public const ROUTE = '/workflows';

	private Credentials $credentials;

	/** @var callable(string, string, string): Client */
	private $client_factory;

	/**
	 * @param Credentials                              $credentials   Configured credentials repository.
	 * @param callable(string, string, string): Client $client_factory Builds a Client from a subdomain/user/pass triple.
	 */
	public function __construct( Credentials $credentials, callable $client_factory ) {
		$this->credentials    = $credentials;
		$this->client_factory = $client_factory;
	}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'account_key' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'default',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ) {
		if ( ! current_user_can( Constants::CAPABILITY ) ) {
			return new WP_Error(
				'smaily_connect_forbidden',
				__( 'You do not have permission to list Smaily workflows.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$account_key = (string) $request->get_param( 'account_key' );

		$set = $this->credentials->get( $account_key );
		if ( $set === null || ! $set->is_complete() ) {
			return new WP_REST_Response(
				array(
					'workflows' => array(),
					'error'     => __( 'Credentials for this account are not configured.', 'smaily-connect' ),
				),
				200
			);
		}

		$client = ( $this->client_factory )( $set->subdomain, $set->username, $set->password );

		try {
			$raw_list = $client->list_autoresponders();
		} catch ( ApiException $e ) {
			return new WP_REST_Response(
				array(
					'workflows' => array(),
					'error'     => $e->getMessage(),
				),
				200
			);
		}

		return new WP_REST_Response(
			array( 'workflows' => $this->normalise( $raw_list ) ),
			200
		);
	}

	/**
	 * Map the Smaily-API workflow shape into a stable React-side contract.
	 *
	 * Smaily's /api/autoresponder.php returns rows with `id`, `name`,
	 * `status`, plus a bunch of metadata (sections, tags, timestamps)
	 * the wizard doesn't need. We keep only what the dropdown renders.
	 *
	 * Rows missing `id` or `name` are dropped — a workflow without a
	 * name can't be picked from a dropdown anyway, and the previous
	 * `#{id}` placeholder was actively misleading Erkki's staging.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 *
	 * @return array<int, array{id: string, name: string, status: string}>
	 */
	private function normalise( array $rows ): array {
		$normalised = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id   = isset( $row['id'] ) ? (string) $row['id'] : '';
			$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
			if ( $id === '' || $id === '0' || $name === '' ) {
				continue;
			}

			$normalised[] = array(
				'id'     => $id,
				'name'   => $name,
				'status' => isset( $row['status'] ) ? (string) $row['status'] : '',
			);
		}

		return $normalised;
	}
}
