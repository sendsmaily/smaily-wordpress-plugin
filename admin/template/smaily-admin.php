<?php

$has_woocommerce = Smaily_Helper::is_woocommerce_active();
$autoresponder_list = $this->autoresponders;

$settings = $this->settings;

if ($has_woocommerce) {
	$sync_additional         = $this->settings['woocommerce']['syncronize_additional'];
	$cart_options            = $this->settings['woocommerce']['cart_options'];
	$customer_sync_enabled   = (bool) (int) $this->settings['woocommerce']['customer_sync_enabled'];
	$is_advanced             = isset($this->settings['is_advanced']) ? (bool) (int) $this->settings['is_advanced'] ?? false : false;
	$advanced_form			 = isset($this->settings['form']) ? $this->settings['form'] ?? false : false;
	$cart_cutoff			 = (int) $this->settings['woocommerce']['cart_cutoff'];
	$cart_enabled            = (bool) (int) $this->settings['woocommerce']['enable_cart'];
	$cart_autoresponder_name = $this->settings['woocommerce']['cart_autoresponder'];
	$cart_autoresponder_id   = (int) $this->settings['woocommerce']['cart_autoresponder_id'];
	$cb_enabled              = (bool) (int) $this->settings['woocommerce']['checkout_checkbox_enabled'];
	$cb_auto_checked         = (bool) (int) $this->settings['woocommerce']['checkbox_auto_checked'];
	$cb_order_selected       = $this->settings['woocommerce']['checkbox_order'];
	$cb_loc_selected         = $this->settings['woocommerce']['checkbox_location'];
	$rss_category            = $this->settings['woocommerce']['rss_category'];
	$rss_limit               = $this->settings['woocommerce']['rss_limit'];
	$rss_order_by            = $this->settings['woocommerce']['rss_order_by'];
	$rss_order               = $this->settings['woocommerce']['rss_order'];
	$rss_feed_url 		     = Smaily_WC\Data_Handler::make_rss_feed_url($rss_category, $rss_limit, $rss_order_by, $rss_order);
	$cat_args 				 = array(
		'orderby'    => 'name',
		'order'      => 'asc',
		'hide_empty' => false,
	);

	$wc_categories_list = get_terms('product_cat', $cat_args);
}

?>

