/**
 * Meta updates for the "Ongoing, no end date" toggle in the Date & Time panel.
 *
 * Kept as a pure function so the behaviour can be unit tested without
 * rendering the panel or mocking the editor store.
 */

const DEFAULT_DURATION_MINUTES = 60;

/**
 * Return `hhmm` advanced by `mins` minutes, wrapping at midnight.
 *
 * @param {string} hhmm Time in HH:MM.
 * @param {number} mins Minutes to add.
 * @return {string} New time in HH:MM.
 */
function addMinutes( hhmm, mins ) {
	const [ h, m ] = hhmm.split( ':' ).map( Number );
	const total = h * 60 + m + mins;
	const hours = Math.floor( total / 60 ) % 24;
	const minutes = total % 60;
	return `${ String( hours ).padStart( 2, '0' ) }:${ String(
		minutes
	).padStart( 2, '0' ) }`;
}

/**
 * Build the meta patch for toggling the ongoing flag.
 *
 * Turning it on clears the end date and time so the stored data matches what
 * the editor shows (the server ignores them for ongoing events regardless).
 * Turning it off restores the fields, seeding an empty end from the start
 * using the panel's existing smart default (same day, one hour later).
 *
 * @param {Object}  meta    Current post meta.
 * @param {boolean} ongoing New toggle value.
 * @return {Object} Meta keys to merge via editPost().
 */
export function getOngoingMetaUpdates( meta, ongoing ) {
	if ( ongoing ) {
		return {
			blockendar_ongoing: true,
			blockendar_end_date: '',
			blockendar_end_time: '',
		};
	}

	const updates = { blockendar_ongoing: false };
	const startDate = meta?.blockendar_start_date ?? '';
	const startTime = meta?.blockendar_start_time ?? '';

	if ( ! meta?.blockendar_end_date && startDate ) {
		updates.blockendar_end_date = startDate;
	}

	if ( ! meta?.blockendar_end_time && startTime ) {
		updates.blockendar_end_time = addMinutes(
			startTime,
			DEFAULT_DURATION_MINUTES
		);
	}

	return updates;
}
