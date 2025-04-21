<?php

namespace Smaily_Connect\Blocks\Checkout_Optin;

use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;

class Extend_Store_Endpoint {
	/**
	 * Stores Rest Extending instance.
	 *
	 * @var ExtendSchema
	 */
	private static $extend;

	/**
	 * Plugin Identifier, unique to each plugin.
	 *
	 * @var string
	 */
	const IDENTIFIER = 'smaily-checkout-optin';

	/**
	 * Initialize the class.
	 */
	public static function init() {
		self::$extend = StoreApi::container()->get( ExtendSchema::class );
		self::extend_store();
	}

	/**
	 * Extend the store schema with custom fields.
	 */
	public static function extend_store() {
		if ( is_callable( array( self::$extend, 'register_endpoint_data' ) ) ) {
			self::$extend->register_endpoint_data(
				array(
					'endpoint'        => CheckoutSchema::IDENTIFIER,
					'namespace'       => self::IDENTIFIER,
					'schema_callback' => array( __CLASS__, 'extend_checkout_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}
	}

	/**
	 * Add newsletter subscription field to checkout schema.
	 *
	 * @return array
	 */
	public static function extend_checkout_schema() {
		return array(
			'user_newsletter' => array(
				'description' => __( 'User newsletter subscription', 'smaily-connect' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => false,
				'optional'    => true,
			),
		);
	}
}
