<?php
/**
 * @var Smaily_Admin $this
 */

defined( 'ABSPATH' ) || exit;

?>

<?php if ( ! $this->are_credentials_valid() ) : ?>
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
<div>
	<p>
		<?php esc_html_e( 'Start by setting up the connection between Smaily and you website. To do this, you need to create API credentials in Smaily and enter them below.', 'smaily' ); ?>
	</p>
	<a href="https://smaily.com/help/api/general/create-api-user/" target="_blank">
		<?php esc_html_e( 'How to create API credentials?', 'smaily' ); ?>
	</a>
</div>
