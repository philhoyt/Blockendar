/**
 * filter-venue — frontend view script.
 *
 * Auto-submits the form when a radio button or select changes.
 * Strips the pagination param before submitting.
 */
( function () {
	document.querySelectorAll( '.blockendar-filter-venue' ).forEach( ( el ) => {
		const form = el.querySelector( 'form' );

		if ( ! form ) {
			return;
		}

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
