/**
 * Campaign editor: the live audience count, coupon and option summaries,
 * save, test, preview and the send confirmation. Shared editor pieces
 * (Personalize, Add Image and so on) come from bhec-editor-kit.js.
 */
(function ($) {
	'use strict';

	var t = bhecCampaigns.i18n;
	var campaignId = parseInt($('#bhec-campaign-id').val(), 10) || 0;
	var dirty = false;
	var post = bhecEditor.post;
	var esc = bhecEditor.esc;
	var fmt = bhecEditor.fmt;
	var plural = bhecEditor.plural;
	var kit = bhecEditor.init({
		bodyId: 'bhec_body',
		onChange: function () { dirty = true; },
	});
	var getBody = kit.getBody;
	var openSection = kit.openSection;
	var toggleRegion = kit.toggleRegion;
	var showError = kit.showError;
	var clearError = kit.clearError;

	// ----- Section summaries -----------------------------------------------

	function bodyHasCoupon() {
		return /\{\{\s*coupon\s*\}\}/.test(kit.getBody());
	}

	function updateCouponSummary() {
		var $s = $('#bhec-coupon-summary');
		if (!$s.length) return;
		var amount = parseFloat($('#bhec-coupon-amount').val());
		var type = $('#bhec-coupon-discount-type').val();
		var days = parseInt($('#bhec-coupon-expiry-days').val(), 10);
		if (!bodyHasCoupon()) {
			$s.text(t.couponNone);
			return;
		}
		if (!amount && !$('#bhec-coupon-free-shipping').is(':checked')) {
			$s.text(t.couponNoAmount).addClass('is-warning');
			return;
		}
		$s.removeClass('is-warning');
		var parts = [];
		if (amount) {
			parts.push(type === 'percent' ? t.couponPercent.replace('%s', amount) : t.couponFixed.replace('%s', bhecCampaigns.currency + amount));
		}
		if ($('#bhec-coupon-free-shipping').is(':checked')) parts.push(t.couponShipping);
		parts.push(days > 0 ? t.couponExpires.replace('%s', days) : t.couponNoExpiry);
		$s.text(parts.join(', '));
	}
	$('#bhec-coupon-body').on('input change', 'input, select', updateCouponSummary);

	function updateMoreSummary() {
		var parts = [];
		var reply = $('#bhec-reply-to-email').val();
		parts.push(reply ? t.replyTo.replace('%s', reply) : t.replyDefault);
		parts.push($('#bhec-layout-template option:selected').text().trim());
		var files = $('.bhec-attachment-item').length;
		if (files) parts.push(plural(files, t.oneAttachment, t.attachments));
		if ($('#bhec-email-bcc').val() || $('#bhec-email-cc').val()) parts.push(t.copies);
		$('#bhec-more-summary').text(parts.join(', '));
	}
	$('#bhec-more-body').on('input change', 'input, select', updateMoreSummary);
	updateMoreSummary();

	// ----- Audience --------------------------------------------------------

	function getFilters() {
		var f = {};
		var $statuses = $('#bhec-filter-order-status');
		var picked = $statuses.find('input:checked').map(function () { return this.value; }).get();
		var defaults = String($statuses.data('default')).split(',');
		var isDefault = picked.length === defaults.length && defaults.every(function (s) { return picked.indexOf(s) !== -1; });
		if (picked.length && !isDefault) f.order_status = picked;

		var map = {
			date_from: '#bhec-filter-date-from',
			date_to: '#bhec-filter-date-to',
			order_count_min: '#bhec-filter-order-count-min',
			order_count_max: '#bhec-filter-order-count-max',
			total_spent_min: '#bhec-filter-total-spent-min',
			total_spent_max: '#bhec-filter-total-spent-max',
			billing_country: '#bhec-filter-billing-country',
		};
		$.each(map, function (key, sel) {
			var v = $(sel).val();
			if (v === '' || v == null) return;
			if (key.indexOf('order_count') === 0) v = parseInt(v, 10);
			if (key.indexOf('total_spent') === 0) v = parseFloat(v);
			f[key] = v;
		});
		if ($('#bhec-filter-include-signups').is(':checked')) f.include_signups = true;
		return f;
	}

	function setFilters(f) {
		f = f || {};
		var statuses = f.order_status && f.order_status.length ? f.order_status : String($('#bhec-filter-order-status').data('default')).split(',');
		$('#bhec-filter-order-status input').each(function () {
			this.checked = statuses.indexOf(this.value) !== -1;
		});
		$('#bhec-filter-date-from').val(f.date_from || '');
		$('#bhec-filter-date-to').val(f.date_to || '');
		$('#bhec-filter-order-count-min').val(f.order_count_min != null ? f.order_count_min : '');
		$('#bhec-filter-order-count-max').val(f.order_count_max != null ? f.order_count_max : '');
		$('#bhec-filter-total-spent-min').val(f.total_spent_min != null ? f.total_spent_min : '');
		$('#bhec-filter-total-spent-max').val(f.total_spent_max != null ? f.total_spent_max : '');
		$('#bhec-filter-billing-country').val(f.billing_country || '').trigger('change.select2');
		$('#bhec-filter-include-signups').prop('checked', !!f.include_signups);
	}

	var countXhr = null;
	var countTimer = null;
	var lastCount = null;
	var sample = [];

	function countAudience() {
		if (countXhr) countXhr.abort();
		$('.bhec-reach').addClass('is-loading');
		countXhr = post('bhec_preview_recipients', {
			filters: JSON.stringify(getFilters()),
			exclude_segment_id: $('#bhec-exclude-segment').val() || 0,
		}).done(function (res) {
			if (!res.success) {
				showReach(null, res.data || t.error);
				return;
			}
			lastCount = res.data.count;
			sample = res.data.sample || [];
			showReach(lastCount);
			renderSample();
		}).fail(function (xhr, status) {
			if (status !== 'abort') showReach(null, t.countFailed);
		}).always(function () {
			countXhr = null;
		});
	}

	function showReach(count, message) {
		$('.bhec-reach').removeClass('is-loading');
		if (count == null) {
			$('#bhec-reach-number').text('');
			$('#bhec-reach-label').text(message);
			return;
		}
		$('#bhec-reach-number').text(fmt(count));
		$('#bhec-reach-label').text(count === 1 ? t.reachOne : (count === 0 ? t.reachNone : t.reachMany));
		$('.bhec-reach').toggleClass('is-empty', count === 0);
	}

	function renderSample() {
		var $list = $('#bhec-sample-list').empty();
		sample.forEach(function (r) {
			var name = r.name ? String(r.name).trim() : '';
			$list.append('<li><span>' + esc(r.email) + '</span>' + (name ? ' <span class="bhec-muted">' + esc(name) + '</span>' : '') + '</li>');
		});
		if (lastCount > sample.length) {
			$list.append('<li class="bhec-muted">' + esc(plural(lastCount - sample.length, t.andOneMore, t.andMore)) + '</li>');
		}
		if (!sample.length) $list.append('<li class="bhec-muted">' + esc(t.reachNone) + '</li>');
	}

	function queueCount() {
		clearTimeout(countTimer);
		$('.bhec-reach').addClass('is-loading');
		countTimer = setTimeout(countAudience, 500);
	}

	$('.bhec-audience').on('input change', 'input, select', function (e) {
		if (e.target.id === 'bhec-save-segment-name' || e.target.id === 'bhec-load-segment') return;
		dirty = true;
		queueCount();
	});
	countAudience();

	$('#bhec-load-segment').on('change', function () {
		var $opt = $(this).find('option:selected');
		if (!$opt.val()) return;
		try {
			setFilters(JSON.parse($opt.attr('data-filters') || '{}'));
		} catch (err) {
			setFilters({});
		}
		$(this).val('');
		dirty = true;
		countAudience();
	});


	$('#bhec-show-sample').on('click', function () {
		toggleRegion($(this));
		$(this).text($(this).attr('aria-expanded') === 'true' ? t.hideWho : t.seeWho);
	});

	$('#bhec-save-segment-toggle').on('click', function () {
		if (toggleRegion($(this))) $('#bhec-save-segment-name').trigger('focus');
	});

	$('#bhec-save-segment').on('click', function () {
		var name = $('#bhec-save-segment-name').val().trim();
		if (!name) {
			$('#bhec-save-segment-name').trigger('focus');
			return;
		}
		var $btn = $(this).prop('disabled', true);
		post('bhec_save_segment', { name: name, filters: JSON.stringify(getFilters()) }).done(function (res) {
			$btn.prop('disabled', false);
			if (res.success) {
				$('#bhec-save-segment-name').val('');
				toggleRegion($('#bhec-save-segment-toggle'));
				$('#bhec-save-state').text(t.segmentSaved.replace('%s', name));
			} else {
				alert(res.data || t.error);
			}
		});
	});

	// ----- When ------------------------------------------------------------

	function isScheduled() {
		return $('input[name="bhec-when"]:checked').val() === 'later';
	}

	$('input[name="bhec-when"]').on('change', function () {
		var later = isScheduled();
		$('.bhec-schedule').prop('hidden', !later);
		$('#bhec-send').text(later ? $('#bhec-send').data('later') : $('#bhec-send').data('now'));
		if (later) $('#bhec-scheduled-at').trigger('focus');
		dirty = true;
	});

	function scheduledAt() {
		return isScheduled() ? $('#bhec-scheduled-at').val() : '';
	}

	function scheduleLabel(value) {
		var d = new Date(value);
		if (isNaN(d)) return value;
		return d.toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
	}

	// ----- Gather, save ----------------------------------------------------

	function getCouponSettings() {
		var s = {};
		var num = function (sel) { var v = $(sel).val(); return v === '' || v == null ? null : parseFloat(v); };
		var ids = function (sel) { var v = $(sel).val(); return v && v.length ? v.map(Number) : null; };
		if ($('#bhec-coupon-prefix').val()) s.prefix = $('#bhec-coupon-prefix').val();
		s.discount_type = $('#bhec-coupon-discount-type').val() || 'percent';
		if (num('#bhec-coupon-amount') !== null) s.amount = num('#bhec-coupon-amount');
		s.free_shipping = $('#bhec-coupon-free-shipping').is(':checked') ? 1 : 0;
		if (num('#bhec-coupon-min-spend') !== null) s.min_spend = num('#bhec-coupon-min-spend');
		if (num('#bhec-coupon-max-spend') !== null) s.max_spend = num('#bhec-coupon-max-spend');
		if (ids('#bhec-coupon-products')) s.product_ids = ids('#bhec-coupon-products');
		if (ids('#bhec-coupon-categories')) s.category_ids = ids('#bhec-coupon-categories');
		if (ids('#bhec-coupon-exclude-products')) s.exclude_product_ids = ids('#bhec-coupon-exclude-products');
		if (ids('#bhec-coupon-exclude-categories')) s.exclude_category_ids = ids('#bhec-coupon-exclude-categories');
		s.individual_use = $('#bhec-coupon-individual-use').is(':checked') ? 1 : 0;
		s.usage_limit = parseInt($('#bhec-coupon-usage-limit').val(), 10) || 1;
		var days = parseInt($('#bhec-coupon-expiry-days').val(), 10);
		s.expiry_days = isNaN(days) ? 30 : days;
		return s;
	}

	function getCampaignData() {
		return {
			campaign_id: campaignId,
			title: $('#bhec-title').val(),
			subject: $('#bhec-subject').val(),
			body: getBody(),
			filters: JSON.stringify(getFilters()),
			exclude_segment_id: $('#bhec-exclude-segment').val() || 0,
			email_to: $('#bhec-email-to').val() || '{{ customer.email }}',
			email_cc: $('#bhec-email-cc').val(),
			email_bcc: $('#bhec-email-bcc').val(),
			reply_to_name: $('#bhec-reply-to-name').val(),
			reply_to_email: $('#bhec-reply-to-email').val(),
			layout_template: $('#bhec-layout-template').val() || 'default',
			attachments: JSON.stringify(kit.attachmentIds()),
			coupon_settings: $('#bhec-coupon-section').length ? JSON.stringify(getCouponSettings()) : '',
			scheduled_at: scheduledAt(),
			subject_b: $('#bhec-subject-b').val() || '',
			ab_percent: $('#bhec-ab-percent').val() || 20,
			ab_hours: $('#bhec-ab-hours').val() || 4,
			ab_metric: $('#bhec-ab-metric').val() || 'opens',
		};
	}

	function save(data) {
		return post('bhec_save_campaign', data || getCampaignData()).then(function (res) {
			if (!res.success) return $.Deferred().reject(res.data || t.error);
			if (res.data.campaign_id && !campaignId) {
				campaignId = res.data.campaign_id;
				$('#bhec-campaign-id').val(campaignId);
				var url = new URL(location.href);
				url.searchParams.set('action', 'edit');
				url.searchParams.set('id', campaignId);
				url.searchParams.delete('done');
				history.replaceState(null, '', url.toString());
				$('.bhec-editor h1').text(t.editTitle);
			}
			dirty = false;
			return res;
		}, function () {
			return $.Deferred().reject(t.error);
		});
	}

	$('#bhec-save-draft').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		var $state = $('#bhec-save-state').text(t.saving);
		clearError();
		var data = getCampaignData();
		save(data).done(function () {
			$state.text(t.savedAt.replace('%s', new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })));
		}).fail(function (message) {
			$state.text('');
			showError(message);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ----- Send ------------------------------------------------------------

	var $dialog = $('#bhec-send-dialog');

	function validate() {
		clearError();
		if (!$('#bhec-subject').val().trim()) {
			showError(t.needSubject, $('#bhec-subject'));
			return false;
		}
		if (!$('<div>').html(getBody()).text().trim() && getBody().indexOf('<img') === -1) {
			showError(t.needBody);
			return false;
		}
		if (isScheduled()) {
			var when = $('#bhec-scheduled-at').val();
			if (!when) {
				showError(t.needTime, $('#bhec-scheduled-at'));
				return false;
			}
		}
		if ($('#bhec-coupon-section').length && bodyHasCoupon() && !parseFloat($('#bhec-coupon-amount').val()) && !$('#bhec-coupon-free-shipping').is(':checked')) {
			openSection($('#bhec-coupon-section'));
			showError(t.needCoupon, $('#bhec-coupon-amount'));
			return false;
		}
		return true;
	}

	$('#bhec-send').on('click', function () {
		if (!validate()) return;
		var $btn = $(this).prop('disabled', true);
		var $spinner = $btn.siblings('.spinner').addClass('is-active');
		var data = getCampaignData();

		post('bhec_campaign_send_check', {
			campaign_id: campaignId,
			subject: data.subject,
			filters: data.filters,
			exclude_segment_id: data.exclude_segment_id,
		}).done(function (res) {
			openSendDialog(res.success ? res.data : null, data);
		}).fail(function () {
			openSendDialog(null, data);
		}).always(function () {
			$btn.prop('disabled', false);
			$spinner.removeClass('is-active');
		});
	});

	function openSendDialog(check, data) {
		var later = !!data.scheduled_at;
		var body = '<p class="bhec-dialog-subject"><span class="bhec-muted">' + esc(t.subjectLabel) + '</span> ' + esc(data.subject) + '</p>';
		var total = check ? check.total : lastCount;
		var $confirm = $('#bhec-send-confirm');

		if (total === 0) {
			$('#bhec-send-dialog-title').text(t.nobodyTitle);
			body += '<p>' + esc(t.nobody) + '</p>';
			$confirm.prop('hidden', true);
		} else if (later) {
			// Who gets held is decided when it sends, so only the reach is shown.
			$confirm.prop('hidden', false).text(t.scheduleButton);
			$('#bhec-send-dialog-title').text(t.scheduleTitle);
			var about = total == null ? t.theAudience : plural(total, t.onePerson, t.people);
			body += '<p>' + t.scheduleLine.replace('%1$s', '<strong>' + esc(about) + '</strong>').replace('%2$s', '<strong>' + esc(scheduleLabel(data.scheduled_at)) + '</strong>') + '</p>';
			body += '<p class="bhec-muted">' + esc(t.scheduleNote) + '</p>';
		} else {
			var held = check ? check.recently_emailed : 0;
			var sendable = total == null ? null : total - held;
			$confirm.prop('hidden', false);
			$('#bhec-send-dialog-title').text(t.sendTitle);
			if (sendable === 0) {
				body += '<p>' + esc(t.allRecent.replace('%1$s', fmt(total)).replace('%2$s', check.days)) + '</p>';
				$confirm.text(t.sendHeldButton);
			} else {
				var who = sendable == null ? t.theAudience : plural(sendable, t.onePerson, t.people);
				body += '<p>' + t.sendLine.replace('%s', '<strong>' + esc(who) + '</strong>') + '</p>';
				if (held > 0) {
					body += '<div class="bhec-dialog-note"><p><strong>' + esc((held === 1 ? t.recentOne : t.recentMany).replace('%1$s', fmt(held)).replace('%2$s', check.days)) + '</strong> '
						+ esc(t.recentHeld) + '</p></div>';
				}
				$confirm.text(sendable == null ? t.sendButtonPlain : plural(sendable, t.sendButtonOne, t.sendButton));
			}
		}
		if (total !== 0 && check && check.similar) {
			body += '<div class="bhec-dialog-note"><p>' + esc(t.similar.replace('%1$s', check.similar.title).replace('%2$s', check.similar.when)) + '</p></div>';
		}
		$('#bhec-send-dialog-body').html(body);
		$dialog.data('payload', data);
		$dialog[0].showModal();
	}

	$dialog.on('close', function () {
		if (this.returnValue !== 'confirm') return;
		var data = $dialog.data('payload');
		var $btn = $('#bhec-send').prop('disabled', true);
		var $spinner = $btn.siblings('.spinner').addClass('is-active');
		var fail = function (message) {
			$btn.prop('disabled', false);
			$spinner.removeClass('is-active');
			showError(message || t.error);
		};

		save(data).done(function () {
			if (data.scheduled_at) {
				location.href = bhecCampaigns.listUrl + '&done=scheduled';
				return;
			}
			post('bhec_queue_campaign', { campaign_id: campaignId }).done(function (res) {
				if (res.success) {
					location.href = bhecCampaigns.listUrl + '&action=view&id=' + campaignId + '&done=sending';
				} else {
					fail(res.data);
				}
			}).fail(function () { fail(); });
		}).fail(fail);
	});

	// ----- Preview and test ------------------------------------------------

	$('#bhec-preview-email').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		post('bhec_preview_render', {
			subject: $('#bhec-subject').val(),
			body: getBody(),
			layout_template: $('#bhec-layout-template').val() || 'default',
		}).done(function (res) {
			if (res.success) {
				kit.showPreview(res.data.subject, res.data.html);
			} else {
				showError(res.data || t.error);
			}
		}).fail(function () {
			showError(t.error);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$('#bhec-test-toggle').on('click', function () {
		if (toggleRegion($(this))) $('#bhec-test-email').trigger('focus');
	});

	$('#bhec-send-test').on('click', function () {
		var email = $('#bhec-test-email').val().trim();
		var $status = $('#bhec-test-status');
		if (!email) {
			$('#bhec-test-email').trigger('focus');
			return;
		}
		var $btn = $(this).prop('disabled', true);
		$status.text(t.sendingTest);
		post('bhec_send_test_email', {
			test_email: email,
			subject: $('#bhec-subject').val(),
			body: getBody(),
			reply_to_name: $('#bhec-reply-to-name').val(),
			reply_to_email: $('#bhec-reply-to-email').val(),
			attachments: JSON.stringify(kit.attachmentIds()),
		}).done(function (res) {
			$status.text(res.success ? t.testSent.replace('%s', email) : (res.data || t.error));
		}).fail(function () {
			$status.text(t.error);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ----- Unsaved changes -------------------------------------------------

	$('.bhec-editor').on('input change', '#post-body-content input, #post-body-content select, #post-body-content textarea, #bhec-scheduled-at', function () {
		dirty = true;
	});
	$(document).on('bhec:body-changed bhec:coupon-added', updateCouponSummary);
	$(document).on('bhec:attachments-changed', updateMoreSummary);
	updateCouponSummary();

	$(window).on('beforeunload', function () {
		if (dirty) return t.unsaved;
	});
	$dialog.on('close', function () {
		if (this.returnValue === 'confirm') dirty = false;
	});

	if (!campaignId) $('#bhec-title').trigger('focus');
})(jQuery);
