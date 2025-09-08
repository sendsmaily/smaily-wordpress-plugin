<?php
/**
 * Smaily helper class with static methods
 */

namespace Smaily_Connect\Includes;

class Helper {
	/**
	 * Make a request to Smaily asking for autoresponders.
	 * Request is authenticated via saved credentials.
	 *
	 * @param Options $options
	 * @return array List of autoresponders in format [id => title].
	 */
	public static function get_autoresponders_list( Options $options ) {
		if ( ! $options->has_credentials() ) {
			return array();
		}

		$request = new Smaily_Client( $options );
		$result  = $request->list_autoresponders();

		if ( ! isset( $result['body'] ) || empty( $result['body'] ) ) {
			return array();
		}

		$autoresponder_list = array();
		foreach ( $result['body'] as $autoresponder ) {
			if ( ! is_array( $autoresponder ) ) {
				continue;
			}

			if ( ! isset( $autoresponder['id'] ) || ! isset( $autoresponder['title'] ) ) {
				continue;
			}

			if ( empty( $autoresponder['id'] ) || empty( $autoresponder['title'] ) ) {
				continue;
			}

			$id                        = $autoresponder['id'];
			$title                     = $autoresponder['title'];
			$autoresponder_list[ $id ] = $title;
		}

		return $autoresponder_list;
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool True if WooCommerce is active, false otherwise.
	 */
	public static function is_woocommerce_active() {
		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( 'woocommerce/woocommerce.php' );
		} else {
			return class_exists( 'WooCommerce' );
		}
	}

