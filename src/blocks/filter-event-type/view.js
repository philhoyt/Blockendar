/**
 * filter-event-type — frontend view script.
 *
 * Progressive enhancement for the event type filter.
 *
 * Dropdown style renders a trigger and a panel, which this upgrades into a
 * popover. List style renders the choices inline and needs no upgrading, so
 * initFilterPopover() finds no trigger and returns null.
 *
 * Neither style submits on its own. Navigating the moment a box is ticked is a
 * WCAG 3.2.2 (On Input) failure — the setting of a control must not change
 * context by itself unless the user was told beforehand — and for a
 * multi-select it was wrong anyway: the first tick navigated and closed the
 * panel before a second could be made.
 *
 * Nothing replaces it. The form is a plain GET whose action already drops this
 * filter's own param and the pagination param, and the other active filters
 * travel as hidden inputs, so the browser's native submit produces exactly the
 * URL the old handler assembled by hand.
 */
import { initFilterPopover } from '../shared/filter-popover';
import '../shared/filter-popover.css';
import '../shared/filter-controls.css';

( function () {
	document
		.querySelectorAll( '.blockendar-filter-event-type' )
		.forEach( ( el ) => {
			if ( ! el.querySelector( 'form' ) ) {
				return;
			}

			initFilterPopover( el );
		} );
} )();
