<?php

use Smaily_WP_Connect\Includes\Helper;

defined( 'ABSPATH' ) || exit;

?>

<section>
	<p>
		<?php esc_html_e( 'You can use the following methods to collect subscribers:', 'smaily-wp-connect' ); ?>
		<ol class="smaily-wp-connect-subscriber-collection-list">
			<?php if ( wp_is_block_theme() ) : ?>
			<li>
				<h3>
					<?php esc_html_e( 'Use the Smaily subscription form block.', 'smaily-wp-connect' ); ?>
				</h3>
				<p>
					<?php
						esc_html_e(
							'Add the Smaily Sign-Up Form block to any page or post. The block is available in the block editor.',
							'smaily-wp-connect'
						);
					?>
					<br>
				</p>
			</li>
			<?php else : ?>
			<li>
				<h3>
					<?php esc_html_e( 'Use the Smaily subscription form widget.', 'smaily-wp-connect' ); ?>
				</h3>
				<p>
					<?php esc_html_e( 'You can add the widget to your website from the widgets page.', 'smaily-wp-connect' ); ?>
				</p>
				<a href="<?php echo esc_url( admin_url( 'widgets.php' ) ); ?>">
					<?php esc_html_e( 'Set up the Smaily Classic Subscription widget.', 'smaily-wp-connect' ); ?>
				</a>
			</li>
			<?php endif; ?>
			<li>
				<h3>
					<?php esc_html_e( 'Use the Smaily subscription form shortcode.', 'smaily-wp-connect' ); ?>
				</h3>
				<p>
				<?php
					printf(
						/* translators: 1: example subdomain between strong tags */
						esc_html__(
							'You can add the %1$s shortcode to any page or post.',
							'smaily-wp-connect'
						),
						'<span class="regular-text code">[smaily_wp_connect_newsletter_form]</span>'
					);
					?>
				</p>
				<?php
					$anchor = esc_html__( 'read the detailed guide', 'smaily-wp-connect' );
					$domain = esc_url( __( 'https://smaily.com/help/how-to/ecommerce-integrations/smaily-wp-connect', 'smaily-wp-connect' ) );
					$link   = sprintf( '<a href="%s" target="_blank">%s</a>', $domain, $anchor );
					echo sprintf(
						/* translators: 1: link to setting up block component guide */
						esc_html__( 'For more configuration options %1$s.', 'smaily-wp-connect' ),
						wp_kses_post( $link )
					);
					?>
			</li>
			<li>
				<h3>
					<?php esc_html_e( 'Build your own from component.', 'smaily-wp-connect' ); ?>
				</h3>
				<p>
					<?php
						esc_html_e(
							"Create a custom HTML form for maximum flexibility and advanced functionality. While this requires more technical knowledge, it gives you complete control over the form's appearance and behavior.",
							'smaily-wp-connect'
						);
						?>
				</p>
				<?php
					$anchor = esc_html__( 'here', 'smaily-wp-connect' );
					$domain = esc_url( __( 'https://smaily.com/help/how-to/forms-subscriptions/an-example-of-a-signup-form/', 'smaily-wp-connect' ) );
					$link   = sprintf( '<a href="%s" target="_blank">%s</a>', $domain, $anchor );
					echo sprintf(
						/* translators: 1: link to custom HTML guide */
						esc_html__( 'You can view the example form %1$s.', 'smaily-wp-connect' ),
						wp_kses_post( $link )
					);
					?>
			</li>
			<li>
				<h3>
					<?php esc_html_e( 'Use the Contact Form 7 form builder and Smaily connector.', 'smaily-wp-connect' ); ?>
				</h3>
				<p>
					<?php
						esc_html_e( 'You can also use Contact Form 7 form builder to create custom forms and connect them to your Smaily account.', 'smaily-wp-connect' );
					?>
				</p>
				<?php if ( Helper::is_cf7_active() ) : ?>
					<p>
						<a href="<?php echo esc_url( menu_page_url( 'wpcf7', false ) ); ?>">
							<?php esc_html_e( 'Enable Smaily Opt-In under the form settings.', 'smaily-wp-connect' ); ?>
						</a>
					</p>
				<?php else : ?>
					<p>
						<?php
							esc_html_e(
								'Please install the Contact Form 7 plugin to use this integration.',
								'smaily-wp-connect'
							);
						?>
					</p>
				<?php endif; ?>
			</li>
			<?php if ( Helper::is_elementor_active() ) : ?>
			<li>
				<h3>
					<?php esc_html_e( 'Use the Smaily Opt-In Form Elementor widget.', 'smaily-wp-connect' ); ?>
				</h3>
				<p>
					<?php
						esc_html_e( 'You can use the built-in Smaily Opt-In Form widget to generate and style a subscription form.', 'smaily-wp-connect' );
					?>
				</p>
				<p>
				<?php
					$anchor = esc_html__( 'read the detailed guide', 'smaily-wp-connect' );
					$domain = esc_url( __( 'https://smaily.com/help/how-to/ecommerce-integrations/smaily-wp-connect', 'smaily-wp-connect' ) );
					$link   = sprintf( '<a href="%s" target="_blank">%s</a>', $domain, $anchor );
					echo sprintf(
						/* translators: 1: link to setting up block component guide */
						esc_html__( 'For more configuration options %1$s.', 'smaily-wp-connect' ),
						wp_kses_post( $link )
					);
				?>
				</p>
			</li>
			<?php endif; ?>
		</ol>
	</p>
</section>