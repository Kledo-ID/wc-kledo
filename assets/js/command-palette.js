/**
 * Copyright (c) Kledo Software. All Rights Reserved
 */

/**
 * Registers the plugin's settings screens as commands in the WordPress command palette.
 *
 * Written against the `core/commands` data store rather than the `useCommand` React hook on
 * purpose: this plugin ships plain scripts and has no build step, and the hook would drag React
 * and a bundler in behind it. The store is what the hook writes to anyway.
 *
 * Everything here is defensive. The palette only reached screens outside the editors in
 * WordPress 6.9, while this plugin still supports far older versions, so each part of the API is
 * checked before it is used and the script simply does nothing when it is not there.
 */
( function () {
	'use strict';

	if ( typeof window.wp === 'undefined' || ! window.wp.data || typeof window.wp.data.dispatch !== 'function' ) {
		return;
	}

	if ( typeof window.wcKledoCommands === 'undefined' || ! Array.isArray( window.wcKledoCommands.commands ) ) {
		return;
	}

	var store = window.wp.data.dispatch( 'core/commands' );

	// The store is absent on any version without the palette, and registerCommand is absent on
	// versions carrying an older shape of it.
	if ( ! store || typeof store.registerCommand !== 'function' ) {
		return;
	}

	window.wcKledoCommands.commands.forEach( function ( command ) {
		if ( ! command || ! command.name || ! command.label || ! command.url ) {
			return;
		}

		store.registerCommand( {
			name: command.name,
			label: command.label,
			searchLabel: command.searchLabel || command.label,
			callback: function ( commandArguments ) {
				// close() is handed over by the palette so the overlay does not linger over the
				// page being navigated to. Treated as optional, like everything else here.
				if ( commandArguments && typeof commandArguments.close === 'function' ) {
					commandArguments.close();
				}

				window.location.href = command.url;
			},
		} );
	} );
}() );
