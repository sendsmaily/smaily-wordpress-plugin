<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/admin
 */

use Smaily_WC\Rss;

class Smaily_Admin {
	/**
	 * The ID of this plugin.
	 *
	 *
	 * @access private
	 * @var    string  $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 *
	 * @access private
	 * @var    string  $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Handler for storing/retrieving data via Options API.
	 *
	 *
	 * @access private
	 * @var    Smaily_Options Handler for WordPress Options API.
	 */
	private $options;

	/**
	 * Tabs for the settings page.
	 *
	 * @access private
	 * @var    array $tabs The tabs for the settings page.
	 */
	private $tabs;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param Smaily_Options $options     Reference to option handler class.
	 * @param string         $plugin_name The name of this plugin.
	 * @param string         $version     The version of this plugin.
	 */
	public function __construct( Smaily_Options $options, $plugin_name, $version ) {
		$this->options     = $options;
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->tabs        = array(
			'connection' => array(
				'title'              => __( 'Connection', 'smaily' ),
				'submit_button_text' => $options->has_credentials() ? __( 'Disconnect', 'smaily' ) : __( 'Connect', 'smaily' ),
				'url'                => add_query_arg(
					array(
						'page' => 'smaily-settings',
						'tab'  => 'connection',
					),
					''
				),
				'register_settings'  => array( $this, 'register_connection_tab_settings' ),
				'option_group'       => 'smaily_settings_connection',
			),
		);

		if ( Smaily_Helper::is_woocommerce_active() && $this->options->has_credentials() ) {
			$this->tabs['customer_sync'] = array(
				'title'              => __( 'Customer Synchronization', 'smaily' ),
				'submit_button_text' => __( 'Save', 'smaily' ),
				'url'                => add_query_arg(
					array(
						'page' => 'smaily-settings',
						'tab'  => 'customer_sync',
					),
					''
				),
				'register_settings'  => array( $this, 'register_customer_sync_tab_settings' ),
				'option_group'       => 'smaily_settings_customer_sync',
			);

			$this->tabs['abandoned_cart'] = array(
				'title'              => __( 'Abandoned Cart', 'smaily' ),
				'submit_button_text' => __( 'Save', 'smaily' ),
				'url'                => add_query_arg(
					array(
						'page' => 'smaily-settings',
						'tab'  => 'abandoned_cart',
					),
					''
				),
				'register_settings'  => array( $this, 'register_abandoned_cart_tab_settings' ),
				'option_group'       => 'smaily_settings_abandoned_cart',
			);

			$this->tabs['rss'] = array(
				'title'              => __( 'RSS', 'smaily' ),
				'submit_button_text' => __( 'Save', 'smaily' ),
				'url'                => add_query_arg(
					array(
						'page' => 'smaily-settings',
						'tab'  => 'rss',
					),
					''
				),
				'register_settings'  => array( $this, 'register_rss_tab_settings' ),
				'option_group'       => 'smaily_settings_rss',
			);
		}
	}

	/**
	 * Register the settings tabs and add their sections and fields.
	 *
	 */
	public function settings_init() {
		foreach ( $this->tabs as $tab => $options ) {
			$options['register_settings']();
		}
	}

	/**
	 * Render Smaily settings page HTML.
	 *
	 * @return void
	 */
	public function settings_page() {
		add_menu_page( 'Smaily Settings', 'Smaily', 'manage_options', 'smaily-settings', array( $this, 'render_admin_page' ), SMAILY_PLUGIN_URL . '/gfx/icon.png' );
	}

	/**
	 * Register the connection tab settings fields.
	 *
	 */
	private function register_connection_tab_settings() {
		$option_group = 'smaily_settings_connection';
		$page         = 'smaily_settings_tab_connection';
		$section      = 'smaily_settings_connection_section';

		register_setting(
			$option_group,
			'smaily_api_credentials',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_api_credentials' ),
				'default'           => array(
					'subdomain' => '',
					'username'  => '',
					'password'  => '',
				),
			)
		);

		add_settings_section(
			$section,
			__( 'Connection', 'smaily' ),
			array( $this, 'render_connection_section_header' ),
			$page
		);

