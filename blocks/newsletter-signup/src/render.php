<?php

defined( 'ABSPATH' ) || exit;

$has_response = isset( $_GET['code'] );
$is_success   = $has_response && $_GET['code'] === '101';
$is_error     = $has_response && ! $is_success;

$language_code = \Smaily_Helper::get_current_language_code();
$current_url   = \Smaily_Helper::get_current_url();

$subscribe_button_bg_color = $attributes['subscribe_button_bg_color'];
if ( isset( $attributes['style']['elements']['button']['color']['background'] ) ) {
	$subscribe_button_bg_color = \Smaily_Block::parse_color_preset( $attributes['style']['elements']['button']['color']['background'] );
}

$subscribe_button_text_color = $attributes['subscribe_button_text_color'];
if ( isset( $attributes['style']['elements']['button']['color']['text'] ) ) {
	$subscribe_button_text_color = \Smaily_Block::parse_color_preset( $attributes['style']['elements']['button']['color']['text'] );
}

$block_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'wp-block-smaily-newsletter-block-wrapper',
		'style' => sprintf( '--smaily-subscribe-button-bg-color: %s; --smaily-subscribe-button-text-color: %s;', $subscribe_button_bg_color, $subscribe_button_text_color ),
	)
);

?>

<div <?php echo wp_kses_data( $block_attributes ); ?>>
	<?php if ( $has_response ) : ?>
		<div class="smaily-newsletter-block-notice-container">
			<?php if ( $is_success && ! empty( $attributes['success_message'] ) ) : ?>
				<div class="components-notice is-success" id="smaily-newsletter-block-success-message">
					<div class="components-notice__content">
						<?php echo esc_html( $attributes['success_message'] ); ?>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( $is_error && ! empty( $attributes['error_message'] ) ) : ?>
				<div class="components-notice is-error" id="smaily-newsletter-block-error-message">
					<div class="components-notice__content">
						<?php echo esc_html( $attributes['error_message'] ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<div class="smaily-newsletter-block-form-container">
		<form
			class="smaily-newsletter-block-form"
			action="<?php echo esc_url( sprintf( 'https://%s.sendsmaily.net/api/opt-in/', $attributes['subdomain'] ) ); ?>"
			method="post"
			autocomplete="off"
		>
			<?php if ( ! empty( $attributes['autoresponder_id'] ) ) : ?>
				<input type="hidden" name="autoresponder" value="<?php echo esc_html( $attributes['autoresponder_id'] ); ?>" />
			<?php endif ?>
			<?php if ( ! empty( $language_code ) ) : ?>
				<input type="hidden" name="lang" value="<?php echo esc_html( $language_code ); ?>" />
			<?php endif ?>
			<input type="hidden" name="success_url" value="<?php echo ! empty( $attributes['success_url'] ) ? esc_url( $attributes['success_url'] ) : esc_url( $current_url ); ?>" />
			<input type="hidden" name="failure_url" value="<?php echo ! empty( $attributes['error_url'] ) ? esc_url( $attributes['error_url'] ) : esc_url( $current_url ); ?>" />
			<?php if ( $attributes['show_name_field'] === true ) : ?>
			<div class="smaily-newsletter-block-form-control">
				<label for="name">
					<?php echo esc_html( $attributes['name_input_label'] ); ?>
				</label>
				<input type="text" name="name" id="smaily-newsletter-block-input-name" class="smaily-newsletter-block-regular-text">
			</div>
			<?php endif ?>
			<div class="smaily-newsletter-block-form-control">
				<label for="email">
					<?php echo esc_html( $attributes['email_input_label'] ); ?>
				</label>
				<input type="email" name="email" id="smaily-newsletter-block-input-email" class="smaily-newsletter-block-regular-text" required>
			</div>
			<button class="smaily-newsletter-block-button-submit components-button is-primary" type="submit">
				<?php echo esc_html( $attributes['subscribe_button_label'] ); ?>
			</button>
		</form>
	</div>
</div>
