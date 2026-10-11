/**
 * The status and its reason in the Event Details panel.
 *
 * Kept apart from the panel so the rule can be tested without rendering it.
 */
import { __ } from '@wordpress/i18n';

/**
 * The statuses the plugin ships with, for an editor the server told nothing.
 */
const DEFAULT_STATUS_OPTIONS = [
	{ label: __( 'Scheduled', 'blockendar' ), value: 'scheduled' },
	{ label: __( 'Cancelled', 'blockendar' ), value: 'cancelled' },
	{ label: __( 'Postponed', 'blockendar' ), value: 'postponed' },
	{ label: __( 'Sold Out', 'blockendar' ), value: 'sold_out' },
];

/**
 * The options the status dropdown offers.
 *
 * The server knows the list: it is what the blockendar_event_statuses filter
 * returns, and what the meta's schema accepts. The panel reads it from the
 * localized editor object so a status a site adds can be chosen, and saved,
 * from the editor. Anything malformed in that list is dropped, and an empty
 * list falls back to the four defaults.
 *
 * @param {unknown} localized The `statuses` entry of window.blockendarEditor.
 * @return {Array<{label: string, value: string}>} Options for a SelectControl.
 */
export function statusOptions( localized ) {
	const options = Array.isArray( localized )
		? localized
				.filter(
					( option ) =>
						option &&
						typeof option.value === 'string' &&
						option.value !== ''
				)
				.map( ( option ) => ( {
					value: option.value,
					label:
						typeof option.label === 'string' && option.label !== ''
							? option.label
							: option.value,
				} ) )
		: [];

	return options.length ? options : DEFAULT_STATUS_OPTIONS;
}

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
