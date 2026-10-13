/**
 * Campaigns list and campaign page: duplicate, delete, pause, resume, stop,
 * retry, and live numbers while a campaign sends.
 */
(function ($) {
	'use strict';

	var t = bhecCampaigns.i18n;

	function post(action, data) {
		return $.post(bhecAdmin.ajaxUrl, $.extend({ action: action, nonce: bhecAdmin.nonce }, data));
	}

	function go(args) {
		var url = new URL(bhecCampaigns.listUrl);
		$.each(args, function (k, v) { url.searchParams.set(k, v); });
		location.href = url.toString();
	}

	$(document).on('click', '.bhec-duplicate-campaign', function () {
		var $btn = $(this).prop('disabled', true);
		post('bhec_duplicate_campaign', { campaign_id: $btn.data('id') }).done(function (res) {
			if (res.success) {
				go({ action: 'edit', id: res.data.campaign_id, done: 'duplicated' });
			} else {
				$btn.prop('disabled', false);
				alert(res.data || t.error);
			}
		}).fail(function () {
			$btn.prop('disabled', false);
			alert(t.error);
		});
	});

	$(document).on('click', '.bhec-delete-campaign', function () {
		var $btn = $(this);
		if (!confirm(t.confirmDelete.replace('%s', $btn.data('title')))) return;
		$btn.prop('disabled', true);
		post('bhec_delete_campaign', { campaign_id: $btn.data('id') }).done(function (res) {
			if (res.success) {
				go({ done: 'deleted' });
			} else {
				$btn.prop('disabled', false);
				alert(res.data || t.error);
			}
		});
	});

	// Campaign page.
	var $view = $('.bhec-view');
	if (!$view.length) return;
	var campaignId = $view.data('campaign-id');

	$(document).on('click', '.bhec-campaign-action', function () {
		var $btn = $(this);
		if ($btn.data('confirm') && !confirm($btn.data('confirm'))) return;
		$btn.prop('disabled', true);
		post($btn.data('action'), { campaign_id: campaignId }).always(function () {
			location.reload();
		});
	});

	$(document).on('click', '.bhec-send-one', function () {
		var $btn = $(this).prop('disabled', true).text(t.sending);
		post('bhec_force_send', { queue_id: $btn.data('id') }).done(function (res) {
			$btn.closest('.row-actions').replaceWith('<p class="bhec-status-detail">' + $('<span>').text(res.data || t.error).html() + '</p>');
		}).fail(function () {
			$btn.prop('disabled', false);
			alert(t.error);
		});
	});

	// While sending, refresh the numbers; reload once it finishes.
	var status = $view.data('status');
	if (status !== 'sending' && status !== 'paused') return;

	// Send Faster: run the sender back to back while this page is open,
	// instead of waiting for the once-a-minute background run.
	var faster = false;
	var fasterXhr = null;

	function sendBatch() {
		if (!faster) return;
		fasterXhr = $.ajax({
			url: bhecAdmin.ajaxUrl,
			type: 'POST',
			timeout: 400000,
			data: { action: 'bhec_process_now', nonce: bhecAdmin.nonce },
		}).done(function (res) {
			var data = res && res.data;
			// Paused because the mail provider asked us to slow down: wait it out.
			var wait = data && typeof data === 'object' && data.wait ? data.wait * 1000 : 1000;
			if (data && typeof data === 'object' && data.wait) {
				$('#bhec-faster-hint').text(data.message);
			}
			setTimeout(sendBatch, wait);
		}).fail(function (xhr, textStatus) {
			if (textStatus !== 'abort') setTimeout(sendBatch, 5000);
		});
	}

	$('#bhec-send-faster').on('click', function () {
		var $btn = $(this);
		faster = !faster;
		$btn.attr('aria-pressed', faster ? 'true' : 'false').text(faster ? $btn.data('on') : $btn.data('off')).toggleClass('button-primary', faster);
		if (faster) {
			sendBatch();
		} else if (fasterXhr) {
			fasterXhr.abort();
		}
	});

	function refresh() {
		post('bhec_queue_status', {}).done(function (res) {
			if (!res.success) return;
			var c = null;
			$.each(res.data, function (i, item) {
				if (parseInt(item.id, 10) === parseInt(campaignId, 10)) c = item;
			});
			if (!c) return;
			var now = c.status === 'completed' ? 'sent' : (c.status === 'queued' ? 'sending' : c.status);
			if (now !== status) {
				location.reload();
				return;
			}
			var p = c.progress;
			var done = p.sent + p.failed + p.skipped;
			var pct = p.total ? Math.min(100, Math.round(1000 * done / p.total) / 10) : 0;
			$('#bhec-live-sent, #bhec-stat-sent').text(p.sent.toLocaleString());
			$('#bhec-live-total').text(p.total.toLocaleString());
			$('#bhec-live-bar .bhec-progress').attr('aria-valuenow', pct).children('span').css('width', pct + '%');
		}).always(function () {
			setTimeout(refresh, 5000);
		});
	}
	setTimeout(refresh, 5000);
})(jQuery);
