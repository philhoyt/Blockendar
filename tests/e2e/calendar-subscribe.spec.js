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
		'blockendar_event_venue',
		'E2E Subscribe Venue',
		'--porcelain',
	] );

	typeTermId = wpCliId( [
		'term',
		'create',
		'blockendar_event_type',
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
		[ 'blockendar_event_venue', venueTermId ],
		[ 'blockendar_event_type', typeTermId ],
	].forEach( ( [ taxonomy, termId ] ) => {
		if ( termId ) {
			wpCli( [ 'term', 'delete', taxonomy, termId ] );
		}
	} );

	setSettings( { rest_public: true, rest_feed_token: '' } );
} );

test( 'both subscribe buttons render with the right schemes', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ plainPageId }` );

	const links = page.locator( SUBSCRIBE );
	await expect( links ).toHaveCount( 2 );

	const ical = links.filter( { hasText: 'iCalendar' } );
	const google = links.filter( { hasText: 'Google Calendar' } );

	await expect( ical ).toBeVisible();
	await expect( google ).toBeVisible();

	// Apple and friends follow webcal://; Google rejects it in its own UI but
	// requires it inside the cid parameter.
	const icalHref = await ical.getAttribute( 'href' );
	expect( icalHref.startsWith( 'webcal://' ) ).toBe( true );
	expect( icalHref ).toContain( 'format=ics' );

	const googleHref = await google.getAttribute( 'href' );
	expect(
		googleHref.startsWith( 'https://www.google.com/calendar/render?cid=' )
	).toBe( true );

	const cid = new URL( googleHref ).searchParams.get( 'cid' );
	expect( cid.startsWith( 'webcal://' ) ).toBe( true );
	expect( cid ).toContain( 'format=ics' );
} );

test( 'each subscribe button can be turned off on its own', async ( {
	page,
} ) => {
	const cases = [
		{ attrs: '"subscribeGoogle":false', expect: 'iCalendar' },
		{ attrs: '"subscribeIcal":false', expect: 'Google Calendar' },
	];

	for ( const { attrs, expect: label } of cases ) {
		const id = wpCliId( [
			'post',
			'create',
			'--post_type=page',
			'--post_title=E2E Subscribe One',
			'--post_status=publish',
			`--post_content=<!-- wp:blockendar/calendar-view {"showSubscribe":true,${ attrs }} /-->`,
			'--porcelain',
		] );

		try {
			await page.goto( `/?p=${ id }` );
			const links = page.locator( SUBSCRIBE );
			await expect( links ).toHaveCount( 1 );
			await expect( links.first() ).toHaveText( label );
		} finally {
			wpCli( [ 'post', 'delete', id, '--force' ] );
		}
	}
} );

test( 'the subscribe link carries the filters set on the block', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ filteredPageId }` );

	// subscribeLabel is now the lead-in text, not the button label.
	await expect(
		page.locator( '.blockendar-calendar-subscribe__label' )
	).toHaveText( 'Follow this calendar' );

	const link = page.locator( SUBSCRIBE ).filter( { hasText: 'iCalendar' } );
	await expect( link ).toBeVisible();

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

	const href = await page
		.locator( SUBSCRIBE )
		.filter( { hasText: 'iCalendar' } )
		.getAttribute( 'href' );

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
