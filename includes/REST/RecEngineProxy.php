<?php
/**
 * Shared helpers of the admin REST proxies to the rec-engine.
 *
 * @package Smaily\Connect\REST
 */

declare(strict_types=1);

namespace Smaily\Connect\REST;

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Constants;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\Client;
use WP_Error;
use WP_REST_Request;

/**
 * The capability check and the engine client of an admin proxy that forwards
 * to the engine with the stored api_key (AutomationsEndpoint,
 * EmailDesignsEndpoint). Each endpoint keeps its own availability gate and
 * error mapping: their response bodies differ on purpose.
 */
trait RecEngineProxy {

	private RecEngineSettings $settings;

	/** @var callable(string $api_key, string $base_url, array<string, string> $endpoints): \Smaily\Connect\Smaily\RecEngine\Client */
	private $client_factory;

	/**
	 * @return bool|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ) {
		if ( ! current_user_can( Constants::CAPABILITY ) ) {
			return new WP_Error(
				'smaily_connect_forbidden',
				__( 'You do not have permission to manage Campaign Intelligence.', 'smaily-connect' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/** An engine client on the stored connection; the api_key stays server-side. */
	private function client(): Client {
		return ( $this->client_factory )(
			$this->settings->api_key(),
			$this->settings->base_url(),
			$this->settings->endpoints()
		);
	}
}
