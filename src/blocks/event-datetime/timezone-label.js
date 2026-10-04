/**
 * A short label for a timezone as it applies on a given day, for the editor preview.
 *
 * render.php prints PHP's abbreviation for the zone. The browser has no copy of
 * that table, so this asks Intl, which gives the same answer for North American
 * zones ("CDT") and an offset ("GMT+2") where PHP has a name ("CEST"). Either
 * reads as a timezone; the raw identifier does not.
 *
 * @param {string} timezone Timezone identifier, or an offset such as "+05:30".
 * @param {string} date     Y-m-d in that timezone.
 * @param {string} time     HH:MM in that timezone; optional.
 * @return {string} The label, or the identifier unchanged if it cannot be resolved.
 */
export function timezoneLabel( timezone, date, time ) {
	if ( ! timezone ) {
		return '';
	}

	if ( /^[+-]\d{2}:\d{2}$/.test( timezone ) ) {
		return `UTC${ timezone }`;
	}

	try {
		// Read as UTC: all that matters is which side of a clock change it falls.
		const moment = new Date(
			`${ date }T${ ( time || '12:00' ).slice( 0, 5 ) }:00Z`
		);

		const part = new Intl.DateTimeFormat( 'en-US', {
			timeZone: timezone,
			timeZoneName: 'short',
		} )
			.formatToParts( moment )
			.find( ( { type } ) => type === 'timeZoneName' );

		return part?.value ?? timezone;
	} catch {
		return timezone;
	}
}
