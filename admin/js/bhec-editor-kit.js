/**
 * Shared pieces of the email editors (campaigns and automatic emails):
 * inserting at the cursor, the Personalize menu, Add Image, Add Product,
 * Add Coupon, attachments, collapsible sections, the subject length hint,
 * the preview dialog and error notices.
 *
 * Usage: var kit = bhecEditor.init({ bodyId: 'bhec_body', onChange: fn });
 * Markup follows admin/views/page-campaign-edit.php (same ids and classes).
 */
(function ($) {
	'use strict';

	var t = window.bhecEditorKit || {};

	function post(action, data) {
		return $.post(bhecAdmin.ajaxUrl, $.extend({ action: action, nonce: bhecAdmin.nonce }, data));
	}

	function esc(str) {
		return $('<span>').text(str == null ? '' : String(str)).html();
	}

	function fmt(n) {
		return Number(n).toLocaleString();
	}

	function plural(n, one, many) {
		return (n === 1 ? one : many).replace('%s', fmt(n));
	}

	function init(options) {
		var o = $.extend({ bodyId: 'bhec_body', subject: '#bhec-subject', onChange: function () {} }, options);
		var changed = function () { o.onChange(); };

		function editor() {
			return typeof tinymce !== 'undefined' ? tinymce.get(o.bodyId) : null;
		}

		function getBody() {
			var ed = editor();
			if (ed && !ed.isHidden()) return ed.getContent();
			return $('#' + o.bodyId).val();
		}

		// ----- Insert at the cursor -------------------------------------------

		function insertInto(target, text) {
			if (target === 'subject') {
				var el = $(o.subject)[0];
				var start = el.selectionStart != null ? el.selectionStart : el.value.length;
				var end = el.selectionEnd != null ? el.selectionEnd : start;
				el.value = el.value.slice(0, start) + text + el.value.slice(end);
				el.focus();
				el.setSelectionRange(start + text.length, start + text.length);
				$(el).trigger('input');
				return;
			}
			var ed = editor();
			if (ed && !ed.isHidden()) {
				ed.focus();
				ed.execCommand('mceInsertContent', false, text);
			} else {
				var ta = document.getElementById(o.bodyId);
				var pos = ta.selectionStart || 0;
				ta.value = ta.value.slice(0, pos) + text + ta.value.slice(ta.selectionEnd || pos);
				ta.focus();
			}
			changed();
		}

		// ----- Popovers (Personalize menu, Add Product) -----------------------

		var $openPopover = null;
		var $openTrigger = null;

		// Popovers are positioned against the page, so they live in <body>.
		$('.bhec-popover').appendTo(document.body);

		function openPopover($pop, $trigger) {
			closePopover();
			var r = $trigger[0].getBoundingClientRect();
			$pop.prop('hidden', false);
			var width = $pop.outerWidth();
			var left = r.left + width > document.documentElement.clientWidth - 8 ? r.right - width : r.left;
			$pop.css({
				top: r.bottom + window.scrollY + 4,
				left: Math.max(8, left) + window.scrollX,
			});
			$trigger.attr('aria-expanded', 'true');
			$openPopover = $pop;
			$openTrigger = $trigger;
		}

		function closePopover(restoreFocus) {
			if (!$openPopover) return;
			$openPopover.prop('hidden', true);
			$openTrigger.attr('aria-expanded', 'false');
			if (restoreFocus) $openTrigger.trigger('focus');
			$openPopover = $openTrigger = null;
		}

		function isOpenFor(el) {
			return $openTrigger && $openTrigger[0] === el;
		}

		$(document).on('mousedown', function (e) {
			if ($openPopover && !$(e.target).closest($openPopover).length && !$(e.target).closest($openTrigger).length && !$(e.target).closest('.select2-container').length) {
				closePopover();
			}
		});
		$(document).on('keydown', function (e) {
			if (e.key === 'Escape' && $openPopover) closePopover(true);
		});

		// Personalize: one menu shared by the subject and the message.
		var tagTarget = 'body';
		$(document).on('click', '.bhec-personalize', function () {
			if (isOpenFor(this)) {
				closePopover();
				return;
			}
			tagTarget = $(this).data('target');
			var $menu = $('#bhec-tag-menu');
			$menu.find('[data-body-only]').prop('hidden', tagTarget === 'subject');
			openPopover($menu, $(this));
			$menu.find('.bhec-tag-item:visible').first().trigger('focus');
		});

		$('#bhec-tag-menu').on('click', '.bhec-tag-item', function () {
			closePopover();
			insertInto(tagTarget, $(this).data('tag'));
		}).on('keydown', '.bhec-tag-item', function (e) {
			var $items = $('#bhec-tag-menu .bhec-tag-item:visible');
			var i = $items.index(this);
			if (e.key === 'ArrowDown') { e.preventDefault(); $items.eq((i + 1) % $items.length).trigger('focus'); }
			if (e.key === 'ArrowUp') { e.preventDefault(); $items.eq((i - 1 + $items.length) % $items.length).trigger('focus'); }
		});

		$(document).on('click', '#bhec-add-product', function () {
			if (isOpenFor(this)) {
				closePopover();
				return;
			}
			openPopover($('#bhec-product-popover'), $(this));
			$('#bhec-product-select').selectWoo('open');
		});

		$('#bhec-product-select').on('change', function () {
			var id = $(this).val();
			if (!id) return;
			closePopover();
			insertInto('body', '{{ product:' + id + ' }}');
			$(this).val(null).trigger('change.select2');
		});

		// An image from the media library, sized for an email (600px wide at most).
		var imageFrame = null;
		$(document).on('click', '#bhec-add-image', function () {
			closePopover();
			if (!imageFrame) {
				imageFrame = wp.media({
					title: t.addImage,
					library: { type: 'image' },
					button: { text: t.insertImage },
					multiple: false,
				});
				imageFrame.on('select', function () {
					var a = imageFrame.state().get('selection').first().toJSON();
					var src = (a.sizes && (a.sizes.large || a.sizes.full)) || a;
					var width = Math.min(600, src.width || 600);
					var height = src.width ? Math.round(src.height * width / src.width) : '';
					insertInto('body', '<img src="' + esc(src.url) + '" alt="' + esc(a.alt || '') + '" width="' + width + '"' + (height ? ' height="' + height + '"' : '') + '>');
				});
			}
			imageFrame.open();
		});

		// Add Coupon places the code and opens the Coupon section to set it up.
		$(document).on('click', '#bhec-add-coupon', function () {
			insertInto('body', '{{ coupon }}');
			openSection($('#bhec-coupon-section'));
			$(document).trigger('bhec:coupon-added');
			if (!$('#bhec-coupon-amount').val()) $('#bhec-coupon-amount').trigger('focus');
		});

		// ----- Subject length --------------------------------------------------

		function updateSubjectMeter() {
			// Count a personal detail as a typical first name's length.
			var n = ($(o.subject).val() || '').replace(/\{\{[^}]*\}\}/g, 'Xxxxxx').length;
			var $m = $('.bhec-subject-meter');
			if (!n) {
				$m.text('');
			} else if (n > 60) {
				$m.text(t.subjectLong.replace('%s', n));
			} else {
				$m.text(t.subjectLength.replace('%s', n));
			}
		}
		$(o.subject).on('input', updateSubjectMeter);
		updateSubjectMeter();

		// ----- Collapsible sections -------------------------------------------

		function openSection($section) {
			$section.find('.bhec-section-toggle').attr('aria-expanded', 'true');
			$section.find('.bhec-section-body').prop('hidden', false);
			$section.addClass('is-open');
		}

		$('.bhec-section-toggle').on('click', function () {
			var $section = $(this).closest('.bhec-section');
			if ($(this).attr('aria-expanded') === 'true') {
				$(this).attr('aria-expanded', 'false');
				$section.find('.bhec-section-body').prop('hidden', true);
				$section.removeClass('is-open');
			} else {
				openSection($section);
			}
		});

		// A button with aria-controls that shows and hides a region.
		function toggleRegion($btn) {
			var $region = $('#' + $btn.attr('aria-controls'));
			var open = $btn.attr('aria-expanded') !== 'true';
			$btn.attr('aria-expanded', open ? 'true' : 'false');
			$region.prop('hidden', !open);
			return open;
		}

		// ----- Attachments -----------------------------------------------------

		$('#bhec-add-attachment').on('click', function () {
			var frame = wp.media({ title: t.chooseAttachment, multiple: true });
			frame.on('select', function () {
				frame.state().get('selection').toJSON().forEach(function (att) {
					if ($('.bhec-attachment-item[data-id="' + att.id + '"]').length) return;
					$('#bhec-attachment-list').append(
						'<li class="bhec-attachment-item" data-id="' + parseInt(att.id, 10) + '">'
						+ '<span class="dashicons dashicons-paperclip" aria-hidden="true"></span>' + esc(att.filename)
						+ ' <button type="button" class="button-link bhec-remove-attachment">' + esc(t.remove) + '</button></li>'
					);
				});
				changed();
				$(document).trigger('bhec:attachments-changed');
			});
			frame.open();
		});

		$(document).on('click', '.bhec-remove-attachment', function () {
			$(this).closest('.bhec-attachment-item').remove();
			changed();
			$(document).trigger('bhec:attachments-changed');
		});

		function attachmentIds() {
			return $('.bhec-attachment-item').map(function () { return parseInt($(this).data('id'), 10); }).get();
		}

		// ----- Dialogs and preview ---------------------------------------------

		// Clicking the backdrop closes a dialog.
		$('dialog.bhec-dialog').on('click', function (e) {
			if (e.target === this) this.close('cancel');
		});

		var $preview = $('#bhec-preview-dialog');
		$preview.on('click', '[data-width]', function () {
			$preview.find('[data-width]').attr('aria-pressed', 'false');
			$(this).attr('aria-pressed', 'true');
			$preview.toggleClass('is-mobile', $(this).data('width') === 'mobile');
		});

		// Shows rendered HTML in the sandboxed preview dialog.
		function showPreview(subject, html) {
			$('#bhec-preview-subject').text(subject || t.noSubject);
			document.getElementById('bhec-preview-frame').srcdoc = html;
			$preview[0].showModal();
		}

		// ----- Errors ------------------------------------------------------------

		function showError(message, $focus) {
			var $box = $('.bhec-form-error');
			$box.find('p').text(message);
			$box.prop('hidden', false);
			$('html, body').animate({ scrollTop: $box.offset().top - 60 }, 150);
			if ($focus) $focus.trigger('focus');
		}

		function clearError() {
			$('.bhec-form-error').prop('hidden', true);
		}

		// ----- Unsaved changes ---------------------------------------------------

		$(document).on('tinymce-editor-init', function (e, ed) {
			if (ed.id !== o.bodyId) return;
			ed.on('input change undo redo', function () {
				changed();
				$(document).trigger('bhec:body-changed');
			});
			$(document).trigger('bhec:body-changed');
		});
		$('#' + o.bodyId).on('input', function () {
			changed();
			$(document).trigger('bhec:body-changed');
		});

		return {
			getBody: getBody,
			insertInto: insertInto,
			openSection: openSection,
			toggleRegion: toggleRegion,
			attachmentIds: attachmentIds,
			showPreview: showPreview,
			showError: showError,
			clearError: clearError,
			closePopover: closePopover,
		};
	}

	window.bhecEditor = {
		init: init,
		post: post,
		esc: esc,
		fmt: fmt,
		plural: plural,
	};
})(jQuery);
