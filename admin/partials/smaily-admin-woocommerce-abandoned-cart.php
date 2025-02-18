<?php

defined( 'ABSPATH' ) || exit;

$mandatory   = array(
	'user_email' => true,
	'store_url'  => true,
);
$sync_fields = get_option( 'smaily_abandoned_cart_fields' );
$labels      = array(
	'user_email'          => __( 'Email', 'smaily' ),
	'store_url'           => __( 'Store URL', 'smaily' ),
	'first_name'          => __( 'Customer First Name', 'smaily' ),
	'last_name'           => __( 'Customer Last Name', 'smaily' ),
	'product_name'        => __( 'Product Name', 'smaily' ),
	'product_description' => __( 'Product Description', 'smaily' ),
	'product_sku'         => __( 'Product SKU', 'smaily' ),
	'product_quantity'    => __( 'Product Quantity', 'smaily' ),
	'product_base_price'  => __( 'Product Base Price', 'smaily' ),
	'product_price'       => __( 'Product Price', 'smaily' ),
	'product_images'      => __( 'Product Images', 'smaily' ),
);

?>
<fieldset >
	<?php foreach ( array_merge( $sync_fields, $mandatory ) as $field => $enabled ) : ?>
		<label for="smaily_abandoned_<?php echo esc_attr( $field ); ?>">
			<input
				<?php if ( isset( $mandatory[ $field ] ) ) : ?>
					disabled
				<?php endif; ?>
				type="checkbox"
				id="smaily_abandoned_<?php echo esc_attr( $field ); ?>"
				name="smaily_abandoned_cart_fields[<?php echo esc_attr( $field ); ?>]"
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
			'Select fields wish to send to Smaily template along with subscriber email and store url.',
			'smaily'
		);
		?>
	</small>
</fieldset>
