/**
 * The Event Date & Time block inside the editor.
 *
 * The block reads its dates off the post's REST `meta` through useEntityProp()
 * and, when `blockendar_start_date` is empty, renders hardcoded placeholder
 * dates in 2025 at half opacity. A wrong meta key would show that placeholder
 * for every event and look perfectly plausible — the same shape of bug the
 * Event Venue block carried (PR #16).
 *
 * The placeholder fixture is a PAGE, not an event with no meta. That is not a
 * harness workaround: on every blockendar_event edit screen the Date & Time
 * sidebar panel (src/editor/DateTimePanel.jsx) seeds today's date into the
 * edited record the moment it finds the start date empty, and useEntityProp
 * reads the edited record. In an event's own editor the placeholder can never
 * be seen. The only real user of that branch is the block dropped somewhere
 * without event meta — a page, a template — which is what fixture B is.
 *
 * Both formats are pinned on the blocks so every assertion is an exact string
 * whatever the site's formats are. Times are HH:MM: the meta sanitizer is
 * exact H:i and rejects HH:MM:SS to ''.
 *
 * The meta values are wall-clock dates and times in the event's own timezone,
 * not instants. The same event is therefore also opened with the browser
 * pinned to a zone on either side of the site's: a formatter that parses
 * the naked value in the browser's zone and formats it in the site's shifts
 * the time by the author's offset — and, from a zone ahead of the site, the
 * date too. Those two tests are the detectors for that bug.
 *
 * Opacity is read from the wrapper's inline style attribute, which is exactly
 * what useBlockProps sets. getComputedStyle would also see core's Spotlight
 * mode dimming unselected blocks to 0.2 — a persisted preference.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );
const { loginAsAdmin, openEditor } = require( './editor' );

const BLOCK =
	'<!-- wp:blockendar/event-datetime {"dateFormat":"Y-m-d","timeFormat":"H:i"} /-->';
const START = { date: '2027-03-09', time: '19:30' };
const END = { date: '2028-01-02', time: '21:00' };
const PLACEHOLDER_START = '2025-06-15';

let eventId;
let pageId;
const createdPosts = [];

test.beforeAll( () => {
	eventId = wpCliId( [
		'post',
		'create',
		'--post_type=blockendar_event',
		'--post_title=E2E Datetime Block Event',
		'--post_status=publish',
		`--post_content=${ BLOCK }`,
		'--porcelain',
	] );
	createdPosts.push( eventId );

	Object.entries( {
		blockendar_start_date: START.date,
		blockendar_start_time: START.time,
		blockendar_end_date: END.date,
		blockendar_end_time: END.time,
		blockendar_timezone: 'UTC',
		blockendar_status: 'scheduled',
	} ).forEach( ( [ key, value ] ) => {
		wpCli( [ 'post', 'meta', 'update', eventId, key, value ] );
	} );

	pageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Datetime Block Page',
		'--post_status=draft',
		`--post_content=${ BLOCK }`,
		'--porcelain',
	] );
	createdPosts.push( pageId );
} );

test.afterAll( () => {
	createdPosts.forEach( ( id ) =>
		wpCli( [ 'post', 'delete', id, '--force' ] )
	);
} );

/**
 * The authored values, exactly as pinned formats render them: `2027-03-09 @ 19:30`
 * and `2028-01-02 @ 21:00`. toHaveText() collapses whitespace and matches whole.
 *
 * @param {Object} block Locator for the block wrapper.
 */
async function expectAuthoredDateTimes( block ) {
	await expect(
		block.locator( '.blockendar-event-datetime__start' )
	).toHaveText( `${ START.date } @ ${ START.time }`, { timeout: 30000 } );
	await expect(
		block.locator( '.blockendar-event-datetime__end' )
	).toHaveText( `${ END.date } @ ${ END.time }`, { timeout: 30000 } );
}

test( 'an event shows its real start and end dates, not the placeholder', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );

	await loginAsAdmin( page );
	const canvas = await openEditor( page, eventId );

	const block = canvas.locator( '.blockendar-event-datetime' ).first();
	await expect( block ).toBeVisible( { timeout: 30000 } );

	// Real data first, so a failure reads as "wrong data" rather than "no data".
	// The end date is in another year, so __end renders a date and the end
	// meta keys are covered as well — a same-day fixture would only show a time.
	await expectAuthoredDateTimes( block );

	await expect( block ).not.toContainText( '2025' );

	// No inline opacity: useBlockProps sets one only in the placeholder state.
	await expect( block ).not.toHaveAttribute( 'style', /opacity/ );
} );

test( 'the block in a page shows the faded placeholder', async ( { page } ) => {
	test.setTimeout( 120000 );

	await loginAsAdmin( page );
	const canvas = await openEditor( page, pageId );

	const block = canvas.locator( '.blockendar-event-datetime' ).first();
	await expect( block ).toBeVisible( { timeout: 30000 } );

	// A guard, not a detector: this passes with the meta key broken too. It
	// holds the placeholder branch in place for the one context that can reach
	// it (see the header).
	await expect(
		block.locator( '.blockendar-event-datetime__start' )
	).toContainText( PLACEHOLDER_START, { timeout: 30000 } );

	await expect( block ).toHaveAttribute( 'style', /opacity:\s*0\.5/ );
} );

/*
 * Site timezone is UTC. Sydney is ahead of it (the date can shift back a day),
 * Los Angeles behind it (only the time shifts). Both must render the authored
 * values unchanged.
 */
for ( const zone of [ 'Australia/Sydney', 'America/Los_Angeles' ] ) {
	test.describe( `with the browser in ${ zone }`, () => {
		test.use( { timezoneId: zone } );

		test( 'an event still shows its authored dates and times', async ( {
			page,
		} ) => {
			test.setTimeout( 120000 );

			await loginAsAdmin( page );
			const canvas = await openEditor( page, eventId );

			const block = canvas
				.locator( '.blockendar-event-datetime' )
				.first();
			await expect( block ).toBeVisible( { timeout: 30000 } );

			await expectAuthoredDateTimes( block );
		} );
	} );
}
