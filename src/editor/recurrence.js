/**
 * The repeat rule behind the "Repeats" controls in the Date & Time panel.
 *
 * The rule is a field of the event (`blockendar_recurrence`), edited with
 * editPost() and saved with the post. These are the pure parts: turning the
 * controls into a rule and a rule back into controls. Kept apart from the panel
 * so they can be tested without rendering it.
 *
 * A rule has to come out of ruleFromForm() exactly as the server reads it back —
 * same keys, same order, same types — because the editor decides whether the
 * event has been edited by comparing the two.
 */

const BYDAY_CODES = [ 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ];

/** How an event that does not repeat is written. */
export const NO_RULE = { frequency: 'none' };

/**
 * The weekday of a date and which one of its month it is.
 *
 * @param {string} dateStr Y-m-d.
 * @return {{dow: number, dom: number, month: number, byday: string, nth: number}|null} Parts, or null without a date.
 */
export function dateParts( dateStr ) {
	if ( ! dateStr ) {
		return null;
	}

	// Noon, so the browser's timezone cannot move it onto another day.
	const d = new Date( dateStr + 'T12:00:00' );
	const dow = d.getDay();
	const dom = d.getDate();

	return {
		dow,
		dom,
		month: d.getMonth(),
		byday: BYDAY_CODES[ dow ],
		nth: Math.min( Math.ceil( dom / 7 ), 5 ),
	};
}

/**
 * The "Repeats" option a stored rule corresponds to.
 *
 * @param {Object|null|undefined} rule Stored rule.
 * @return {string} Preset key.
 */
export function ruleToPreset( rule ) {
	switch ( rule?.frequency ) {
		case 'daily':
			return 'daily';
		case 'weekly':
			return 'weekly_day';
		case 'monthly':
			return 'monthly_weekday';
		case 'yearly':
			return 'yearly_date';
		default:
			return 'none';
	}
}

/**
 * The state of the controls for a stored rule.
 *
 * @param {Object|null|undefined} rule Stored rule.
 * @return {{preset: string, endType: string, untilDate: string, count: string}} Control values.
 */
export function formFromRule( rule ) {
	let endType = 'never';

	if ( rule?.until_date ) {
		endType = 'date';
	} else if ( rule?.count ) {
		endType = 'count';
	}

	return {
		preset: ruleToPreset( rule ),
		endType,
		untilDate: rule?.until_date ?? '',
		count: rule?.count ? String( rule.count ) : '',
	};
}

/**
 * The rule the controls describe, for an event starting on a given date.
 *
 * "Weekly" means weekly on the start date's weekday and "monthly" on its nth
 * weekday of the month, so the rule depends on the start date as well.
 *
 * @param {{preset: string, endType: string, untilDate: string, count: string}} form      Control values.
 * @param {string}                                                              startDate Event start date, Y-m-d.
 * @return {Object} The rule, or NO_RULE.
 */
export function ruleFromForm( form, startDate ) {
	const parts = dateParts( startDate );
	let frequency;
	let byday = null;
	let bysetpos = null;

	switch ( form.preset ) {
		case 'daily':
			frequency = 'daily';
			break;
		case 'weekly_day':
			frequency = 'weekly';
			byday = parts?.byday ?? null;
			break;
		case 'monthly_weekday':
			frequency = 'monthly';
			byday = parts?.byday ?? null;
			bysetpos = parts ? String( parts.nth ) : null;
			break;
		case 'yearly_date':
			frequency = 'yearly';
			break;
		default:
			return NO_RULE;
	}

	const count = parseInt( form.count, 10 );

	return {
		frequency,
		interval_val: 1,
		byday,
		bymonthday: null,
		bysetpos,
		until_date:
			form.endType === 'date' && form.untilDate ? form.untilDate : null,
		count: form.endType === 'count' && count > 0 ? count : null,
	};
}
