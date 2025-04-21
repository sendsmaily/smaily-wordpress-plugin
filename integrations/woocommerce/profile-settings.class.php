<?php

namespace Smaily_Connect\Integrations\WooCommerce;

use Smaily_Connect\Includes\Options;

class Profile_Settings {
	/**
	 * Fields to be added to WooCommerce account and checkout forms.
	 *
	 * @var array
	 */
	private $fields;

	public function __construct() {}

	/**
	 * Register hooks for the WooCommerce profile settings integration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'personal_options_update', array( $this, 'smaily_save_account_fields' ), 10 ); // edit own account admin.
		add_action( 'edit_user_profile_update', array( $this, 'smaily_save_account_fields' ), 10 ); // edit other account admin.

		// Add fields to admin area.
		add_action( 'show_user_profile', array( $this, 'smaily_print_user_admin_fields' ), 30 ); // admin: edit profile.
		add_action( 'edit_user_profile', array( $this, 'smaily_print_user_admin_fields' ), 30 ); // admin: edit other users.

		// Add fields to registration form and account area.
		add_action( 'woocommerce_register_form', array( $this, 'smaily_print_user_frontend_fields' ), 10 );
		add_action( 'woocommerce_edit_account_form', array( $this, 'smaily_print_user_frontend_fields' ), 10 );

		// Show fields in checkout area.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'smaily_checkout_fields' ), 10, 1 );

		// Save registration fields.
		add_action( 'woocommerce_created_customer', array( $this, 'smaily_save_wc_account_fields' ), 10 ); // register/checkout.
		add_action( 'woocommerce_save_account_details', array( $this, 'smaily_save_wc_account_fields' ), 10 ); // edit WC account.
	}

	/**
	 * Add fields to registration area and account area.
	 *
	 * @return void
	 */
	public function smaily_print_user_frontend_fields() {
		$fields            = $this->get_fields();
		$is_user_logged_in = is_user_logged_in();

		foreach ( $fields as $key => $field_args ) {
			$value = null;

			// Conditionals to show fields based on hide-settings.
			if ( $is_user_logged_in && ! empty( $field_args['hide_in_account'] ) ) {
				continue;
			}

			if ( ! $is_user_logged_in && ! empty( $field_args['hide_in_registration'] ) ) {
				continue;
			}

			// Get user information and save in value.
			if ( $is_user_logged_in ) {
				$user_id = $this->smaily_get_edit_user_id();
				$value   = $this->smaily_get_userdata( $user_id, $key );
			}

			$value = isset( $field_args['value'] ) ? $field_args['value'] : $value;

			woocommerce_form_field( $key, $field_args, $value );
		}
	}

	/**
	 * Show fields at checkout form
	 *
	 * @param array $checkout_fields Old checkout fields.
	 * @return array $checkout_fields Updated checkout fields
	 */
	public function smaily_checkout_fields( $checkout_fields ) {
		$fields       = $this->get_fields();
		$billing_list = array( 'user_gender', 'user_phone', 'user_dob' );

		foreach ( $fields as $key => $field_args ) {
			if ( $field_args['hide_in_checkout'] === true ) {
				continue;
			}

			// Append billing details to customer billing details list.
			if ( in_array( $key, $billing_list, true ) ) {
				$checkout_fields['billing'][ $key ] = $field_args;
			}

			// Append the checkout fields to the correct location.
			if ( $key === 'user_newsletter' ) {
				$this->add_checkout_subscription_checkbox( $checkout_fields );
			}
		}

		return $checkout_fields;
	}

	/**
	 * Add fields to admin area
	 *
	 * @return void
	 */
	public function smaily_print_user_admin_fields() {
		$fields = $this->get_fields();
		?>
		<h2><?php esc_html_e( 'Additional Information', 'smaily-connect' ); ?></h2>
		<table class="form-table" id="smaily-connect-additional-information">
			<?php foreach ( $fields as $key => $field_args ) { ?>
				<?php
				if ( ! empty( $field_args['hide_in_admin'] ) ) {
					continue;
				}

				$user_id = $this->smaily_get_edit_user_id();
				$value   = $this->smaily_get_userdata( $user_id, $key );
				?>
				<tr>
					<th>
						<label for="<?php echo esc_html( $key ); ?>"><?php echo esc_html( $field_args['label'] ); ?></label>
					</th>
					<td>
						<?php $field_args['label'] = false; ?>
						<?php woocommerce_form_field( $key, $field_args, $value ); ?>
					</td>
				</tr>
			<?php } ?>
		</table>
		<?php
	}

