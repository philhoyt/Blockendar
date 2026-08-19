/**
 * filter-event-type — frontend view script.
 *
 * Progressive enhancement for the event type filter:
 * - Auto-submits the form when a checkbox changes (no Apply button needed).
 * - Strips the pagination param before submitting so filter changes reset to page 1.
 * - Hides the Apply button when JS is available.
 */
( function () {
	document
		.querySelectorAll( '.blockendar-filter-event-type' )
		.forEach( ( el ) => {
			const form = el.querySelector( 'form' );

			if ( ! form ) {
				return;
			}

			const submitBtn = el.querySelector( '.blockendar-filter__submit' );

			// Hide the submit button — checkboxes auto-submit.
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
