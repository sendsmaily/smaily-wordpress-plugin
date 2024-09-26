<?php

/**
 * Defines the request making functionality of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/includes
 */

class Smaily_Request {


	/**
	 * Smaily API Username.
	 *
	 * @access   private
	 * @var      string    $_username    Smaily API username used for authentication.
	 */
	private static $_username = null;

	/**
	 * Smaily API Password.
	 *
	 * @access   private
	 * @var      string    $_password    Smaily API password used for authentication.
	 */
	private static $_password = null;

	/**
	 * Smaily API subdomain
	 *
	 * @access   private
	 * @var      string    $_subdomain    Smaily API subdomain used for authentication and requests.
	 */
	private static $_subdomain = null;

	/**
	 * Set Smaily API Credentials for request.
	 *
	 *
	 * @param  string $username Smaily API Username.
	 * @param  string $password Smaily API Password.
	 */
	public static function set_credentials( $credentials ) {
		self::$_subdomain = $credentials['subdomain'];
		self::$_username  = $credentials['username'];
		self::$_password  = $credentials['password'];
	}

	/**
	 * Execute the request.
	 *
	 *
	 * @return array $response. Data recieved back from making the request.
	 */
	public static function request( string $endpoint, array $data, $method = 'GET' ) {
		$response  = array();
		$useragent = 'smaily/' . SMAILY_PLUGIN_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . '; +' . get_bloginfo( 'url' ) . ')';
		$args      = array(
			'headers'    => array(
				'Authorization' => 'Basic ' . base64_encode( self::$_username . ':' . self::$_password ),
			),
			'user-agent' => $useragent,
		);

		switch ( $method ) {
			case 'GET':
				$api_call = wp_remote_get( 'https://' . self::$_subdomain . '.sendsmaily.net/api/' . $endpoint . '.php?' . http_build_query( $data ), $args );
				break;
			case 'POST':
				$api_call = wp_remote_post( 'https://' . self::$_subdomain . '.sendsmaily.net/api/' . $endpoint . '.php', array_merge( $args, $data ) );
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

	/**
	 * Execute get request.
	 *
	 *
	 * @return array $response. Data recieved back from making the request.
	 */
	public static function get( string $endpoint, array $data ) {
		return self::request( $endpoint, $data, 'GET' );
	}

	/**
	 * Execute post request.
	 *
	 *
	 * @return array $response. Data recieved back from making the request.
	 */
	public static function post( string $endpoint, array $data ) {
		return self::request( $endpoint, $data, 'POST' );
	}
}
