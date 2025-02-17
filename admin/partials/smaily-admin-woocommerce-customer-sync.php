<?php

defined( 'ABSPATH' ) || exit;

$mandatory   = array(
	'user_email' => true,
	'store_url'  => true,
);
$sync_fields = get_option( 'smaily_customer_sync_fields' );
$labels      = array(
	'user_email'       => __( 'Email', 'smaily' ),
	'store_url'        => __( 'Store URL', 'smaily' ),
	'customer_group'   => __( 'Customer Group', 'smaily' ),
	'customer_id'      => __( 'Customer ID', 'smaily' ),
	'user_dob'         => __( 'Date Of Birth', 'smaily' ),
	'first_registered' => __( 'First Registered', 'smaily' ),
	'first_name'       => __( 'First name', 'smaily' ),
	'user_gender'      => __( 'Gender', 'smaily' ),
	'last_name'        => __( 'Last name', 'smaily' ),
	'nickname'         => __( 'Nickname', 'smaily' ),
	'user_phone'       => __( 'Phone', 'smaily' ),
	'site_title'       => __( 'Site Title', 'smaily' ),
);

?>
<fieldset >
	<?php foreach ( array_merge( $sync_fields, $mandatory ) as $field => $enabled ) : ?>
		<label for="smaily_sync_<?php echo esc_attr( $field ); ?>">
			<input
				<?php if ( isset( $mandatory[ $field ] ) ) : ?>
					disabled
				<?php endif; ?>
				type="checkbox"
				id="smaily_sync_<?php echo esc_attr( $field ); ?>"
				name="smaily_customer_sync_fields[<?php echo esc_attr( $field ); ?>]"
				value="1"
				<?php checked( $enabled, true ); ?>
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
