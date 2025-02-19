'use strict';
(function ($) {
	$().ready(function () {
		// Generate RSS product feed URL if options change.
		$('.smaily-rss-options').change(function () {
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
			if (rss_order_by != 'none' && rss_order_by != 'rand') {
				rss_url.searchParams.set('order', rss_order);
			}

			$('#smaily-rss-feed-url').html(rss_url.href)
		})
	})
})(jQuery)
