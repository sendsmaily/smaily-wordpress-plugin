<?php

namespace Smaily_Connect\Admin;

use Smaily_Connect\Includes\Options;

class Sanitizer {
	/**
	 * Stores abandoned cart enabled status and validates that autoresponder is selected
	 * when abandoned cart automation is enabled.
	 *
	 * @param array $input
	 */
	public function sanitize_abandoned_cart_status( $input ) {
		$enabled          = rest_sanitize_boolean( $input['enabled'] ?? false );
		$autoresponder_id = sanitize_text_field( $input['autoresponder_id'] ?? '' );

		if ( $enabled === true && $autoresponder_id === '' ) {
			add_settings_error(
				'smaily_connect_messages',
				'no_autoresponder_for_abandoned_cart',
				__( 'Please select autoresponder for abandoned cart automation.', 'smaily-connect' ),
				'error'
			);

			return get_option( Options::ABANDONED_CART_STATUS_OPTION );
		}

		return array(
			'enabled'          => $enabled,
			'autoresponder_id' => $autoresponder_id,
		);
	}

	/**
	 * Sanitize input user input for credentials fields.
	 *
	 * @param array $input
	 */
	public function sanitize_api_credentials( $input ) {
		// Disconnect.
		if ( isset( $input['enabled'] ) && $input['enabled'] === '1' ) {
			add_settings_error(
				'smaily_connect_messages',
				'credentials_validated',
				'API credentials disconnected!',
				'success'
			);

			return array(
				'subdomain' => '',
				'username'  => '',
				'password'  => '',
			);
		}

		$validation_errors = array();
		if ( empty( trim( $input['subdomain'] ) ) ) {
			$validation_errors[] = __( 'Please enter subdomain!', 'smaily-connect' );
		}
		if ( empty( trim( $input['username'] ) ) ) {
			$validation_errors[] = __( 'Please enter username!', 'smaily-connect' );
		}
		if ( empty( trim( $input['password'] ) ) ) {
			$validation_errors[] = __( 'Please enter password!', 'smaily-connect' );
		}
		if ( ! empty( $validation_errors ) ) {
			$validation_errors = implode( '<br>', $validation_errors );
			add_settings_error(
				'smaily_connect_messages',
				'invalid_api_credentials',
				$validation_errors,
				'error'
			);

			return get_option( Options::API_CREDENTIALS_OPTION );
		}

		return array(
			'subdomain' => $this->normalize_subdomain( sanitize_text_field( $input['subdomain'] ) ),
			'username'  => sanitize_text_field( $input['username'] ),
			'password'  => sanitize_text_field( $input['password'] ),
		);
	}

	/**
	 * Sanitizes subscriber sync additional values.
	 *
	 * @param array $input
	 * @return array
	 */
	public function sanitize_subscriber_sync_fields( $input ) {
		$default_fields = Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS;

		$sanitized = array();
		foreach ( $default_fields as $field => $default_value ) {
			if ( $default_value === true ) {
				$sanitized[ $field ] = true;
				continue;
			}

			$sanitized[ $field ] = ! empty( $input[ $field ] ) && $input[ $field ] !== '0';
		}

		return $sanitized;
	}

	/**
	 * Sanitizes abandoned cart additional values.
	 *
	 * @param array $input
	 * @return array
	 */
	public function sanitize_abandoned_cart_fields( $input ) {
		$default_fields = Options::ABANDONED_CART_DEFAULT_FIELDS;

		$sanitized = array();
		foreach ( $default_fields as $field => $default_value ) {
			if ( $default_value === true ) {
				$sanitized[ $field ] = true;
				continue;
			}

			$sanitized[ $field ] = ! empty( $input[ $field ] ) && $input[ $field ] !== '0';
		}

		return $sanitized;
	}

	/**
	 * Normalize subdomain into the bare necessity.
	 *
	 *
	 * @access private
	 * @param  string $subdomain Messy subdomain, e.g http://demo.sendsmaily.net
	 * @return string Clean subdomain, e.g demo
	 */
	private function normalize_subdomain( $subdomain ) {
		// Normalize subdomain.
		// First, try to parse as full URL. If that fails, try to parse as subdomain.sendsmaily.net, and
		// if all else fails, then clean up subdomain and pass as is.
		if ( filter_var( $subdomain, FILTER_VALIDATE_URL ) ) {
			$url       = wp_parse_url( $subdomain );
			$parts     = explode( '.', $url['host'] );
			$subdomain = count( $parts ) >= 3 ? $parts[0] : '';
		} elseif ( preg_match( '/^[^\.]+\.sendsmaily\.net$/', $subdomain ) ) {
			$parts     = explode( '.', $subdomain );
			$subdomain = $parts[0];
		}

		return preg_replace( '/[^a-zA-Z0-9]+/', '', $subdomain );
	}
}
