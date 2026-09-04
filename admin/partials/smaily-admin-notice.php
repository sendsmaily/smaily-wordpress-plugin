<?php

defined( 'ABSPATH' ) || exit;

?>

<div
	id="<?php echo esc_attr( $id ); ?>"
	class="notice smaily-connect-notice notice-<?php echo esc_attr( $notice['type'] ); ?>
	<?php echo $notice['dismissible'] ? 'is-dismissible' : ''; ?>">

	<?php
	require $template_path;
	?>

</div>
