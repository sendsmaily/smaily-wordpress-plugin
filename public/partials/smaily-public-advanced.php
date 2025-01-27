<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Administrator controlled html.
// phpcs:ignore  WordPress.Security.EscapeOutput.OutputNotEscaped
echo html_entity_decode( $this->form, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );
