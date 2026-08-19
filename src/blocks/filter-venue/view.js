/**
 * filter-venue — frontend view script.
 *
 * Auto-submits the form when a radio button changes, and strips the pagination
 * param so a filter change returns to page 1.
 *
 * Dropdown style additionally upgrades the trigger/panel pair into a popover.
 * Unlike event type, venue is single-select, so a click is a complete decision
 * and auto-submit still applies — the panel closes because the page reloads.
 */
import { initFilterPopover } from '../shared/filter-popover';
import '../shared/filter-popover.css';

( function () {
	document.querySelectorAll( '.blockendar-filter-venue' ).forEach( ( el ) => {
		const form = el.querySelector( 'form' );

		if ( ! form ) {
			return;
		}

		initFilterPopover( el );

		const submitBtn = el.querySelector( '.blockendar-filter__submit' );

		if ( submitBtn ) {
			submitBtn.hidden = true;
		}

		form.addEventListener( 'change', () => {
			const url = new URL( form.action, window.location.href );
			const data = new FormData( form );
			const params = new URLSearchParams( url.searchParams );

			for ( const [ key, val ] of data.entries() ) {
				if ( '' === val ) {
					params.delete( key );
				} else {
					params.set( key, val );
				}
			}

			// Reset pagination.
			const queryId =
				el.closest( '[data-blockendar-query-id]' )?.dataset
					?.blockendarQueryId ?? '';
			const pageKey = queryId
				? 'blockendar_page_' + queryId
				: 'blockendar_page';
			params.delete( pageKey );

			url.search = params.toString();
			window.location.assign( url.toString() );
		} );
	} );
} )();
