'use strict';
(function ($) {
	$().ready(function () {
		// Generate RSS product feed URL if options change.
		$('.smaily-rss-options').on('change', function () {
			var rss_url = new URL( smaily_settings['rss_feed_url'] );

			var rss_category = $('#smaily-rss-category').val()
			if (rss_category != '') {
				rss_url.searchParams.set('category', rss_category);
			}

			var rss_limit = $('#smaily-rss-limit').val()
			if (rss_limit != '') {
				rss_url.searchParams.set('limit', rss_limit);
			}

			var rss_order_by = $('#smaily-rss-sort-field').val()
			if (rss_order_by != 'none') {
				rss_url.searchParams.set('order_by', rss_order_by);
			}

			var rss_order = $('#smaily-rss-sort-order').val()
			if (rss_order_by != 'none') {
				rss_url.searchParams.set('order', rss_order);
			}

			var tax_rate = $('#smaily-rss-tax-rate').val()
			if (tax_rate != '') {
				rss_url.searchParams.set('tax_rate', tax_rate);
			}

			$('#smaily-rss-feed-url').html(rss_url.href)
		});

		// Copy RSS product feed URL to clipboard.
		$('#smaily-rss-feed-url-copy').on('click', function () {
			var url = document.getElementById("smaily-rss-feed-url").innerText;
			console.log(url);
			navigator.clipboard.writeText(url).then(function() {
				$('#smaily-rss-feed-url-copy-icon').animate({opacity: 1}, 200);
				setTimeout(function() {
					$('#smaily-rss-feed-url-copy-icon').animate({opacity: 0}, 200);
				}, 1000);
			}, function(err) {
				console.error('Async: Could not copy text: ', err);
			});
		});
	})
})(jQuery)
