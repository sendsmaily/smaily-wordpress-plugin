<?php
/**
 * WooCommerce customer sync fields.
 *
 * @var Smaily_Admin\Renderer $this
 */

defined( 'ABSPATH' ) || exit;
$mandatory   = array( 'user_email', 'store_url', 'language' );
$sync_fields = get_option( Smaily_Options::CUSTOMER_SYNC_FIELDS_OPTION, Smaily_Options::CUSTOMER_SYNC_DEFAULT_FIELDS );
$labels      = array(
	'customer_group'   => __( 'Customer Group', 'smaily' ),
	'customer_id'      => __( 'Customer ID', 'smaily' ),
	'first_name'       => __( 'First name', 'smaily' ),
	'first_registered' => __( 'First Registered', 'smaily' ),
	'last_name'        => __( 'Last name', 'smaily' ),
	'language'         => __( 'Language', 'smaily' ),
	'nickname'         => __( 'Nickname', 'smaily' ),
	'site_title'       => __( 'Site Title', 'smaily' ),
	'store_url'        => __( 'Store URL', 'smaily' ),
	'user_dob'         => __( 'Birthday', 'smaily' ),
	'user_email'       => __( 'Email', 'smaily' ),
	'user_gender'      => __( 'Gender', 'smaily' ),
	'user_phone'       => __( 'Phone', 'smaily' ),
);

?>
<fieldset >
	<?php foreach ( $sync_fields  as $field => $enabled ) : ?>
		<label for="<?php echo sprintf( '%s[%s]', esc_attr( Smaily_Options::CUSTOMER_SYNC_FIELDS_OPTION ), esc_attr( $field ) ); ?>">
			<input
				<?php if ( in_array( $field, $mandatory, true ) ) : ?>
					disabled
				<?php endif; ?>
				type="checkbox"
				id="smaily_sync_<?php echo esc_attr( $field ); ?>"
				name="<?php echo sprintf( '%s[%s]', esc_attr( Smaily_Options::CUSTOMER_SYNC_FIELDS_OPTION ), esc_attr( $field ) ); ?>"
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
			'Select fields you wish to synchronize along with subscriber email and store URL.',
			'smaily'
		);
		?>
	</small>
</fieldset>
