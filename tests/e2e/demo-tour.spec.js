/**
 * End-to-end coverage for the companion demo plugin's guided tour.
 *
 * The tour pages are serialized block markup built by string concatenation in
 * PHP. An unbalanced delimiter or a block used outside the context it needs
 * renders as an empty page rather than an error, so only a browser check proves
 * the pages actually produce output.
 *
 * The demo plugin is mapped into wp-env but deliberately not listed under
 * "plugins", because it seeds on activation and would otherwise pollute every
 * other spec. This file activates it, seeds, and tears the content down again.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli } = require( './wp-cli' );

// `label` is the nav button text, which is shorter than the page title.
const TOUR = [
	{ slug: 'blockendar-demo', title: 'Blockendar Demo', label: 'Start' },
	{ slug: 'calendar', title: 'Calendar', label: 'Calendar' },
	{ slug: 'find-an-event', title: 'Find an Event', label: 'Find an Event' },
	{
		slug: 'anatomy-of-an-event',
		title: 'Anatomy of an Event',
		label: 'Event Anatomy',
	},
	{ slug: 'venues-and-maps', title: 'Venues & Maps', label: 'Venues & Maps' },
	{ slug: 'subscribe', title: 'Subscribe', label: 'Subscribe' },
];

test.beforeAll( () => {
	wpCli( [ 'plugin', 'activate', 'blockendar-demo' ] );
	// Activation only sets a flag; the seed runs on the next init, which the
	// command below triggers. Calling seed explicitly is then a no-op, so this
	// is safe either way.
	wpCli( [ 'blockendar-demo', 'seed' ] );
} );

test.afterAll( () => {
	wpCli( [ 'blockendar-demo', 'reset' ] );
	wpCli( [ 'plugin', 'deactivate', 'blockendar-demo' ] );
} );

test.describe( 'demo guided tour', () => {
	for ( const { slug, title, label } of TOUR ) {
		test( `${ slug } renders with tour navigation`, async ( { page } ) => {
			const response = await page.goto( `/${ slug }/` );
			expect( response.status() ).toBe( 200 );

			await expect(
				page.locator( 'h1, h2' ).filter( { hasText: title } ).first()
			).toBeVisible();

			// Every tour page, this one included, so the row keeps its shape
			// as you move through the tour.
			const nav = page.locator(
				'.wp-block-buttons .wp-block-button__link'
			);
			await expect( nav ).toHaveCount( TOUR.length );

			// The page you are on is the one filled button. Matched on the
			// label rather than the href: the landing page is the site's front
			// page, so its permalink is "/", not "/blockendar-demo/".
			const current = page.locator( '.wp-block-button.is-style-fill' );
			await expect( current ).toHaveCount( 1 );
			await expect( current ).toHaveText( label );
		} );
	}

	test( 'the page body renders at wide width', async ( { page } ) => {
		await page.goto( '/calendar/' );

		const calendar = page.locator( '.wp-block-blockendar-calendar-view' );
		await expect( calendar ).toBeVisible();
		await expect( calendar ).toHaveClass( /alignwide/ );

		// The point of the alignment: the calendar is wider than the text
		// column it used to be squeezed into.
		const heading = page.locator( 'h2' ).first();
		const calendarBox = await calendar.boundingBox();
		const headingBox = await heading.boundingBox();

		expect( calendarBox.width ).toBeGreaterThan( headingBox.width );
	} );

	test( 'the filters sit on one row above the query', async ( { page } ) => {
		await page.goto( '/find-an-event/' );

		const type = page.locator( '.blockendar-filter-event-type' );
		const venue = page.locator( '.blockendar-filter-venue' );
		const dates = page.locator( '.blockendar-filter-date-range' );

		await expect( type ).toBeVisible();
		await expect( venue ).toBeVisible();
		await expect( dates ).toBeVisible();

		// Same row means the same vertical position, not stacked.
		const boxes = await Promise.all( [
			type.boundingBox(),
			venue.boundingBox(),
			dates.boundingBox(),
		] );

		for ( const box of boxes.slice( 1 ) ) {
			expect( Math.abs( box.y - boxes[ 0 ].y ) ).toBeLessThan( 4 );
		}
	} );

	test( 'every event in a listing has a thumbnail', async ( { page } ) => {
		await page.goto( '/find-an-event/' );

		const items = page.locator( '.blockendar-events-query__item' );
		const count = await items.count();
		expect( count ).toBeGreaterThan( 0 );

		for ( let i = 0; i < count; i++ ) {
			await expect(
				items.nth( i ).locator( 'img' ).first()
			).toBeVisible();
		}
	} );

	test( 'the calendar page renders a populated calendar', async ( {
		page,
	} ) => {
		await page.goto( '/calendar/' );

		const calendar = page.locator( '.fc' );
		await expect( calendar ).toBeVisible( { timeout: 15000 } );
		await expect( page.locator( '.fc-event' ).first() ).toBeVisible( {
			timeout: 15000,
		} );
	} );

	test( 'the anatomy page renders the single-event blocks', async ( {
		page,
	} ) => {
		await page.goto( '/anatomy-of-an-event/' );

		// These blocks only render inside events-query, which is the whole point
		// of building the page that way.
		await expect(
			page.locator( '.wp-block-blockendar-event-datetime' ).first()
		).toBeVisible();
		await expect(
			page.locator( '.wp-block-blockendar-events-query li' ).first()
		).toBeVisible();
	} );

	test( 'find-an-event narrows results when a type filter is applied', async ( {
		page,
	} ) => {
		await page.goto( '/find-an-event/' );

		const items = page.locator( '.wp-block-blockendar-events-query li' );
		await expect( items.first() ).toBeVisible();
		const unfiltered = await items.count();
		expect( unfiltered ).toBeGreaterThan( 0 );

		// Resolve a real term ID rather than guessing.
		const termId = wpCli( [
			'term',
			'list',
			'event_type',
			'--slug=tech',
			'--field=term_id',
		] ).trim();
		expect( termId ).toMatch( /^\d+$/ );

		await page.goto( `/find-an-event/?blockendar_type_tour=${ termId }` );

		const filtered = await page
			.locator( '.wp-block-blockendar-events-query li' )
			.count();

		expect( filtered ).toBeGreaterThan( 0 );
		expect( filtered ).toBeLessThanOrEqual( unfiltered );
	} );
} );
