'use strict';
(function ($) {
	// Display messages handler.
	function displayMessage(text, error = false) {
		// Generate message.
		var message = document.createElement('div')
		// Add classes based on error / success.
		if (error) {
			message.classList.add('error')
			message.classList.add('smaily-notice')
			message.classList.add('is-dismissible')
		} else {
			message.classList.add('notice-success')
			message.classList.add('notice')
			message.classList.add('smaily-notice')
			message.classList.add('is-dismissible')
		}
		var paragraph = document.createElement('p')
		// Add text.
		paragraph.innerHTML = text
		message.appendChild(paragraph)
		// Close button
		var button = document.createElement('BUTTON')
		button.classList.add('notice-dismiss')
		button.onclick = function () {
			$(this).closest('div').hide()
		}
		message.appendChild(button)
		// Remove any previously existing messages(success and error).
		var existingMessages = document.querySelectorAll('.smaily-notice')
		Array.prototype.forEach.call(existingMessages, function (msg) {
			msg.remove()
		})
		// Inserts message before tabs.
		document
			.getElementById('smaily-settings')
			.insertBefore(message, document.getElementById('tabs'))
	}

	$().ready(function () {
		// Generate RSS product feed URL if options change.
		$('.smaily-rss-options').change(function () {
			var rss_url = new URL( smaily_settings['rss_feed_url'] );

			var rss_category = $('#rss-category').val()
			if (rss_category != '') {
				rss_url.searchParams.set('category', rss_category);
			}

			var rss_limit = $('#rss-limit').val()
			if (rss_limit != '') {
				rss_url.searchParams.set('limit', rss_limit);
			}

			var rss_order_by = $('#rss-sort-field').val()
			if (rss_order_by != 'none') {
				rss_url.searchParams.set('order_by', rss_order_by);
			}

			var rss_order = $('#rss-sort-order').val()
			if (rss_order_by != 'none' && rss_order_by != 'rand') {
				rss_url.searchParams.set('order', rss_order);
			}

			$('#smaily-rss-feed-url').html(rss_url.href)
		})
	})
})(jQuery)
