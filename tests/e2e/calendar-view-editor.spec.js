/**
 * The Calendar View block inside the editor.
 *
 * The editor never mounts FullCalendar; it renders a settings placeholder whose
 * "Event types" and "Venues" rows are built from `getEntityRecords( 'taxonomy',
 * … )` and — this is the point — exist only when that fetch resolved to at
 * least one term. A misspelled taxonomy name returns null, `?? []` turns it
 * into an empty list, and the row is simply not rendered. So both tests here
 * are detectors: the filtered block must name its terms, and the unfiltered
 * block must show "All", which is only ever rendered after the fetch resolved.
 * Neither passes with the fetch broken.
 *
 * On the dev site other specs leave `event_type` terms behind, so the types
 * row would render there without this spec's fixture; no spec leaves
 * `event_venue` terms, so the venue path — and CI's fresh install — exercise
 * the gate from zero.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );
const { ensureTerm, deleteTerm } = require( './terms' );
const { loginAsAdmin, openEditor } = require( './editor' );

const VENUE = { name: 'E2E Calendar Venue', slug: 'e2e-calendar-venue' };
const TYPE = { name: 'E2E Calendar Type', slug: 'e2e-calendar-type' };

let venueId;
let typeId;
let filteredPageId;
let unfilteredPageId;
const createdPosts = [];

test.beforeAll( () => {
	venueId = ensureTerm( 'event_venue', VENUE.name, VENUE.slug );
	typeId = ensureTerm( 'event_type', TYPE.name, TYPE.slug );

	// IDs are interpolated unquoted: the block compares `t.id` to the attribute
	// with strict equality, and a quoted string never matches a number.
	filteredPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Calendar Editor Filtered',
		'--post_status=draft',
		`--post_content=<!-- wp:blockendar/calendar-view {"venueIds":[${ venueId }],"typeIds":[${ typeId }]} /-->`,
		'--porcelain',
	] );
	createdPosts.push( filteredPageId );

	unfilteredPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Calendar Editor Unfiltered',
		'--post_status=draft',
		'--post_content=<!-- wp:blockendar/calendar-view /-->',
		'--porcelain',
	] );
	createdPosts.push( unfilteredPageId );
} );

test.afterAll( () => {
	createdPosts.forEach( ( id ) =>
		wpCli( [ 'post', 'delete', id, '--force' ] )
	);
	deleteTerm( 'event_venue', venueId );
	deleteTerm( 'event_type', typeId );
} );

/**
 * The value cell of one placeholder row, found by its label.
 *
 * All four rows share the same classes and "Views" and "First day" come before
 * the two that matter, so the row has to be picked by its label text.
 *
 * @param {Object} canvas Locator scope for the editor canvas.
 * @param {string} label  Exact row label, e.g. 'Venues'.
 * @return {Object} Locator for the row's value.
 */
function placeholderValue( canvas, label ) {
	return canvas.locator(
		`.blockendar-calendar-placeholder__row:has(.blockendar-calendar-placeholder__label:text-is("${ label }")) .blockendar-calendar-placeholder__value`
	);
}

test( 'a filtered block names the venue and type it was given', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );

	await loginAsAdmin( page );
	const canvas = await openEditor( page, filteredPageId );

	await expect(
		canvas.locator( '.blockendar-calendar-placeholder' ).first()
	).toBeVisible( { timeout: 30000 } );

	await expect( placeholderValue( canvas, 'Venues' ) ).toContainText(
		VENUE.name,
		{ timeout: 30000 }
	);
	await expect( placeholderValue( canvas, 'Event types' ) ).toContainText(
		TYPE.name,
		{ timeout: 30000 }
	);
} );

test( 'an unfiltered block shows "All" for both taxonomies', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );

	await loginAsAdmin( page );
	const canvas = await openEditor( page, unfilteredPageId );

	await expect(
		canvas.locator( '.blockendar-calendar-placeholder' ).first()
	).toBeVisible( { timeout: 30000 } );

	// A detector, not a guard: "All" is rendered only inside a row that exists
	// only once the taxonomy fetch resolved. Break the taxonomy name and the
	// row — and this test — go with it.
	await expect( placeholderValue( canvas, 'Venues' ) ).toHaveText( 'All', {
		timeout: 30000,
	} );
	await expect( placeholderValue( canvas, 'Event types' ) ).toHaveText(
		'All',
		{ timeout: 30000 }
	);
} );
