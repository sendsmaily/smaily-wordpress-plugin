<?php
/**
 * Shopper-facing profiling-consent control ((a).2).
 *
 * The opt-out the opt-out model legally requires: a logged-in customer can switch
 * off "use my data for personalised recommendations" from WooCommerce → My Account.
 * The displayed state mirrors the read-back from Smaily — so a Smaily-side opt-out
 * shows here too (closes the "WP says opt-in while Smaily says opt-out" gap).
 *
 * @package Smaily\Connect\Privacy
 */

declare(strict_types=1);

namespace Smaily\Connect\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a privacy section on the My Account dashboard + handles its form POST.
 * The form is a single "use my data" checkbox: checked = opt-in (default-on),
 * unchecked = opt-out. The handler maps that to ProfilingConsent::opt_in/opt_out,
 * which write to Smaily + update the cache + opt the customer in/out of the engine.
 */
final class ProfilingConsentAccount {

	public const FIELD  = 'smly_profiling_use_my_data';
	public const ACTION = 'smly_profiling_consent';

	private ProfilingConsent $profiling;

	public function __construct( ProfilingConsent $profiling ) {
		$this->profiling = $profiling;
	}

	public function register(): void {
		add_action( 'woocommerce_account_dashboard', array( $this, 'render' ) );
		// Handle the POST before output so the redirect-after-post works.
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
	}

	/**
	 * Apply a shopper's choice — the testable core, free of WP request glue.
	 * `$use_my_data` true = opt back in; false = opt out.
	 */
	public function apply( string $email, bool $use_my_data ): void {
		if ( $email === '' ) {
			return;
		}
		if ( $use_my_data ) {
			$this->profiling->opt_in( $email );
		} else {
			$this->profiling->opt_out( $email );
		}
	}

	public function handle_post(): void {
		if ( ! isset( $_POST[ self::ACTION . '_submit' ] ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return;
		}

		$email = $this->current_email();
		if ( $email === '' ) {
			return;
		}

		// Checkbox present = opt-in (use my data); absent = opt-out.
		$this->apply( $email, isset( $_POST[ self::FIELD ] ) );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Your recommendation preferences have been saved.', 'smaily-connect' ), 'success' );
		}

		$back = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'dashboard' ) : home_url();
		wp_safe_redirect( $back );
		exit;
	}

	public function render(): void {
		$email = $this->current_email();
		if ( $email === '' ) {
			return;
		}

		// Read-back-as-authority: reflect the true (Smaily) state, not local guesswork.
		$use_my_data = $this->profiling->may_profile( $email );

		?>
		<section class="smly-profiling-consent">
			<h3><?php esc_html_e( 'Smaily Campaign Intelligence', 'smaily-connect' ); ?></h3>
			<p>
				<?php esc_html_e( 'We use your browsing and purchase history to personalise the product recommendations in our emails. You can turn this off at any time — you will still receive our emails.', 'smaily-connect' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( self::ACTION ); ?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( self::FIELD ); ?>" value="1" <?php checked( $use_my_data ); ?> />
					<?php esc_html_e( 'Use my data for personalised recommendations', 'smaily-connect' ); ?>
				</label>
				<p>
					<button type="submit" name="<?php echo esc_attr( self::ACTION . '_submit' ); ?>" value="1" class="button">
						<?php esc_html_e( 'Save preferences', 'smaily-connect' ); ?>
					</button>
				</p>
			</form>
		</section>
		<?php
	}

	private function current_email(): string {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return '';
		}
		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return '';
		}
		return strtolower( trim( (string) $user->user_email ) );
	}
}
