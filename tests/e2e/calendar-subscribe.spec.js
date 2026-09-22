/**
 * End-to-end coverage for the calendar-view subscribe button.
 *
 * The href is assembled server-side from resolved block attributes, and the
 * button is suppressed whenever the feed is not publicly readable. Both are
 * security-relevant: rendering the link on a token-gated site would put a
 * bearer credential into public markup. Only a real page render proves what
 * actually reaches the browser.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );

const SUBSCRIBE = '.blockendar-calendar-subscribe__link';

let plainPageId;
let filteredPageId;
let venueTermId;
let typeTermId;

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

test.beforeAll( () => {
	setSettings( { rest_public: true, rest_feed_token: '' } );

	venueTermId = wpCliId( [
		'term',
		'create',
		'event_venue',
		'E2E Subscribe Venue',
		'--porcelain',
	] );

	typeTermId = wpCliId( [
		'term',
		'create',
		'event_type',
		'E2E Subscribe Type',
		'--porcelain',
	] );

	plainPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Subscribe Plain',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/calendar-view {"showSubscribe":true} /-->',
		'--porcelain',
	] );

	const filtered = `<!-- wp:blockendar/calendar-view {"showSubscribe":true,"subscribeLabel":"Follow this calendar","venueIds":[${ venueTermId }],"typeIds":[${ typeTermId }],"featuredOnly":true} /-->`;

	filteredPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Subscribe Filtered',
		'--post_status=publish',
		`--post_content=${ filtered }`,
		'--porcelain',
	] );
} );

test.afterAll( () => {
	[ plainPageId, filteredPageId ].forEach( ( id ) => {
		if ( id ) {
			wpCli( [ 'post', 'delete', id, '--force' ] );
		}
	} );

	[
		[ 'event_venue', venueTermId ],
		[ 'event_type', typeTermId ],
	].forEach( ( [ taxonomy, termId ] ) => {
		if ( termId ) {
			wpCli( [ 'term', 'delete', taxonomy, termId ] );
		}
	} );

	setSettings( { rest_public: true, rest_feed_token: '' } );
} );

test( 'the subscribe button links to a webcal feed', async ( { page } ) => {
	await page.goto( `/?p=${ plainPageId }` );

	const link = page.locator( SUBSCRIBE );
	await expect( link ).toBeVisible();
	await expect( link ).toHaveText( 'Subscribe' );

	const href = await link.getAttribute( 'href' );

	expect( href.startsWith( 'webcal://' ) ).toBe( true );
	expect( href ).toContain( 'blockendar/v1/calendar' );
	expect( href ).toContain( 'format=ics' );
} );

test( 'the subscribe link carries the filters set on the block', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ filteredPageId }` );

	const link = page.locator( SUBSCRIBE );
	await expect( link ).toBeVisible();
	await expect( link ).toHaveText( 'Follow this calendar' );

	const href = await link.getAttribute( 'href' );

	// Comma-joined, matching how the REST route parses them back.
	expect( href ).toContain( `venue=${ venueTermId }` );
	expect( href ).toContain( `type=${ typeTermId }` );
	expect( href ).toContain( 'featured=1' );
} );

test( 'the webcal URL resolves to a real iCalendar feed over http', async ( {
	page,
	request,
} ) => {
	await page.goto( `/?p=${ plainPageId }` );

	const href = await page.locator( SUBSCRIBE ).getAttribute( 'href' );

	// Calendar clients rewrite webcal:// to http(s) before fetching.
	const response = await request.get(
		href.replace( /^webcal:\/\//, 'http://' )
	);

	expect( response.status() ).toBe( 200 );
	expect( response.headers()[ 'content-type' ] ).toContain( 'text/calendar' );

	const body = await response.text();

	expect( body.startsWith( 'BEGIN:VCALENDAR' ) ).toBe( true );
	expect( body ).toContain( 'REFRESH-INTERVAL' );
} );

test( 'no subscribe button is rendered when the feed is not public', async ( {
	page,
} ) => {
	setSettings( { rest_public: false, rest_feed_token: 'E2E_SECRET_TOKEN' } );

	try {
		await page.goto( `/?p=${ plainPageId }` );

		await expect(
			page.locator( '.wp-block-blockendar-calendar-view' )
		).toBeVisible();
		await expect( page.locator( SUBSCRIBE ) ).toHaveCount( 0 );

		// The token must not reach public markup by any route.
		expect( await page.content() ).not.toContain( 'E2E_SECRET_TOKEN' );
	} finally {
		setSettings( { rest_public: true, rest_feed_token: '' } );
	}
} );

test( 'no subscribe button is rendered when the toggle is off', async ( {
	page,
} ) => {
	const offPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Subscribe Off',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/calendar-view /-->',
		'--porcelain',
	] );

	try {
		await page.goto( `/?p=${ offPageId }` );

		await expect(
			page.locator( '.wp-block-blockendar-calendar-view' )
		).toBeVisible();
		await expect( page.locator( SUBSCRIBE ) ).toHaveCount( 0 );
	} finally {
		wpCli( [ 'post', 'delete', offPageId, '--force' ] );
	}
} );
