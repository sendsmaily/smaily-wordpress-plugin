<?php
/**
 * Adds and controls WooCommerce Register and Account Details fields.
 * Adds and controls WordPress User Profile and Admin Profile fields.
 *
 * @package Smaily_WC
 */

namespace Smaily_WC;

use Smaily_Options;

class Profile_Settings {
	/**
	 * Add newsletter subscribe button to admin preferred place in checkout page.
	 *
	 * @return void
	 */
	public function smaily_checkout_newsletter_checkbox() {
		$enabled = get_option( Smaily_Options::CUSTOMER_SYNC_ENABLED_OPTION );
		?>
		<?php if ( $enabled ) : ?>
			<p class="form-row form-row-wide smaily-for-woocommerce-newsletter">
				<label class="checkbox woocommerce-form__label woocommerce-form__label-for-checkbox">
					<input type="checkbox" class="input-checkbox woocommerce-form__input woocommerce-form__input-checkbox" name="user_newsletter" id="smaily-checkout-subscribe" value="1" />
					<span><?php esc_html_e( 'Subscribe to newsletter', 'smaily' ); ?></span>
				</label>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Add fields to registration area and account area
	 *
	 * @return void
	 */
	public function smaily_print_user_frontend_fields() {
		$fields            = $this->smaily_get_account_fields();
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
	 * Add additional account fields data
	 *
	 * @return array $smaily_account_fields New fields to add in forms.
	 */
	public function smaily_get_account_fields() {
		$options = get_option(
			Smaily_Options::CUSTOMER_SYNC_FIELDS_OPTION,
			Smaily_Options::CUSTOMER_SYNC_DEFAULT_FIELDS
		);

		$fields_available = array(
			'user_gender' => array(
				'type'                 => 'radio',
				'label'                => __( 'Gender', 'smaily' ),
				'required'             => false,
				'class'                => array( 'tog' ),
				'options'              => array(
					1 => __( 'Male', 'smaily' ),
					2 => __( 'Female', 'smaily' ),
				),
				'hide_in_account'      => false,
				'hide_in_admin'        => false,
				'hide_in_checkout'     => false,
				'hide_in_registration' => false,
			),
			'user_phone'  => array(
				'type'                 => 'tel',
				'label'                => __( 'Phone', 'smaily' ),
				'placeholder'          => __( 'Enter phone number', 'smaily' ),
				'required'             => false,
				'class'                => array( 'regular-text' ),
				'hide_in_account'      => false,
				'hide_in_admin'        => false,
				'hide_in_checkout'     => true,
				'hide_in_registration' => false,
			),
			'user_dob'    => array(
				'type'                 => 'date',
				'label'                => __( 'Birthday', 'smaily' ),
				'placeholder'          => __( 'Enter birthday', 'smaily' ),
				'required'             => false,
				'class'                => array( 'regular-text' ),
				'hide_in_account'      => false,
				'hide_in_admin'        => false,
				'hide_in_checkout'     => false,
				'hide_in_registration' => false,
			),
		);

		$add_fields = array(
			'user_newsletter' => array(
				'type'                 => 'checkbox',
				'label'                => __( 'Subscribe to newsletter', 'smaily' ),
				'required'             => false,
				'hide_in_account'      => false,
				'hide_in_admin'        => false,
				'hide_in_checkout'     => false,
				'hide_in_registration' => false,
			),
		);

		foreach ( $options as $key => $value ) {
			if ( $value === false ) {
				continue;
			}

			if ( array_key_exists( $key, $fields_available ) ) {
				$add_fields[ $key ] = $fields_available[ $key ];
			}
		}

		return apply_filters(
			'smaily_account_fields',
			$add_fields
		);
	}

	/**
	 * Show fields at checkout form
	 *
	 * @param array $checkout_fields Old checkout fields.
	 * @return array $checkout_fields Updated checkout fields
	 */
	public function smaily_checkout_fields( $checkout_fields ) {
		$fields = $this->smaily_get_account_fields();
		// Fields to append to billing information.
		$billing_details_list = array( 'user_gender', 'user_phone', 'user_dob' );

		foreach ( $fields as $key => $field_args ) {
			if ( ! empty( $field_args['hide_in_checkout'] ) ) {
				continue;
			}

			// Append billing details to customer billing details list.
			if ( in_array( $key, $billing_details_list, true ) ) {
				$checkout_fields['billing'][ $key ] = $field_args;
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
		$fields = $this->smaily_get_account_fields();
		?>
		<h2><?php esc_html_e( 'Additional Information', 'smaily' ); ?></h2>
		<table class="form-table" id="smaily-additional-information">

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
	 * Helper functions
	 */

	/**
	 * Parses the request and returns array of selected customer synchronization additional fields that have been sanitized.
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
		foreach ( $this->smaily_get_account_fields() as $key => $field_args ) {
			if ( ! $this->smaily_is_field_visible( $field_args, $action ) ) {
				continue;
			}

			$fields[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}

		return $fields;
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
	 *  Check if field is one of WordPress predefined fields.
	 *
	 * @param string $key Key to be checked.
	 * @return boolean $inUserdata True if is in predefined fields.
	 */
	private function smaily_is_userdata( $key ) {
		$userdata = array(
			'user_pass',
			'user_login',
			'user_nickname',
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
}
