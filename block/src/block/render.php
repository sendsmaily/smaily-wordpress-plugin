<?php

$form_is_successful = isset( $_GET['code'] ) && (int) $_GET['code'] === 101;
$form_has_response  = isset( $_GET['code'] ) || ! empty( $_GET['code'] );
$response_message   = '';


if ( $form_has_response ) {
	switch ( (int) $_GET['code'] ) {
		case 101:
			$response_message = __( 'Thank you for subscribing to our newsletter.', 'smaily' );
			break;
		case 201:
			$response_message = __( 'Form was not submitted using POST method.', 'smaily' );
			break;
		case 204:
			$response_message = __( 'Input does not contain a recognizable email address.', 'smaily' );
			break;
		default:
			__( 'Could not add to subscriber list for an unknown reason. Probably something in Smaily.', 'smaily' );
	}
}

?>
<div <?php echo get_block_wrapper_attributes(); ?>>
	<form id="smly" action="https://<?php echo esc_html( $attributes['subdomain'] ); ?>.sendsmaily.net/api/opt-in/" method="post">
		<div style="display: flex;flex-direction: column;">
		<?php if ( ! empty( $response_message ) ) : ?>
			<p class="<?php $form_is_successful ? 'success' : 'error'; ?>" style="padding:15px;background-color:#f2dede;margin:0 0 10px;">
				<?php echo esc_html( $response_message ); ?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $attributes['autoresponder_id'] ) ) : ?>
			<input type="hidden" name="autoresponder" value="<?php echo esc_html( $attributes['autoresponder_id'] ); ?>" />
		<?php endif; ?>
		<input type="hidden" name="lang" value="<?php echo esc_html( \Smaily_Helper::maybe_get_current_language_code() ); ?>" />
		<input type="hidden" name="success_url" value="<?php echo empty( $attributes['success_url'] ) ? esc_url( \Smaily_Helper::get_current_url() ) : esc_url( $attributes['success_url'] ); ?>" />
		<input type="hidden" name="failure_url" value="<?php echo empty( $attributes['error_url'] ) ? esc_url( \Smaily_Helper::get_current_url() ) : esc_url( $attributes['success_url'] ); ?>" />
		<p>
			<label>
				<?php echo esc_html( $attributes['email_input_label'] ); ?>
			</label>
			<br/>
			<input type="text" name="email" value="" required />
		</p>
		<?php if ( $attributes['show_name_field'] ) : ?>
			<p>
				<label>
					<?php echo esc_html( $attributes['name_input_label'] ); ?>
				</label>
				<br/>
				<input type="text" name="name" value="" />
			</p>
		<?php endif; ?>
		<p>
			<button class="components-button is-primary" type="submit">
				<?php echo esc_html( $attributes['subscribe_button_label'] ); ?>
			</button>
		</p>
		</div>
	</form>
</div>
