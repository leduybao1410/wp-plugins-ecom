/**
 * "Send test message" button on WooCommerce → Settings → Discord Notify.
 * Vanilla JS (no jQuery needed for this one call) — jQuery is only listed
 * as a script dependency for load-order safety alongside WooCommerce's own
 * settings-screen scripts.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'epic-discord-test-webhook' );
		var result = document.getElementById( 'epic-discord-test-webhook-result' );

		if ( ! button || typeof EpicDiscordNotify === 'undefined' ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			result.style.color = '';
			result.textContent = EpicDiscordNotify.i18n.sending;

			var body = new URLSearchParams();
			body.set( 'action', 'epic_discord_test_webhook' );
			body.set( 'nonce', EpicDiscordNotify.nonce );

			fetch( EpicDiscordNotify.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					var message = json && json.data && json.data.message ? json.data.message : '';
					result.style.color = json && json.success ? '#1e8e3e' : '#c0392b';
					result.textContent = message || ( json && json.success ? 'OK' : 'Error' );
				} )
				.catch( function () {
					result.style.color = '#c0392b';
					result.textContent = 'Request failed.';
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	} );
} )();
