/**
 * Shared admin behaviour. Each screen has its own script (bhec-{screen}.js);
 * only what appears on several screens lives here.
 */
(function ($) {
	'use strict';

	// Sending-paused notice (any of our screens): resume now.
	$(document).on('click', '.bhec-resume-sending', function () {
		var $btn = $(this).prop('disabled', true);
		$.post(bhecAdmin.ajaxUrl, {
			action: 'bhec_resume_sending',
			nonce: bhecAdmin.nonce,
			type: $btn.data('type'),
		}, function () { location.reload(); });
	});

	// A campaign's held emails: send them anyway, or don't.
	$(document).on('click', '.bhec-resolve-held', function () {
		var $btn = $(this);
		var send = $btn.data('decision') === 'send';
		if (!confirm(send ? bhecAdmin.i18n.sendHeld : bhecAdmin.i18n.discardHeld)) return;
		$btn.prop('disabled', true);
		$.post(bhecAdmin.ajaxUrl, {
			action: 'bhec_resolve_held',
			nonce: bhecAdmin.nonce,
			campaign_id: $btn.data('id'),
			decision: $btn.data('decision'),
		}).always(function () {
			location.reload();
		});
	});
})(jQuery);
