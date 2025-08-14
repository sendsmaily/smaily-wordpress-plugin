<?php
/**
 * Smaily admin page template.
 *
 * @var Smaily_Connect\Admin $this
 */

defined( 'ABSPATH' ) || exit;

$tabs        = $this->list_admin_page_tabs();
$current_tab = array_keys( $tabs )[0];
if ( isset( $_GET['tab'] ) ) {
	$tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
	if ( array_key_exists( $tab, $tabs ) ) {
		$current_tab = $tab;
	}
}
$show_submit_button = isset( $tabs[ $current_tab ]['submit_button_text'] );
settings_errors( 'smaily_connect_messages' );

?>
<!-- Rendering admin notices outside the form. Check wp-admin/js/common.js:1087 for ref. -->
<div class="wrap">
	<h1></h1>
</div>
<div class="wrap smaily-connect-admin-settings">
	<form action="options.php" method="post">
		<nav class="smaily-connect-admin-tab-nav">
			<div class="smaily-connect-admin-tab-logo">
				<svg width="31" height="31" viewBox="0 0 31 31" fill="none" xmlns="http://www.w3.org/2000/svg">
					<g clip-path="url(#clip0_704_436)">
						<path d="M5.17383 30.9999L22.7476 30.9893L27.6756 27.451V4.13684H5.17383V30.9999Z" fill="white"/>
						<path d="M25.8155 0H5.17379C2.31966 0 0 2.31966 0 5.17379V25.8369C0 28.691 2.31966 31.0107 5.17379 31.0107H5.20586C7.60034 31 9.61 29.3538 10.1872 27.1303C10.6362 27.5472 11.1386 27.9 11.7052 28.1779C12.6245 28.6269 13.5866 28.8514 14.57 28.8514C15.051 28.8514 15.5321 28.7979 16.0345 28.6697C16.5262 28.5521 16.9645 28.381 17.36 28.1459C17.7555 27.9107 18.0655 27.6221 18.3114 27.2586C18.5465 26.8952 18.6748 26.4676 18.6748 25.9759C18.6748 25.1421 18.3648 24.49 17.7448 24.0197C17.1248 23.5386 16.3445 23.1324 15.4038 22.7903C14.4738 22.4483 13.4476 22.1169 12.3572 21.7748C11.2669 21.4328 10.2514 20.9838 9.31069 20.4172C8.38069 19.84 7.58965 19.1024 6.96965 18.1724C6.34965 17.2424 6.03965 16.0131 6.03965 14.4845C6.03965 12.9559 6.31759 11.7159 6.89483 10.6041C7.47207 9.49241 8.22034 8.56241 9.17172 7.81414C10.1231 7.06586 11.2134 6.49931 12.4641 6.12517C13.7041 5.75103 14.9869 5.55862 16.3124 5.55862C17.8303 5.55862 19.3055 5.7831 20.7165 6.22138C21.7107 6.52069 22.63 6.99103 23.4959 7.57897H23.5065C23.87 7.84621 24.2228 8.13483 24.5648 8.43414L20.5348 14.1745C20.5348 14.1745 19.4552 10.4545 14.8693 11.299C14.4097 11.3952 13.9821 11.5662 13.5759 11.78C13.1697 12.0045 12.849 12.2824 12.5817 12.6566C12.3252 13.02 12.1969 13.4583 12.1969 13.9714C12.1969 14.8052 12.4962 15.4359 13.1055 15.8741C13.7148 16.3124 14.4738 16.6866 15.4145 16.9965C16.3338 17.3065 17.3386 17.6166 18.4076 17.9265C19.4765 18.2366 20.46 18.6748 21.4007 19.2414C22.32 19.8186 23.1003 20.5669 23.7097 21.5397C24.319 22.491 24.6183 23.7738 24.6183 25.3879C24.6183 27.0021 24.3403 28.2421 23.7845 29.3752C23.4959 29.9631 23.1538 30.4976 22.7583 30.9786H25.8369C28.691 30.9786 31.0107 28.659 31.0107 25.8048V5.17379C31 2.31966 28.6803 0 25.8262 0H25.8155Z" fill="#E91E63"/>
					</g>
					<defs>
						<clipPath id="clip0_704_436">
							<rect width="31" height="31" fill="white"/>
						</clipPath>
					</defs>
				</svg>
			</div>
			<?php foreach ( $tabs as $tab => $options ) : ?>
				<a
					class="smaily-connect-admin-tab <?php echo $tab === $current_tab ? 'smaily-connect-admin-tab-active' : ''; ?>"
					href="<?php echo esc_url( $options['url'] ); ?>"
				>
				<?php echo esc_html( $options['title'] ); ?>
				</a>
			<?php endforeach ?>
		</nav>
		<div class="smaily-connect-admin-tab-content">
			<?php
				settings_fields( $tabs[ $current_tab ]['option_group'] );
				do_settings_sections( $tabs[ $current_tab ]['page'] );
				$show_submit_button && submit_button( $tabs[ $current_tab ]['submit_button_text'] );
			?>
		</div>
	</form>
</div>
