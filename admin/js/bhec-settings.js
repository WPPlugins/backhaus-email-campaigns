/**
 * Settings: fields that only matter with another setting, the opt-in switch
 * warning, and license activation.
 */
(function ($) {
	'use strict';

	var t = (window.bhecPage && bhecPage.i18n) || {};

	// Show a row only when the setting it depends on is on.
	function dependsOn(rows, isOn) {
		var update = function () { $(rows).closest('tr').toggle(isOn()); };
		update();
		return update;
	}

	var utm = dependsOn('.bhec-utm-field', function () { return $('#bhec_utm_enabled').is(':checked'); });
	$('#bhec_utm_enabled').on('change', utm);

	var boxDefault = dependsOn('#bhec-checkbox-default-wrap', function () { return $('input[name="bhec_optin_mode"]:checked').val() === 'explicit'; });
	$('input[name="bhec_optin_mode"]').on('change', boxDefault);

	var captcha = dependsOn('.bhec-captcha-field', function () { return $('#bhec_captcha_provider').val() !== 'none'; });
	$('#bhec_captcha_provider').on('change', captcha);

	// Switching to explicit opt-in unsubscribes people; ask on save.
	$('.bhec-settings-form').on('submit', function (e) {
		var $mode = $(this).find('fieldset[data-current]');
		if (!$mode.length) return;
		var now = $mode.find('input[name="bhec_optin_mode"]:checked').val();
		if ($mode.data('current') !== 'explicit' && now === 'explicit' && !confirm(t.confirmExplicit)) {
			e.preventDefault();
		}
	});

	// License.
	var $msg = $('#bhec-license-status-msg');

	$('#bhec-activate-license').on('click', function () {
		var key = $('#bhec-license-key-input').val().trim();
		if (!key) {
			$('#bhec-license-key-input').trigger('focus');
			return;
		}
		var $btn = $(this).prop('disabled', true);
		$msg.text(t.activating);
		$.post(bhecAdmin.ajaxUrl, { action: 'bhec_activate_license', nonce: bhecAdmin.nonce, license_key: key }).done(function (res) {
			if (res.success) {
				location.reload();
				return;
			}
			$btn.prop('disabled', false);
			$msg.text((res.data && res.data.message) || res.data || t.failed);
		}).fail(function () {
			$btn.prop('disabled', false);
			$msg.text(t.failed);
		});
	});

	$('#bhec-deactivate-license').on('click', function () {
		if (!confirm(t.confirmDeactivate)) return;
		var $btn = $(this).prop('disabled', true);
		$msg.text(t.deactivating);
		$.post(bhecAdmin.ajaxUrl, { action: 'bhec_deactivate_license', nonce: bhecAdmin.nonce }).always(function () {
			location.reload();
		});
	});
})(jQuery);
