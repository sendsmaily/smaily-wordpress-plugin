<?php

namespace Smaily_Connect\Includes;

use Smaily_Connect\Includes\Options;

class Smaily_Client {
	/**
	 * Smaily API Subdomain.
	 *
	 *
	 * @access private
	 * @var    string $subdomain Smaily API Subdomain.
	 */
	private $_subdomain;

	/**
	 * Smaily API Username.
	 *
	 *
	 * @access private
	 * @var    string $username Smaily API Username.
	 */
	private $_username;

	/**
	 * Smaily API Password.
	 *
	 *
	 * @access private
	 * @var    string $password Smaily API Password.
	 */
	private $_password;

	/**
	 * Constructor.
	 *
	 * @param string|Options $subdomain Smaily API Subdomain or saved credentials.
	 * @param string                $username  Smaily API Username.
	 * @param string                $password  Smaily API Password.
	 */
	public function __construct( $subdomain = '', $username = '', $password = '' ) {
		if ( $subdomain instanceof Options ) {
			$credentials      = $subdomain->get_api_credentials();
			$this->_subdomain = $credentials['subdomain'];
			$this->_username  = $credentials['username'];
			$this->_password  = $credentials['password'];
		} else {
			$this->_subdomain = $subdomain;
			$this->_username  = $username;
			$this->_password  = $password;
		}
	}

	/**
	 * List autoresponders.
	 *
	 * @return array
	 */
	public function list_autoresponders() {
		return $this->get(
			'workflows',
			array(
				'trigger_type' => 'form_submitted',
			)
		);
	}

	/**
	 * Trigger autoresponder workflow.
	 *
	 * @param int   $autoresponder_id Autoresponder ID.
	 * @param array $addresses        Email addresses.
	 * @param bool  $force_opt_in     Trigger opt-in.
	 * @return array
	 */
	public function trigger_automation( int $autoresponder_id, $addresses, $force_opt_in = true ) {
		return $this->post(
			'autoresponder',
			array(
				'body' => array(
					'autoresponder' => $autoresponder_id,
					'addresses'     => $addresses,
					'force_opt_in'  => $force_opt_in,
				),
			)
		);
	}

	/**
	 * List unsubscribers.
	 *
	 * @return array
	 */
	public function list_unsubscribers() {
		return $this->get(
			'contact',
			array(
				'list'   => 2,
				'fields' => '',
			)
		);
	}

	/**
	 * Update subscriber information.
	 *
	 * @param array $subscribers
	 * @return array
	 */
	public function update_subscribers( array $subscribers ) {
		return $this->post( 'contact', array( 'body' => $subscribers ) );
	}

	/**
	 * Execute get request.
	 *
	 *
	 * @return array $response. Data received back from making the request.
	 */
	private function get( string $endpoint, array $data ) {
		return $this->request( $endpoint, $data, 'GET' );
	}

	/**
	 * Execute post request.
	 *
	 *
	 * @return array $response. Data received back from making the request.
	 */
	private function post( string $endpoint, array $data ) {
		return $this->request( $endpoint, $data, 'POST' );
	}

	/**
	 * Execute the request.
	 *
	 *
	 * @return array $response. Data received back from making the request.
	 */
	private function request( string $endpoint, array $data, $method = 'GET' ) {
		$response  = array();
		$useragent = 'smaily-connect/' . SMAILY_CONNECT_PLUGIN_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . '; +' . get_bloginfo( 'url' ) . ')';
		$args      = array(
			'headers'    => array(
				'Authorization' => 'Basic ' . base64_encode( $this->_username . ':' . $this->_password ),
			),
			'user-agent' => $useragent,
		);

		switch ( $method ) {
			case 'GET':
				$api_call = wp_remote_get( 'https://' . $this->_subdomain . '.sendsmaily.net/api/' . $endpoint . '.php?' . http_build_query( $data ), $args );
				break;
			case 'POST':
				$api_call = wp_remote_post( 'https://' . $this->_subdomain . '.sendsmaily.net/api/' . $endpoint . '.php', array_merge( $args, $data ) );
				break;
		}

		// Response code from Smaily API.
		if ( is_wp_error( $api_call ) ) {
			$response = array( 'error' => $api_call->get_error_message() );
		}
		$response['body'] = json_decode( wp_remote_retrieve_body( $api_call ), true );
		$response['code'] = wp_remote_retrieve_response_code( $api_call );

		return $response;
	}
}
