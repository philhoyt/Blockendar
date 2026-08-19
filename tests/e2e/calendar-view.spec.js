/**
 * End-to-end coverage for the calendar-view block on the front end.
 *
 * The block loads FullCalendar and its view plugins through dynamic import(), so
 * these tests are the thing that proves the split chunks actually resolve in a
 * browser. A wrong publicPath would 404 the chunks and leave an empty container,
 * which no PHP-side test can detect.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli } = require( './wp-cli' );

let pageId;
let eventId;

test.beforeAll( () => {
	// A dated event so the index has a row for the calendar to fetch.
	eventId = wpCli( [
		'post',
		'create',
		'--post_type=blockendar_event',
		'--post_title=E2E Calendar Event',
		'--post_status=publish',
		'--porcelain',
	] ).match( /\d+/ )[ 0 ];

	const today = new Date();
	const day = new Date( today.getFullYear(), today.getMonth(), 15 );
	const ymd = day.toISOString().slice( 0, 10 );

	const meta = {
		blockendar_start_date: ymd,
		blockendar_end_date: ymd,
		blockendar_start_time: '09:00',
		blockendar_end_time: '10:00',
		blockendar_timezone: 'UTC',
		blockendar_status: 'scheduled',
	};

	Object.entries( meta ).forEach( ( [ key, value ] ) => {
		wpCli( [ 'post', 'meta', 'update', eventId, key, value ] );
	} );

	// Re-save so the index builder picks up the meta.
	wpCli( [ 'post', 'update', eventId, '--post_title=E2E Calendar Event' ] );

	pageId = wpCli( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Calendar Page',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/calendar-view /-->',
		'--porcelain',
	] ).match( /\d+/ )[ 0 ];
} );

test.afterAll( () => {
	[ pageId, eventId ].forEach( ( id ) => {
		if ( id ) {
			wpCli( [ 'post', 'delete', id, '--force' ] );
		}
	} );
} );

test( 'calendar renders after its chunks load, with no console or network errors', async ( {
	page,
} ) => {
	const consoleErrors = [];
	const failedRequests = [];

	page.on( 'console', ( msg ) => {
		if ( 'error' === msg.type() ) {
			consoleErrors.push( msg.text() );
		}
	} );
	page.on( 'requestfailed', ( req ) => {
		failedRequests.push( `${ req.url() } — ${ req.failure()?.errorText }` );
	} );
	page.on( 'response', ( res ) => {
		if ( res.status() >= 400 && res.url().includes( '/build/' ) ) {
			failedRequests.push( `${ res.url() } — HTTP ${ res.status() }` );
		}
	} );

	await page.goto( `/?p=${ pageId }` );

	// The block wrapper is server-rendered; the grid only appears once the
	// dynamically imported FullCalendar chunk has executed.
	await expect(
		page.locator( '.wp-block-blockendar-calendar-view' )
	).toBeVisible();

	await expect( page.locator( '.fc' ) ).toBeVisible( { timeout: 15000 } );
	await expect( page.locator( '.fc-toolbar-title' ) ).not.toBeEmpty();

	expect( failedRequests, 'no failed asset requests' ).toEqual( [] );
	expect( consoleErrors, 'no console errors' ).toEqual( [] );
} );

test( 'calendar fetches events from the REST endpoint and renders them', async ( {
	page,
} ) => {
	const calendarRequests = [];

	page.on( 'request', ( req ) => {
		if ( req.url().includes( '/blockendar/v1/calendar' ) ) {
			calendarRequests.push( req.url() );
		}
	} );

	await page.goto( `/?p=${ pageId }` );
	await expect( page.locator( '.fc' ) ).toBeVisible( { timeout: 15000 } );

	// Wait for the events request the calendar issues once mounted.
	await expect
		.poll( () => calendarRequests.length, { timeout: 15000 } )
		.toBeGreaterThan( 0 );

	await expect(
		page.locator( '.fc-event-title', { hasText: 'E2E Calendar Event' } )
	).toBeVisible( { timeout: 15000 } );
} );

test( 'clicking an event navigates to it without the interaction plugin', async ( {
	page,
} ) => {
	// eventClick is core FullCalendar behaviour, not something @fullcalendar/interaction
	// provides — that package is only needed for dateClick, selection and dragging.
	// This asserts the click handler still fires now that the dependency is gone.
	await page.goto( `/?p=${ pageId }` );
	await expect( page.locator( '.fc' ) ).toBeVisible( { timeout: 15000 } );

	const event = page
		.locator( '.fc-event', { hasText: 'E2E Calendar Event' } )
		.first();
	await expect( event ).toBeVisible( { timeout: 15000 } );

	await event.click();

	await page.waitForURL( /e2e-calendar-event/, { timeout: 15000 } );
	await expect( page.locator( 'body' ) ).toContainText(
		'E2E Calendar Event'
	);
} );

test( 'only the view plugins the calendar needs are downloaded', async ( {
	page,
} ) => {
	const chunks = [];

	page.on( 'response', ( res ) => {
		// Enqueued scripts carry a ?ver= cache buster, so compare against the
		// pathname rather than the raw URL.
		const path = new URL( res.url() ).pathname;
		if ( path.includes( '/build/' ) && path.endsWith( '.js' ) ) {
			chunks.push( path.split( '/' ).pop() );
		}
	} );

	await page.goto( `/?p=${ pageId }` );
	await expect( page.locator( '.fc' ) ).toBeVisible( { timeout: 15000 } );

	// The entry script must stay small; the bulk arrives as separate chunks.
	// If this ever collapses back to a single large view.js, the split regressed.
	expect(
		chunks.length,
		`chunks loaded: ${ chunks.join( ', ' ) }`
	).toBeGreaterThan( 1 );
} );
