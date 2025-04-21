<?php

defined( 'ABSPATH' ) || exit;

$code             = isset( $_GET['code'] ) ? intval( $_GET['code'] ) : null;
$messages         = array(
	101 => __( 'Thank you for subscribing to our newsletter.', 'smaily-connect' ),
	201 => __( 'Form was not submitted using POST method.', 'smaily-connect' ),
	204 => __( 'Input does not contain a recognizable email address.', 'smaily-connect' ),
);
$response_message = isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Subscribing failed with unknown reason.', 'smaily-connect' );
$is_success       = $code === 101;
$is_error         = $code !== null && ! $is_success;

?>

<?php if ( $parameters['has_credentials'] ) : ?>
<form id="smly" action="https://<?php echo esc_attr( $parameters['subdomain'] ); ?>.sendsmaily.net/api/opt-in/" method="post">
	<p class="error" style="padding:15px;background-color:#f2dede;margin:0 0 10px;display:<?php echo $is_error ? 'block' : 'none'; ?>">
		<?php echo esc_html( $response_message ); ?>
	</p>
	<p class="success" style="padding:15px;background-color:#dff0d8;margin:0 0 10px;display:<?php echo $is_success ? 'block' : 'none'; ?>">
		<?php echo esc_html( $response_message ); ?>
	</p>
	<?php if ( $parameters['autoresponder_id'] ) : ?>
		<input type="hidden" name="autoresponder" value="<?php echo esc_attr( $parameters['autoresponder_id'] ); ?>" />
	<?php endif; ?>
	<input type="hidden" name="language" value="<?php echo esc_attr( $parameters['language_code'] ); ?>" />
	<input type="hidden" name="success_url" value="<?php echo esc_url( $parameters['success_url'] ); ?>" />
	<input type="hidden" name="failure_url" value="<?php echo esc_url( $parameters['failure_url'] ); ?>" />
	<p>
		<input type="text" name="email" value="" placeholder="<?php echo esc_html__( 'Email', 'smaily-connect' ); ?>" required />
	</p>
	<?php if ( $parameters['show_name'] ) : ?>
		<p>
			<input type="text" name="name" value="" placeholder="<?php echo esc_html__( 'Name', 'smaily-connect' ); ?>" />
		</p>
	<?php endif; ?>
	<p>
		<button type="submit">
			<?php echo esc_html__( 'Subscribe', 'smaily-connect' ); ?>
		</button>
	</p>
</form>
<?php else : ?>
<p class="error">
	<?php echo esc_html__( 'Smaily credentials not validated. Subscription form will not work!', 'smaily-connect' ); ?>
</p>
<?php endif; ?>
