<?php

defined( 'ABSPATH' ) || exit;

?>

<div
	id="<?php echo esc_attr( $id ); ?>" 
	class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> 
	<?php echo $notice['dismissible'] ? 'is-dismissible' : ''; ?>">
	
	<?php
	require $template_path;
	?>

</div>

<?php if ( $notice['dismissible'] ) : ?>
<script>
	jQuery(document).ready(function($){
		$('#<?php echo esc_attr( $id ); ?>').on('click', '.notice-dismiss', function() {
			// Dismiss the notice via AJAX.
			$.post(
				ajaxurl,
				{
					action: 'smaily_connect_dismiss_notice',
					id: '<?php echo esc_js( $id ); ?>',
					nonce: '<?php echo esc_attr( wp_create_nonce( 'smaily_connect_dismiss_notice' ) ); ?>'
				},
				function(response) {
					if (response.success) {
						$('#<?php echo esc_attr( $id ); ?>').fadeOut();
					}
				}
			);
		});
	});
</script>
<?php endif; ?>