<div id="smaily-settings" class="wrap">
	<h1>
		<span id="smaily-title">
			<span id="capital-s">S</span>maily
		</span>

		<?php echo esc_html__('Plugin Settings', 'smaily'); ?>

		<div class="loader"></div>
	</h1>

	<?php if (isset($autoresponder_list) && empty($autoresponder_list)) : ?>
		<div class="error smaily-notice is-dismissible">
			<p>
				<?php
				esc_html_e(
					'There seems to be a problem with your connection to Smaily. Please revalidate your credentials!',
					'smaily'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div id="tabs">
		<div class="nav-tab-wrapper">
			<ul id="tabs-list">
				<li>
					<a href="#general" class="nav-tab nav-tab-active">
						<?php echo esc_html__('General', 'smaily'); ?>
					</a>
				</li>
				<li>
					<a href="#newsletter" class="nav-tab">
						<?php echo esc_html__('Newsletter', 'smaily'); ?>
					</a>
				</li>
				<?php if ($has_woocommerce) : ?>
					<li>
						<a href="#customer" class="nav-tab">
							<?php echo esc_html__('Customer Synchronization', 'smaily'); ?>
						</a>
					</li>
					<li>
						<a href="#cart" class="nav-tab">
							<?php echo esc_html__('Abandoned Cart', 'smaily'); ?>
						</a>
					</li>
					<li>
						<a href="#checkout_subscribe" class="nav-tab">
							<?php echo esc_html__('Checkout Opt-in', 'smaily'); ?>
						</a>
					</li>
					<li>
						<a href="#rss" class="nav-tab">
							<?php echo esc_html__('RSS Feed', 'smaily'); ?>
						</a>
					</li>
				<?php endif; ?>
			</ul>
		</div>

		<form method="POST">
			<?php wp_nonce_field('smaily-settings-nonce', 'nonce', false); ?>

			<div id="general">
				<table class="form-table">
					<tbody>
						<tr class="form-field">
							<th scope="row"></th>
							<td>
								<a href="https://smaily.com/help/api/general/create-api-user/" target="_blank">
									<?php echo esc_html__('How to create API credentials?', 'smaily'); ?>
								</a>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row">
								<label for="api-subdomain"><?php echo esc_html__('Subdomain', 'smaily'); ?></label>
							</th>
							<td>
								<input id="api-subdomain" name="api[subdomain]" value="<?php echo ($this->credentials['subdomain']) ? esc_html($this->credentials['subdomain']) : ''; ?>" type="text" />
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
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row">
								<label for="api-username"><?php echo esc_html__('API username', 'smaily'); ?></label>
							</th>
							<td>
								<input id="api-username" name="api[username]" value="<?php echo ($this->credentials['username']) ? esc_html($this->credentials['username']) : ''; ?>" type="text" />
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row">
								<label for="api-password"><?php echo esc_html__('API password', 'smaily'); ?></label>
							</th>
							<td>
								<input id="api-password" name="api[password]" value="<?php echo ($this->credentials['password']) ? esc_html($this->credentials['password']) : ''; ?>" type="password" autocomplete="off" />
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row">
								<span><?php echo esc_html__('Subscribe Widget', 'smaily'); ?></span>
							</th>
							<td>
								<?php
								echo esc_html__(
									'To add a subscribe widget, use Widgets menu. Validate credentials before using.',
									'smaily'
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div id="newsletter">

				<table class="form-table">
					<tbody>
						<tr class="form-field">
							<th scope="row">
								<label for="is_advanced">
									<?php echo esc_html__('Is it advanced?', 'smaily'); ?>
								</label>
								<p style="font-size:10px; font-weight: 400;"><?php echo esc_html__('Note: When you save with advanced option switched OFF, default form will be used.', 'smaily-for-wp'); ?></p>
							</th>
							<td>
								<input name="advanced-form[is_advanced]" type="checkbox" <?php checked($this->settings['is_advanced']); ?> class="smaily-toggle" id="is_advanced" value="<?php echo (int)esc_html($this->settings['is_advanced']); ?>" />
								<label for="is_advanced"></label>
							</td>
						</tr>
						<tr class="form-field is-advanced-row">
							<th scope="row">
								<label for="smaily-advanced-form">
									<?php echo esc_html__('Advanced form', 'smaily'); ?> <a id="reset-form" href="#">(generate)</a>
								</label>
							</th>
							<td>
								<textarea name="advanced-form[form]" id="smaily-advanced-form" cols="15" rows="15"><?php echo esc_html($this->settings['form']); ?></textarea>
							</td>
						</tr>
					</tbody>
				</table>

			</div>
			<?php if ($has_woocommerce) : ?>
				<div id="customer">
					<table class="form-table">
						<tbody>
							<tr class="form-field">
								<th scope="row">
									<label for="customer-sync-enabled">
										<?php echo esc_html__('Enable Customer synchronization', 'smaily'); ?>
									</label>
								</th>
								<td>
									<input name="customer_sync[enabled]" type="checkbox" <?php checked($customer_sync_enabled); ?> class="smaily-toggle" id="customer-sync-enabled" value="1" />
									<label for="customer-sync-enabled"></label>
								</td>
							</tr>
							<tr class="form-field">
								<th scope="row">
									<label for="customer-sync-fields">
										<?php echo esc_html__('Syncronize additional fields', 'smaily'); ?>
									</label>
								</th>
								<td>
									<select id="customer-sync-fields" name="customer_sync[fields][]" multiple="multiple" size="10">
										<?php
										// All available option fields.
										$sync_options = array(
											'customer_group'   => __('Customer Group', 'smaily'),
											'customer_id'      => __('Customer ID', 'smaily'),
											'user_dob'         => __('Date Of Birth', 'smaily'),
											'first_registered' => __('First Registered', 'smaily'),
											'first_name'       => __('Firstname', 'smaily'),
											'user_gender'      => __('Gender', 'smaily'),
											'last_name'        => __('Lastname', 'smaily'),
											'nickname'         => __('Nickname', 'smaily'),
											'user_phone'       => __('Phone', 'smaily'),
											'site_title'       => __('Site Title', 'smaily'),
										);
										// Add options for select and select them if allready saved before.
										foreach ($sync_options as $value => $name) {
											$selected = in_array($value, $sync_additional, true) ? 'selected' : '';
											echo ("<option value='$value' $selected>$name</option>");
										}
										?>
									</select>
									<small class="form-text text-muted">
										<?php
										echo esc_html__(
											'Select fields you wish to synchronize along with subscriber email and store URL',
											'smaily'
										);
										?>
									</small>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div id="cart">
					<table class="form-table">
						<tbody>
							<tr class="form-field">
								<th scope="row">
									<label for="abandoned-cart-enabled">
										<?php echo esc_html__('Enable Abandoned Cart reminder', 'smaily'); ?>
									</label>
								</th>
								<td>
									<input name="abandoned_cart[enabled]" type="checkbox" <?php checked($cart_enabled); ?> class="smaily-toggle" id="abandoned-cart-enabled" value="1" />
									<label for="abandoned-cart-enabled"></label>
								</td>
							</tr>
							<tr class="form-field">
								<th scope="row">
									<label for="abandoned-cart-autoresponder">
										<?php echo esc_html__('Cart Autoresponder ID', 'smaily'); ?>
									</label>
								</th>
								<td>
									<select id="abandoned-cart-autoresponder" name="abandoned_cart[autoresponder]">
										<?php if (!empty($autoresponder_list)) : ?>
											<?php foreach ($autoresponder_list as $autoresponder_id => $autoresponder_name) : ?>
												<option <?php selected($cart_autoresponder_id, $autoresponder_id); ?> value="<?php echo $autoresponder_id; ?>">
													<?php echo esc_html($autoresponder_name); ?>
												</option>
											<?php endforeach; ?>
										<?php else : ?>
											<option value="">
												<?php echo esc_html__('No automations created', 'smaily'); ?>
											</option>
										<?php endif; ?>
									</select>
								</td>
							</tr>
							<tr class="form-field">
								<th scope="row">
									<label for="abandoned-cart-fields">
										<?php echo esc_html__('Additional cart fields', 'smaily'); ?>
									</label>
								</th>
								<td>
									<select id="abandoned-cart-fields" name="abandoned_cart[fields][]" multiple="multiple" size="8">
										<?php
										// All available option fields.
										$cart_fields = array(
											'first_name'       => __('Customer First Name', 'smaily'),
											'last_name'        => __('Customer Last Name', 'smaily'),
											'product_name'     => __('Product Name', 'smaily'),
											'product_description' => __('Product Description', 'smaily'),
											'product_sku'      => __('Product SKU', 'smaily'),
											'product_quantity' => __('Product Quantity', 'smaily'),
											'product_base_price' => __('Product Base Price', 'smaily'),
											'product_price'    => __('Product Price', 'smaily'),
											'product_images'    => __('Product Images', 'smaily')
										);
										// Add options for select and select them if allready saved before.
										foreach ($cart_fields as $value => $name) {
											$select = in_array($value, $cart_options, true) ? 'selected' : '';
											echo ("<option value='$value' $select>$name</option>");
										}
										?>
									</select>
									<small id="cart-options-help" class="form-text text-muted">
										<?php
										echo esc_html__(
											'Select fields wish to send to Smaily template along with subscriber email and store url.',
											'smaily'
										);
										?>
									</small>
								</td>
							</tr>
							<tr class="form-field">
								<th scope="row">
									<label for="abandoned-cart-delay">
										<?php echo esc_html__('Cart cutoff time', 'smaily'); ?>
									</label>
								</th>
								<td> <?php echo esc_html__('Consider cart abandoned after:', 'smaily'); ?>
									<input id="abandoned-cart-delay" name="abandoned_cart[delay]" style="width:65px;" value="<?php echo ($cart_cutoff) ? $cart_cutoff : ''; ?>" type="number" min="10" />
									<?php echo esc_html__('minute(s)', 'smaily'); ?>

									<small class="form-text text-muted">
										<?php echo esc_html__('Minimum 10 minutes.', 'smaily'); ?>
									</small>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div id="checkout_subscribe">
					<table class="form-table">
						<tbody>
							<tr class="form-field">
								<th scope="row">
									<span>
										<?php echo esc_html__('Subscription checkbox', 'smaily'); ?>
									</span>
								</th>
								<td>
									<?php
									esc_html_e(
										'Customers can subscribe by checking "subscribe to newsletter" checkbox on checkout page.',
										'smaily'
									);
									?>
								</td>
							</tr>
							<tr class="form-field">
								<th scope="row">
									<label for="checkout-checkbox-enabled">
										<?php echo esc_html__('Enable', 'smaily'); ?>
									</label>
								</th>
								<td>
									<input name="checkout_checkbox[enabled]" type="checkbox" class="smaily-toggle" id="checkout-checkbox-enabled" <?php checked($cb_enabled); ?> value="1" />
									<label for="checkout-checkbox-enabled"></label>
								</td>
							</tr>
							<tr class="form-field">
								<th scope="row">
									<label for="checkout-checkbox-auto-check">
										<?php echo esc_html__('Checked by default', 'smaily'); ?>
									</label>
								</th>
								<td>
									<input name="checkout_checkbox[auto_check]" type="checkbox" id="checkout-checkbox-auto-check" <?php checked($cb_auto_checked); ?> value="1" />
								</td>
							</tr>
							<tr class="form-field">
								<th scope="row">
									<label for="checkout-checkbox-location">
										<?php echo esc_html__('Location', 'smaily'); ?>
									</label>
								</th>
								<td>
									<select name="checkout_checkbox[position]">
										<option value="before" <?php echo ('before' === $cb_order_selected ? 'selected' : ''); ?>>
											<?php echo esc_html__('Before', 'smaily'); ?>
										</option>
										<option value="after" <?php echo ('after' === $cb_order_selected ? 'selected' : ''); ?>>
											<?php echo esc_html__('After', 'smaily'); ?>
										</option>
									</select>
									<select id="checkout-checkbox-location" name="checkout_checkbox[location]">
										<?php
										$cb_loc_available = array(
											'order_notes' => __('Order notes', 'smaily'),
											'checkout_billing_form' => __('Billing form', 'smaily'),
											'checkout_shipping_form' => __('Shipping form', 'smaily'),
											'checkout_registration_form' => __('Registration form', 'smaily'),
										);
										// Display option and select saved value.
										foreach ($cb_loc_available as $loc_value => $loc_translation) :
										?>
											<option value="<?php echo esc_html($loc_value); ?>" <?php echo $cb_loc_selected === $loc_value ? 'selected' : ''; ?>>
												<?php echo esc_html($loc_translation); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div id="rss">
					<table class="form-table">
						<tbody>
							<tr class="form-field">
								<th scope="row">
									<label for="rss-limit">
										<?php echo esc_html__('Product limit', 'smaily'); ?>
									</label>
								</th>
								<td>
									<input type="number" id="rss-limit" name="rss[limit]" class="smaily-rss-options" min="1" max="250" value="<?php echo esc_html($rss_limit); ?>" />
									<small>
										<?php
										echo esc_html__(
											'Limit how many products you will add to your field. Maximum 250.',
											'smaily'
										);
										?>
									</small>
								</td>
							</tr>
							<tr class="form-field">
								<th scope="row">
									<label for="rss-category">
										<?php echo esc_html__('Product category', 'smaily'); ?>
									</label>
								</th>
								<td>
									<select id="rss-category" name="rss[category]" class="smaily-rss-options">
										<?php
										// Display available WooCommerce product categories and saved category.
										foreach ($wc_categories_list as $category) :
										?>
											<option value="<?php echo esc_html($category->slug); ?>" <?php echo $rss_category === $category->slug ? 'selected' : ''; ?>>
												<?php echo esc_html($category->name); ?>
											</option>
										<?php endforeach; ?>
										<option value="" <?php echo empty($rss_category) ? 'selected' : ''; ?>>
											<?php echo esc_html__('All products', 'smaily'); ?>
										</option>
									</select>
									<small>
										<?php
										echo esc_html__(
											'Show products from specific category',
											'smaily'
										);
										?>
									</small>
								</td>
							</tr>
							<tr class="form-field">
								<th scope="row">
									<label for="rss-sort-field">
										<?php echo esc_html__('Order products by', 'smaily'); ?>
									</label>
								</th>
								<td id="smaily_rss_order_options">
									<select id="rss-sort-field" name="rss[sort_field]" class="smaily-rss-options">
										<?php
										$sort_categories_available = array(
											'date'     => __('Created At', 'smaily'),
											'id'       => __('ID', 'smaily'),
											'modified' => __('Modified At', 'smaily'),
											'name'     => __('Name', 'smaily'),
											'rand'     => __('Random', 'smaily'),
											'type'     => __('Type', 'smaily'),
										);
										// Display option and select saved value.
										foreach ($sort_categories_available as $sort_value => $sort_name) :
										?>
											<option <?php selected($rss_order_by, $sort_value); ?> value="<?php echo esc_html($sort_value); ?>">
												<?php echo esc_html($sort_name); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<select id="rss-sort-order" name="rss[sort_order]" class="smaily-rss-options">
										<option value="ASC" <?php selected($rss_order, 'ASC'); ?>>
											<?php echo esc_html__('Ascending', 'smaily'); ?>
										</option>
										<option value="DESC" <?php selected($rss_order, 'DESC'); ?>>
											<?php echo esc_html__('Descending', 'smaily'); ?>
										</option>
									</select>
								</td>
							</tr>
							<tr class="form-field">
								<th scope="row">
									<span>
										<?php echo esc_html__('Product RSS feed', 'smaily'); ?>
									</span>
								</th>
								<td>
									<strong id="smaily-rss-feed-url" name="rss_feed_url">
										<?php echo esc_html($rss_feed_url); ?>
									</strong>
									<small>
										<?php
										echo esc_html__(
											"Copy this URL into your template editor's RSS block, to receive RSS-feed.",
											'smaily'
										);
										?>
									</small>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			<button type="submit" name="save" class="button-primary">
				<?php echo esc_html__('Save Settings', 'smaily'); ?>
			</button>
		</form>
	</div>
</div>