		add_settings_field(
			'api_credentials',
			__( 'Credentials', 'smaily' ),
			array( $this, 'render_credentials_fields' ),
			$page,
			$section
		);
	}

	/**
	 * Registers customer synchronization related configuration options.
	 *
	 * @return void
	 */
	private function register_customer_sync_tab_settings() {
		$option_group          = 'smaily_settings_customer_sync';
		$page                  = 'smaily_settings_tab_customer_sync';
		$customer_sync_section = 'smaily_settings_customer_sync_customer_sync_section';

		register_setting(
			$option_group,
			'smaily_customer_sync_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			$option_group,
			'smaily_customer_sync_fields',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_customer_sync_fields' ),
				'default'           => array(
					'user_email'       => true,
					'store_url'        => true,
					'customer_group'   => false,
					'customer_id'      => false,
					'user_dob'         => false,
					'first_registered' => false,
					'first_name'       => false,
					'user_gender'      => false,
					'last_name'        => false,
					'nickname'         => false,
					'user_phone'       => false,
					'site_title'       => false,
				),
			)
		);

		add_settings_section(
			$customer_sync_section,
			__( 'Customer Synchronization', 'smaily' ),
			array( $this, 'render_customer_sync_section_header' ),
			$page
		);

		add_settings_field(
			'smaily_customer_sync_enabled',
			__( 'Enable Customer Synchronization', 'smaily' ),
			array( $this, 'render_enabled_field' ),
			$page,
			$customer_sync_section,
			array(
				'option_name' => 'smaily_customer_sync_enabled',
			)
		);

		add_settings_field(
			'smaily_customer_sync_fields',
			__( 'Additional Fields', 'smaily' ),
			array( $this, 'render_sync_additional_fields' ),
			$page,
			$customer_sync_section
		);
	}

	/**
	 * Registers abandoned cart related configuration options.
	 *
	 * @return void
	 */
	private function register_abandoned_cart_tab_settings() {
		$option_group           = 'smaily_settings_abandoned_cart';
		$page                   = 'smaily_settings_tab_abandoned_cart';
		$abandoned_cart_section = 'smaily_settings_abandoned_cart_section';
		register_setting(
			$option_group,
			'smaily_abandoned_cart_status',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'save_abandoned_cart_status' ),
				'default'           => array(
					'enabled'          => false,
					'autoresponder_id' => '',
				),
			)
		);

		register_setting(
			$option_group,
			'smaily_abandoned_sync_fields',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_abandoned_cart_fields' ),
				'default'           => array(
					'user_email'          => true,
					'store_url'           => true,
					'first_name'          => false,
					'last_name'           => false,
					'product_name'        => false,
					'product_description' => false,
					'product_sku'         => false,
					'product_quantity'    => false,
					'product_base_price'  => false,
					'product_price'       => false,
					'product_images'      => false,
				),
			)
		);

		add_settings_section(
			$abandoned_cart_section,
			__( 'Abandoned Cart', 'smaily' ),
			array( $this, 'render_abandoned_cart_section_header' ),
			$page
		);

		add_settings_field(
			'smaily_abandoned_cart_status',
			__( 'Enable Abandoned Cart', 'smaily' ),
			array( $this, 'render_abandoned_cart_status_field' ),
			$page,
			$abandoned_cart_section,
			array(
				'autoresponders' => $this->get_autoresponders(),
			)
		);

		add_settings_field(
			'abandoned_sync_fields',
			__( 'Additional Fields', 'smaily' ),
			array( $this, 'render_abandoned_additional_fields' ),
			$page,
			$abandoned_cart_section
		);

		$checkout_subscription_section = 'smaily_settings_woocommerce_checkout_subscription_section';
		register_setting(
			$option_group,
			'smaily_checkout_subscription_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			$option_group,
			'smaily_checkout_subscription_position',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'before',
			)
		);

		register_setting(
			$option_group,
			'smaily_checkout_subscription_location',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'order_notes',
			)
		);

		add_settings_section(
			$checkout_subscription_section,
			__( 'Checkout subscription', 'smaily' ),
			array( $this, 'render_checkout_subscription_section_header' ),
			$page
		);

		add_settings_field(
			'smaily_checkout_subscription_enabled',
			__( 'Enable Checkout Subscription', 'smaily' ),
			array( $this, 'render_enabled_field' ),
			$page,
			$checkout_subscription_section,
			array(
				'option_name' => 'smaily_checkout_subscription_enabled',
			)
		);

		add_settings_field(
			'smaily_checkout_subscription_position',
			__( 'Position', 'smaily' ),
			array( $this, 'render_checkout_subscription_position' ),
			$page,
			$checkout_subscription_section
		);

		add_settings_field(
			'smaily_checkout_subscription_location',
			__( 'Location', 'smaily' ),
			array( $this, 'render_checkout_subscription_location' ),
			$page,
			$checkout_subscription_section
		);
	}

	/**
	 * Registers RSS-feed related configuration options.
	 *
	 * @return void
	 */
	private function register_rss_tab_settings() {
		$option_group = 'smaily_settings_rss';
		$page         = 'smaily_settings_tab_rss';
		$rss_section  = 'smaily_settings_rss_section';

		register_setting(
			$option_group,
			'smaily_rss_limit',
			array(
				'type'              => 'number',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 50,
			)
		);

		register_setting(
			$option_group,
			'smaily_rss_category',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			$option_group,
			'smaily_rss_sort_by',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'date',
			)
		);

		register_setting(
			$option_group,
			'smaily_rss_order_by',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'ASC',
			)
		);

		register_setting(
			$option_group,
			'smaily_rss_url',
			array(
				'type'              => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => Rss::make_rss_feed_url(
					get_option( 'smaily_rss_category' ),
					get_option( 'smaily_rss_limit' ),
					get_option( 'smaily_rss_sort_by' ),
					get_option( 'smaily_rss_order_by' )
				),
			)
		);

		add_settings_section(
			$rss_section,
			__( 'Smaily RSS feed', 'smaily' ),
			array( $this, 'render_rss_section_header' ),
			$page
		);

		add_settings_field(
			'smaily_rss_limit',
			__( 'Limit', 'smaily' ),
			array( $this, 'render_number_field' ),
			$page,
			$rss_section,
			array(
				'option_name' => 'smaily_rss_limit',
				'min'         => 1,
				'max'         => 250,
				'help'        => __( 'Limit how many products you will add to your field. Maximum 250.', 'smaily' ),
			)
		);

		add_settings_field(
			'smaily_rss_category',
			__( 'Product Category', 'smaily' ),
			array( $this, 'render_rss_category_field' ),
			$page,
			$rss_section,
			array(
				'categories' => get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'orderby'    => 'name',
						'order'      => 'asc',
						'hide_empty' => false,
					)
				),
			)
		);

		add_settings_field(
			'smaily_rss_sort_by',
			__( 'Sort by', 'smaily' ),
			array( $this, 'render_rss_sort_by_field' ),
			$page,
			$rss_section,
			array(
				'sort_by' => array(
					'date'     => __( 'Created At', 'smaily' ),
					'id'       => __( 'ID', 'smaily' ),
					'modified' => __( 'Modified At', 'smaily' ),
					'name'     => __( 'Name', 'smaily' ),
					'rand'     => __( 'Random', 'smaily' ),
					'type'     => __( 'Type', 'smaily' ),
				),
			)
		);

		add_settings_field(
			'smaily_rss_order_by',
			__( 'Order by', 'smaily' ),
			array( $this, 'render_rss_order_by_field' ),
			$page,
			$rss_section
		);

		add_settings_field(
			'smaily_rss_url',
			__( 'Product RSS feed', 'smaily' ),
			array( $this, 'render_rss_url' ),
			$page,
			$rss_section
		);
	}

	/**
	 * Render admin page HTML content.
	 *
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return wp_die( 'Insufficient permissions' );
		}

		include_once SMAILY_PLUGIN_PATH . '/admin/partials/smaily-admin-page.php';
	}

	/**
	 * Lists available admin page tabs.
	 *
	 * @return array
	 */
	public function list_admin_page_tabs() {
		return $this->tabs;
	}

	/**
	 * Renders header section for Smaily API credentials form.
	 *
	 * @return void
	 */
	public function render_connection_section_header() {
		include_once SMAILY_PLUGIN_PATH . '/admin/partials/smaily-admin-credentials-header.php';
	}

	/**
	 * Render API credentials fields HTML form content.
	 * @return void
	 */
	public function render_credentials_fields() {
		include_once SMAILY_PLUGIN_PATH . '/admin/partials/smaily-admin-credentials.php';
	}

	/**
	 * Renders enabled field checkbox input.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_enabled_field( $args ) {
		$option = get_option( $args['option_name'], false );
		?>
		<fieldset>
			<label for="<?php echo esc_attr( $args['option_name'] ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $args['option_name'] ); ?>"
					name="<?php echo esc_attr( $args['option_name'] ); ?>"
					value="1"
					<?php checked( $option, true ); ?>
				/>
				<?php esc_html_e( 'Enabled', 'smaily' ); ?>
			</label>
		</fieldset>
		<?php
	}

	/**
	 * Render customer synchronization additional fields HTML content.
	 * @return void
	 */
	public function render_sync_additional_fields() {
		include_once SMAILY_PLUGIN_PATH . '/admin/partials/smaily-admin-woocommerce-customer-sync.php';
	}

	/**
	 * Renders customer synchronization tab header HTML content.
	 *
	 * @return void
	 */
	public function render_customer_sync_section_header() {
		?>
		<div>
			<p>
				<?php esc_html_e( 'Customer Synchronization allows you to automate synchronizing newsletter subscribers and their information directly to Smaily.', 'smaily' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render abandoned cart tab header HTML content.
	 *
	 * @return void
	 */
	public function render_abandoned_cart_section_header() {
		?>
		<div>
			<p>
				<?php esc_html_e( 'This is the Smaily WooCommerce Abandoned Cart plugin. It allows you to send abandoned cart emails to your customers.', 'smaily' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders abandoned cart status field HTML content.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_abandoned_cart_status_field( $args ) {
		$autoresponders         = $args['autoresponders'];
		$abandoned_cart_status  = get_option( 'smaily_abandoned_cart_status' );
		$enabled                = $abandoned_cart_status['enabled'];
		$selected_autoresponder = $abandoned_cart_status['autoresponder_id'];
		?>
		<fieldset>
			<label for="smaily_abandoned_cart_status[enabled]">
				<input
					type="checkbox"
					id="smaily_abandoned_cart_status_enabled"
					name="smaily_abandoned_cart_status[enabled]"
					value="1"
					<?php checked( $enabled, true ); ?>
				/>
				<?php esc_html_e( 'Enabled', 'smaily' ); ?>
			</label>
		</fieldset>
		<fieldset>
			<select name="smaily_abandoned_cart_status[autoresponder_id]">
				<?php if ( ! empty( $autoresponders ) ) : ?>
					<?php foreach ( $autoresponders as $autoresponder_id => $autoresponder_name ) : ?>
						<option
							value="<?php echo esc_attr( $autoresponder_id ); ?>"
							<?php selected( $autoresponder_id, $selected_autoresponder ); ?>
						>
							<?php echo esc_html( $autoresponder_name ); ?>
						</option>
					<?php endforeach; ?>
				<?php else : ?>
					<option value="">
						<?php esc_html_e( 'No automations created', 'smaily' ); ?>
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
		include_once SMAILY_PLUGIN_PATH . '/admin/partials/smaily-admin-woocommerce-abandoned-cart.php';
	}

	/**
	 * Render checkout subscription section header HTML content.
	 *
	 * @return void
	 */
	public function render_checkout_subscription_section_header() {
		?>
		<div>
			<p>
				<?php esc_html_e( 'Customers can subscribe by checking "subscribe to newsletter" checkbox on checkout page.', 'smaily' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders checkout subscription block position HTML content.
	 *
	 * @return void
	 */
	public function render_checkout_subscription_position() {
		$position = get_option( 'smaily_checkout_subscription_position', 'before' );
		?>
		<fieldset>
			<select name="smaily_checkout_subscription_position">
				<option value="before" <?php selected( 'before', $position ); ?>>
					<?php esc_html_e( 'Before', 'smaily' ); ?>
				</option>
				<option value="after" <?php selected( 'after', $position ); ?>>
					<?php esc_html_e( 'After', 'smaily' ); ?>
				</option>
			</select>
		</fieldset>
		<?php
	}

	/**
	 * Renders checkout subscription block location HTML content.
	 *
	 * @return void
	 */
	public function render_checkout_subscription_location() {
		$location         = get_option( 'smaily_checkout_subscription_location', 'order_notes' );
		$cb_loc_available = array(
			'order_notes'                => __( 'Order notes', 'smaily' ),
			'checkout_billing_form'      => __( 'Billing form', 'smaily' ),
			'checkout_shipping_form'     => __( 'Shipping form', 'smaily' ),
			'checkout_registration_form' => __( 'Registration form', 'smaily' ),
		);
		?>
		<fieldset>
			<select name="smaily_checkout_subscription_location">
				<?php foreach ( $cb_loc_available as $key => $value ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $location ); ?>>
						<?php echo esc_html( $value ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</fieldset>
		<?php
	}

	/**
	 * Render RSS tab header HTML content.
	 *
	 * @return void
	 */
	public function render_rss_section_header() {
		?>
		<div>
			<p>
				<?php esc_html_e( 'Smaily RSS feed allows you insert product feeds to email templates.', 'smaily' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render number field HTML content.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_number_field( $args ) {
		$option = get_option( $args['option_name'] );
		$id     = sprintf( 'smaily_%s', $args['option_name'] );
		$name   = $args['option_name'];
		$min    = $args['min'];
		$max    = $args['max'];
		$help   = $args['help'] ?? '';
		?>
		<fieldset>
			<label for="<?php echo esc_attr( $id ); ?>">
				<input
					type="number"
					id="rss-limit"
					class="smaily-rss-options"
					min="<?php echo esc_attr( $min ); ?>"
					max="<?php echo esc_attr( $max ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $option ); ?>"
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
	 * Render possible RSS feed categories HTML content.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_rss_category_field( $args ) {
		$current_category = get_option( 'smaily_rss_category' );
		?>
		<fieldset>
			<select id="rss-category" name="smaily_rss_category" class="smaily-rss-options">
				<?php
				foreach ( $args['categories'] as $category ) :
					?>
					<option value="<?php echo esc_attr( $category->slug ); ?>" <?php selected( $category->slug, $current_category ); ?>>
						<?php echo esc_html( $category->name ); ?>
					</option>
				<?php endforeach; ?>
				<option value="" <?php echo empty( $current_category ) ? 'selected' : ''; ?>>
					<?php esc_html_e( 'All products', 'smaily' ); ?>
				</option>
			</select>
				<small class="form-text text-muted">
					<?php
					esc_html_e(
						'Show products from specific category',
						'smaily'
					);
					?>
				</small>
		</fieldset>
		<?php
	}

	/**
	 * Render RSS sort by field HTML content.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_rss_sort_by_field( $args ) {
		$current_sort_by = get_option( 'smaily_rss_sort_by' );
		?>
		<fieldset>
			<select id="rss-sort-field" name="smaily_rss_sort_by" class="smaily-rss-options">
				<?php
				foreach ( $args['sort_by'] as $sort_value => $sort_name ) :
					?>
					<option <?php selected( $current_sort_by, $sort_value ); ?> value="<?php echo esc_attr( $sort_value ); ?>">
						<?php echo esc_html( $sort_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</fieldset>
		<?php
	}

	/**
	 * Render RSS order by field HTML content.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_rss_order_by_field() {
		$current_order_by = get_option( 'smaily_rss_order_by' );
		?>
		<fieldset>
			<select id="rss-sort-order" name="smaily_rss_order_by" class="smaily-rss-options">
				<option <?php selected( $current_order_by, 'ASC' ); ?> value="ASC">
					<?php esc_html_e( 'Ascending', 'smaily' ); ?>
				</option>
				<option <?php selected( $current_order_by, 'DESC' ); ?> value="DESC">
					<?php esc_html_e( 'Descending', 'smaily' ); ?>
				</option>
			</select>
		</fieldset>
		<?php
	}

	/**
	 * Render RSS URL HTML content.
	 *
	 * @return void
	 */
	public function render_rss_url() {
		$url = Rss::make_rss_feed_url(
			get_option( 'smaily_rss_category' ),
			get_option( 'smaily_rss_limit' ),
			get_option( 'smaily_rss_sort_by' ),
			get_option( 'smaily_rss_order_by' )
		);
		?>
		<fieldset>
			<strong id="smaily-rss-feed-url" name="rss_feed_url" class="smaily-rss-options">
				<?php echo esc_url( $url ); ?>
			</strong>
			<small class="form-text text-muted">
				<?php
				esc_html_e(
					"Copy this URL into your template editor's RSS block, to receive RSS-feed.",
					'smaily'
				);
				?>
			</small>
		</fieldset>
		<?php
	}

	/**
	 * Stores abandoned cart enabled status and validates that autoresponder is selected
	 * when abandoned cart automation is enabled.
	 *
	 * @param array $input
	 */
	public function save_abandoned_cart_status( $input ) {
		$enabled          = rest_sanitize_boolean( $input['enabled'] ?? false );
		$autoresponder_id = sanitize_text_field( $input['autoresponder_id'] );

		if ( $enabled === true && $autoresponder_id === '' ) {
			add_settings_error(
				'smaily_messages',
				'no_autoresponder_for_abandoned_cart',
				__( 'Please select autoresponder for abandoned cart automation.', 'smaily' ),
				'error'
			);

			return get_option( 'smaily_abandoned_cart_status' ); // Prevent saving invalid credentials
		}

		return array(
			'enabled'          => $enabled,
			'autoresponder_id' => $autoresponder_id,
		);
	}

	/**
	 * Sanitize input user input for credentials fields.
	 *
	 * @param array $input
	 */
	public function sanitize_api_credentials( $input ) {
		// Reset credentials if disconnecting.
		if ( isset( $input['enabled'] ) && $input['enabled'] === '1' ) {
			add_settings_error(
				'smaily_messages',
				'credentials_validated',
				'API credentials disconnected!',
				'success'
			);

			return array(
				'subdomain' => '',
				'username'  => '',
				'password'  => '',
			);
		}

		$validation_errors = array();
		if ( empty( trim( $input['subdomain'] ) ) ) {
			$validation_errors[] = __( 'Please enter subdomain!', 'smaily' );
		}
		if ( empty( trim( $input['username'] ) ) ) {
			$validation_errors[] = __( 'Please enter username!', 'smaily' );
		}
		if ( empty( trim( $input['password'] ) ) ) {
			$validation_errors[] = __( 'Please enter password!', 'smaily' );
		}
		if ( ! empty( $validation_errors ) ) {
			$validation_errors = implode( '<br>', $validation_errors );
			add_settings_error(
				'smaily_messages',
				'invalid_api_credentials',
				$validation_errors,
				'error'
			);

			return get_option( 'smaily_api_credentials' ); // Prevent saving invalid credentials
		}

		$validated              = array();
		$validated['subdomain'] = $this->normalize_subdomain( sanitize_text_field( $input['subdomain'] ) );
		$validated['username']  = trim( sanitize_text_field( $input['username'] ) );
		$validated['password']  = sanitize_text_field( $input['password'] ); // TODO: Check if sanitization is affects.

		return $validated;
	}

	/**
	 * Validates API credentials by making a request to Smaily before updating settings.
	 * After successful validation stores the credentials with encrypted password.
	 *
	 * https://developer.wordpress.org/reference/hooks/pre_update_option_option/
	 * @param array $new_value
	 * @param array $old_value
	 * @param string $option
	 */
	public function validate_api_credentials_after_save( $new_value, $old_value, $option ) {
		// Using separate function instead of sanitize callback as the sanitize callback is
		// occasionally executed twice.
		// The flow can be sanitize -> validate after save -> sanitize
		// https://core.trac.wordpress.org/ticket/21989

		if ( $new_value['subdomain'] === ''
			&& $new_value['username'] === ''
			&& $new_value['password'] === ''
		) {
			return $new_value;
		}

		$credentials_valid = $this->validate_api_credentials( $new_value['subdomain'], $new_value['username'], $new_value['password'] );
		if ( $credentials_valid[0] === true ) {
			add_settings_error(
				'smaily_messages',
				'credentials_validated',
				'API credentials validated successfully!',
				'success'
			);

			return array(
				'subdomain' => $new_value['subdomain'],
				'username'  => $new_value['username'],
				'password'  => Smaily_Cypher::encrypt( $new_value['password'] ),
			);
		} else {
			switch ( $credentials_valid[1] ) {
				case 404:
					add_settings_error(
						'smaily_messages',
						'invalid_api_credentials',
						'Check subdomain. API credentials validation failed.',
						'error'
					);
					break;
				default:
					add_settings_error(
						'smaily_messages',
						'invalid_api_credentials',
						'API credentials validation failed. Please check your details.',
						'error'
					);
					break;
			}

			return $old_value;
		}
	}

	/**
	 * Sanitizes customer sync additional values.
	 *
	 * @param array $input
	 * @return array
	 */
	public function sanitize_customer_sync_fields( $input ) {
		$default_fields = array(
			'user_email'       => true,
			'store_url'        => true,
			'customer_group'   => false,
			'customer_id'      => false,
			'user_dob'         => false,
			'first_registered' => false,
			'first_name'       => false,
			'user_gender'      => false,
			'last_name'        => false,
			'nickname'         => false,
			'user_phone'       => false,
			'site_title'       => false,
		);

		$sanitized = array();
		foreach ( $default_fields as $field => $default_value ) {
			$sanitized[ $field ] = isset( $input[ $field ] ) ? true : false;
		}

		return $sanitized;
	}

	/**
	 * Sanitizes abandoned cart additional values.
	 *
	 * @param array $input
	 * @return array
	 */
	public function sanitize_abandoned_cart_fields( $input ) {
		$default_fields = array(
			'user_email'          => true,
			'store_url'           => true,
			'first_name'          => false,
			'last_name'           => false,
			'product_name'        => false,
			'product_description' => false,
			'product_sku'         => false,
			'product_quantity'    => false,
			'product_base_price'  => false,
			'product_price'       => false,
			'product_images'      => false,
		);

		$sanitized = array();
		foreach ( $default_fields as $field => $default_value ) {
			$sanitized[ $field ] = isset( $input[ $field ] ) ? true : false;
		}

		return $sanitized;
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 */
	public function enqueue_styles() {
		wp_register_style( $this->plugin_name, SMAILY_PLUGIN_URL . '/admin/css/smaily-admin.css', array(), $this->version, 'all' );
		wp_register_style( $this->plugin_name . '-widget', SMAILY_PLUGIN_URL . '/admin/css/smaily-widget-admin.css', array(), $this->version, 'all' );

		wp_enqueue_style( $this->plugin_name );
		wp_enqueue_style( $this->plugin_name . '-widget' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 */
	public function enqueue_scripts() {
		wp_register_script( $this->plugin_name . '-jscolor', SMAILY_PLUGIN_URL . '/admin/js/jscolor.min.js', array(), $this->version, true );
		wp_register_script( $this->plugin_name, SMAILY_PLUGIN_URL . '/admin/js/smaily-admin.js', array( 'jquery', 'jquery-ui-tabs' ), $this->version, true );
		wp_register_script( $this->plugin_name . '-widget', SMAILY_PLUGIN_URL . '/admin/js/admin-widget.js', array( 'jquery', $this->plugin_name . '-jscolor' ), $this->version, true );

		wp_enqueue_script( $this->plugin_name . '-jscolor' );
		wp_enqueue_script( $this->plugin_name );
		wp_enqueue_script( $this->plugin_name . '-widget' );

		wp_localize_script(
			$this->plugin_name,
			'smaily_translations',
			array(
				'went_wrong' => __( 'Something went wrong connecting to Smaily!', 'smaily' ),
				'validated'  => __( 'Smaily settings successfully saved!', 'smaily' ),
				'data_error' => __( 'Something went wrong with saving data!', 'smaily' ),
			)
		);

		if ( Smaily_Helper::is_woocommerce_active() ) {
			// Make RSS URL accessible in admin .js.
			wp_add_inline_script(
				$this->plugin_name,
				'var smaily_settings = ' . wp_json_encode(
					array(
						'rss_feed_url' => Smaily_WC\Rss::make_rss_feed_url(),
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * Adds setting link to plugin
	 *
	 * @param array  $links Default links in plugin page.
	 * @return array Updated array of links
	 */
	public function settings_link( $links ) {
		// receive all current links and add custom link to the list.
		$settings_link = '<a href="admin.php?page=smaily-settings">' . esc_html__( 'Settings', 'smaily' ) . '</a>';
		// Settings before disable.
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Load subscribe widget.
	 *
	 */
	public function smaily_subscription_widget_init() {
		$widget = new Smaily_Widget( $this->options, $this );
		register_widget( $widget );
	}


	/**
	 * Validates API credentials by making a request to Smaily.
	 *
	 *
	 * @access private
	 * @param  string $subdomain Smaily subdomain.
	 * @param  string $username  Smaily username.
	 * @param  string $password  Smaily password.
	 * @return array{bool,int}  Success of operation and error code.
	 */
	private function validate_api_credentials( $subdomain, $username, $password ) {
		Smaily_Request::set_credentials(
			array(
				'subdomain' => $subdomain,
				'username'  => $username,
				'password'  => $password,
			)
		);

		// Validate credentials with get request.
		$rqst = Smaily_Request::get(
			'workflows',
			array(
				'trigger_type' => 'form_submitted',
			)
		);

		$code = isset( $rqst['code'] ) ? $rqst['code'] : 0;
		if ( $code !== 200 ) {
			return array( false, $code );
		}

		return array( true, $code );
	}

	/**
	 * Normalize subdomain into the bare necessity.
	 *
	 *
	 * @access private
	 * @param  string $subdomain Messy subdomain, e.g http://demo.sendsmaily.net
	 * @return string Clean subdomain, e.g demo
	 */
	private function normalize_subdomain( $subdomain ) {
		// Normalize subdomain.
		// First, try to parse as full URL. If that fails, try to parse as subdomain.sendsmaily.net, and
		// if all else fails, then clean up subdomain and pass as is.
		if ( filter_var( $subdomain, FILTER_VALIDATE_URL ) ) {
			$url       = wp_parse_url( $subdomain );
			$parts     = explode( '.', $url['host'] );
			$subdomain = count( $parts ) >= 3 ? $parts[0] : '';
		} elseif ( preg_match( '/^[^\.]+\.sendsmaily\.net$/', $subdomain ) ) {
			$parts     = explode( '.', $subdomain );
			$subdomain = $parts[0];
		}

		return preg_replace( '/[^a-zA-Z0-9]+/', '', $subdomain );
	}

	/**
	 * Make a request to Smaily asking for autoresponders.
	 * Request is authenticated via saved credentials.
	 *
	 * @return array List of autoresponders in format [id => title].
	 */
	public function get_autoresponders() {
		// Load configuration data.
		$api_credentials = $this->options->get_api_credentials();

		if ( ! $this->options->has_credentials() ) {
			return array();
		}

		$result = Smaily_Request::get(
			'workflows',
			array(
				'trigger_type' => 'form_submitted',
			)
		);

		if ( empty( $result['body'] ) ) {
			return array();
		}

		$autoresponder_list = array();
		foreach ( $result['body'] as $autoresponder ) {
			$id                        = $autoresponder['id'];
			$title                     = $autoresponder['title'];
			$autoresponder_list[ $id ] = $title;
		}
		return $autoresponder_list;
	}

	/**
	 * Check if the current credentials are stale.
	 * If the User has not connected to Smaily, the credentials are considered valid.
	 *
	 * @return bool
	 */
	public function are_credentials_valid() {
		$credentials = $this->options->get_api_credentials();
		if ( ! $credentials ) {
			return true;
		}

		$subdomain = $credentials['subdomain'];
		$username  = $credentials['username'];
		$password  = $credentials['password'];
		$enabled   = $subdomain && $username && $password;

		if ( ! $enabled ) {
			return true;
		}

		return $this->validate_api_credentials( $subdomain, $username, $password )[0];
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
