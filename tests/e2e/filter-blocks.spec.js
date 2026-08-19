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

/**
 * Return an event_type term ID, creating the term only if it is missing.
 *
 * `wp term create` errors on an existing name, which would strand the suite
 * after any interrupted run.
 *
 * @param {string} name Term name.
 * @return {string} Term ID.
 */
function ensureTerm( name ) {
	const existing = wpCli( [
		'term',
		'list',
		'event_type',
		`--name=${ name }`,
		'--field=term_id',
	] ).trim();

	if ( existing ) {
		return existing;
	}

	return wpCliId( [ 'term', 'create', 'event_type', name, '--porcelain' ] );
}

let concertTermId;

test.beforeAll( () => {
	concertTermId = ensureTerm( 'Concert' );
	ensureTerm( 'Workshop' );

	const year = new Date().getFullYear();
	createEvent( CONCERT, `${ year }-09-10`, 'Concert' );
	createEvent( WORKSHOP, `${ year }-09-20`, 'Workshop' );

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

	const concertBox = page
		.locator( '.blockendar-filter-event-type input[type="checkbox"]' )
		.first();
	await expect( concertBox ).toBeVisible();

	// view.js auto-submits on change and navigates.
	await Promise.all( [
		page.waitForURL( /blockendar_type/ ),
		concertBox.check(),
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
