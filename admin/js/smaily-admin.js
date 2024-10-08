'use strict'
;(function ($) {
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

	function smailyToggleAdvanced() {
		if ($('#is_advanced').is(':checked')) {
			$('.is-advanced-row').show()
			$('#is_advanced').val(1)
		} else {
			$('.is-advanced-row').hide()
			$('#is_advanced').val(0)
		}
	}
	smailyToggleAdvanced()
	$('#is_advanced').on('change', smailyToggleAdvanced)

	$('#reset-form').on('click', function (e) {
		e.preventDefault()
		$.post(ajaxurl, {
			action: 'smaily_admin_save',
			payload: 'op=resetForm&nonce=' + $('#nonce').val(),
		}).done(function (response) {
			let $resp = JSON.parse(response)
			if (!$resp.error) {
				$('#smaily-advanced-form').val($resp.content)
				displayMessage($resp.message)
			}
		})
	})

	// Top tabs handler.
	$('#tabs').tabs()
	// Add custom class for active tab.
	$('#tabs-list li a').click(function () {
		$('a.nav-tab-active').removeClass('nav-tab-active')
		$(this).addClass('nav-tab-active')
	})

	// Hide spinner.
	$('.loader').hide()

	// First Form on Settings page to check if
	// subdomain / username / password are correct.
	$().ready(function () {
		var $settings = $('#smaily-settings'),
			$form = $settings.find('form'),
			$spinner = $settings.find('.loader')

		$form.submit(function (ev) {
			ev.preventDefault()

			// Show loading spinner.
			$spinner.show()

			$.post(
				ajaxurl,
				{
					action: 'smaily_admin_save',
					payload: $form.serialize(),
				},
				function (response) {
					if (response.error) {
						displayMessage(response.error, true)
					} else if (!response) {
						displayMessage(smaily_translations.went_wrong, true)
					} else {
						var $autoresponders = $('#abandoned-cart-autoresponder'),
							selected = parseInt($autoresponders.val(), 10)

						// Remove existing abandoned cart autoresponders.
						$autoresponders.find('option').remove()

						// Populate abandoned cart autoresponders.

						if (response.autoresponders) {
							$.each(response.autoresponders, function (index, item) {
								$autoresponders.append(
									$('<option>', {
										value: index,
										selected: index === selected,
										text: item,
									})
								)
							})
						}

						displayMessage(smaily_translations.validated)
					}

					// Hide loading spinner.
					$spinner.hide()
				},
				'json'
			)
		})

		// Generate RSS product feed URL if options change.
		$('.smaily-rss-options').change(function () {
			var rss_url_base = smaily_settings['rss_feed_url'] + '?'
			var parameters = {}

			var rss_category = $('#rss-category').val()
			if (rss_category != '') {
				parameters.category = rss_category
			}

			var rss_limit = $('#rss-limit').val()
			if (rss_limit != '') {
				parameters.limit = rss_limit
			}

			var rss_order_by = $('#rss-sort-field').val()
			if (rss_order_by != 'none') {
				parameters.order_by = rss_order_by
			}

			var rss_order = $('#rss-sort-order').val()
			if (rss_order_by != 'none' && rss_order_by != 'rand') {
				parameters.order = rss_order
			}

			$('#smaily-rss-feed-url').html(rss_url_base + $.param(parameters))
		})
	})
})(jQuery)
