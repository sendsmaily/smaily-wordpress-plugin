<?php
/**
 * Smaily admin page template.
 *
 * @var Smaily_WP_Connect\Admin $this
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

settings_errors( 'smaily_messages' );
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<form action="options.php" method="post">
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab => $options ) : ?>
				<a
					class="nav-tab <?php echo $tab === $current_tab ? 'nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( $options['url'] ); ?>"
				>
				<?php echo esc_html( $options['title'] ); ?>
				</a>
			<?php endforeach ?>
		</nav>
		<?php
			settings_fields( $tabs[ $current_tab ]['option_group'] );
			do_settings_sections( $tabs[ $current_tab ]['page'] );
			$show_submit_button && submit_button( $tabs[ $current_tab ]['submit_button_text'] );
		?>
	</form>
</div>
