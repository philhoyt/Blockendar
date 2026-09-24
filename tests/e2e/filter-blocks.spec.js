/**
 * End-to-end coverage for the filter block suite.
 *
 * The event-type filter shipped broken: its checkboxes submit
 * `blockendar_type[]`, which PHP delivers as an array, while FilterContext read
 * the value with sanitize_text_field() — a function that returns '' for arrays.
 * Selecting a type therefore filtered nothing. These tests drive the real form
 * in a browser so that failure mode cannot come back unnoticed.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );
const { daysFromNow } = require( './dates' );
const { ensureTerm } = require( './terms' );

const CONCERT = 'E2E Concert';
const WORKSHOP = 'E2E Workshop';

let pageId;
const created = [];

/**
 * Create a published event on a given date, assigned to an event_type term.
 *
 * @param {string} title Event title.
 * @param {string} ymd   Y-m-d date.
 * @param {string} type  event_type term name.
 * @return {string} Post ID.
 */
function createEvent( title, ymd, type ) {
	const id = wpCliId( [
		'post',
		'create',
		'--post_type=blockendar_event',
		`--post_title=${ title }`,
		'--post_status=publish',
		'--porcelain',
	] );

	const meta = {
		blockendar_start_date: ymd,
		blockendar_end_date: ymd,
		blockendar_start_time: '09:00',
		blockendar_end_time: '10:00',
		blockendar_timezone: 'UTC',
		blockendar_status: 'scheduled',
	};

	Object.entries( meta ).forEach( ( [ key, value ] ) => {
		wpCli( [ 'post', 'meta', 'update', id, key, value ] );
	} );

	wpCli( [ 'post', 'term', 'set', id, 'event_type', type ] );
	// Re-save so the index builder picks up meta and terms.
	wpCli( [ 'post', 'update', id, `--post_title=${ title }` ] );

	created.push( id );
	return id;
}

let concertTermId;

test.beforeAll( () => {
	concertTermId = ensureTerm( 'event_type', 'Concert', 'concert' );
	ensureTerm( 'event_type', 'Workshop', 'workshop' );

	createEvent( CONCERT, daysFromNow( 7 ), 'Concert' );
	createEvent( WORKSHOP, daysFromNow( 14 ), 'Workshop' );

	pageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Filters Page',
		'--post_status=publish',
		// events-query renders its inner blocks once per event, so the template
		// needs at least a title block or every result row comes out empty.
		'--post_content=<!-- wp:blockendar/filter-event-type /--><!-- wp:blockendar/events-query --><!-- wp:post-title {"isLink":true,"level":3} /--><!-- /wp:blockendar/events-query -->',
		'--porcelain',
	] );
	created.push( pageId );
} );

test.afterAll( () => {
	created.forEach( ( id ) => wpCli( [ 'post', 'delete', id, '--force' ] ) );
} );

test( 'unfiltered query lists every event', async ( { page } ) => {
	await page.goto( `/?p=${ pageId }` );

	await expect( page.getByText( CONCERT ) ).toBeVisible();
	await expect( page.getByText( WORKSHOP ) ).toBeVisible();
} );

test( 'checking a type actually filters the results', async ( { page } ) => {
	await page.goto( `/?p=${ pageId }` );

	// The block defaults to its dropdown style, so the choices live behind the
	// trigger and multi-select is committed with Apply rather than on change.
	await page.locator( '.blockendar-filter__trigger' ).click();
	await expect( page.locator( '.blockendar-filter__panel' ) ).toBeVisible();

	const concertBox = page
		.locator( '.blockendar-filter-event-type input[type="checkbox"]' )
		.first();
	await expect( concertBox ).toBeVisible();
	await concertBox.check();

	await Promise.all( [
		page.waitForURL( /blockendar_type/ ),
		page.locator( '.blockendar-filter__submit' ).click(),
	] );

	// This is the assertion the original bug would fail: before the
	// FilterContext fix the array param parsed to [], so both events remained.
	const listed = await page.locator( '.blockendar-events-query' ).innerText();

	expect(
		listed.includes( CONCERT ) !== listed.includes( WORKSHOP ),
		`exactly one event should remain, got: ${ listed }`
	).toBe( true );
} );

test( 'a type filter supplied as an array URL param is honoured', async ( {
	page,
} ) => {
	// The no-JS shape: repeated blockendar_type[] params.
	await page.goto( `/?p=${ pageId }&blockendar_type[]=${ concertTermId }` );

	const listed = await page.locator( '.blockendar-events-query' ).innerText();

	expect( listed ).toContain( CONCERT );
	expect( listed ).not.toContain( WORKSHOP );
} );

test( 'a type filter supplied as a comma string is honoured identically', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }&blockendar_type=${ concertTermId }` );

	const listed = await page.locator( '.blockendar-events-query' ).innerText();

	expect( listed ).toContain( CONCERT );
	expect( listed ).not.toContain( WORKSHOP );
} );

test( 'filter page loads without console or asset errors', async ( {
	page,
} ) => {
	const consoleErrors = [];
	const failed = [];

	page.on( 'console', ( msg ) => {
		if ( 'error' === msg.type() ) {
			consoleErrors.push( msg.text() );
		}
	} );
	page.on( 'response', ( res ) => {
		if ( res.status() >= 400 && res.url().includes( '/build/' ) ) {
			failed.push( `${ res.url() } — HTTP ${ res.status() }` );
		}
	} );

	await page.goto( `/?p=${ pageId }` );
	await expect(
		page.locator( '.blockendar-filter-event-type' )
	).toBeVisible();

	expect( failed, 'no failed asset requests' ).toEqual( [] );
	expect( consoleErrors, 'no console errors' ).toEqual( [] );
} );

/*
 * Regression guard for a WCAG 3.2.2 (On Input) failure.
 *
 * Both the list-style type filter and the venue filter used to navigate the
 * instant a box or radio changed, with the Apply button hidden by JavaScript.
 * That made the control commit itself before the visitor had finished
 * choosing, and for keyboard users it was worse: arrow keys move between
 * radios and each move fired `change`, so the page left before the intended
 * option was reached.
 */
test( 'a list-style filter waits for Apply instead of submitting on change', async ( {
	page,
} ) => {
	const listPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E List Filter Page',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/filter-event-type {"displayStyle":"list"} /--><!-- wp:blockendar/events-query --><!-- wp:post-title {"isLink":true,"level":3} /--><!-- /wp:blockendar/events-query -->',
		'--porcelain',
	] );
	created.push( listPageId );

	const navigations = [];
	page.on(
		'framenavigated',
		( f ) => f === page.mainFrame() && navigations.push( f.url() )
	);

	await page.goto( `/?p=${ listPageId }` );
	navigations.length = 0;

	// The Apply button must stay reachable — it used to be hidden outright.
	const apply = page.locator(
		'.blockendar-filter-event-type .blockendar-filter__submit'
	);
	await expect( apply ).toBeVisible();

	const box = page
		.locator( '.blockendar-filter-event-type input[type="checkbox"]' )
		.first();
	await box.check();
	await page.waitForTimeout( 500 );

	expect( navigations, 'ticking a box must not navigate on its own' ).toEqual(
		[]
	);
	await expect( box ).toBeChecked();

	// Apply is what commits it.
	await Promise.all( [
		page.waitForURL( /blockendar_type/ ),
		apply.click(),
	] );

	const listed = await page.locator( '.blockendar-events-query' ).innerText();
	expect(
		listed.includes( CONCERT ) !== listed.includes( WORKSHOP ),
		`exactly one event should remain, got: ${ listed }`
	).toBe( true );
} );
