/**
 * Subscribers: subscribe and unsubscribe (one person or in bulk, with a
 * confirmation that says who is left out and why), unsubscribing a typed-in
 * address, email history, and the opt-out import.
 */
(function ($) {
	'use strict';

	var t = bhecPage.i18n;

	function esc(str) {
		return $('<span>').text(str == null ? '' : String(str)).html();
	}

	function post(action, data) {
		return $.post(bhecAdmin.ajaxUrl, $.extend({ action: action, nonce: bhecAdmin.nonce }, data));
	}

	function go(args) {
		var url = new URL(location.href);
		['done', 'count', 'skipped', 'paged'].forEach(function (k) { url.searchParams.delete(k); });
		$.each(args, function (k, v) { url.searchParams.set(k, v); });
		location.href = url.toString();
	}

	function people(count, one, many) {
		return count === 1 ? one : many.replace('%s', count.toLocaleString());
	}

	// Click on the backdrop closes a dialog.
	$('dialog.bhec-dialog').on('click', function (e) {
		if (e.target === this) this.close('cancel');
	});

	// ----- Subscribe and unsubscribe -------------------------------------

	var $dialog = $('#bhec-sub-dialog');
	var pending = null;

	/**
	 * @param {Object} opts title, body (HTML), button (label, or '' for none), run.
	 */
	function ask(opts) {
		$('#bhec-sub-dialog-title').text(opts.title);
		$('#bhec-sub-dialog-body').html(opts.body);
		$('#bhec-sub-dialog-confirm').text(opts.button || '').prop('hidden', !opts.button).prop('disabled', false);
		$('#bhec-sub-dialog-cancel').text(opts.button ? $('#bhec-sub-dialog-cancel').data('label') : t.close);
		pending = opts.button ? opts.run : null;
		$dialog[0].showModal();
	}
	$('#bhec-sub-dialog-cancel').data('label', $('#bhec-sub-dialog-cancel').text());

	$dialog.on('close', function () {
		if (this.returnValue !== 'confirm' || !pending) return;
		var run = pending;
		pending = null;
		run();
	});

	function fail(message) {
		ask({ title: t.error, body: '<p>' + esc(message || t.error) + '</p>' });
	}

	/**
	 * @param {string}   action  subscribe or unsubscribe.
	 * @param {string[]} emails  Who to change.
	 * @param {number}   blocked Picked but left out because they opted out.
	 */
	function change(action, emails, blocked) {
		var busy = $('.bhec-sub-action, #doaction, #doaction2').prop('disabled', true);
		var request = emails.length === 1
			? post('bhec_toggle_subscription', { email: emails[0], sub_action: action })
			: post('bhec_bulk_subscription', { emails: emails, sub_action: action });
		request.done(function (res) {
			if (!res || !res.success) {
				busy.prop('disabled', false);
				fail(res && res.data);
				return;
			}
			var skipped = (blocked || 0) + (emails.length > 1 ? res.data.skipped : 0);
			var args = { done: action === 'subscribe' ? 'subscribed' : 'unsubscribed', count: emails.length === 1 ? 1 : res.data.updated };
			if (skipped) args.skipped = skipped;
			go(args);
		}).fail(function () {
			busy.prop('disabled', false);
			fail();
		});
	}

	// One person, from the row actions.
	$(document).on('click', '.bhec-sub-action', function () {
		var $btn = $(this);
		var email = String($btn.data('email'));
		var action = $btn.data('action');
		var who = '<strong>' + esc(email) + '</strong>';

		if (action === 'subscribe') {
			var line = $btn.data('state') === 'pending' ? t.pendingOne : t.subscribeOne;
			ask({
				title: t.subscribeTitle,
				body: '<p>' + esc(line).replace('%s', who) + '</p>',
				button: t.subscribe,
				run: function () { change('subscribe', [email]); }
			});
		} else {
			ask({
				title: t.unsubscribeTitle,
				body: '<p>' + esc(t.unsubscribeOne).replace('%s', who) + '</p><div class="bhec-dialog-note"><p>' + esc(t.backIn) + '</p></div>',
				button: t.unsubscribe,
				run: function () { change('unsubscribe', [email]); }
			});
		}
	});

	// Several people, from the bulk actions menu.
	$('#doaction, #doaction2').on('click', function (e) {
		var action = $(this).siblings('select').val();
		if (action !== 'subscribe' && action !== 'unsubscribe') return;
		e.preventDefault();

		var $checked = $('#bhec-subscribers-form input[name="email[]"]:checked');
		if (!$checked.length) {
			ask({ title: action === 'subscribe' ? t.nobodySubTitle : t.nobodyUnsubTitle, body: '<p>' + esc(t.selectFirst) + '</p>' });
			return;
		}

		var targets = [];
		var states = [];
		var blocked = 0;
		$checked.each(function () {
			var state = $(this).data('state');
			if (action === 'subscribe') {
				if (state === 'subscribed') return;
				if (String($(this).data('can-subscribe')) !== '1') {
					blocked++;
					return;
				}
			} else if (state === 'unsubscribed') {
				return; // Keeps the date they opted out.
			}
			targets.push($(this).val());
			states.push(state);
		});

		var count = targets.length;
		var one = function (line) {
			return count === 1 ? esc(line).replace('%s', '<strong>' + esc(targets[0]) + '</strong>') : '';
		};
		if (action === 'subscribe') {
			if (!count) {
				ask({ title: t.nobodySubTitle, body: '<p>' + esc(t.nobodySub) + '</p>' });
				return;
			}
			var body = '<p>' + (one(states[0] === 'pending' ? t.pendingOne : t.subscribeOne) || esc(t.subscribeThem)) + '</p>';
			if (blocked) {
				body += '<div class="bhec-dialog-note"><p>' + esc(people(blocked, t.blockedOne, t.blockedMany)) + '</p></div>';
			}
			ask({
				title: people(count, t.subscribeTitle, t.subscribeMany),
				body: body,
				button: people(count, t.subscribe, t.subscribeButton),
				run: function () { change('subscribe', targets, blocked); }
			});
		} else {
			if (!count) {
				ask({ title: t.nobodyUnsubTitle, body: '<p>' + esc(t.nobodyUnsub) + '</p>' });
				return;
			}
			ask({
				title: people(count, t.unsubscribeTitle, t.unsubscribeMany),
				body: '<p>' + (one(t.unsubscribeOne) || esc(t.unsubscribeThem)) + '</p><div class="bhec-dialog-note"><p>' + esc(t.backIn) + '</p></div>',
				button: people(count, t.unsubscribe, t.unsubscribeButton),
				run: function () { change('unsubscribe', targets); }
			});
		}
	});

	// One person, typed in: no need to find them in the list first.
	var $quick = $('#bhec-quick-unsub');
	var $quickEmail = $('#bhec-quick-unsub-email');

	function quickError(message) {
		$('#bhec-quick-unsub-error').text(message || '').prop('hidden', !message);
		$quickEmail.attr('aria-invalid', message ? 'true' : null);
	}

	$quickEmail.on('input', function () { quickError(''); });

	$quick.on('submit', function (e) {
		e.preventDefault();
		var email = $.trim($quickEmail.val());
		$quickEmail.val(email);
		if (!email || !$quickEmail[0].checkValidity()) {
			quickError(t.notEmail);
			$quickEmail.trigger('focus');
			return;
		}
		quickError('');
		ask({
			title: t.unsubscribeTitle,
			body: '<p>' + esc(t.unsubscribeOne).replace('%s', '<strong>' + esc(email) + '</strong>') + '</p><div class="bhec-dialog-note"><p>' + esc(t.backIn) + '</p></div>',
			button: t.unsubscribe,
			run: function () {
				var $button = $quick.find('button').prop('disabled', true);
				post('bhec_quick_unsubscribe', { email: email }).done(function (res) {
					if (!res || !res.success) {
						$button.prop('disabled', false);
						quickError((res && res.data) || t.error);
						return;
					}
					go(res.data.changed ? { done: 'unsubscribed', count: 1 } : { done: 'unsubscribed', count: 0, skipped: 1 });
				}).fail(function () {
					$button.prop('disabled', false);
					quickError(t.error);
				});
			}
		});
	});

	// ----- Email history --------------------------------------------------

	var $history = $('#bhec-history-dialog');
	var $historyBody = $('#bhec-history-body');
	var historyRequest = null;

	function mark(value) {
		if (value === null) return '';
		return value
			? '<span class="dashicons dashicons-yes" aria-hidden="true"></span><span class="screen-reader-text">' + esc(t.yes) + '</span>'
			: '<span class="bhec-muted">' + esc(t.no) + '</span>';
	}

	function renderHistory(d, fallbackName) {
		var name = d.name || fallbackName;
		$('#bhec-history-name').text(name || '').prop('hidden', !name);

		var html = '<div class="bhec-history-status"><span class="bhec-badge bhec-badge-' + esc(d.tone) + '">' + esc(d.label) + '</span> '
			+ '<span class="bhec-muted">' + esc(d.detail) + '</span></div>'
			+ '<p class="bhec-history-meta"><span class="bhec-muted">' + esc(t.sourceLabel) + '</span> ' + esc(d.source);
		if (d.profile) html += ' <a href="' + esc(d.profile) + '">' + esc(t.profile) + '</a>';
		html += '</p>';

		if (!d.emails.length) {
			html += '<p class="bhec-history-empty">' + esc(t.noEmails) + '</p>';
			$historyBody.html(html);
			return;
		}
		if (!d.tracking) html += '<p class="bhec-hint">' + esc(t.trackingOff) + '</p>';

		html += '<table class="widefat striped bhec-history-table"><thead><tr>'
			+ '<th scope="col">' + esc(t.colEmail) + '</th><th scope="col">' + esc(t.colWhen) + '</th>'
			+ '<th scope="col" class="bhec-history-mark">' + esc(t.colOpened) + '</th><th scope="col" class="bhec-history-mark">' + esc(t.colClicked) + '</th>'
			+ '</tr></thead><tbody>';
		d.emails.forEach(function (m) {
			var title = m.link ? '<a href="' + esc(m.link) + '">' + esc(m.name) + '</a>' : esc(m.name);
			html += '<tr>'
				+ '<td data-label="' + esc(t.colEmail) + '"><strong>' + title + '</strong><span class="bhec-status-detail">' + esc(m.kind) + '</span></td>'
				+ '<td data-label="' + esc(t.colWhen) + '"><span>' + esc(m.when) + (m.status ? '<span class="bhec-status-detail">' + esc(m.status) + '</span>' : '') + '</span></td>'
				+ '<td data-label="' + esc(t.colOpened) + '" class="bhec-history-mark">' + mark(m.opened) + '</td>'
				+ '<td data-label="' + esc(t.colClicked) + '" class="bhec-history-mark">' + mark(m.clicked) + '</td>'
				+ '</tr>';
		});
		html += '</tbody></table>';
		if (d.more) html += '<p class="bhec-hint">' + esc(t.latest.replace('%s', d.limit)) + '</p>';
		$historyBody.html(html);
	}

	$(document).on('click', '.bhec-sub-history', function () {
		var $btn = $(this);
		var email = String($btn.data('email'));
		var name = String($btn.data('name') || '');

		$('#bhec-history-title').text(email);
		$('#bhec-history-name').text(name).prop('hidden', !name);
		$historyBody.html('<p class="bhec-history-loading"><span class="spinner is-active"></span>' + esc(t.loading) + '</p>');
		$history[0].showModal();

		if (historyRequest) historyRequest.abort();
		historyRequest = post('bhec_customer_history', { email: email }).done(function (res) {
			if (!res || !res.success) {
				$historyBody.html('<p>' + esc((res && res.data) || t.error) + '</p>');
				return;
			}
			renderHistory(res.data, name);
		}).fail(function (xhr, status) {
			if (status !== 'abort') $historyBody.html('<p>' + esc(t.error) + '</p>');
		});
	});

	// ----- Import opt-outs ------------------------------------------------

	var $import = $('#bhec-import-dialog');
	var $importForm = $('#bhec-import-form');
	var imported = false;

	function importError(message) {
		$('#bhec-import-error').text(message).prop('hidden', !message);
	}

	$(document).on('click', '.bhec-open-import', function () {
		imported = false;
		$importForm[0].reset();
		importError('');
		$('#bhec-import-intro').prop('hidden', false);
		$('#bhec-import-result').prop('hidden', true);
		$('#bhec-import-submit').prop('hidden', false).prop('disabled', false);
		$('#bhec-import-cancel').text($('#bhec-import-cancel').data('label')).prop('disabled', false);
		$import[0].showModal();
	});
	$('#bhec-import-cancel').data('label', $('#bhec-import-cancel').text());

	$('#bhec-import-cancel').on('click', function () {
		$import[0].close('cancel');
	});

	$import.on('close', function () {
		if (imported) go({});
	});

	$importForm.on('submit', function (e) {
		e.preventDefault();
		var file = document.getElementById('bhec-import-file');
		if (!file.files.length) {
			importError(t.chooseFile);
			file.focus();
			return;
		}
		importError('');

		var data = new FormData(this);
		data.append('action', 'bhec_import_optouts');
		data.append('nonce', bhecAdmin.nonce);

		var $buttons = $('#bhec-import-submit, #bhec-import-cancel').prop('disabled', true);
		var $spinner = $('#bhec-import-spinner').addClass('is-active');

		$.ajax({
			url: bhecAdmin.ajaxUrl,
			type: 'POST',
			data: data,
			processData: false,
			contentType: false
		}).done(function (res) {
			if (!res || !res.success) {
				importError((res && res.data) || t.error);
				return;
			}
			imported = res.data.imported > 0;
			$('#bhec-import-summary').text(res.data.lines.join(' '));
			$('#bhec-import-intro').prop('hidden', true);
			$('#bhec-import-result').prop('hidden', false);
			$('#bhec-import-submit').prop('hidden', true);
			$('#bhec-import-cancel').text(t.close);
		}).fail(function () {
			importError(t.error);
		}).always(function () {
			$buttons.prop('disabled', false);
			$spinner.removeClass('is-active');
		});
	});
})(jQuery);
