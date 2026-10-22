/**
 * Settings › OmnaBase: the shortcode builder (M14 round two). Plain JavaScript, no library.
 *
 * "Build a shortcode" opens the browser's own dialog; the tag follows the choices as the owner clicks; Copy puts it on the clipboard (or
 * selects the field for the keyboard's copy); "Use this page" picks an existing message centre page through the tab's own save form.
 * Escape or Close shuts the dialog and the keyboard is back on the button. The tag's rules are the server's (ShortcodeTag): the subject
 * loses `"`, `[` and `]` as it is typed; `project` only when one is chosen; `subject` only for the form, `guest` only for the message
 * centre. Every word the owner reads comes from the page (translated there); text is only ever set as text, never as markup.
 */
(function () {
	'use strict';

	var UNSAFE = /["[\]]/;
	var UNSAFE_ALL = /["[\]]/g;

	function init() {
		var dialog = document.querySelector('dialog.ob-wp-builder');
		if (!dialog || typeof dialog.showModal !== 'function') {
			return;
		}
		var opener = document.querySelector('[aria-controls="' + dialog.id + '"]');
		var form = dialog.querySelector('form');
		if (!opener || !form) {
			return;
		}
		var parts = {
			form: form,
			tag: dialog.querySelector('.ob-wp-builder__tag'),
			subject: form.querySelector('[name="omnabase_builder[subject]"]'),
			project: form.querySelector('[name="omnabase_builder[project]"]'),
			create: dialog.querySelector('[data-ob-create]'),
			status: dialog.querySelector('.ob-wp-builder__copied')
		};
		parts.createLabel = parts.create ? parts.create.textContent.trim() : '';

		opener.parentElement.hidden = false;
		opener.addEventListener('click', function () {
			syncPicked(dialog);
			refresh(dialog, parts);
			dialog.showModal();
		});
		dialog.addEventListener('close', function () {
			opener.focus();
		});
		dialog.querySelector('[data-ob-close]').addEventListener('click', function () {
			dialog.close();
		});
		form.addEventListener('change', function () {
			refresh(dialog, parts);
		});
		parts.subject.addEventListener('input', function () {
			stripUnsafe(parts.subject);
			refresh(dialog, parts);
		});
		dialog.querySelector('[data-ob-copy]').addEventListener('click', function () {
			copy(parts);
		});
		dialog.querySelectorAll('[data-ob-use-page]').forEach(function (button) {
			button.addEventListener('click', function () {
				usePage(button);
			});
		});
		refresh(dialog, parts);
	}

	/** The value of the checked radio of a builder field ('' when none). */
	function chosen(form, name) {
		var checked = form.querySelector('input[name="omnabase_builder[' + name + ']"]:checked');
		return checked ? checked.value : '';
	}

	/** The tag for the current choices. */
	function build(parts) {
		var kind = chosen(parts.form, 'kind');
		var project = parts.project ? parts.project.value : '';
		var subject = parts.subject.value.replace(UNSAFE_ALL, '').replace(/\s+/g, ' ').trim();
		var atts = 'form' === kind ? [['project', project], ['subject', subject]] : [['guest', chosen(parts.form, 'guest')], ['project', project]];
		var text = '[' + ('form' === kind ? 'omnabase_form' : 'omnabase_messages');
		atts.forEach(function (att) {
			if ('' !== att[1]) {
				text += ' ' + att[0] + '="' + att[1] + '"';
			}
		});
		return text + ']';
	}

	/** Shows what belongs to the chosen kind, the offer of an existing page, the Create button's words, and the tag. */
	function refresh(dialog, parts) {
		var kind = chosen(parts.form, 'kind');
		var offer = null;
		dialog.querySelectorAll('[data-for]').forEach(function (el) {
			el.hidden = el.getAttribute('data-for') !== kind;
		});
		dialog.querySelectorAll('.ob-wp-builder__existing').forEach(function (el) {
			el.hidden = el.getAttribute('data-kind') !== kind;
			offer = el.hidden ? offer : el;
		});
		if (parts.create) {
			parts.create.textContent = offer ? parts.create.getAttribute('data-anyway') : parts.createLabel;
			parts.create.classList.toggle('button-primary', !offer);
		}
		parts.tag.value = build(parts);
		parts.status.textContent = '';
	}

	/** Removes quotes and square brackets from the subject as it is typed, keeping the caret where it was. */
	function stripUnsafe(field) {
		if (!UNSAFE.test(field.value)) {
			return;
		}
		var caret = field.selectionStart || 0;
		var before = field.value.slice(0, caret).replace(UNSAFE_ALL, '').length;
		field.value = field.value.replace(UNSAFE_ALL, '');
		field.setSelectionRange(before, before);
	}

	/** Copies the tag with the browser's clipboard, or selects the field so the keyboard can copy it. */
	function copy(parts) {
		var copied = function () {
			parts.status.textContent = parts.status.getAttribute('data-copied');
		};
		var fallback = function () {
			parts.tag.focus();
			parts.tag.select();
			var done = false;
			try {
				done = document.execCommand('copy');
			} catch (e) {
				done = false;
			}
			if (done) {
				copied();
			}
		};
		if (navigator.clipboard && 'function' === typeof navigator.clipboard.writeText) {
			navigator.clipboard.writeText(parts.tag.value).then(copied, fallback);
		} else {
			fallback();
		}
	}

	/** The message centre page picked on the tab right now (or "none yet"), shown in the dialog. */
	function syncPicked(dialog) {
		var picker = document.querySelector('select[name="omnabase_portal[page]"]');
		var picked = dialog.querySelector('.ob-wp-builder__picked');
		if (!picker || !picked) {
			return;
		}
		var option = picker.options[picker.selectedIndex];
		picked.textContent = option && '0' !== option.value ? option.textContent : picked.getAttribute('data-none');
	}

	/** Picks an existing page as the message centre page and saves the tab, as choosing it in the picker and clicking Save does. */
	function usePage(button) {
		var picker = document.querySelector('select[name="omnabase_portal[page]"]');
		if (!picker || !picker.form) {
			return;
		}
		var id = button.getAttribute('data-ob-use-page');
		var known = Array.prototype.some.call(picker.options, function (option) {
			return option.value === id;
		});
		if (!known) {
			var option = document.createElement('option');
			option.value = id;
			option.textContent = button.getAttribute('data-title');
			picker.appendChild(option);
		}
		picker.value = id;
		if ('function' === typeof picker.form.requestSubmit) {
			picker.form.requestSubmit();
		} else {
			picker.form.submit();
		}
	}

	/** The Appearance tab: picking a colour in the colour field picks "Your own colour" too. */
	function initColour() {
		var field = document.querySelector('input[type="color"][name="omnabase_appearance[colour]"]');
		var own = document.querySelector('input[type="radio"][name="omnabase_appearance[accent]"][value="own"]');
		if (field && own) {
			field.addEventListener('input', function () { own.checked = true; });
		}
	}

	function start() {
		init();
		initColour();
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
