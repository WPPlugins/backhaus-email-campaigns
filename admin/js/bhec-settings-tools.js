/**
 * Settings > Logs and Status: email details, deleting old logs, the test
 * email, maintenance and the settings import confirmation.
 */
(function ($) {
	'use strict';

	var t = (window.bhecTools && bhecTools.i18n) || {};

	function post(action, data) {
		return $.post(bhecAdmin.ajaxUrl, $.extend({ action: action, nonce: bhecAdmin.nonce }, data));
	}

	// Message from a failed request: the server's text, or a generic one.
	function errorText(res) {
		return res && typeof res.data === 'string' && res.data ? res.data : t.error;
	}

	// ----- Logs: email details ----------------------------------------------

	var $dialog = $('#bhec-log-dialog');
	var frame = document.getElementById('bhec-log-frame');
	var request = null;

	function field(name) {
		return $dialog.find('[data-field="' + name + '"]');
	}

	$(document).on('click', '.bhec-log-details', function () {
		if (!$dialog.length) return;
		if (request) request.abort();

		$dialog.removeClass('has-content');
		$dialog.find('.bhec-log-facts, .bhec-log-content, .bhec-log-no-content').prop('hidden', true);
		$dialog.find('.bhec-log-message').text(t.loading).prop('hidden', false);
		frame.srcdoc = '';
		$dialog[0].showModal();

		request = post('bhec_get_log_detail', { log_id: $(this).data('id') }).done(function (res) {
			if (!res || !res.success) {
				$dialog.find('.bhec-log-message').text(errorText(res));
				return;
			}
			var d = res.data;
			var $to = field('to').text(d.recipient_email);
			if (d.recipient_name) {
				$to.append($('<span class="bhec-row-subject">').text(d.recipient_name));
			}
			field('subject').text(d.subject);
			field('source').empty().append(
				d.source_url ? $('<a>').attr('href', d.source_url).text(d.source) : document.createTextNode(d.source)
			);
			// The badge is built and escaped on the server.
			var $result = field('result').html(d.result);
			if (d.error_message && d.status !== 'sent') {
				$result.append($('<span class="bhec-status-detail">').text(d.error_message));
			}
			field('when').text(d.when);

			$dialog.find('.bhec-log-message').prop('hidden', true);
			$dialog.find('.bhec-log-facts').prop('hidden', false);
			if (d.email_body) {
				// Sandboxed, so the email's own markup can't reach this page.
				frame.srcdoc = d.email_body;
				$dialog.addClass('has-content').find('.bhec-log-content').prop('hidden', false);
			} else {
				$dialog.find('.bhec-log-no-content').prop('hidden', false);
			}
		}).fail(function (xhr, status) {
			if (status !== 'abort') $dialog.find('.bhec-log-message').text(t.error);
		});
	});

	// Clicking the backdrop closes the dialog.
	$dialog.on('click', function (e) {
		if (e.target === this) this.close();
	});

	// ----- Logs: delete old entries -----------------------------------------

	$('#bhec-delete-old-logs').on('click', function () {
		var $btn = $(this);
		if (!confirm($btn.data('confirm'))) return;
		$btn.prop('disabled', true);
		post('bhec_purge_logs').done(function (res) {
			if (res && res.success) {
				var url = new URL(location.href);
				url.searchParams.delete('paged');
				url.searchParams.set('deleted', res.data.deleted);
				location.href = url.toString();
				return;
			}
			$btn.prop('disabled', false);
			alert(errorText(res));
		}).fail(function () {
			$btn.prop('disabled', false);
			alert(t.error);
		});
	});

	// ----- Status: test email -----------------------------------------------

	$('#bhec-send-test-email').on('click', function () {
		var $btn = $(this);
		var $input = $('#bhec-test-email-to');
		var $status = $('#bhec-test-email-status').removeClass('is-error');
		if (!$input[0].reportValidity()) return;
		var email = $input.val().trim();

		$btn.prop('disabled', true);
		$status.text(t.sending);
		post('bhec_send_health_check', { email: email }).done(function (res) {
			if (res && res.success) {
				$status.text(t.testSent.replace('%s', email));
			} else {
				$status.addClass('is-error').text(t.testFailed);
			}
		}).fail(function () {
			$status.addClass('is-error').text(t.error);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ----- Status: maintenance ----------------------------------------------

	$('#bhec-delete-coupons').on('click', function () {
		var $btn = $(this);
		var $status = $btn.siblings('.bhec-tool-status').removeClass('is-error');
		if (!confirm(t.confirmCoupons)) return;
		$btn.prop('disabled', true);
		$status.text(t.deleting);
		post('bhec_purge_coupons').done(function (res) {
			$status.toggleClass('is-error', !(res && res.success)).text(res && res.success ? res.data : errorText(res));
		}).fail(function () {
			$status.addClass('is-error').text(t.error);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '.bhec-reschedule-cron', function () {
		var $btn = $(this);
		var $status = $btn.siblings('.bhec-tool-status').removeClass('is-error');
		if (!confirm(t.confirmRestart)) return;
		$btn.prop('disabled', true);
		$status.text(t.restarting);
		post('bhec_reschedule_cron').done(function (res) {
			if (res && res.success) {
				$status.text(t.restarted.replace('%s', res.data && res.data.next ? res.data.next : ''));
				// Reload so the summary above shows the new schedule.
				setTimeout(function () { location.reload(); }, 1500);
				return;
			}
			$btn.prop('disabled', false);
			$status.addClass('is-error').text(errorText(res));
		}).fail(function () {
			$btn.prop('disabled', false);
			$status.addClass('is-error').text(t.error);
		});
	});

	// ----- Status: import settings ------------------------------------------

	$('.bhec-import-form').on('submit', function (e) {
		var $form = $(this);
		var message = [
			t.confirmImport,
			$form.find('[name="overwrite_settings"]').is(':checked') ? t.importReplace : t.importKeep
		];
		if ($form.find('[name="import_subscriptions"]').is(':checked')) {
			message.push(t.importPeople);
		}
		if (!confirm(message.join(' '))) e.preventDefault();
	});
})(jQuery);
