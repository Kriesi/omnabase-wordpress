/**
 * OmnaBase for WordPress: the message centre [omnabase_messages].
 *
 * The message centre's forms work without JavaScript (they post back to the page, which is never cached). This only keeps a double click
 * from posting a form twice: the first submit turns its button off. The New ticket form's token, printed in the page, already makes a resent
 * form one ticket on the server's side; this spares the second round trip. The site, not the browser, talks to the support system.
 */
(function () {
	'use strict';

	function start(form) {
		form.addEventListener('submit', function () {
			var button = form.querySelector('button[type="submit"]');
			if (button) {
				// after this tick, so the browser still sends the form the click started
				window.setTimeout(function () { button.disabled = true; }, 0);
			}
		});
	}

	function startAll() {
		document.querySelectorAll('.ob-wp-messages form').forEach(start);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', startAll);
	} else {
		startAll();
	}
})();
