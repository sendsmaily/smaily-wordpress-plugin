<?php
/**
 * WooCommerce abandoned cart fields settings.
 *
 * @var Smaily_Admin\Renderer $this
 */

defined( 'ABSPATH' ) || exit;

$mandatory   = array( 'user_email', 'store_url', 'language' );
$sync_fields = get_option( Smaily_Options::ABANDONED_CART_FIELDS_OPTION, Smaily_Options::ABANDONED_CART_DEFAULT_FIELDS );
$labels      = array(
	'user_email'          => __( 'Email', 'smaily' ),
	'store_url'           => __( 'Store URL', 'smaily' ),
	'first_name'          => __( 'Customer First Name', 'smaily' ),
	'last_name'           => __( 'Customer Last Name', 'smaily' ),
	'language'            => __( 'Language', 'smaily' ),
	'product_name'        => __( 'Product Name', 'smaily' ),
	'product_description' => __( 'Product Description', 'smaily' ),
	'product_sku'         => __( 'Product SKU', 'smaily' ),
	'product_quantity'    => __( 'Product Quantity', 'smaily' ),
	'product_base_price'  => __( 'Product Base Price', 'smaily' ),
	'product_price'       => __( 'Product Price', 'smaily' ),
	'product_image_url'   => __( 'Product Image', 'smaily' ),
);

?>
<fieldset >
	<?php foreach ( $sync_fields as $field => $enabled ) : ?>
		<label for="<?php echo sprintf( '%s[%s]', esc_attr( Smaily_Options::ABANDONED_CART_FIELDS_OPTION ), esc_attr( $field ) ); ?>">
			<input
				<?php if ( in_array( $field, $mandatory, true ) ) : ?>
					disabled
				<?php endif; ?>
				type="checkbox"
				id="smaily_abandoned_<?php echo esc_attr( $field ); ?>"
				name="<?php echo sprintf( '%s[%s]', esc_attr( Smaily_Options::ABANDONED_CART_FIELDS_OPTION ), esc_attr( $field ) ); ?>"
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
			'Select fields wish to send to Smaily template along with subscriber email and store url.',
			'smaily'
		);
		?>
	</small>
</fieldset>
