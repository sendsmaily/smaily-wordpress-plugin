<?php

defined( 'ABSPATH' ) || exit;

$has_response = isset( $_GET['code'] );
$is_success   = $has_response && $_GET['code'] === '101';
$is_error     = $has_response && ! $is_success;

$language_code = \Smaily_Helper::get_current_language_code();
$current_url   = \Smaily_Helper::get_current_url();

$block_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'wp-block-smaily-newsletter-block-wrapper',
		'style' => sprintf(
			'--smaily-subscribe-button-bg-color: %s; --smaily-subscribe-button-text-color: %s; --smaily-subscribe-button-width: %s;',
			esc_attr( $attributes['subscribeButtonBackgroundColor'] ),
			esc_attr( $attributes['subscribeButtonTextColor'] ),
			esc_attr( $attributes['subscribeButtonWidth'] )
		),
	)
);

?>

<div <?php echo wp_kses_data( $block_attributes ); ?>>
	<?php if ( $has_response ) : ?>
		<div class="smaily-newsletter-block-notice-container">
			<?php if ( $is_success && ! empty( $attributes['successMessage'] ) ) : ?>
				<div class="components-notice is-success" id="smaily-newsletter-block-success-message">
					<div class="components-notice__content">
						<?php echo esc_html( $attributes['successMessage'] ); ?>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( $is_error && ! empty( $attributes['errorMessage'] ) ) : ?>
				<div class="components-notice is-error" id="smaily-newsletter-block-error-message">
					<div class="components-notice__content">
						<?php echo esc_html( $attributes['errorMessage'] ); ?>
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
			<?php if ( ! empty( $attributes['autoresponderId'] ) ) : ?>
				<input type="hidden" name="autoresponder" value="<?php echo esc_html( $attributes['autoresponderId'] ); ?>" />
			<?php endif ?>
			<?php if ( ! empty( $language_code ) ) : ?>
				<input type="hidden" name="language" value="<?php echo esc_html( $language_code ); ?>" />
			<?php endif ?>
			<input type="hidden" name="success_url" value="<?php echo ! empty( $attributes['successURL'] ) ? esc_url( $attributes['successURL'] ) : esc_url( $current_url ); ?>" />
			<input type="hidden" name="failure_url" value="<?php echo ! empty( $attributes['errorURL'] ) ? esc_url( $attributes['errorURL'] ) : esc_url( $current_url ); ?>" />
			<?php if ( $attributes['showNameField'] === true ) : ?>
			<div class="smaily-newsletter-block-form-control">
				<label for="name">
					<?php echo esc_html( $attributes['nameInputLabel'] ); ?>
				</label>
				<input type="text" name="name" id="smaily-newsletter-block-input-name" class="smaily-newsletter-block-regular-text">
			</div>
			<?php endif ?>
			<div class="smaily-newsletter-block-form-control">
				<label for="email">
					<?php echo esc_html( $attributes['emailInputLabel'] ); ?>
				</label>
				<input type="email" name="email" id="smaily-newsletter-block-input-email" class="smaily-newsletter-block-regular-text" required>
			</div>
			<button class="smaily-newsletter-block-button-submit components-button is-primary" type="submit">
				<?php echo esc_html( $attributes['subscribeButtonLabel'] ); ?>
			</button>
		</form>
	</div>
</div>
