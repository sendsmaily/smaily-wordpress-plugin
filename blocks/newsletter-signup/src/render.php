<?php

defined( 'ABSPATH' ) || exit;

$has_response = isset( $_GET['code'] );
$is_success   = $has_response && $_GET['code'] === '101';
$is_error     = $has_response && ! $is_success;

$language_code = Smaily_Helper::maybe_get_current_language_code();
$current_url   = Smaily_Helper::get_current_url();

?>

<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'wp-block-smaily-newsletter-block-wrapper' ) ) ); ?>>
	<?php if ( $has_response ) : ?>
		<div class="smaily-newsletter-block-notice-container">
			<?php if ( $is_success && ! empty( $attributes['success_message'] ) ) : ?>
				<div class="notice notice-success" id="smaily-newsletter-block-success-message">
					<div class="notice-content">
						<?php echo esc_html( $attributes['success_message'] ); ?>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( $is_error && ! empty( $attributes['error_message'] ) ) : ?>
				<div class="notice notice-error" id="smaily-newsletter-block-error-message">
					<div class="notice-content">
						<?php echo esc_html( $attributes['error_message'] ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<div class="smaily-newsletter-block-form-container">
		<div class="smaily-newsletter-block-form-body">
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
				<button class="smaily-newsletter-block-button-submit button-primary" type="submit">
					<?php echo esc_html( $attributes['subscribe_button_label'] ); ?>
				</button>
			</form>
		</div>
	</div>
</div>