	/**
	 *  Save registration fields during checkout customer creation or while editing your WC account.
	 *
	 * @param int $customer_id Customer ID.
	 * @return void
	 */
	public function smaily_save_wc_account_fields( $customer_id ) {
		$sanitized_data = $this->sanitize_request_smaily_account_fields( 'save-account-details-nonce', 'save_account_details' );
		$this->save_account_fields( $customer_id, $sanitized_data );
	}

	/**
	 *  Save registration fields triggered from editing own account or other user accounts in admin area.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function smaily_save_account_fields( $user_id ) {
		$sanitized_data = $this->sanitize_request_smaily_account_fields( '_wpnonce', 'update-user_' . $user_id );
		$this->save_account_fields( $user_id, $sanitized_data );
	}

	/**
	 * Parses the request and returns array of selected subscriber synchronization additional fields that have been sanitized.
	 * Nonce field should be verified prior to calling this function.
	 *
	 * @return array
	 */
	private function sanitize_request_smaily_account_fields( string $nonce_field, $action ) {
		$nonce_val = isset( $_REQUEST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_field ] ) ) : '';
		if ( ! wp_verify_nonce( sanitize_key( $nonce_val ), $action ) ) {
			return array();
		}

		$fields = array();
		$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		foreach ( $this->get_fields() as $key => $field_args ) {
			if ( ! $this->smaily_is_field_visible( $field_args, $action ) ) {
				continue;
			}

			$fields[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}

		return $fields;
	}

	/**
	 * Get currently editing user ID (frontend account/edit profile/edit other user).
	 * Nonce field should be verified prior to calling this function.
	 *
	 * @return int $user_id Current user ID
	 */
	public function smaily_get_edit_user_id() {
		return isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : get_current_user_id();
	}

	/**
	 * Get user data based on key
	 *
	 * @param int    $user_id User ID.
	 * @param string $key Key to search from user data.
	 * @return string $userdata User data based on key
	 */
	public function smaily_get_userdata( $user_id, $key ) {
		if ( ! $this->smaily_is_userdata( $key ) ) {
			return get_user_meta( $user_id, $key, true );
		}

		$userdata = get_userdata( $user_id );

		if ( ! $userdata || ! isset( $userdata->{$key} ) ) {
			return '';
		}

		return $userdata->{$key};
	}

	/**
	 * Check if the field is visible to user.
	 *
	 * @param array $field_args Form field.
	 * @return boolean $visible Visibility.
	 */
	public function smaily_is_field_visible( $field_args, $action ) {
		if ( is_admin() && $field_args['hide_in_admin'] === false ) {
			return true;
		}

		if ( is_account_page() || $action === 'save_account_details' ) {
			if ( is_user_logged_in() && $field_args['hide_in_account'] === false ) {
				return true;
			}

			if ( is_user_logged_in() && $field_args['hide_in_registration'] === false ) {
				return true;
			}
		}

		if ( is_checkout() && $field_args['hide_in_checkout'] === false ) {
			return true;
		}

		return false;
	}

	/**
	 * Updates user metadata and user in the database.
	 *
	 * @param int $user_id
	 * @param array $fields
	 * @return void
	 */
	private function save_account_fields( $user_id, $fields ) {
		$user_data = array();

		foreach ( $fields as $key => $value ) {
			if ( $this->smaily_is_userdata( $key ) ) {
				$user_data[ $key ] = $value;
				continue;
			}

			update_user_meta( $user_id, $key, $value );
		}

		if ( ! empty( $user_data ) ) {
			$sanitized_data['ID'] = $user_id;
			wp_update_user( $sanitized_data );
		}
	}

	/**
	 *  Check if field is one of WordPress predefined fields.
	 *
	 * @param string $key Key to be checked.
	 * @return boolean $inUserdata True if is in predefined fields.
	 */
	private function smaily_is_userdata( $key ) {
		$userdata = array(
			'user_pass',
			'user_login',
			'user_nicename', // Not a typo. URL sanitized version of user_login.
			'user_email',
			'display_name',
			'nickname',
			'first_name',
			'last_name',
			'description',
			'rich_editing',
			'user_registered',
			'role',
			'jabber',
			'aim',
			'yim',
			'show_admin_bar_front',
		);

		return in_array( $key, $userdata, true );
	}

	/**
	 * Filters out fields that are enabled by subscriber synchronization settings.
	 *
	 * @param array $fields
	 * @return array
	 */
	private function filter_enabled_fields( array $fields ) {
		$enabled_fields = array();

		$sync_fields                   = get_option(
			Options::SUBSCRIBER_SYNC_FIELDS_OPTION,
			Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS
		);
		$enabled_sync_fields           = array_keys( array_filter( $sync_fields ) );
		$checkout_subscription_enabled = get_option( Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION );

		$account_fields         = array(
			'user_gender',
			'user_phone',
			'user_dob',
		);
		$account_fields_enabled = array_intersect( $account_fields, $enabled_sync_fields );

		if ( $checkout_subscription_enabled ) {
			$enabled_fields['user_newsletter'] = $fields['user_newsletter'];
		}

		foreach ( $account_fields_enabled as $field ) {
			$enabled_fields[ $field ] = $fields[ $field ];
		}

		return $enabled_fields;
	}

	/**
	 * Add checkout subscription checkbox to checkout fields.
	 *
	 * @param array $checkout_fields Checkout fields.
	 * @return void
	 */
	private function add_checkout_subscription_checkbox( &$checkout_fields ) {
		// Check if the user has already subscribed to the newsletter.
		// We don't want to override the user's choice.
		$user_id = get_current_user_id();
		$checked = get_user_meta( $user_id, 'user_newsletter', true );

		if ( ! empty( $checked ) ) {
			return;
		}

		$location = get_option( Options::CHECKOUT_SUBSCRIPTION_LOCATION_OPTION, Options::CHECKOUT_SUBSCRIPTION_DEFAULT_LOCATION );
		$position = get_option( Options::CHECKOUT_SUBSCRIPTION_POSITION_OPTION, Options::CHECKOUT_SUBSCRIPTION_DEFAULT_POSITION );

		if ( $position === 'before' ) {
			$checkout_fields[ $location ] = array( 'user_newsletter' => $this->get_fields()['user_newsletter'] ) + $checkout_fields[ $location ];
		} else {
			$checkout_fields[ $location ]['user_newsletter'] = $this->get_fields()['user_newsletter'];
		}
	}

	/**
	 * Get fields to be added to WooCommerce account and checkout forms.
	 *
	 * Notice! The fields are not added during the constructor as the
	 * translations are not available at that point.
	 *
	 * @return array
	 */
	private function get_fields() {
		if ( ! isset( $this->fields ) ) {
			$fields = array(
				'user_gender'     => array(
					'type'                 => 'radio',
					'label'                => __( 'Gender', 'smaily-connect' ),
					'required'             => false,
					'class'                => array( 'tog' ),
					'options'              => array(
						1 => __( 'Male', 'smaily-connect' ),
						2 => __( 'Female', 'smaily-connect' ),
					),
					'hide_in_account'      => false,
					'hide_in_admin'        => false,
					'hide_in_checkout'     => false,
					'hide_in_registration' => false,
				),
				'user_phone'      => array(
					'type'                 => 'tel',
					'label'                => __( 'Phone', 'smaily-connect' ),
					'placeholder'          => __( 'Enter phone number', 'smaily-connect' ),
					'required'             => false,
					'class'                => array( 'regular-text' ),
					'hide_in_account'      => false,
					'hide_in_admin'        => false,
					'hide_in_checkout'     => true,
					'hide_in_registration' => false,
				),
				'user_dob'        => array(
					'type'                 => 'date',
					'label'                => __( 'Birthday', 'smaily-connect' ),
					'placeholder'          => __( 'Enter birthday', 'smaily-connect' ),
					'required'             => false,
					'class'                => array( 'regular-text' ),
					'hide_in_account'      => false,
					'hide_in_admin'        => false,
					'hide_in_checkout'     => false,
					'hide_in_registration' => false,
				),
				'user_newsletter' => array(
					'type'                 => 'checkbox',
					'label'                => __( 'Subscribe to newsletter', 'smaily-connect' ),
					'required'             => false,
					'hide_in_account'      => false,
					'hide_in_admin'        => false,
					'hide_in_checkout'     => false,
					'hide_in_registration' => false,
				),
			);

			$enabled_fields = $this->filter_enabled_fields( $fields );
			$this->fields   = apply_filters( 'smaily_connect_account_fields', $enabled_fields );
		}

		return $this->fields;
	}
}
