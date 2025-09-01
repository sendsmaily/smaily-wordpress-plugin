<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<?php if ( ! isset( $_GET['post'] ) ) : ?>
	<p class="smaily-connect-cf7-notice error">
		<?php
		esc_html_e(
			'Configuring Smaily integration is disabled when using "Add New Form". Please save this form or edit an already existing form',
			'smaily-connect'
		);
		?>
	</p>
<?php else : ?>
	<?php if ( ! $template_variables['has_credentials'] ) : ?>
		<p class="smaily-connect-cf7-notice error">
			<?php esc_html_e( 'Please authenticate Smaily credentials under Smaily Settings.', 'smaily-connect' ); ?>
		</p>
	<?php else : ?>
		<div>
			<?php if ( ! $template_variables['is_captcha_enabled'] ) : ?>
				<p class="smaily-connect-cf7-notice warning">
					<?php esc_html_e( 'CAPTCHA disabled. Please use a CAPTCHA if this is a public site.', 'smaily-connect' ); ?>
				</p>
			<?php endif; ?>
			<table class="smaily-connect-cf7-table">
				<tr class="form-field">
					<th scope="row">
						<label for="smaily_status">
							<?php esc_html_e( 'Enable Smaily for this form', 'smaily-connect' ); ?>
						</label>
					</th>
					<td>
						<input
							<?php checked( $template_variables['is_enabled'] ); ?> 
							class="smaily-toggle"
							id="smaily_status"
							name="smailyforcf7[status]"
							type="checkbox"
							value="<?php echo (int) $template_variables['is_enabled']; ?>"
						/>
						<label for="smaily_status"></label>
					</td>
				</tr>
				<tr class='form-field'>
					<th>
						<?php esc_html_e( 'Autoresponder', 'smaily-connect' ); ?>
					</th>
					<td>
						<select id='smailyforcf7-autoresponder-select' name='smailyforcf7-autoresponder'>
							<option value='' <?php echo $template_variables['autoresponder_id'] === 0 ? 'selected="selected"' : ''; ?>>
								<?php esc_html_e( 'No autoresponder', 'smaily-connect' ); ?>
							</option>
							<?php foreach ( $template_variables['autoresponders'] as $autoresponder_id => $autoresponder_title ) : ?>
								<option value='<?php echo esc_html( $autoresponder_id ); ?>'
									<?php if ( $template_variables['autoresponder_id'] === $autoresponder_id ) : ?>
										selected='selected'
									<?php endif; ?>
								>
									<?php echo esc_html( $autoresponder_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
		</div>
	<?php endif; ?>
<?php endif; ?>
