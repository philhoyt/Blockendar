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
const { loginAsAdmin } = require( './editor' );

let pageId;
let eventId;
let futureEventId;

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

	/*
	 * A second event dated ahead of today. The one above is pinned to the 15th
	 * so it lands in the current month view, which means it is already in the
	 * past for most of the month — and the server-rendered fallback lists
	 * upcoming events only, so it needs something it will actually show.
	 */
	futureEventId = wpCli( [
		'post',
		'create',
		'--post_type=blockendar_event',
		'--post_title=E2E Calendar Future Event',
		'--post_status=publish',
		'--porcelain',
	] ).match( /\d+/ )[ 0 ];

	const future = new Date( Date.now() + 10 * 24 * 60 * 60 * 1000 );
	const futureYmd = future.toISOString().slice( 0, 10 );

	Object.entries( {
		...meta,
		blockendar_start_date: futureYmd,
		blockendar_end_date: futureYmd,
	} ).forEach( ( [ key, value ] ) => {
		wpCli( [ 'post', 'meta', 'update', futureEventId, key, value ] );
	} );

	wpCli( [
		'post',
		'update',
		futureEventId,
		'--post_title=E2E Calendar Future Event',
	] );

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
	[ pageId, eventId, futureEventId ].forEach( ( id ) => {
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

	// A block with the default views has no year view, so the multimonth
	// plugin must not be downloaded for it. The year-view spec asserts the
	// opposite for a block that enables it.
	expect( chunks ).toContain( 'fullcalendar-daygrid.js' );
	expect( chunks ).not.toContain( 'fullcalendar-multimonth.js' );
} );

/*
 * The block used to render an empty div and rely entirely on FullCalendar.
 * Both registered archive templates carry it as their only content block, so
 * a visitor without JavaScript — or one whose chunks fail to load — got a
 * page title and nothing else.
 */
test.describe( 'without JavaScript', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'the calendar falls back to a server-rendered list of events', async ( {
		page,
	} ) => {
		await page.goto( `/?p=${ pageId }` );

		const fallback = page.locator( '.blockendar-calendar-fallback' );
		await expect( fallback ).toBeVisible();

		// The seeded event is listed, with a working link to it.
		const link = fallback.locator( 'a', {
			hasText: 'E2E Calendar Future Event',
		} );
		await expect( link ).toBeVisible();
		await expect( link ).toHaveAttribute( 'href', /.+/ );

		// And the container is a named region rather than an anonymous div.
		const container = page.locator( '.wp-block-blockendar-calendar-view' );
		await expect( container ).toHaveAttribute( 'role', 'region' );
		await expect( container ).toHaveAttribute( 'aria-label', /.+/ );
	} );
} );

test( 'the fallback is removed once the calendar mounts', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	// The calendar itself proves the chunks resolved.
	await expect( page.locator( '.fc' ) ).toBeVisible( { timeout: 15000 } );

	await expect( page.locator( '.blockendar-calendar-fallback' ) ).toHaveCount(
		0
	);
} );

/**
 * Write the plugin settings option as JSON.
 *
 * @param {Object} settings Settings to store.
 */
function setSettings( settings ) {
	wpCli( [
		'option',
		'update',
		'blockendar_settings',
		JSON.stringify( settings ),
		'--format=json',
	] );
}

/*
 * With the REST API set to non-public the calendar route needs a logged-in
 * reader. Cookie authentication only counts when the request carries a wp_rest
 * nonce — without one WordPress treats even a logged-in visitor as anonymous —
 * so the block has to send it, or the people the setting is meant to let in
 * see an empty grid.
 */