	/**
	 * Check if Contact Form 7 is active.
	 *
	 * @return bool True if Contact Form 7 is active, false otherwise.
	 */
	public static function is_cf7_active() {
		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( 'contact-form-7/wp-contact-form-7.php' );
		} else {
			return class_exists( 'WPCF7' );
		}
	}

	/**
	 * Check if Elementor is active.
	 *
	 * @return bool True if Elementor is active, false otherwise.
	 */
	public static function is_elementor_active() {
		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( 'elementor/elementor.php' );
		} else {
			return class_exists( 'Elementor\Plugin' );
		}
	}

	/**
	 * Check if the user is on an admin view. Since is_admin itself is not as reliable, incorporate additional checks.
	 *
	 * @return bool True if the view is for admins.
	 */
	public static function is_admin_screen() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		return function_exists( 'is_admin' ) && is_admin() ||
			(
				strpos( $request_uri, '/wp-admin/' ) !== false ||
				strpos( $request_uri, 'admin-ajax.php' ) !== false
			);
	}

	/**
	 * Check if the request is made by a browser for assets like favicon.
	 *
	 * @return bool True if the request is likely made by the browser.
	 */
	public static function is_browser_request() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		// Check for common browser-initiated requests
		$browser_requests = array(
			'/favicon.ico',
			'/apple-touch-icon',
			'/apple-touch-icon-precomposed',
		);

		foreach ( $browser_requests as $request ) {
			if ( strpos( $request_uri, $request ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize array data for input.
	 *
	 * @param array $data Array to sanitize.
	 * @return array Sanitized array.
	 */
	public static function sanitize_array( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				// Recursively sanitize nested arrays.
				$data[ $key ] = self::sanitize_array( $value );
			} elseif ( is_string( $value ) ) {
				// Sanitize strings using WordPress sanitizing functions.
				if ( self::is_html( $value ) ) {
					// Use wp_kses() to allow safe HTML.
					$data[ $key ] = wp_kses( $value, self::allowed_html() );
				} else {
					$data[ $key ] = sanitize_text_field( $value );
				}
			} elseif ( is_email( $value ) ) {
				// Sanitize email.
				$data[ $key ] = sanitize_email( $value );
			} elseif ( is_int( $value ) ) {
				// Sanitize integer.
				$data[ $key ] = filter_var( $value, FILTER_SANITIZE_NUMBER_INT );
			} elseif ( is_float( $value ) ) {
				// Sanitize float.
				$data[ $key ] = filter_var( $value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );
			} elseif ( is_bool( $value ) ) {
				// Sanitize boolean.
				$data[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			} else {
				// Use default PHP sanitization for other types.
				$data[ $key ] = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
			}
		}
		return $data;
	}

	/**
	 * Check if a string contains HTML tags.
	 *
	 * @param string $value String to check.
	 * @return bool True if string contains HTML, false otherwise.
	 */
	private static function is_html( $value ) {
		// Consider script and style tags also part of HTML.
		// phpcs:ignore  WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		return $value !== strip_tags( $value );
	}

	/**
	 * Allowed HTML tags
	 */
	private static function allowed_html() {
		$allowedtags             = array();
		$allowed_atts            = array(
			'align'       => array(),
			'class'       => array(),
			'type'        => array(),
			'id'          => array(),
			'dir'         => array(),
			'lang'        => array(),
			'style'       => array(),
			'alt'         => array(),
			'href'        => array(),
			'rel'         => array(),
			'rev'         => array(),
			'target'      => array(),
			'novalidate'  => array(),
			'type'        => array(),
			'value'       => array(),
			'name'        => array(),
			'tabindex'    => array(),
			'action'      => array(),
			'method'      => array(),
			'for'         => array(),
			'width'       => array(),
			'height'      => array(),
			'data'        => array(),
			'title'       => array(),
			'placeholder' => array(),
			'required'    => array(),
		);
		$allowedtags['form']     = $allowed_atts;
		$allowedtags['label']    = $allowed_atts;
		$allowedtags['input']    = $allowed_atts;
		$allowedtags['textarea'] = $allowed_atts;
		$allowedtags['style']    = $allowed_atts;
		$allowedtags['strong']   = $allowed_atts;
		$allowedtags['button']   = $allowed_atts;
		$allowedtags['small']    = $allowed_atts;
		$allowedtags['table']    = $allowed_atts;
		$allowedtags['span']     = $allowed_atts;
		$allowedtags['abbr']     = $allowed_atts;
		$allowedtags['pre']      = $allowed_atts;
		$allowedtags['div']      = $allowed_atts;
		$allowedtags['img']      = $allowed_atts;
		$allowedtags['h1']       = $allowed_atts;
		$allowedtags['h2']       = $allowed_atts;
		$allowedtags['h3']       = $allowed_atts;
		$allowedtags['h4']       = $allowed_atts;
		$allowedtags['h5']       = $allowed_atts;
		$allowedtags['h6']       = $allowed_atts;
		$allowedtags['ol']       = $allowed_atts;
		$allowedtags['ul']       = $allowed_atts;
		$allowedtags['li']       = $allowed_atts;
		$allowedtags['em']       = $allowed_atts;
		$allowedtags['hr']       = $allowed_atts;
		$allowedtags['br']       = $allowed_atts;
		$allowedtags['tr']       = $allowed_atts;
		$allowedtags['td']       = $allowed_atts;
		$allowedtags['p']        = $allowed_atts;
		$allowedtags['a']        = $allowed_atts;
		$allowedtags['b']        = $allowed_atts;
		$allowedtags['i']        = $allowed_atts;

		// Custom filter to allow specific styles
		add_filter(
			'safe_style_css',
			function ( $styles ) {
				$styles[] = 'display';
				$styles[] = 'position';
				$styles[] = 'top';
				$styles[] = 'right';
				$styles[] = 'bottom';
				$styles[] = 'left';
				$styles[] = 'overflow';
				$styles[] = 'z-index';
				return $styles;
			}
		);

		return $allowedtags;
	}

	/**
	 * Store the user language code.
	 *
	 * @since 1.0.0
	 * @param int $user_id
	 * @return void
	 */
	public static function set_user_language_code( int $user_id ) {
		update_user_meta( $user_id, 'smaily_connect_user_language', self::get_user_language_code( $user_id ) );
	}

	/**
	 * Get the user language code. If user ID is provided, get the user's language code. Otherwise, get the current webpage language code.
	 * Supports WPML.
	 *
	 * @since 1.0.0
	 * @param int|null $user_id User ID.
	 * @return string Language code.
	 */
	public static function get_user_language_code( $user_id = null ) {
		if ( $user_id ) {
			$lang = get_user_meta( $user_id, 'smaily_connect_user_language', true );
			if ( ! empty( $lang ) ) {
				return $lang;
			}

			$wpml_lang = get_user_meta( $user_id, 'icl_admin_language', true );
			if ( ! empty( $wpml_lang ) ) {
				return $wpml_lang;
			}
		}

		return self::get_current_language_code();
	}

	/**
	 * Try to get current webpage language code. Returns an empty string if no language could be determined.
	 * Supports WPML and Polylang language settings.
	 *
	 * Notice! This function is context dependent and not suitable for use in cron jobs or other non-HTTP requests.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_current_language_code() {
		// WPML.
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			return ICL_LANGUAGE_CODE;
		}

		// Polylang.
		if ( function_exists( 'pll_current_language' ) ) {
			return pll_current_language();
		}

		$lang = get_locale();
		if ( strlen( $lang ) > 0 ) {
			return explode( '_', $lang )[0];
		}

		return '';
	}

	/**
	 * Get the current URL of the webpage. Defaults to site URL when request URL couldn't be determined.
	 *
	 * @return string
	 */
	public static function get_current_url() {
		if ( ! isset( $_SERVER['HTTP_HOST'] ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return get_site_url();
		}

		$protocol = is_ssl() ? 'https://' : 'http://';
		$host     = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		$uri      = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		return $protocol . $host . $uri;
	}

	/**
	 * Determines if the current request is a redirect from Smaily sign up form.
	 * Also determines if the request is a success or error based on the response code
	 * from Smaily.
	 *
	 * The response is a redirect from Smaily sign up form.
	 * Form documentation: https://smaily.com/help/how-to/forms-subscriptions/an-example-of-a-signup-form/
	 * Response codes: https://smaily.com/help/api/general/response-codes/
	 *
	 * @return string|bool 'success' or 'error' if the URL contains a code, false otherwise.
	 */
	public static function get_optin_form_response_type() {
		$code       = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : null;
		$is_success = $code === '101';
		$is_error   = $code && ! $is_success;

		if ( $is_success ) {
			return 'success';
		} elseif ( $is_error ) {
			return 'error';
		}

		return false;
	}
}
