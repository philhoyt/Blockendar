/**
 * Date helpers for end-to-end fixtures.
 *
 * The Events Query shows upcoming events only, so a fixture pinned to a fixed
 * calendar date silently empties the results once that date has passed — and
 * every test in the file then fails before reaching the behaviour under test.
 * Seed relative to today instead.
 */

/**
 * A Y-m-d date the given number of days from today.
 *
 * @param {number} days Days from today; negative for the past.
 * @return {string} Y-m-d.
 */
function daysFromNow( days ) {
	const date = new Date();
	date.setDate( date.getDate() + days );
	return date.toISOString().slice( 0, 10 );
}

module.exports = { daysFromNow };
