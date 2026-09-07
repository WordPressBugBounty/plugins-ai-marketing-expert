/**
 * AI Marketing Expert - Upgrade Notice Dismissal Handler.
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		var $notice = $('.aime-pro-upgrade-notice');
		if (!$notice.length) {
			return;
		}

		function dismissNotice(e) {
			if (e) {
				e.preventDefault();
			}

			// Immediately slide up and remove from DOM for instant feedback.
			$notice.slideUp(250, function () {
				$(this).remove();
			});

			// Persist dismissal via AJAX.
			if (window.aimeUpgradeNotice && window.aimeUpgradeNotice.ajaxUrl) {
				$.post(window.aimeUpgradeNotice.ajaxUrl, {
					action: window.aimeUpgradeNotice.action,
					nonce: window.aimeUpgradeNotice.nonce,
				}).fail(function () {
					// Fallback to location redirect if AJAX failed.
					if (window.aimeUpgradeNotice.dismissUrl) {
						window.location.href = window.aimeUpgradeNotice.dismissUrl;
					}
				});
			}
		}

		// Handle WordPress standard notice-dismiss button.
		$notice.on('click', '.notice-dismiss', function (e) {
			dismissNotice(e);
		});

		// Handle custom "Maybe Later" text link.
		$notice.on('click', '.aime-notice-dismiss-link', function (e) {
			dismissNotice(e);
		});
	});
})(jQuery);
