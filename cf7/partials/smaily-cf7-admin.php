<?php

/**
 * Content of Smaily for Contact Form 7 tab.
 *
 * @package Smaily for Contact Form 7
 * @author  Smaily
 */

?>
<?php if (!isset($_GET['post'])) : ?>
	<div id='form-id-unknown'>
		<p id='smailyforcf7-form-id-error' style='padding:15px; background-color:#f2dede; margin:0 0 10px;'>
			<?php esc_html_e(
				'Configuring Smaily integration is disabled when using "Add New Form". Please save this form or edit an already existing form',
				'smaily'
			); ?>
		</p>
	</div>
<?php else : ?>
	<p id='smailyforcf7-captcha-error' style='padding:15px; background-color:#ffdf92; margin:0 0 10px; display:<?php echo $has_credentials ? 'none' : 'block'; ?>'>
		<?php esc_html_e('Please authenticate smaily credentials under Smaily Settings.', 'smaily'); ?>
	</p>
	<div id='smailyforcf7-credentials-valid' style='display:<?php echo $has_credentials ? 'block' : 'none'; ?>'>
		<p id='smailyforcf7-captcha-error' style='padding:15px; background-color:#ffdf92; margin:0 0 10px; display:<?php echo $captcha_enabled ? 'none' : 'block'; ?>'>
			<?php esc_html_e('Captcha disabled. Please use a captcha if this is a public site.', 'smaily'); ?>
		</p>
		<table class='autoresponders-table' style='margin:15px'>
			<tr class="form-field">
				<th scope="row" style="text-align:left;padding:10px;">
					<label for="smaily_status">
						<?php esc_html_e('Enable Smaily for this form', 'smaily'); ?>
					</label>
				</th>
				<td>
					<input name="smailyforcf7[status]" type="checkbox" <?php checked($is_enabled); ?> class="smaily-toggle" id="smaily_status" value="<?php echo (int)$is_enabled ?>" />
					<label for="smaily_status"></label>
				</td>
			</tr>
			<tr id='smailyforcf7-autoresponders' class='form-field'>
				<th style="text-align:left;padding:10px;">
					<?php esc_html_e('Autoresponder', 'smaily'); ?>
				</th>
				<td>
					<select id='smailyforcf7-autoresponder-select' name='smailyforcf7-autoresponder'>
						<option value='' <?php echo $default_autoresponder === 0 ? 'selected="selected"' : ''; ?>>
							<?php esc_html_e('No autoresponder', 'smaily'); ?>
						</option>
						<?php foreach ($autoresponder_list as $autoresponder_id => $autoresponder_title) : ?>
							<option value='<?php echo esc_html($autoresponder_id); ?>' <?php if ($default_autoresponder === $autoresponder_id) : ?> selected='selected' <?php endif; ?>>
								<?php echo esc_html($autoresponder_title); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
				</th>
			</tr>
		</table>
	</div>
<?php endif; ?>