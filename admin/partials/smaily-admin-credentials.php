<?php
/**
 * @var Smaily_Admin\Renderer $this
 */

defined( 'ABSPATH' ) || exit;

$account   = $this->get_connected_api_account();
$subdomain = $account['subdomain'];
$username  = $account['username'];
$connected = $subdomain && $username;
?>

<fieldset>
	<input type="hidden" name="smaily_api_credentials[enabled]" value="<?php echo esc_attr( $connected ); ?>" />
	<p class="form-field">
		<label for="smaily_subdomain">
			<?php esc_html_e( 'Subdomain', 'smaily' ); ?>
			<input
				<?php if ( ! empty( $subdomain ) ) : ?>
					disabled
				<?php endif; ?>
				class="regular-text code"
				id="smaily_subdomain"
				name="smaily_api_credentials[subdomain]"
				type="text"
				value="<?php echo esc_attr( $subdomain ); ?>"
			/>
		</label>
		<small class="form-text text-muted">
			<?php
			printf(
				/* translators: 1: example subdomain between strong tags */
				esc_html__(
					'For example "%1$s" from https://%1$s.sendsmaily.net/',
					'smaily'
				),
				'<strong>demo</strong>'
			);
			?>
		</small>
	</p>
	<p class="form-field">
		<label for="smaily_username">
			<?php esc_html_e( 'Username', 'smaily' ); ?>
			<input
				<?php if ( ! empty( $username ) ) : ?>
					disabled
				<?php endif; ?>
				class="regular-text code"
				name="smaily_api_credentials[username]"
				type="text" id="smaily_username"
				value="<?php echo esc_attr( $username ); ?>"
			/>
		</label>
	</p>
	<?php if ( ! $connected ) : ?>
	<p class="form-field">
		<label for="smaily_password">
			<?php esc_html_e( 'Password', 'smaily' ); ?>
			<input
				<?php if ( ! empty( $password ) ) : ?>
					disabled
				<?php endif; ?>
				class="regular-text code"
				id="smaily_password"
				name="smaily_api_credentials[password]"
				type="password"
				value=""
			/>
		</label>
	</p>
	<?php endif ?>
</fieldset>