test.describe( 'with a non-public REST API', () => {
	test.beforeAll( () => {
		setSettings( { rest_public: false, rest_feed_token: '' } );
	} );

	test.afterAll( () => {
		setSettings( { rest_public: true, rest_feed_token: '' } );
	} );

	test( 'a logged-in visitor still gets events on the calendar', async ( {
		page,
	} ) => {
		await loginAsAdmin( page );
		await page.goto( `/?p=${ pageId }` );

		await expect( page.locator( '.fc' ) ).toBeVisible( { timeout: 15000 } );
		await expect(
			page.locator( '.fc-event-title', { hasText: 'E2E Calendar Event' } )
		).toBeVisible( { timeout: 15000 } );
	} );

	test( 'a logged-out visitor is not handed a nonce', async ( { page } ) => {
		await page.goto( `/?p=${ pageId }` );

		await expect(
			page.locator( '.wp-block-blockendar-calendar-view' )
		).toBeVisible();
		await expect(
			page.locator( '.wp-block-blockendar-calendar-view' )
		).not.toHaveAttribute( 'data-rest-nonce' );
	} );
} );

/*
 * Once FullCalendar has mounted, the server-rendered list is gone. If the
 * events request then failed, the visitor was left with an empty grid and
 * nothing to say why, or what to do about it.
 */
test( 'a failed events request says so and offers a retry', async ( {
	page,
} ) => {
	let fail = true;

	await page.route( '**/blockendar/v1/calendar**', ( route ) =>
		fail ? route.fulfill( { status: 500, body: '{}' } ) : route.continue()
	);
	await page.route( /rest_route=.*blockendar.*calendar/, ( route ) =>
		fail ? route.fulfill( { status: 500, body: '{}' } ) : route.continue()
	);

	await page.goto( `/?p=${ pageId }` );
	await expect( page.locator( '.fc' ) ).toBeVisible( { timeout: 15000 } );

	const error = page.locator( '.blockendar-calendar-error' );
	await expect( error ).toBeVisible( { timeout: 15000 } );
	await expect( error ).toHaveAttribute( 'role', 'alert' );

	fail = false;
	await error.getByRole( 'button' ).click();

	await expect(
		page.locator( '.fc-event-title', { hasText: 'E2E Calendar Event' } )
	).toBeVisible( { timeout: 15000 } );
	await expect( error ).toHaveCount( 0 );
} );

/*
 * The fallback list is removed only when the calendar is ready to replace it.
 * A visitor whose FullCalendar chunks never arrive keeps the list.
 */
test( 'the fallback list stays when the calendar chunks fail to load', async ( {
	page,
} ) => {
	// Everything in build/ except the block's own entry script: the named
	// FullCalendar chunks and the numbered locale chunks.
	await page.route( /\/build\/(\d+|fullcalendar-[a-z]+)\.js/, ( route ) =>
		route.abort()
	);

	await page.goto( `/?p=${ pageId }` );

	await expect(
		page.locator( '.blockendar-calendar-fallback' )
	).toBeVisible();
	await expect( page.locator( '.fc' ) ).toHaveCount( 0 );
	await expect( page.locator( '.blockendar-calendar-error' ) ).toHaveCount(
		0
	);
} );

/*
 * Every other block follows the site's language. The calendar was given no
 * locale, so its month and day names and its buttons stayed in English.
 */
test.describe( 'on a German site', () => {
	test.beforeAll( () => {
		// WordPress refuses a site language it has no files for, so the pack
		// has to be installed; setting the option alone is silently ignored.
		// The calendar's own strings still come from FullCalendar.
		wpCli( [ 'language', 'core', 'install', 'de_DE' ] );
		wpCli( [ 'site', 'switch-language', 'de_DE' ] );
	} );

	test.afterAll( () => {
		wpCli( [ 'site', 'switch-language', 'en_US' ] );
		wpCli( [ 'language', 'core', 'uninstall', 'de_DE' ] );
	} );

	test( 'the calendar is in German', async ( { page } ) => {
		await page.goto( `/?p=${ pageId }` );
		await expect( page.locator( '.fc' ) ).toBeVisible( { timeout: 15000 } );

		const month = new Intl.DateTimeFormat( 'de', { month: 'long' } ).format(
			new Date()
		);

		await expect( page.locator( '.fc-toolbar-title' ) ).toContainText(
			month
		);
		await expect( page.locator( '.fc-today-button' ) ).toHaveText(
			'Heute'
		);
		await expect(
			page.locator( '.fc-col-header-cell' ).first()
		).toContainText( /^(Mo|So)/ );
	} );
} );
