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
import '../shared/filter-controls.css';

( function () {
	document.querySelectorAll( '.blockendar-filter-venue' ).forEach( ( el ) => {
		const form = el.querySelector( 'form' );

		if ( ! form ) {
			return;
		}

		initFilterPopover( el );

		/*
		 * No auto-submit, and the Apply button stays visible.
		 *
		 * Navigating the moment a radio changes is a WCAG 3.2.2 (On Input)
		 * failure, and it broke keyboard use outright: arrow keys move between
		 * radios and each move fires `change`, so the page navigated away
		 * before the intended option was ever reached.
		 *
		 * Nothing is needed in its place. The form is a plain GET whose action
		 * already drops this filter's own param and the pagination param, and
		 * the other active filters travel as hidden inputs — so the browser's
		 * native submit produces exactly the URL the old handler assembled by
		 * hand. This is what filter-date-range has always done.
		 */
	} );
} )();
