/**
 * The status and its reason in the Event Details panel.
 *
 * Kept apart from the panel so the rule can be tested without rendering it.
 */

/**
 * Whether a status is one that can have a reason: anything but going ahead.
 *
 * @param {string|undefined} status Event status.
 * @return {boolean} True when the reason field belongs on screen.
 */
export function statusHasReason( status ) {
	return !! status && status !== 'scheduled';
}

/**
 * The meta to write when the status is changed.
 *
 * Putting an event back on takes its reason with it. The field disappears
 * from the panel at that moment, and text left in it would be saved unseen:
 * a reason for a postponement that is over, readable through the REST API.
 *
 * @param {string} status The status chosen.
 * @return {Object} Meta updates.
 */
export function statusUpdate( status ) {
	return statusHasReason( status )
		? { blockendar_status: status }
		: { blockendar_status: status, blockendar_status_reason: '' };
}
