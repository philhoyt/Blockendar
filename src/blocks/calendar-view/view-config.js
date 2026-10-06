/**
 * calendar-view block — pure helpers for the frontend view script.
 *
 * Nothing here touches the DOM or FullCalendar, so Jest can cover it without
 * mounting a calendar. view.jsx reads the block's data attributes and hands
 * the raw values to these functions.
 */

/**
 * Map a FullCalendar view name to the plugin package that provides it.
 *
 * @param {string} view View name, e.g. 'dayGridMonth' or 'listNextMonth'.
 * @return {string|null} Plugin key, or null when the view is unrecognised.
 */
export function pluginForView( view ) {
	if ( view.startsWith( 'dayGrid' ) ) {
		return 'dayGrid';
	}
	if ( view.startsWith( 'timeGrid' ) ) {
		return 'timeGrid';
	}
	if ( view.startsWith( 'list' ) ) {
		return 'list';
	}
	if ( view.startsWith( 'multiMonth' ) ) {
		return 'multiMonth';
	}
	return null;
}

const DAY_VIEW = 'timeGridDay';
const WEEK_VIEW = 'timeGridWeek';

/**
 * Nav-link options for the views a calendar offers.
 *
 * FullCalendar's navLinks turn day numbers, column headings, list day
 * headings and week numbers into links, and cannot turn day links off
 * independently of week links. A link must only ever land on a view the
 * calendar offers, or the visitor is stranded on a view with no toolbar
 * button back, so each kind of link targets its own view when that view is
 * offered and the other one otherwise, and links are off when neither is.
 *
 * @param {string[]} views View names the calendar can render.
 * @return {Object} Props to spread onto the calendar.
 */
export function navLinkOptions( views ) {
	const hasDay = views.includes( DAY_VIEW );
	const hasWeek = views.includes( WEEK_VIEW );

	if ( ! hasDay && ! hasWeek ) {
		return { navLinks: false };
	}

	return {
		navLinks: true,
		navLinkDayClick: hasDay ? DAY_VIEW : WEEK_VIEW,
		navLinkWeekClick: hasWeek ? WEEK_VIEW : DAY_VIEW,
	};
}
