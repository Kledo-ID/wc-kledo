/**
 * Kledo > Sync: confirmations and the progress poll.
 *
 * Buttons carrying `data-confirm` ask first. While a job is running or paused, the progress block
 * is refreshed every few seconds; when the job ends the page reloads so the order list shows the
 * final state.
 *
 * @since 1.8.0
 */
( function () {
	'use strict';

	var settings = window.wc_kledo_sync;

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-confirm]' );

		if ( button && ! window.confirm( button.getAttribute( 'data-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	var progress = document.getElementById( 'wc-kledo-sync-progress' );

	if ( ! settings || ! progress ) {
		return;
	}

	/**
	 * Status of the job shown in the progress block, or an empty string.
	 *
	 * @return {string}
	 */
	function currentStatus() {
		var job = progress.querySelector( '.wc-kledo-sync-job' );

		return job ? job.getAttribute( 'data-status' ) : '';
	}

	var initialStatus = currentStatus();

	if ( 'running' !== initialStatus && 'paused' !== initialStatus ) {
		return;
	}

	function poll() {
		var body = new URLSearchParams();
		body.set( 'action', 'wc_kledo_sync_poll' );
		body.set( 'security', settings.security );

		window.fetch( settings.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					return;
				}

				if ( payload.data.status !== initialStatus && 'paused' !== payload.data.status ) {
					window.location.reload();

					return;
				}

				progress.innerHTML = payload.data.html;
			} )
			.catch( function () {} )
			.then( function () {
				window.setTimeout( poll, settings.interval );
			} );
	}

	window.setTimeout( poll, settings.interval );
}() );
