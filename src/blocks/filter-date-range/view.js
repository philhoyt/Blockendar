/**
 * filter-date-range — frontend view script.
 *
 * Upgrades the two native <input type="date"> fields to a Flatpickr range
 * picker. Falls back to the native inputs if Flatpickr fails or is unavailable.
 *
 * On date selection the form auto-submits after a short debounce. The
 * pagination param is stripped so a new date range always starts at page 1.
 * Cross-field validation ensures start ≤ end.
 */
import flatpickr from 'flatpickr';

( function () {
	document
		.querySelectorAll( '.blockendar-filter-date-range' )
		.forEach( ( el ) => {
			const form = el.querySelector( 'form' );
			const paramStart = el.dataset.paramStart;
			const paramEnd = el.dataset.paramEnd;
			const minDate = el.dataset.minDate || null;
			const maxDate = el.dataset.maxDate || null;

			if ( ! form || ! paramStart || ! paramEnd ) {
				return;
			}

			const inputStart = form.querySelector( `[name="${ paramStart }"]` );
			const inputEnd = form.querySelector( `[name="${ paramEnd }"]` );

			if ( ! inputStart || ! inputEnd ) {
				return;
			}

			const submitBtn = el.querySelector( '.blockendar-filter__submit' );

			let debounceTimer = null;

			const submit = () => {
				clearTimeout( debounceTimer );
				debounceTimer = setTimeout( () => {
					const url = new URL( form.action, window.location.href );
					const params = new URLSearchParams( url.searchParams );

					if ( inputStart.value ) {
						params.set( paramStart, inputStart.value );
					} else {
						params.delete( paramStart );
					}

					if ( inputEnd.value ) {
						params.set( paramEnd, inputEnd.value );
					} else {
						params.delete( paramEnd );
					}

					// Reset pagination.
					const queryId =
						el.closest( '[data-blockendar-query-id]' )?.dataset
							?.blockendarQueryId ?? '';
					const pageKey = queryId
						? 'blockendar_page_' + queryId
						: 'blockendar_page';
					params.delete( pageKey );

					// Carry over other hidden filter inputs.
					new FormData( form ).forEach( ( val, key ) => {
						if ( key !== paramStart && key !== paramEnd ) {
							params.set( key, val );
						}
					} );

					url.search = params.toString();
					window.location.assign( url.toString() );
				}, 300 );
			};

			// Hide the submit button — picker auto-submits.
			if ( submitBtn ) {
				submitBtn.hidden = true;
			}

			// Replace the two separate inputs with a single Flatpickr range picker.
			// We use the start input as the anchor and hide the end input.
			inputEnd.closest(
				'.blockendar-filter-date-range__field'
			).hidden = true;

			flatpickr( inputStart, {
				mode: 'range',
				dateFormat: 'Y-m-d',
				minDate: minDate ?? undefined,
				maxDate: maxDate ?? undefined,
				defaultDate: [
					inputStart.value || null,
					inputEnd.value || null,
				].filter( Boolean ),
				onClose( selectedDates ) {
					if ( selectedDates.length === 2 ) {
						const fmt = ( d ) =>
							d.getFullYear() +
							'-' +
							String( d.getMonth() + 1 ).padStart( 2, '0' ) +
							'-' +
							String( d.getDate() ).padStart( 2, '0' );
						inputStart.value = fmt( selectedDates[ 0 ] );
						inputEnd.value = fmt( selectedDates[ 1 ] );
						submit();
					} else if ( selectedDates.length === 0 ) {
						inputStart.value = '';
						inputEnd.value = '';
						submit();
					}
				},
			} );
		} );
} )();
