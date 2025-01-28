<?php

namespace Smaily_CF7;

defined( 'ABSPATH' ) || exit;

class_exists( 'WPCF7_Service' ) || exit;

use WPCF7_Service;
use Smaily_Options;

class Smaily_CF7_Service extends WPCF7_Service {
	private static $instance;
	private $options;

	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->options = new Smaily_Options();
	}

	public function get_title() {
		return __( 'Smaily', 'smaily' );
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

		$url = menu_page_url( 'smaily-settings', false );
		$url = add_query_arg( array( 'service' => 'smaily' ), $url );

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
			<?php esc_html_e( 'Smaily email marketing and automation plugin for Contact Form 7 allows you to automatically add newsletter subscribers to your Smaily subscriber list, by using forms created in Contact Form 7.', 'smaily' ); ?>
		</p>
		<p>
			<strong>
				<a href="https://smaily.com/integrations/smaily-for-contact-form-7">
					<?php esc_html_e( 'Smaily integration', 'smaily' ); ?>
				</a>
			</strong>
		</p>
		<?php if ( $this->is_active() ) : ?>
			<p class="dashicons-before dashicons-yes">
				<?php esc_html_e( 'Smaily integration is active on this site.', 'smaily' ); ?>
			</p>
		<?php endif ?>
		<p>
			<a class="button" href="<?php echo esc_url( menu_page_url( 'smaily-settings', false ) ); ?>">
				<?php esc_html_e( 'Setup integration', 'smaily' ); ?>
			</a>
		</p>
		<?php
	}
}
