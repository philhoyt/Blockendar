/**
 * filter-date-range — frontend view script.
 *
 * Upgrades the two native <input type="date"> fields to a Flatpickr range
 * picker. Falls back to the native inputs if Flatpickr fails or is unavailable.
 *
 * The two fields live inside a popover panel. Flatpickr is only initialised the
 * first time that panel opens: static:true measures the input to place the
 * calendar, and an element inside a display:none panel has no dimensions to
 * measure, so initialising on load would mis-position it.
 *
 * Flatpickr is pulled in with a dynamic import() so it ships as its own chunk:
 * the native inputs are fully usable on their own, so there is no reason to make
 * every visitor download a date picker before the form works.
 *
 * On date selection the form auto-submits after a short debounce. The
 * pagination param is stripped so a new date range always starts at page 1.
 * An inverted range is normalised server-side in FilterContext, so the two
 * fields do not need to police each other here.
 */
import { initFilterPopover } from '../shared/filter-popover';
import '../shared/filter-popover.css';
import '../shared/filter-controls.css';

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

			const startLabel = inputStart
				.closest( '.blockendar-filter-date-range__field' )
				?.querySelector( 'label' );
			const endField = inputEnd.closest(
				'.blockendar-filter-date-range__field'
			);

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

			// The Apply button stays available: the picker auto-submits once a
			// full range is chosen, but the button is the no-JS path and the way
			// to commit if the picker never loads.

			/**
			 * Build the Flatpickr instance. Called on first open rather than on
			 * load, because static:true measures the anchor input to place the
			 * calendar and an input inside a display:none panel has no dimensions.
			 *
			 * @return {Promise<void>} Resolves once the picker is mounted.
			 */
			const buildPicker = () =>
				Promise.all( [
					import( 'flatpickr' ),
					// Flatpickr's own stylesheet. Without it the calendar renders
					// as a full-size unstyled block, because the library relies on
					// this CSS for its positioning and sizing.
					import( 'flatpickr/dist/flatpickr.css' ),
				] )
					.then( ( [ { default: flatpickr } ] ) => {
						flatpickr( inputStart, {
							mode: 'range',
							dateFormat: 'Y-m-d',
							// Renders the calendar in a wrapper next to the input
							// rather than floating it against page coordinates.
							static: true,
							minDate: minDate ?? undefined,
							maxDate: maxDate ?? undefined,
							defaultDate: [
								inputStart.value || null,
								inputEnd.value || null,
							].filter( Boolean ),
							onClose( selectedDates ) {
								// Only navigate on a real change. Submitting on
								// every close meant that opening and dismissing the
								// picker reloaded the page.
								const fmt = ( d ) =>
									d.getFullYear() +
									'-' +
									String( d.getMonth() + 1 ).padStart(
										2,
										'0'
									) +
									'-' +
									String( d.getDate() ).padStart( 2, '0' );

								const nextStart =
									selectedDates.length === 2
										? fmt( selectedDates[ 0 ] )
										: '';
								const nextEnd =
									selectedDates.length === 2
										? fmt( selectedDates[ 1 ] )
										: '';

								// One date picked is not a range yet.
								if ( selectedDates.length === 1 ) {
									return;
								}

								if (
									nextStart === inputStart.value &&
									nextEnd === inputEnd.value
								) {
									return;
								}

								inputStart.value = nextStart;
								inputEnd.value = nextEnd;
								submit();
							},
						} );

						// One input now covers both ends of the range, so "From"
						// no longer describes it. The replacement text comes from
						// PHP because view scripts carry no translation context.
						if ( startLabel && el.dataset.labelRange ) {
							startLabel.textContent = el.dataset.labelRange;
						}

						endField?.setAttribute( 'hidden', '' );
					} )
					.catch( () => {
						// Picker unavailable: both native date inputs stay visible
						// with their From/To labels and the form still submits.
					} );

			let pickerBuilt = false;

			initFilterPopover( el, {
				onOpen: () => {
					if ( pickerBuilt ) {
						return;
					}

					pickerBuilt = true;
					buildPicker();
				},
			} );
		} );
} )();
