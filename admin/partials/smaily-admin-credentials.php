<?php
/**
 * The credentials form for the Smaily API.
 *
 * @var Smaily_Connect\Admin\Renderer $this
 */

defined( 'ABSPATH' ) || exit;

$account   = $this->get_connected_api_account();
$subdomain = $account['subdomain'];
$username  = $account['username'];
$connected = $subdomain && $username;
?>

<fieldset>
	<input type="hidden" name="<?php echo esc_attr( \Smaily_Connect\Includes\Options::API_CREDENTIALS_OPTION ); ?>[enabled]" value="<?php echo esc_attr( $connected ); ?>" />
	<p class="smaily-connect-admin-form-field">
		<label for="smaily_subdomain">
			<?php esc_html_e( 'Smaily account subdomain', 'smaily-connect' ); ?>*
			<input
				<?php if ( ! empty( $subdomain ) ) : ?>
					disabled
				<?php endif; ?>
				required
				class="regular-text code"
				id="smaily_subdomain"
				name="<?php echo esc_attr( \Smaily_Connect\Includes\Options::API_CREDENTIALS_OPTION ); ?>[subdomain]"
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
					'smaily-connect'
				),
				'<strong>demo</strong>'
			);
			?>
		</small>
	</p>
	<p class="smaily-connect-admin-form-field">
		<label for="smaily_username">
			<?php esc_html_e( 'API Username', 'smaily-connect' ); ?>*
			<input
				<?php if ( ! empty( $username ) ) : ?>
					disabled
				<?php endif; ?>
				required
				class="regular-text code"
				name="<?php echo esc_attr( \Smaily_Connect\Includes\Options::API_CREDENTIALS_OPTION ); ?>[username]"
				type="text" id="smaily_username"
				value="<?php echo esc_attr( $username ); ?>"
			/>
		</label>
	</p>
	<?php if ( ! $connected ) : ?>
	<p class="smaily-connect-admin-form-field">
		<label for="smaily_password">
			<?php esc_html_e( 'API Password', 'smaily-connect' ); ?>*
			<input
				<?php if ( ! empty( $password ) ) : ?>
					disabled
				<?php endif; ?>
				required
				class="regular-text code"
				id="smaily_password"
				name="<?php echo esc_attr( \Smaily_Connect\Includes\Options::API_CREDENTIALS_OPTION ); ?>[password]"
				type="password"
				value=""
			/>
		</label>
	</p>
	<?php endif ?>
</fieldset>
