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

/**
 * Two-digit zero-padded number.
 *
 * @param {number} n Value.
 * @return {string} Padded value.
 */
function pad( n ) {
	return String( n ).padStart( 2, '0' );
}

/**
 * The current wall-clock time in the site's zone, as a naive ISO string.
 *
 * The calendar is given a named IANA zone with no timezone plugin, so it
 * cannot work out offsets itself and would place "now" at the visitor's local
 * wall clock while drawing events at the site's. FullCalendar reads a naive
 * string as wall clock in its own zone, so handing it this string puts the
 * now indicator and the "today" highlight where the site's clock says.
 *
 * Computed in the browser from the zone name rather than from a server
 * offset: a cached page would otherwise carry a stale offset across a
 * daylight-saving change.
 *
 * @param {string} timeZone IANA zone name, 'UTC', or 'local'.
 * @param {Date}   [date]   The instant to format; defaults to now.
 * @return {string} 'YYYY-MM-DDTHH:MM:SS' in that zone.
 */
export function siteNow( timeZone, date = new Date() ) {
	if ( timeZone && 'local' !== timeZone ) {
		try {
			const parts = new Intl.DateTimeFormat( 'en-CA', {
				timeZone,
				hourCycle: 'h23',
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				second: '2-digit',
			} ).formatToParts( date );
			const get = ( type ) =>
				parts.find( ( part ) => part.type === type )?.value;

			return `${ get( 'year' ) }-${ get( 'month' ) }-${ get(
				'day'
			) }T${ get( 'hour' ) }:${ get( 'minute' ) }:${ get( 'second' ) }`;
		} catch {
			// An unknown zone name throws. The browser's clock is the same
			// fallback FullCalendar uses on its own.
		}
	}

	return `${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }-${ pad(
		date.getDate()
	) }T${ pad( date.getHours() ) }:${ pad( date.getMinutes() ) }:${ pad(
		date.getSeconds()
	) }`;
}

/**
 * Events a day may show before the rest fold into a "more" link.
 *
 * @param {string|undefined} raw The data attribute value.
 * @return {number} An integer from 1 to 10; 3 when the value is unusable.
 */
export function eventsPerDay( raw ) {
	const n = parseInt( raw, 10 );

	if ( Number.isNaN( n ) ) {
		return 3;
	}

	return Math.min( 10, Math.max( 1, n ) );
}

/**
 * The venue and cost line shown under an event's title.
 *
 * Read from the extendedProps the calendar feed already sends for every
 * event. The month and year views have no room for it; the week, day and
 * list views do.
 *
 * @param {Object} [extendedProps]       The event's extendedProps.
 * @param {Object} [extendedProps.venue] Venue summary: id, name, city.
 * @param {string} [extendedProps.cost]  Cost as the editor entered it.
 * @return {{venue: string|null, cost: string|null}|null} The two strings, or
 *   null when the event has neither.
 */
export function eventMeta( extendedProps = {} ) {
	const name = extendedProps.venue?.name?.trim() || '';
	const city = extendedProps.venue?.city?.trim() || '';
	const cost = String( extendedProps.cost ?? '' ).trim();

	let venue = null;

	if ( name ) {
		venue = city ? `${ name }, ${ city }` : name;
	}

	if ( ! venue && ! cost ) {
		return null;
	}

	return { venue, cost: cost || null };
}
