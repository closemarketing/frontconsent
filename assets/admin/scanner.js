/**
 * FrontConsent Scanner: starts a scan and follows it until it finishes.
 * Used by the Scanner settings tab and the dashboard widget.
 *
 * @package FrontConsent
 */

(function () {
	'use strict';

	var config = window.frcnScanner;

	if (!config) {
		return;
	}

	function post(action) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', config.nonce);

		return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (response) {
			return response.json().then(function (json) {
				if (!json || !json.success) {
					throw new Error(json && json.data && json.data.message ? json.data.message : config.i18n.error);
				}
				return json.data;
			}, function () {
				throw new Error(config.i18n.error);
			});
		});
	}

	function initScanner(root) {
		var button = root.querySelector('[data-frcn-scanner-start]');
		var result = root.querySelector('[data-frcn-scanner-result]');
		var message = root.querySelector('[data-frcn-scanner-message]');
		var compact = root.classList.contains('frcn-scanner--widget');
		var polls = 0;

		function setMessage(text, isError) {
			if (!message) {
				return;
			}
			message.textContent = text || '';
			message.classList.toggle('frcn-scanner__message--error', !!isError);
		}

		function render(data) {
			if (result) {
				result.innerHTML = compact ? data.compactHtml : data.html;
			}
			if (button) {
				button.disabled = !!data.pending;
			}
			if (!data.pending) {
				setMessage('');
				return;
			}
			if (polls >= config.maxPolls) {
				setMessage(config.i18n.timeout, true);
				return;
			}
			window.setTimeout(poll, Math.max(3, data.pollAfterSeconds || 5) * 1000);
		}

		function fail(error) {
			if (button) {
				button.disabled = false;
			}
			setMessage(error.message, true);
		}

		function poll() {
			polls++;
			post('frcn_scanner_status').then(render, fail);
		}

		if (button) {
			button.addEventListener('click', function () {
				button.disabled = true;
				polls = 0;
				setMessage(config.i18n.running);
				post('frcn_scanner_start').then(render, fail);
			});
		}

		if (result && result.hasAttribute('data-frcn-scanner-pending')) {
			poll();
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('[data-frcn-scanner]'), initScanner);
	});
})();
