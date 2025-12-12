<?php

namespace Smaily_Connect\Admin;

use Smaily_Connect\Includes\Helper;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Includes\Smaily_Client;
use Smaily_Connect\Integrations\WooCommerce\Rss;

class Renderer {
	/**
	 * Smaily options.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Class constructor.
	 *
	 * @param Options $options
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Renders header section for Smaily API credentials form.
	 *
	 * @return void
	 */
	public function render_connection_section_header() {
		?>
		<?php if ( ! $this->are_credentials_valid() ) : ?>
			<div class="smaily-connect-admin-notice notice-error">
				<p>
					<?php
					esc_html_e(
						'There seems to be a problem with your connection to Smaily. Please revalidate your credentials!',
						'smaily-connect'
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		<section class="smaily-connect-connection-section-header">
			<p>
				<?php esc_html_e( "Start by connecting your Smaily account with your website. Go to your Smaily account's settings integrations tab and create a new API user. Then copy the account subdomain, API username and API password below.", 'smaily-connect' ); ?>
			</p>
			<a href="https://smaily.com/help/api/general/create-api-user/" target="_blank">
				<?php esc_html_e( 'How to create API credentials?', 'smaily-connect' ); ?>
			</a>
		</section>
		<?php
	}

	/**
	 * Renders tutorial section subscriber collection HTML content.
	 *
	 * @return void
	 */
	public function render_tutorial_subscriber_collection_section() {
		require_once SMAILY_CONNECT_PLUGIN_PATH . '/admin/partials/smaily-admin-tutorial-subscriber-collection.php';
	}

	/**
	 * Renders tutorial section for WooCommerce integration.
	 *
	 * @return void
	 */
	public function render_tutorial_woocommerce_section() {
		?>
		<section>
			<p>
				<?php esc_html_e( 'Smaily integration with your WooCommerce store allows you to:', 'smaily-connect' ); ?>
				<ol>
					<li>
						<?php esc_html_e( 'Automatically Synchronize subscribers using the daily Subscriber Synchronization.', 'smaily-connect' ); ?>
					</li>
					<li>
						<?php esc_html_e( 'Send Abandoned Cart reminder emails to store customers.', 'smaily-connect' ); ?>
					</li>
					<li>
						<?php esc_html_e( 'Collect subscribers during checkout.', 'smaily-connect' ); ?>
					</li>
					<li>
						<?php esc_html_e( 'Generate RSS-feeds of your products that enables product import into Smaily drag-and-drop templates.', 'smaily-connect' ); ?>
					</li>
				</ol>
			</p>
		</section>
		<?php
	}

	/**
	 * Render API credentials fields HTML form content.
	 *
	 * @return void
	 */
	public function render_credentials_fields() {
		include_once SMAILY_CONNECT_PLUGIN_PATH . '/admin/partials/smaily-admin-credentials.php';
	}

	/**
	 * Render subscriber synchronization additional fields HTML content.
	 *
	 * @return void
	 */
	public function render_sync_additional_fields() {
		include_once SMAILY_CONNECT_PLUGIN_PATH . '/admin/partials/smaily-admin-woocommerce-subscriber-sync.php';
	}

	/**
	 * Renders subscriber synchronization tab header HTML content.
	 *
	 * @return void
	 */
	public function render_subscriber_sync_section_header() {
		?>
		<section>
			<p>
				<?php
					esc_html_e(
						'Subscriber Synchronization allows you Synchronize newsletter subscribers and their information directly to Smaily',
						'smaily-connect'
					);
				?>
			</p>
		</section>
		<?php
	}

	/**
	 * Render abandoned cart tab header HTML content.
	 *
	 * @return void
	 */
	public function render_abandoned_cart_section_header() {
		?>
		<section>
			<p>
				<?php
				esc_html_e(
					'Abandoned Cart allows you to send automated emails to customers who have left their shopping cart.',
					'smaily-connect'
				);
				?>
			</p>
		</section>
		<?php
	}

	/**
	 * Renders abandoned cart status field HTML content.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_abandoned_cart_status_field( $args ) {
		$autoresponders           = Helper::get_autoresponders_list( $this->options );
		$abandoned_cart_status    = get_option( Options::ABANDONED_CART_STATUS_OPTION, Options::ABANDONED_CART_DEFAULT_STATUS );
		$enabled                  = $abandoned_cart_status['enabled'];
		$current_autoresponder_id = $abandoned_cart_status['autoresponder_id'];
		?>
		<fieldset>
			<label for="<?php echo esc_attr( Options::ABANDONED_CART_STATUS_OPTION ); ?>_enabled">
				<input
					type="checkbox"
					id="<?php echo esc_attr( Options::ABANDONED_CART_STATUS_OPTION ); ?>_enabled"
					name="<?php echo esc_attr( Options::ABANDONED_CART_STATUS_OPTION ); ?>[enabled]"
					value="1"
					<?php checked( $enabled, true ); ?>
				/>
				<?php esc_html_e( 'Enabled', 'smaily-connect' ); ?>
			</label>
		</fieldset>
		<fieldset>
			<select name="<?php echo esc_attr( Options::ABANDONED_CART_STATUS_OPTION ); ?>[autoresponder_id]">
				<?php if ( ! empty( $autoresponders ) ) : ?>
					<?php foreach ( $autoresponders as $autoresponder_id => $autoresponder_name ) : ?>
						<option
							value="<?php echo esc_attr( $autoresponder_id ); ?>"
							<?php selected( $autoresponder_id, $current_autoresponder_id ); ?>
						>
							<?php echo esc_html( $autoresponder_name ); ?>
						</option>
					<?php endforeach; ?>
				<?php else : ?>
					<option value="">
						<?php esc_html_e( 'No automations created', 'smaily-connect' ); ?>
					</option>
				<?php endif; ?>
			</select>
		</fieldset>
		<?php
	}

	/**
	 * Render abandoned cart additional fields HTML content.
	 *
	 * @return void
	 */
	public function render_abandoned_additional_fields() {
		include_once SMAILY_CONNECT_PLUGIN_PATH . '/admin/partials/smaily-admin-woocommerce-abandoned-cart.php';
	}

	/**
	 * Render checkout subscription section header HTML content.
	 *
	 * @return void
	 */
	public function render_checkout_subscription_section_header() {
		?>
		<section>
			<p>
				<?php
				esc_html_e(
					'Customers can subscribe by checking "subscribe to newsletter" checkbox on checkout page.',
					'smaily-connect'
				);
				?>
			</p>
		</section>
		<?php
	}

	/**
	 * Render RSS tab header HTML content.
	 *
	 * @return void
	 */
	public function render_rss_section_header() {
		?>
		<section>
			<p>
				<?php
				esc_html_e(
					'Smaily RSS feed allows you insert product feeds to email templates.',
					'smaily-connect'
				);
				?>
			</p>
			<a href="https://smaily.com/help/user-manual/templates/adding-rss-feed-to-template/" target="_blank">
				<?php esc_html_e( 'How to add RSS feed to template?', 'smaily-connect' ); ?>
			</a>
		</section>
		<?php
	}

	/**
	 * Render RSS URL HTML content.
	 *
	 * @return void
	 */
	public function render_rss_url() {
		$url = Rss::make_rss_feed_url(
			get_option( Options::RSS_CATEGORY_OPTION, null ),
			get_option( Options::RSS_LIMIT_OPTION, null ),
			get_option( Options::RSS_SORT_BY_OPTION, null ),
			get_option( Options::RSS_ORDER_BY_OPTION, null ),
			get_option( Options::RSS_TAX_RATE, null )
		);
		?>
		<fieldset style="max-width: 315px;">
			<strong id="smaily-rss-feed-url" name="rss_feed_url" class="smaily-rss-options">
				<?php echo esc_url( $url ); ?>
			</strong>
			<small class="form-text text-muted">
				<?php
				esc_html_e(
					"Copy this URL into your template editor's RSS block, to receive RSS-feed.",
					'smaily-connect'
				);
				?>
			</small>
			<div style="display: flex; align-items: center; margin-top: 10px;">
			<button
				type="button"
				class="button button-secondary"
				id="smaily-rss-feed-url-copy"
			>
				<?php esc_html_e( 'Copy', 'smaily-connect' ); ?>
			</button>
			<span
				id="smaily-rss-feed-url-copy-icon"
				class="dashicons-before dashicons-yes"
				style="color: green; opacity: 0;"
			/>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Renders enabled field checkbox input.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_enabled_field( $args ) {
		$value = get_option( $args['option_name'], false );
		$name  = $args['option_name'];
		$id    = $args['id'] ?? '';
		$help  = $args['help'] ?? '';
		?>
		<fieldset>
			<label for="<?php echo esc_attr( $name ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="1"
					<?php checked( $value, true ); ?>
				/>
				<?php esc_html_e( 'Enabled', 'smaily-connect' ); ?>
			</label>
			<?php if ( ! empty( $help ) ) : ?>
				<small class="form-text text-muted">
					<?php echo esc_html( $help ); ?>
				</small>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Render number field HTML content.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_number_field( $args ) {
		$value = get_option( $args['option_name'] );
		$name  = $args['option_name'];
		$id    = $args['id'] ?? '';
		$min   = $args['min'] ?? '';
		$max   = $args['max'] ?? '';
		$help  = $args['help'] ?? '';
		$class = $args['class'] ?? '';
		$step  = $args['step'] ?? '';
		?>
		<fieldset>
			<label for="<?php echo esc_attr( $name ); ?>">
				<input
					type="number"
					step="<?php echo esc_attr( $step ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					class="<?php echo esc_attr( $class ); ?>"
					min="<?php echo esc_attr( $min ); ?>"
					max="<?php echo esc_attr( $max ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
				/>
				<?php if ( ! empty( $help ) ) : ?>
					<small class="form-text text-muted">
						<?php echo esc_html( $help ); ?>
					</small>
				<?php endif; ?>
			</label>
		</fieldset>
		<?php
	}

	/**
	 * Render select field HTML content.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_select_field( $args ) {
		$value   = get_option( $args['option_name'] );
		$name    = $args['option_name'];
		$options = $args['options'];
		$id      = $args['id'] ?? '';
		$class   = $args['class'] ?? '';
		$help    = $args['help'] ?? '';
		?>
		<fieldset>
			<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="<?php echo esc_attr( $class ); ?>">
				<?php foreach ( $options as $option_value => $option_name ) : ?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $option_value, $value ); ?>>
						<?php echo esc_html( $option_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( ! empty( $help ) ) : ?>
				<small class="form-text text-muted">
					<?php echo esc_html( $help ); ?>
				</small>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Check if the current credentials are stale.
	 * If the User has not connected to Smaily, the credentials are considered valid.
	 *
	 * @return bool
	 */
	public function are_credentials_valid() {
		if ( ! $this->options->has_credentials() ) {
			return true;
		}

		$request  = new Smaily_Client( $this->options );
		$response = $request->list_autoresponders();

		if ( $response['code'] === 200 ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the current API account details including subdomain and username.
	 *
	 * @return array{subdomain: string, username: string}
	 */
	public function get_connected_api_account() {
		$credentials = $this->options->get_api_credentials();

		return array(
			'subdomain' => $credentials['subdomain'],
			'username'  => $credentials['username'],
		);
	}
}
