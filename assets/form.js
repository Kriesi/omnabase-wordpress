/**
 * OmnaBase for WordPress: the shortcode form [omnabase_form].
 *
 * On load each form fetches its single-use time token from the site (the page itself never holds one, so a cached page is safe) and only
 * then enables Send. Sending posts the fields as JSON to the site's own form route and shows the sentence it answers; a wrong field's
 * sentence appears beside the field. The site, not the browser, talks to OmnaBase: nothing here holds a key.
 */
(function () {
	'use strict';

	var FIELDS = ['project', 'token', 'name', 'email', 'subject', 'message', 'product', 'licence', 'omnabase_hp'];
	var FALLBACK = 'This form is not available right now.';

	function headers(form, json) {
		var h = { Accept: 'application/json' };
		if (json) {
			h['Content-Type'] = 'application/json';
		}
		if (form.dataset.nonce) {
			h['X-WP-Nonce'] = form.dataset.nonce; // a signed-in user's token against forged requests
		}
		return h;
	}

	function say(form, text, isError) {
		var status = form.querySelector('.ob-wp-form__status');
		status.textContent = text || '';
		status.classList.toggle('ob-wp-form__status--error', !!isError);
	}

	function clearErrors(form) {
		form.querySelectorAll('.ob-wp-form__error').forEach(function (el) {
			el.textContent = '';
			el.hidden = true;
		});
	}

	function showErrors(form, fields) {
		Object.keys(fields || {}).forEach(function (name) {
			var el = form.querySelector('.ob-wp-form__error[data-for="' + name + '"]');
			if (el) {
				el.textContent = fields[name];
				el.hidden = false;
			}
		});
	}

	function readAnswer(response) {
		return response.json().then(
			function (data) { return { ok: response.ok, status: response.status, data: data || {} }; },
			function () { return { ok: response.ok, status: response.status, data: {} }; }
		);
	}

	function fetchToken(form) {
		var url = new URL(form.dataset.tokenUrl, window.location.href);
		url.searchParams.set('project', form.dataset.project || '');
		return fetch(url.toString(), { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: headers(form, false) })
			.then(readAnswer)
			.then(function (answer) {
				if (answer.ok && answer.data.token) {
					form.elements.token.value = answer.data.token;
					form.querySelector('.ob-wp-form__submit').disabled = false;
					return;
				}
				say(form, answer.data.message || FALLBACK, true);
			})
			.catch(function () { say(form, FALLBACK, true); });
	}

	function collect(form) {
		var body = {};
		FIELDS.forEach(function (name) {
			var el = form.elements[name];
			if (el) {
				body[name] = el.value;
			}
		});
		return body;
	}

	function send(form, button) {
		button.disabled = true;
		clearErrors(form);
		say(form, '', false);
		return fetch(form.dataset.submitUrl, { method: 'POST', credentials: 'same-origin', headers: headers(form, true), body: JSON.stringify(collect(form)) })
			.then(readAnswer)
			.then(function (answer) {
				say(form, answer.data.message || FALLBACK, !answer.ok);
				showErrors(form, answer.data.fields);
				// After a message went (or was refused for good), Send stays off: another message needs a fresh page and token.
				button.disabled = answer.ok || answer.status === 409 || answer.status === 429 || answer.status === 403;
			})
			.catch(function () {
				say(form, FALLBACK, true);
				button.disabled = false;
			});
	}

	function start(form) {
		var button = form.querySelector('.ob-wp-form__submit');
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!button.disabled && form.elements.token.value) {
				send(form, button);
			}
		});
		fetchToken(form);
	}

	function startAll() {
		document.querySelectorAll('form.ob-wp-form').forEach(start);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', startAll);
	} else {
		startAll();
	}
})();
