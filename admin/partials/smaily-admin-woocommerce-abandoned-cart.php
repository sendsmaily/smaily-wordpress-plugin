<?php
/**
 * WooCommerce abandoned cart fields settings.
 *
 * @var Smaily_WP_Connect\Admin\Renderer $this
 */

use Smaily_WP_Connect\Includes\Options;

defined( 'ABSPATH' ) || exit;

$mandatory   = array( 'user_email', 'store_url', 'language' );
$sync_fields = get_option( Options::ABANDONED_CART_FIELDS_OPTION, Options::ABANDONED_CART_DEFAULT_FIELDS );
$labels      = array(
	'user_email'          => __( 'Email', 'smaily-wp-connect' ),
	'store_url'           => __( 'Store URL', 'smaily-wp-connect' ),
	'first_name'          => __( 'Customer First Name', 'smaily-wp-connect' ),
	'last_name'           => __( 'Customer Last Name', 'smaily-wp-connect' ),
	'language'            => __( 'Language', 'smaily-wp-connect' ),
	'product_name'        => __( 'Product Name', 'smaily-wp-connect' ),
	'product_description' => __( 'Product Description', 'smaily-wp-connect' ),
	'product_sku'         => __( 'Product SKU', 'smaily-wp-connect' ),
	'product_quantity'    => __( 'Product Quantity', 'smaily-wp-connect' ),
	'product_base_price'  => __( 'Product Base Price', 'smaily-wp-connect' ),
	'product_price'       => __( 'Product Price', 'smaily-wp-connect' ),
	'product_image_url'   => __( 'Product Image', 'smaily-wp-connect' ),
);

?>
<fieldset >
	<?php foreach ( $sync_fields as $field => $enabled ) : ?>
		<label for="<?php echo sprintf( '%s[%s]', esc_attr( Options::ABANDONED_CART_FIELDS_OPTION ), esc_attr( $field ) ); ?>">
			<input
				<?php if ( in_array( $field, $mandatory, true ) ) : ?>
					disabled
				<?php endif; ?>
				type="checkbox"
				id="smaily_abandoned_<?php echo esc_attr( $field ); ?>"
				name="<?php echo sprintf( '%s[%s]', esc_attr( Options::ABANDONED_CART_FIELDS_OPTION ), esc_attr( $field ) ); ?>"
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
			'smaily-wp-connect'
		);
		?>
	</small>
</fieldset>
