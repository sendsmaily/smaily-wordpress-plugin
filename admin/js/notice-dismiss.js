/**
 * Persists the dismissal of a Smaily Connect admin notice.
 *
 * WordPress' core handler hides the notice in the DOM when the "x" is clicked;
 * this posts the dismissal back so it stays hidden on the next page load. Config
 * (ajax URL + nonce) is provided via wp_localize_script as `smailyConnectNoticeDismiss`.
 */
(function ($) {
	'use strict';

	var cfg = window.smailyConnectNoticeDismiss || {};

	$(document).on('click', '.smaily-connect-notice .notice-dismiss', function () {
		var $notice = $(this).closest('.smaily-connect-notice');
		var id = $notice.attr('id');

		if (!id || !cfg.ajaxUrl) {
			return;
		}

		$.post(
			cfg.ajaxUrl,
			{
				action: 'smaily_connect_dismiss_notice',
				id: id,
				nonce: cfg.nonce
			},
			function (response) {
				if (response && response.success) {
					$notice.fadeOut();
				}
			}
		);
	});
})(jQuery);
