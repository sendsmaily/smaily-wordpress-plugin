<?php

namespace Smaily_Connect\Blocks\Newsletter_Signup;

defined( 'ABSPATH' ) || exit;

use Smaily_Connect\Includes\Helper;

class Integration {
	/**
	 * Renders the newsletter signup block.
	 *
	 * @param array $attributes Block attributes.
	 * @param string $content Block content.
	 */
	public static function render( $attributes, $content ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads a Smaily redirect response code for display; external redirect, not a form post to us, so nonce N/A.
		$code       = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : null;
		$is_success = $code === '101';
		$is_error   = $code && ! $is_success;

		$language_code = Helper::get_current_language_code();
		$current_url   = Helper::get_current_url();

		// Ensure default values for attributes
		$defaults   = array(
			'subscribeButtonBackgroundColor' => '#000',
			'subscribeButtonTextColor'       => '#fff',
			'subscribeButtonWidth'           => 'auto',
			'subscribeButtonBorderRadius'    => 0,
			'successMessage'                 => '',
			'errorMessage'                   => '',
			'subdomain'                      => '',
			'autoresponderId'                => '',
			'successURL'                     => '',
			'errorURL'                       => '',
			'showNameField'                  => false,
			'nameInputLabel'                 => 'Name',
			'emailInputLabel'                => 'Email',
			'subscribeButtonLabel'           => 'Subscribe',
			'hiddenFields'                   => array(),
		);
		$attributes = wp_parse_args( $attributes, $defaults );

		$block_attributes = get_block_wrapper_attributes(
			array(
				'class' => 'wp-block-smaily-newsletter-block-wrapper',
				'style' => sprintf(
					'--smaily-subscribe-button-bg-color: %s;
					--smaily-subscribe-button-text-color: %s;
					--smaily-subscribe-button-width: %s;
					--smaily-subscribe-button-border-radius: %spx;
					',
					esc_attr( self::css_color( $attributes['subscribeButtonBackgroundColor'], $defaults['subscribeButtonBackgroundColor'] ) ),
					esc_attr( self::css_color( $attributes['subscribeButtonTextColor'], $defaults['subscribeButtonTextColor'] ) ),
					esc_attr( self::css_width( $attributes['subscribeButtonWidth'] ) ),
					esc_attr( self::css_radius( $attributes['subscribeButtonBorderRadius'] ) )
				),
			)
		);

		ob_start();
		?>
		<div <?php echo wp_kses_data( $block_attributes ); ?>>
			<?php if ( $code ) : ?>
				<div class="smaily-newsletter-block-notice-container">
					<?php if ( $is_success && ! empty( $attributes['successMessage'] ) ) : ?>
						<div class="components-notice is-success" id="smaily-newsletter-block-success-message">
							<div class="components-notice__content">
								<?php echo wp_kses_post( $attributes['successMessage'] ); ?>
							</div>
						</div>
					<?php endif; ?>
					<?php if ( $is_error && ! empty( $attributes['errorMessage'] ) ) : ?>
						<div class="components-notice is-error" id="smaily-newsletter-block-error-message">
							<div class="components-notice__content">
								<?php echo wp_kses_post( $attributes['errorMessage'] ); ?>
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
							<input type="hidden" name="autoresponder" value="<?php echo esc_attr( $attributes['autoresponderId'] ); ?>" />
						<?php endif ?>
						<?php if ( ! empty( $language_code ) ) : ?>
							<input type="hidden" name="language" value="<?php echo esc_attr( $language_code ); ?>" />
						<?php endif ?>
						<input type="hidden" name="success_url" value="<?php echo ! empty( $attributes['successURL'] ) ? esc_url( $attributes['successURL'] ) : esc_url( $current_url ); ?>" />
					<input type="hidden" name="failure_url" value="<?php echo ! empty( $attributes['errorURL'] ) ? esc_url( $attributes['errorURL'] ) : esc_url( $current_url ); ?>" />
					<?php if ( ! empty( $attributes['hiddenFields'] ) && is_array( $attributes['hiddenFields'] ) ) : ?>
						<?php foreach ( $attributes['hiddenFields'] as $field ) : ?>
							<?php
							$field_name  = isset( $field['name'] ) ? sanitize_text_field( $field['name'] ) : '';
							$field_value = isset( $field['value'] ) ? sanitize_text_field( $field['value'] ) : '';
							?>
							<?php if ( ! empty( $field_name ) ) : ?>
								<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $field_value ); ?>" />
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
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
		<?php

		return ob_get_clean();
	}

	/**
	 * A button colour as the block's editor saves it, else the default. The
	 * value goes into the wrapper's `style` attribute, and esc_attr() does not
	 * stop CSS: anything but a colour could add declarations (CON-346).
	 * Accepted: what the editor saves — a hex colour (3, 4, 6 or 8 digits,
	 * the custom colour picker) and var(--name) with an optional hex
	 * fallback (the editor's defaults and the theme presets,
	 * `var(--wp--preset--color--slug)`).
	 *
	 * @param mixed  $value    The saved attribute.
	 * @param string $fallback The colour used when the value is not one.
	 * @return string
	 */
	private static function css_color( $value, $fallback ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		$hex   = '#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})';
		$color = '/^(?:' . $hex . '|var\(\s*--[a-z0-9_-]+\s*(?:,\s*' . $hex . '\s*)?\))$/i';

		return preg_match( $color, $value ) === 1 ? $value : $fallback;
	}

	/**
	 * The button width: `auto` or a number with an optional unit, else `auto`.
	 *
	 * @param mixed $value The saved attribute.
	 * @return string
	 */
	private static function css_width( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		return preg_match( '/^(?:auto|\d+(?:\.\d+)?(?:px|%|em|rem|vw|vh|ch)?)$/', $value ) === 1 ? $value : 'auto';
	}

	/**
	 * The button corner radius in pixels: a non-negative number, else 0.
	 *
	 * @param mixed $value The saved attribute.
	 * @return string
	 */
	private static function css_radius( $value ) {
		if ( ( is_int( $value ) || is_float( $value ) ) && $value >= 0 ) {
			return (string) $value;
		}
		if ( is_string( $value ) && preg_match( '/^\d+(?:\.\d+)?$/', $value ) === 1 ) {
			return $value;
		}

		return '0';
	}
}
