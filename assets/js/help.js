/**
 * Kledo > Guide & FAQ: search the topics, and open the one a link points to.
 *
 * @since 1.8.0
 */
( function () {
	'use strict';

	var search = document.getElementById( 'wc-kledo-help-search' );
	var items = document.querySelectorAll( '.wc-kledo-help-item' );
	var empty = document.querySelector( '.wc-kledo-help-no-result' );

	/**
	 * Open the topic named in the URL hash, so links to `#guide-…` / `#faq-…` land on it open.
	 */
	function openFromHash() {
		if ( ! window.location.hash ) {
			return;
		}

		var target = document.getElementById( window.location.hash.slice( 1 ) );

		if ( target && 'DETAILS' === target.tagName ) {
			target.open = true;
			target.scrollIntoView();
		}
	}

	window.addEventListener( 'hashchange', openFromHash );
	openFromHash();

	if ( ! search ) {
		return;
	}

	search.addEventListener( 'input', function () {
		var words = search.value.toLowerCase().trim().split( /\s+/ ).filter( Boolean );
		var shown = 0;

		for ( var index = 0; index < items.length; index++ ) {
			var item = items[ index ];
			var text = item.textContent.toLowerCase();
			var match = words.every( function ( word ) {
				return -1 !== text.indexOf( word );
			} );

			item.hidden = ! match;
			item.open = words.length > 0 && match;

			if ( match ) {
				shown++;
			}
		}

		if ( 0 === words.length ) {
			items[ 0 ].open = true;
		}

		empty.hidden = 0 !== shown;
	} );
}() );
