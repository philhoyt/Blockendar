/**
 * filter-date-range — frontend view script.
 *
 * Upgrades the two native <input type="date"> fields to a Flatpickr range
 * picker. Falls back to the native inputs if Flatpickr fails or is unavailable.
 *
 * The popover panel holds the calendar itself, rather than two text fields that
 * open a calendar beneath them — the latter made the panel taller than it could
 * show and forced the visitor to scroll to reach the month grid.
 *
 * The calendar is built the first time the panel opens. Flatpickr measures on
 * init, and an element inside a display:none panel has no dimensions to measure.
 *
 * Flatpickr is pulled in with a dynamic import() so it ships as its own chunk:
 * the native inputs are fully usable on their own, so there is no reason to make
 * every visitor download a date picker before the form works.
 *
 * Selecting dates writes them to the hidden native inputs; the Apply button
 * commits them. There is no auto-submit: with a range you are mid-decision after
 * the first click, and navigating then would be wrong.
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

			const startField = inputStart.closest(
				'.blockendar-filter-date-range__field'
			);
			const endField = inputEnd.closest(
				'.blockendar-filter-date-range__field'
			);

			/*
			 * No JavaScript submit helper. The Apply button is a native submit
			 * inside the form, the form action already drops the pagination param,
			 * and the other filters travel as hidden inputs — so the browser does
			 * everything the old handler did.
			 */
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
						const mount = el.querySelector(
							'.blockendar-filter-date-range__calendar'
						);

						if ( ! mount ) {
							return;
						}

						flatpickr( inputStart, {
							mode: 'range',
							dateFormat: 'Y-m-d',
							// The calendar is the panel's content, always visible
							// rather than opening in response to a field.
							inline: true,
							appendTo: mount,
							minDate: minDate ?? undefined,
							maxDate: maxDate ?? undefined,
							defaultDate: [
								inputStart.value || null,
								inputEnd.value || null,
							].filter( Boolean ),
							onChange( selectedDates ) {
								const fmt = ( d ) =>
									d.getFullYear() +
									'-' +
									String( d.getMonth() + 1 ).padStart(
										2,
										'0'
									) +
									'-' +
									String( d.getDate() ).padStart( 2, '0' );

								// A range is only meaningful once both ends exist.
								// Until then the fields keep their previous value,
								// so dismissing mid-selection changes nothing.
								if ( selectedDates.length === 2 ) {
									inputStart.value = fmt(
										selectedDates[ 0 ]
									);
									inputEnd.value = fmt( selectedDates[ 1 ] );
								} else if ( selectedDates.length === 0 ) {
									inputStart.value = '';
									inputEnd.value = '';
								}
							},
						} );

						// The native fields carry the values but are no longer the
						// control; the calendar is. They stay in the DOM so the form
						// still submits them.
						[ startField, endField ].forEach( ( field ) =>
							field?.setAttribute( 'hidden', '' )
						);

						mount.removeAttribute( 'aria-hidden' );
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
