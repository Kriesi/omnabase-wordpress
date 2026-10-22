/**
 * OmnaBase for WordPress: the shortcode form [omnabase_form].
 *
 * On load each form fetches its single-use time token from the site (the page itself never holds one, so a cached page is safe) and only
 * then enables Send. Sending posts the fields as JSON to the site's own form route. A refusal keeps the form and the visitor's words: the
 * sentence shows above Send and a wrong field's sentence under the field (the field marked aria-invalid). A message taken shows the success
 * card in the form's place, its words printed (and translated) by the page; "Send another" brings the form back with an empty message and
 * a fresh token. The site, not the browser, talks to OmnaBase: nothing here holds a key.
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

	function markField(form, name, wrong) {
		var field = form.elements[name];
		if (!field || !field.setAttribute) {
			return;
		}
		if (wrong) {
			field.setAttribute('aria-invalid', 'true');
		} else {
			field.removeAttribute('aria-invalid');
		}
		var box = field.closest('.ob-input');
		if (box) {
			box.classList.toggle('ob-input--invalid', wrong);
		}
	}

	function clearErrors(form) {
		form.querySelectorAll('.ob-wp-form__error').forEach(function (el) {
			el.querySelector('.ob-wp-form__error-text').textContent = '';
			el.hidden = true;
			markField(form, el.dataset.for, false);
		});
	}

	function showErrors(form, fields) {
		Object.keys(fields || {}).forEach(function (name) {
			var el = form.querySelector('.ob-wp-form__error[data-for="' + name + '"]');
			if (el) {
				el.querySelector('.ob-wp-form__error-text').textContent = fields[name];
				el.hidden = false;
				markField(form, name, true);
			}
		});
	}

	function readAnswer(response) {
		return response.json().then(
			function (data) { return { ok: response.ok, status: response.status, data: data || {} }; },
			function () { return { ok: response.ok, status: response.status, data: {} }; }
		);
	}

	function submitButton(form) {
		return form.querySelector('.ob-wp-form__submit');
	}

	function fetchToken(form) {
		var url = new URL(form.dataset.tokenUrl, window.location.href);
		url.searchParams.set('project', form.dataset.project || '');
		return fetch(url.toString(), { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: headers(form, false) })
			.then(readAnswer)
			.then(function (answer) {
				if (answer.ok && answer.data.token) {
					form.elements.token.value = answer.data.token;
					submitButton(form).disabled = false;
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

	/** The card's first line: the reference when OmnaBase filed it, else the words for a message on its way (202) or taken (200). */
	function cardTitle(card, answer) {
		if (answer.data.reference) {
			return card.dataset.sent.replace('%d', String(answer.data.reference));
		}
		return answer.status === 202 ? card.dataset.queued : card.dataset.taken;
	}

	function showCard(form, card, answer) {
		var title = card.querySelector('.ob-wp-form__done-title');
		title.textContent = cardTitle(card, answer);
		form.hidden = true;
		card.hidden = false;
		title.focus(); // the card replaced the form: a screen reader starts reading here
	}

	function sendAnother(form, card) {
		card.hidden = true;
		clearErrors(form);
		say(form, '', false);
		form.elements.message.value = '';
		form.elements.token.value = '';
		submitButton(form).disabled = true;
		form.hidden = false;
		form.elements.message.focus();
		return fetchToken(form); // a token is good for one message: the next one needs its own
	}

	function send(form, card, button) {
		button.disabled = true;
		clearErrors(form);
		say(form, '', false);
		return fetch(form.dataset.submitUrl, { method: 'POST', credentials: 'same-origin', headers: headers(form, true), body: JSON.stringify(collect(form)) })
			.then(readAnswer)
			.then(function (answer) {
				if (answer.ok && card) {
					showCard(form, card, answer);
					return;
				}
				say(form, answer.data.message || FALLBACK, !answer.ok);
				showErrors(form, answer.data.fields);
				// Refused for good (sent already, too many, not available): Send stays off; another message needs a fresh page and token.
				button.disabled = answer.ok || answer.status === 409 || answer.status === 429 || answer.status === 403;
			})
			.catch(function () {
				say(form, FALLBACK, true);
				button.disabled = false;
			});
	}

	/** The page's own ids stay unique: a second copy of the same form on one page renames its ids (and the references to them). */
	function uniqueIds(form, wrap, copy) {
		if (!wrap || copy === 0) {
			return;
		}
		wrap.querySelectorAll('[id]').forEach(function (el) { el.id = el.id + '-' + copy; });
		wrap.querySelectorAll('[for], [aria-describedby]').forEach(function (el) {
			if (el.htmlFor) {
				el.htmlFor = el.htmlFor + '-' + copy;
			}
			if (el.getAttribute('aria-describedby')) {
				el.setAttribute('aria-describedby', el.getAttribute('aria-describedby') + '-' + copy);
			}
		});
	}

	function start(form, index, all) {
		var copy = Array.prototype.slice.call(all, 0, index).filter(function (other) {
			return other.dataset.project === form.dataset.project;
		}).length;
		uniqueIds(form, form.closest('.ob-wp'), copy);
		var button = submitButton(form);
		var wrap = form.closest('.ob-wp');
		var card = wrap ? wrap.querySelector('.ob-wp-form__done') : null;
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!button.disabled && form.elements.token.value) {
				send(form, card, button);
			}
		});
		if (card) {
			card.querySelector('.ob-wp-form__again').addEventListener('click', function () { sendAnother(form, card); });
		}
		fetchToken(form);
	}

	function startAll() {
		var forms = document.querySelectorAll('form.ob-wp-form');
		forms.forEach(function (form, index) { start(form, index, forms); });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', startAll);
	} else {
		startAll();
	}
})();
