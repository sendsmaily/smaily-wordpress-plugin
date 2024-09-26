<?php

// Administrator controlled html.
// phpcs:ignore  WordPress.Security.EscapeOutput.OutputNotEscaped
echo html_entity_decode( $this->form );
