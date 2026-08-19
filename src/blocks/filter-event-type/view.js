/**
 * filter-event-type — frontend view script.
 *
 * Progressive enhancement for the event type filter.
 *
 * Two shapes, depending on the display style the editor chose:
 *
 * - Dropdown style renders a trigger and a panel, which become a popover. Because
 *   several types can be ticked at once, the panel keeps its Apply button and does
 *   not auto-submit — otherwise the first tick would navigate and close the panel
 *   before a second could be made.
 * - List style keeps the original behaviour: ticking a box submits immediately and
 *   the Apply button is hidden as redundant.
 *
 * Either way the pagination param is stripped so a filter change returns to page 1.
 */
import { initFilterPopover } from '../shared/filter-popover';
import '../shared/filter-popover.css';

( function () {
	document
		.querySelectorAll( '.blockendar-filter-event-type' )
		.forEach( ( el ) => {
			const form = el.querySelector( 'form' );

			if ( ! form ) {
				return;
			}

			// Dropdown style: upgrade the trigger/panel pair and stop here. The
			// Apply button inside the panel is the way to commit a multi-select.
			if ( initFilterPopover( el ) ) {
				return;
			}

			// List style: the box itself is the control, so Apply is redundant.
			const submitBtn = el.querySelector( '.blockendar-filter__submit' );

			if ( submitBtn ) {
				submitBtn.hidden = true;
			}

			form.addEventListener( 'change', () => {
				// Strip the page param from the action URL before navigating so
				// applying a filter always starts at page 1.
				const url = new URL( form.action, window.location.href );
				const data = new FormData( form );
				const params = new URLSearchParams();

				// Carry over params already in the action URL that aren't
				// overridden by the form (e.g. other filters from hidden inputs).
				url.searchParams.forEach( ( val, key ) => {
					params.set( key, val );
				} );

				// Apply form data, replacing any existing values.
				for ( const [ key, val ] of data.entries() ) {
					if ( params.has( key ) ) {
						// Collect multi-value checkboxes as comma-separated ID list.
						params.set( key, params.get( key ) + ',' + val );
					} else {
						params.set( key, val );
					}
				}

				// If no checkboxes are checked the param must be absent (not empty).
				const paramName = el.dataset.paramName;
				if ( paramName && ! data.has( paramName + '[]' ) ) {
					params.delete( paramName );
					params.delete( paramName + '[]' );
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
