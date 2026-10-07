/**
 * Kledo > Diagnostics: run the steps one by one and show each result as it arrives.
 *
 * @since 1.8.0
 */
( function () {
	'use strict';

	var settings = window.wc_kledo_diagnostics;
	var form = document.getElementById( 'wc-kledo-diag-form' );
	var list = document.getElementById( 'wc-kledo-diag-steps' );
	var conclusion = document.getElementById( 'wc-kledo-diag-conclusion' );

	if ( ! settings || ! form || ! list || ! conclusion ) {
		return;
	}

	var icons = { ok: '✔', warn: '⚠', fail: '✖', info: 'ℹ' };
	var CHECK_STEPS = [ 'environment', 'connection', 'settings', 'order', 'payload', 'kledo', 'history', 'queues' ];
	var RECHECK_TRIES = 3;
	var TYPED_PREFIX = 'typed:';
	var RECHECK_DELAY = 20000;

	initOrderPicker();

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		if ( ! new FormData( form ).get( 'order' ) ) {
			conclusion.hidden = false;
			conclusion.className = 'wc-kledo-diag-conclusion is-fail';
			conclusion.textContent = settings.i18n.no_order;

			return;
		}

		run();
	} );

	/**
	 * Turn the order field into a searchable list that also accepts a typed order number.
	 *
	 * Opening it shows orders with a problem in Kledo first, then recent orders; typing searches
	 * by number, customer name or email. Anything typed that looks like an order number can be
	 * used as is, so an order the list does not show can still be diagnosed.
	 */
	function initOrderPicker() {
		var $ = window.jQuery;

		if ( ! $ || ! $.fn.selectWoo ) {
			return;
		}

		var $select = $( '#wc-kledo-diag-order' );

		$select.selectWoo( {
			width: '100%',
			placeholder: settings.i18n.pick_order,
			allowClear: true,
			tags: true,
			minimumInputLength: 0,
			createTag: function ( params ) {
				var term = String( params.term || '' ).trim().replace( /^#/, '' );

				if ( ! /^[A-Za-z0-9-]+$/.test( term ) ) {
					return null;
				}

				// Its own value, so it never shares an <option> with the same order listed for real:
				// selectWoo adds the typed option to the <select> before the results arrive.
				return {
					id: TYPED_PREFIX + term,
					text: settings.i18n.typed_order.replace( '%s', '#' + term ),
					typed: true
				};
			},
			insertTag: function ( data, tag ) {
				// Offered only when no listed order already is that number, and then last, after
				// the real matches.
				var listed = data.some( function ( item ) {
					return TYPED_PREFIX + String( item.id ) === String( tag.id );
				} );

				if ( ! listed ) {
					data.push( tag );
				}
			},
			ajax: {
				url: settings.ajax_url,
				type: 'POST',
				dataType: 'json',
				delay: 250,
				data: function ( params ) {
					return {
						action: 'wc_kledo_diag_orders',
						security: settings.security,
						term: params.term || ''
					};
				},
				processResults: function ( payload ) {
					return { results: payload && payload.success ? payload.data.results : [] };
				},
				cache: true
			},
			templateResult: function ( item ) {
				if ( item.loading || item.children || ! item.id || item.typed ) {
					return item.text;
				}

				var $row = $( '<div class="wc-kledo-diag-option"></div>' );

				$( '<div class="wc-kledo-diag-option-title"></div>' ).text( item.text ).appendTo( $row );
				$( '<div class="wc-kledo-diag-option-meta"></div>' ).text( item.meta || '' ).appendTo( $row );
				$( '<div class="wc-kledo-diag-option-states"></div>' )
					.toggleClass( 'is-problem', !! item.problem )
					.text( item.states || '' )
					.appendTo( $row );

				return $row;
			},
			templateSelection: function ( item ) {
				return item.text;
			},
			language: {
				searching: function () {
					return settings.i18n.searching;
				},
				noResults: function () {
					return settings.i18n.no_results;
				},
				errorLoading: function () {
					return settings.i18n.load_failed;
				}
			}
		} );

		// The page was opened for one order (from the Transactions tab or the order screen).
		if ( $select.val() ) {
			$select.trigger( 'change' );
		}
	}

	/**
	 * POST to admin-ajax and resolve with the `data` of a successful answer.
	 *
	 * @param {string} action
	 * @param {Object} fields
	 *
	 * @return {Promise<Object>}
	 */
	function post( action, fields ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'security', settings.security );

		Object.keys( fields ).forEach( function ( key ) {
			body.set( key, fields[ key ] );
		} );

		return window.fetch( settings.ajax_url, {
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
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : settings.i18n.failed );
				}

				return payload.data;
			} );
	}

	function wait( milliseconds ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, milliseconds );
		} );
	}

	/**
	 * Add a step row in its "running" state.
	 *
	 * @param {string} step
	 *
	 * @return {HTMLElement}
	 */
	function addRow( step ) {
		var row = document.createElement( 'li' );
		row.className = 'wc-kledo-diag-step is-running';
		row.innerHTML = '<span class="wc-kledo-diag-icon"><span class="spinner is-active"></span></span>'
			+ '<span class="wc-kledo-diag-label"></span> <span class="wc-kledo-diag-summary"></span>';
		row.querySelector( '.wc-kledo-diag-label' ).textContent = settings.steps[ step ];
		row.querySelector( '.wc-kledo-diag-summary' ).textContent = settings.i18n.running;
		list.appendChild( row );

		return row;
	}

	/**
	 * Fill a step row with its result.
	 *
	 * @param {HTMLElement} row
	 * @param {Object}      result
	 */
	function fillRow( row, result ) {
		row.className = 'wc-kledo-diag-step is-' + result.status;
		row.querySelector( '.wc-kledo-diag-icon' ).textContent = icons[ result.status ] || '•';
		row.querySelector( '.wc-kledo-diag-summary' ).textContent = result.summary;

		var old = row.querySelector( 'details' );

		if ( old ) {
			old.remove();
		}

		if ( result.details && '[]' !== result.details ) {
			var details = document.createElement( 'details' );
			var summary = document.createElement( 'summary' );
			var pre = document.createElement( 'pre' );

			summary.textContent = settings.i18n.details;
			pre.textContent = result.details;
			details.appendChild( summary );
			details.appendChild( pre );
			row.appendChild( details );
		}
	}

	function runStep( code, step, row ) {
		return post( 'wc_kledo_diag_step', { code: code, step: step } ).then( function ( result ) {
			fillRow( row, result );

			return result;
		} );
	}

	/**
	 * After a resend, read Kledo up to three times, 20 seconds apart.
	 *
	 * @param {string} code
	 *
	 * @return {Promise}
	 */
	function recheck( code ) {
		var row = addRow( 'recheck' );
		var attempt = 1;

		function next() {
			row.querySelector( '.wc-kledo-diag-summary' ).textContent = settings.i18n.waiting.replace( '%1$d', attempt ).replace( '%2$d', RECHECK_TRIES );

			return wait( RECHECK_DELAY )
				.then( function () {
					return runStep( code, 'recheck', row );
				} )
				.then( function ( result ) {
					attempt++;

					if ( 'warn' === result.status && attempt <= RECHECK_TRIES ) {
						return next();
					}

					return result;
				} );
		}

		return next();
	}

	function run() {
		var button = document.getElementById( 'wc-kledo-diag-run' );
		var data = new FormData( form );
		var resend = !! data.get( 'resend' );

		button.disabled = true;
		list.innerHTML = '';
		list.hidden = false;
		conclusion.hidden = true;

		post( 'wc_kledo_diag_start', {
			order: String( data.get( 'order' ) || '' ).replace( TYPED_PREFIX, '' ),
			resend: resend ? '1' : '',
			full_customer_data: data.get( 'full_customer_data' ) ? '1' : ''
		} )
			.then( function ( started ) {
				var steps = CHECK_STEPS.slice();
				var chain = Promise.resolve();

				if ( resend ) {
					steps.push( 'resend' );
				}

				steps.forEach( function ( step ) {
					chain = chain.then( function () {
						return runStep( started.code, step, addRow( step ) );
					} );
				} );

				return chain
					.then( function ( lastResult ) {
						if ( resend && lastResult && 'ok' === lastResult.status ) {
							return recheck( started.code );
						}
					} )
					.then( function () {
						return post( 'wc_kledo_diag_finish', { code: started.code } );
					} );
			} )
			.then( showConclusion )
			.catch( function ( error ) {
				conclusion.hidden = false;
				conclusion.className = 'wc-kledo-diag-conclusion is-fail';
				conclusion.textContent = error.message;
			} )
			.then( function () {
				button.disabled = false;
			} );
	}

	/**
	 * Show the likely cause and the report actions.
	 *
	 * @param {Object} finished
	 */
	function showConclusion( finished ) {
		var cause = finished.conclusion;

		conclusion.hidden = false;
		conclusion.className = 'wc-kledo-diag-conclusion is-' + ( -1 !== [ 'in_kledo', 'fixed_by_resend' ].indexOf( cause.cause ) ? 'ok' : 'fail' );
		conclusion.innerHTML = '<h3></h3><p class="wc-kledo-diag-cause"></p><p class="wc-kledo-diag-explanation"></p><p class="wc-kledo-diag-fix"><strong></strong> <span></span></p>'
			+ '<p class="wc-kledo-diag-code"></p>'
			+ '<p class="wc-kledo-diag-actions"><a class="button button-primary" data-format="txt"></a> <a class="button" data-format="json"></a></p>';

		conclusion.querySelector( 'h3' ).textContent = settings.i18n.likely;
		conclusion.querySelector( '.wc-kledo-diag-cause' ).textContent = cause.title;
		conclusion.querySelector( '.wc-kledo-diag-explanation' ).textContent = cause.explanation;
		conclusion.querySelector( '.wc-kledo-diag-fix strong' ).textContent = settings.i18n.todo;
		conclusion.querySelector( '.wc-kledo-diag-fix span' ).textContent = cause.fix;
		conclusion.querySelector( '.wc-kledo-diag-code' ).textContent = settings.i18n.code.replace( '%s', finished.code );

		var text = conclusion.querySelector( '[data-format="txt"]' );
		var json = conclusion.querySelector( '[data-format="json"]' );

		text.href = finished.download.txt;
		text.textContent = settings.i18n.download_text;
		json.href = finished.download.json;
		json.textContent = settings.i18n.download_json;
	}
}() );
