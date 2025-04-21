<?php

namespace Smaily_Connect\Integrations\CF7;

class_exists( 'WPCF7_Service' ) || exit;

use WPCF7_Service;
use Smaily_Connect\Includes\Options;

class Service extends WPCF7_Service {
	/**
	 * The plugin name.
	 *
	 * @var string
	 */
	private $plugin_name;

	private static $instance;

	/**
	 * @var Options Instance of plugin options.
	 */
	private $options;

	public static function get_instance( $options = null, $plugin_name = null ) {
		if ( empty( self::$instance ) ) {
			self::$instance = new self( $options, $plugin_name );
		}

		return self::$instance;
	}

	private function __construct( $options = null, $plugin_name = null ) {
		$this->options     = $options;
		$this->plugin_name = $plugin_name;
	}

	public function get_title() {
		return __( 'Smaily Connect', 'smaily-connect' );
	}

	public function is_active() {
		return $this->options->has_credentials();
	}

	public function get_categories() {
		return array( 'email_marketing' );
	}

	public function icon() {
	}

	public function link() {
		echo '<a href="https://smaily.com/integrations/smaily-for-contact-form-7">smaily.com</a>';
	}

	protected function menu_page_url( $args = '' ) {
		$args = wp_parse_args( $args, array() );

		$url = menu_page_url( $this->plugin_name, false );
		$url = add_query_arg( array( 'service' => 'smaily-connect' ), $url );

		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		return $url;
	}

	public function load( $action = '' ) {}

	public function admin_notice( $message = '' ) {}

	public function display( $action = '' ) {
		?>
		<p>
			<?php esc_html_e( 'Smaily email marketing and automation plugin for Contact Form 7 allows you to automatically add newsletter subscribers to your Smaily subscriber list, by using forms created in Contact Form 7.', 'smaily-connect' ); ?>
		</p>
		<p>
			<strong>
				<a href="https://smaily.com/integrations/smaily-for-contact-form-7">
					<?php esc_html_e( 'Smaily integration', 'smaily-connect' ); ?>
				</a>
			</strong>
		</p>
		<?php if ( $this->is_active() ) : ?>
			<p class="dashicons-before dashicons-yes">
				<?php esc_html_e( 'Smaily integration is active on this site.', 'smaily-connect' ); ?>
			</p>
		<?php endif ?>
		<p>
			<a class="button" href="<?php echo esc_url( menu_page_url( $this->plugin_name, false ) ); ?>">
				<?php esc_html_e( 'Setup integration', 'smaily-connect' ); ?>
			</a>
		</p>
		<?php
	}
}
