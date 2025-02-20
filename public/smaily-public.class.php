<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @package    Smaily
 * @subpackage Smaily/public
 */

class Smaily_Public {
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
	 * @var    Smaily_Options $options Handler for Options API.
	 */
	private $options;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param Smaily_Options $options     Reference to options handler class.
	 * @param string                $plugin_name The name of the plugin.
	 * @param string                $version     The version of this plugin.
	 */
	public function __construct( Smaily_Options $options, $plugin_name, $version ) {
		$this->options     = $options;
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register all shortcodes present in the function.
	 *
	 *
	 */
	public function add_shortcodes() {
		add_shortcode( 'smaily_newsletter_form', array( $this, 'smaily_shortcode_render' ) );
	}

	/**
	 * Render Smaily form using shortcode.
	 *
	 * @param  array $attrs Shortcode attributes.
	 */
	public function smaily_shortcode_render( $attrs ) {
		// Allow overriding the template.
		$template_path = locate_template( 'smaily/smaily-public-basic.php' );
		if ( ! $template_path ) {
			$template_path = SMAILY_PLUGIN_PATH . 'public/partials/smaily-public-basic.php';
		}

		$shortcode_attrs    = shortcode_atts(
			array(
				'success_url'      => Smaily_Helper::get_current_url(),
				'failure_url'      => Smaily_Helper::get_current_url(),
				'show_name'        => false,
				'autoresponder_id' => '',
			),
			$attrs
		);
		$autoresponder_id   = $shortcode_attrs['autoresponder_id'];
		$failure_url        = $shortcode_attrs['failure_url'];
		$form_has_response  = false;
		$form_is_successful = false;
		$language_code      = Smaily_Helper::get_current_language_code();
		$response_message   = null;
		$show_name          = $shortcode_attrs['show_name'];
		$subdomain          = $this->options->get_subdomain();
		$success_url        = $shortcode_attrs['success_url'];

		if ( ! $this->options->has_credentials() ) {
			$form_has_response = true;
			$response_message  = __( 'Smaily credentials not validated. Subscription form will not work!', 'smaily' );
		}

		$code = isset( $_GET['code'] ) ? intval( $_GET['code'] ) : null;
		switch ( $code ) {
			case null:
				break;
			case 101:
				$form_is_successful = true;
				break;
			case 201:
				$form_has_response = true;
				$response_message  = __( 'Form was not submitted using POST method.', 'smaily' );
				break;
			case 204:
				$form_has_response = true;
				$response_message  = __( 'Input does not contain a recognizable email address.', 'smaily' );
				break;
			default:
				$form_has_response = true;
				$response_message  = __( 'Could not add to subscriber list for an unknown reason. Probably something in Smaily.', 'smaily' );
				break;
		}

		Smaily_Public::render_basic_form(
			compact(
				'autoresponder_id',
				'failure_url',
				'form_has_response',
				'form_is_successful',
				'language_code',
				'response_message',
				'show_name',
				'subdomain',
				'success_url'
			)
		);
	}

	/**
	 * Renders basic form for subscribing to newsletter.
	 *
	 * @param array $parameters
	 * @return void
	 */
	public static function render_basic_form( array $parameters ) {
		?>
		<form id="smly" action="https://<?php echo esc_attr( $parameters['subdomain'] ); ?>.sendsmaily.net/api/opt-in/" method="post">
			<p class="error" style="padding:15px;background-color:#f2dede;margin:0 0 10px;display:<?php echo $parameters['form_has_response'] ? 'block' : 'none'; ?>">
				<?php echo esc_html( $parameters['response_message'] ); ?>
			</p>
			<p class="success" style="padding:15px;background-color:#dff0d8;margin:0 0 10px;display:<?php echo $parameters['form_is_successful'] ? 'block' : 'none'; ?>">
				<?php echo esc_html__( 'Thank you for subscribing to our newsletter.', 'smaily' ); ?>
			</p>
			<?php if ( $parameters['autoresponder_id'] ) : ?>
				<input type="hidden" name="autoresponder" value="<?php echo esc_attr( $parameters['autoresponder_id'] ); ?>" />
			<?php endif; ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $parameters['language_code'] ); ?>" />
			<input type="hidden" name="success_url" value="<?php echo esc_url( $parameters['success_url'] ); ?>" />
			<input type="hidden" name="failure_url" value="<?php echo esc_url( $parameters['failure_url'] ); ?>" />
			<p>
				<input type="text" name="email" value="" placeholder="<?php echo esc_html__( 'Email', 'smaily' ); ?>" required />
			</p>
			<?php if ( $parameters['show_name'] ) : ?>
				<p>
					<input type="text" name="name" value="" placeholder="<?php echo esc_html__( 'Name', 'smaily' ); ?>" />
				</p>
			<?php endif; ?>
			<p>
				<button type="submit">
					<?php echo esc_html__( 'Subscribe', 'smaily' ); ?>
				</button>
			</p>
		</form>
		<?php
	}
}
