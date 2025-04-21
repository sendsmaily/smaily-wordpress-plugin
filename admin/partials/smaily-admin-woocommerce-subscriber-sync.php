<?php
/**
 * WooCommerce subscriber sync fields.
 *
 * @var Smaily_Connect\Admin\Renderer $this
 */

use Smaily_Connect\Includes\Options;

defined( 'ABSPATH' ) || exit;
$mandatory   = array( 'user_email', 'store_url', 'language' );
$sync_fields = get_option( Options::SUBSCRIBER_SYNC_FIELDS_OPTION, Options::SUBSCRIBER_SYNC_DEFAULT_FIELDS );
$labels      = array(
	'customer_group'   => __( 'Customer Group', 'smaily-connect' ),
	'customer_id'      => __( 'Customer ID', 'smaily-connect' ),
	'first_name'       => __( 'First name', 'smaily-connect' ),
	'first_registered' => __( 'First Registered', 'smaily-connect' ),
	'last_name'        => __( 'Last name', 'smaily-connect' ),
	'language'         => __( 'Language', 'smaily-connect' ),
	'nickname'         => __( 'Nickname', 'smaily-connect' ),
	'site_title'       => __( 'Site Title', 'smaily-connect' ),
	'store_url'        => __( 'Store URL', 'smaily-connect' ),
	'user_dob'         => __( 'Birthday', 'smaily-connect' ),
	'user_email'       => __( 'Email', 'smaily-connect' ),
	'user_gender'      => __( 'Gender', 'smaily-connect' ),
	'user_phone'       => __( 'Phone', 'smaily-connect' ),
);

?>
<fieldset >
	<?php foreach ( $sync_fields  as $field => $enabled ) : ?>
		<label for="<?php echo sprintf( '%s[%s]', esc_attr( Options::SUBSCRIBER_SYNC_FIELDS_OPTION ), esc_attr( $field ) ); ?>">
			<input
				<?php if ( in_array( $field, $mandatory, true ) ) : ?>
					disabled
				<?php endif; ?>
				type="checkbox"
				id="<?php echo sprintf( '%s[%s]', esc_attr( Options::SUBSCRIBER_SYNC_FIELDS_OPTION ), esc_attr( $field ) ); ?>"
				name="<?php echo sprintf( '%s[%s]', esc_attr( Options::SUBSCRIBER_SYNC_FIELDS_OPTION ), esc_attr( $field ) ); ?>"
				value="1"
				<?php checked( $enabled || in_array( $field, $mandatory, true ) ); ?>
			/>
			<?php echo esc_html( $labels[ $field ] ); ?>
		</label>
		<br>
	<?php endforeach; ?>
	<small class="form-text text-muted">
		<?php
		esc_html_e(
			'Select extra fields you wish to send to Smaily.',
			'smaily-connect'
		);
		?>
	</small>
</fieldset>
