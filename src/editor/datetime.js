/**
 * Date and time arithmetic for the Date & Time panel.
 *
 * Every value here is a wall-clock string — Y-m-d and HH:MM — in the event's
 * own timezone. Nothing is an instant, so nothing is converted. Kept as pure
 * functions so the rules can be tested without rendering the panel.
 */

const pad = ( n ) => String( n ).padStart( 2, '0' );

/** The minutes the picker offers. */
const MINUTE_STEP = 5;

/**
 * The options for the minute select, always including the stored minute.
 *
 * The picker offers five-minute steps. A stored value off that grid — an
 * imported 19:58 — used to be shown rounded and then saved rounded the next
 * time the hour was touched. It is offered as an extra option, so the select
 * shows what is stored and nothing is rewritten.
 *
 * @param {number} minute The stored minute, 0–59.
 * @return {{label: string, value: string}[]} Options in ascending order.
 */
export function minuteOptions( minute ) {
	const minutes = [];

	for ( let m = 0; m < 60; m += MINUTE_STEP ) {
		minutes.push( m );
	}

	if ( Number.isInteger( minute ) && ! minutes.includes( minute ) ) {
		minutes.push( minute );
		minutes.sort( ( a, b ) => a - b );
	}

	return minutes.map( ( m ) => ( { label: pad( m ), value: pad( m ) } ) );
}

/**
 * The day after a date.
 *
 * @param {string} ymd Y-m-d.
 * @return {string} Y-m-d.
 */
export function nextDay( ymd ) {
	// UTC throughout, so no timezone or clock change can skip or repeat a day.
	const d = new Date( `${ ymd }T00:00:00Z` );
	d.setUTCDate( d.getUTCDate() + 1 );

	return d.toISOString().slice( 0, 10 );
}

/**
 * One hour after a time, without running past the end of the day.
 *
 * @param {string} hhmm HH:MM.
 * @return {string} HH:MM, at most 23:59.
 */
export function oneHourAfter( hhmm ) {
	const [ h, m ] = hhmm.split( ':' ).map( Number );

	return h >= 23 ? '23:59' : `${ pad( h + 1 ) }:${ pad( m ) }`;
}

/**
 * Meta to change when the author picks an end time.
 *
 * An end at or before the start on the same day is an event that runs past
 * midnight, so the end date moves to the next day. It used to be forced to
 * five minutes after the start, which made a 10 pm–1 am event impossible to
 * enter without changing the end date first.
 *
 * @param {{startDate: string, endDate: string, startTime: string}} event   Current values.
 * @param {string}                                                  endTime The time picked.
 * @return {Object} Meta keys to merge.
 */
export function endTimeUpdates( event, endTime ) {
	const updates = { blockendar_end_time: endTime };

	if (
		event.startDate &&
		event.startDate === event.endDate &&
		endTime <= event.startTime
	) {
		updates.blockendar_end_date = nextDay( event.startDate );
	}

	return updates;
}

/**
 * Meta to change when the author picks an end date.
 *
 * The end cannot be before the start. Pulled back onto the start date with an
 * end time that is not after the start time, the event would end before it
 * began, so the end time moves to an hour after the start.
 *
 * @param {{startDate: string, startTime: string, endTime: string}} event   Current values.
 * @param {string}                                                  endDate The date picked.
 * @return {Object} Meta keys to merge.
 */
export function endDateUpdates( event, endDate ) {
	const date = endDate < event.startDate ? event.startDate : endDate;
	const updates = { blockendar_end_date: date };

	if ( date === event.startDate && event.endTime <= event.startTime ) {
		updates.blockendar_end_time = oneHourAfter( event.startTime );
	}

	return updates;
}

/**
 * Meta to change when the author picks a start time.
 *
 * On a one-day event, a start at or after the end pushes the end to an hour
 * after the new start.
 *
 * @param {{startDate: string, endDate: string, endTime: string}} event     Current values.
 * @param {string}                                                startTime The time picked.
 * @return {Object} Meta keys to merge.
 */
export function startTimeUpdates( event, startTime ) {
	const updates = { blockendar_start_time: startTime };

	if (
		event.startDate &&
		event.startDate === event.endDate &&
		event.endTime <= startTime
	) {
		updates.blockendar_end_time = oneHourAfter( startTime );
	}

	return updates;
}
