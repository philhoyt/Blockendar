/**
 * Turning the site's language and time format into what FullCalendar takes.
 *
 * Pure functions, kept apart from the view script so they can be tested
 * without a browser or a calendar.
 */

/**
 * The FullCalendar locale files to try for a WordPress locale, best first.
 *
 * FullCalendar names its locales "de", "pt-br", "zh-cn". WordPress writes
 * "de_DE", "pt_BR", "de_DE_formal". The region is tried first, then the
 * language alone. English is FullCalendar's own, with no file to load, so a
 * locale it has no better match for is left to fall back to it.
 *
 * @param {string} wpLocale WordPress locale, e.g. "de_DE".
 * @return {string[]} Locale file names, without extension.
 */
export function localeCandidates( wpLocale ) {
	const parts = String( wpLocale || '' )
		.toLowerCase()
		.split( /[_-]/ );
	const language = parts[ 0 ];

	if ( ! /^[a-z]{2,3}$/.test( language ) ) {
		return [];
	}

	const region = parts[ 1 ];
	const candidates = [];

	if ( region && /^[a-z]{2,4}$/.test( region ) ) {
		candidates.push( `${ language }-${ region }` );
	}

	// "en" is the built-in default; "en-gb" and the like are real files.
	if ( 'en' !== language ) {
		candidates.push( language );
	}

	return candidates;
}

/**
 * FullCalendar's eventTimeFormat for a PHP time format.
 *
 * Only what a time format can vary in is read: 12- or 24-hour, a leading zero
 * on the hour, and whether the meridiem is shown. The site's separator and
 * the case of "am" are FullCalendar's to choose for the locale.
 *
 * @param {string} phpFormat PHP date format, e.g. "g:i a" or "H:i".
 * @return {Object} A FullCalendar date-formatting object.
 */
export function eventTimeFormat( phpFormat ) {
	const format = String( phpFormat || 'g:i a' ).replace( /\\./g, '' );
	const twelveHour = /[gh]/.test( format );

	const options = {
		hour: /[hH]/.test( format ) ? '2-digit' : 'numeric',
		minute: '2-digit',
		hour12: twelveHour,
	};

	if ( twelveHour && /[aA]/.test( format ) ) {
		options.meridiem = 'short';
	} else if ( twelveHour ) {
		options.meridiem = false;
	}

	return options;
}
