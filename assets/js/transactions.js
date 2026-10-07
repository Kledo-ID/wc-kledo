/**
 * Kledo > Kledo Status tab (screen ID `transactions`): row actions without a page reload.
 *
 * "Check status" and "Resend" are submit buttons of the screen's form, so they keep working when
 * this file does not load. Here they are intercepted, posted to admin-ajax, and the rows of the
 * order, the summary cards and the tab counts are replaced with what the server sends back.
 *
 * @since 1.8.0
 */
( function () {
	'use strict';

	var settings = window.wc_kledo_transactions;
	var form = document.getElementById( 'wc-kledo-transactions-form' );

	if ( ! settings || ! form ) {
		return;
	}

	var selectAll = document.getElementById( 'wc-kledo-tx-select-all' );

	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			var boxes = form.querySelectorAll( 'input[name="wc_kledo_failed_keys[]"]' );

			for ( var index = 0; index < boxes.length; index++ ) {
				boxes[ index ].checked = selectAll.checked;
			}
		} );
	}

	form.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.wc-kledo-tx-action' );

		if ( ! button || ! form.contains( button ) ) {
			return;
		}

		event.preventDefault();
		runRowAction( button );
	} );

	/**
	 * Post one row action and apply the answer.
	 *
	 * @param {HTMLButtonElement} button The clicked action.
	 */
	function runRowAction( button ) {
		var row = button.closest( 'tr' );
		var action = button.getAttribute( 'data-action' );
		var label = button.textContent;

		if ( ! row || button.disabled ) {
			return;
		}

		setRowBusy( row, true );
		button.textContent = settings.i18n[ action ] || label;
		button.insertAdjacentHTML( 'afterend', '<span class="spinner is-active wc-kledo-tx-spinner"></span>' );
		showRowMessage( row, '', '' );

		var body = new URLSearchParams( window.location.search );
		body.set( 'action', 'wc_kledo_tx_row_action' );
		body.set( 'security', settings.security );
		body.set( 'key', button.value );
		body.set( 'row_action', action );

		window.fetch( settings.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, data: {} };
				} );
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var message = payload && payload.data && payload.data.message ? payload.data.message : settings.i18n.failed;
					restoreButton( row, button, label );
					showRowMessage( row, message, 'error' );

					return;
				}

				applyResult( button.value, payload.data );
			} )
			.catch( function () {
				restoreButton( row, button, label );
				showRowMessage( row, settings.i18n.failed, 'error' );
			} );
	}

	/**
	 * Replace the order's rows and the summary, then flash and annotate the clicked row.
	 *
	 * @param {string} clickedKey The `type:order_id` that was acted on.
	 * @param {Object} data       The server's answer.
	 */
	function applyResult( clickedKey, data ) {
		var rows = data.rows || {};

		Object.keys( rows ).forEach( function ( key ) {
			var current = form.querySelector( 'tr[data-key="' + cssEscape( key ) + '"]' );

			// Only rows the page already shows are replaced; the other type of the order may
			// belong to a tab that is not open.
			if ( ! current ) {
				return;
			}

			var wrapper = document.createElement( 'tbody' );
			wrapper.innerHTML = rows[ key ];

			var replacement = wrapper.firstElementChild;

			if ( ! replacement ) {
				return;
			}

			current.replaceWith( replacement );
			replacement.classList.add( 'wc-kledo-tx-row--updated' );

			window.setTimeout( function () {
				replacement.classList.remove( 'wc-kledo-tx-row--updated' );
			}, 2000 );

			if ( key === clickedKey ) {
				showRowMessage( replacement, data.message || settings.i18n.updated, data.level || 'info' );
			}
		} );

		var summary = document.getElementById( 'wc-kledo-tx-summary' );

		if ( summary && 'string' === typeof data.summary ) {
			summary.innerHTML = data.summary;
		}
	}

	/**
	 * Disable or enable every action of a row.
	 *
	 * @param {HTMLElement} row
	 * @param {boolean}     busy
	 */
	function setRowBusy( row, busy ) {
		var buttons = row.querySelectorAll( '.wc-kledo-tx-action' );

		for ( var index = 0; index < buttons.length; index++ ) {
			buttons[ index ].disabled = busy;
		}

		row.classList.toggle( 'wc-kledo-tx-row--busy', busy );
	}

	/**
	 * Undo the busy state after a failure, keeping the row as it was.
	 *
	 * @param {HTMLElement}       row
	 * @param {HTMLButtonElement} button
	 * @param {string}            label
	 */
	function restoreButton( row, button, label ) {
		var spinner = row.querySelector( '.wc-kledo-tx-spinner' );

		if ( spinner ) {
			spinner.remove();
		}

		button.textContent = label;
		setRowBusy( row, false );
	}

	/**
	 * Show a short message under the order's actions.
	 *
	 * @param {HTMLElement} row
	 * @param {string}      message
	 * @param {string}      level   success, info, warning or error.
	 */
	function showRowMessage( row, message, level ) {
		var box = row.querySelector( '.wc-kledo-tx-row-message' );

		if ( ! box ) {
			return;
		}

		box.textContent = message;
		box.className = 'wc-kledo-tx-row-message' + ( level ? ' wc-kledo-tx-row-message--' + level : '' );
	}

	/**
	 * Escape a value for use inside an attribute selector.
	 *
	 * @param {string} value
	 *
	 * @return {string}
	 */
	function cssEscape( value ) {
		return window.CSS && window.CSS.escape ? window.CSS.escape( value ) : value.replace( /"/g, '\\"' );
	}
}() );
