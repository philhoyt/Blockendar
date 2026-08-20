/**
 * Regression coverage: interacting with a filter must not navigate unless the
 * visitor actually changed something.
 *
 * The date picker used to submit on every close, including a close with nothing
 * selected. That reloaded the page and returned the visitor to the top of the
 * results — the kind of failure that only shows up in a browser, and only when
 * the page is scrolled.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );

let pageId;

test.beforeAll( () => {
	// Filler sits *above* the filters as well as below. These tests assert the
	// page does not jump while the visitor is scrolled, and Playwright scrolls a
	// target into view before clicking it — so with the filters at the top of the
	// page the click itself moves the page, and the assertion ends up measuring
	// the test harness rather than the product.
	const filler =
		'<!-- wp:paragraph --><p>' +
		'Spacer. '.repeat( 60 ) +
		'</p><!-- /wp:paragraph -->';

	pageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E No Navigation Page',
		'--post_status=publish',
		'--post_content=' +
			filler.repeat( 3 ) +
			'<!-- wp:blockendar/filter-event-type /--><!-- wp:blockendar/filter-venue /--><!-- wp:blockendar/filter-date-range /-->' +
			filler.repeat( 6 ),
		'--porcelain',
	] );
} );

test.afterAll( () => {
	if ( pageId ) {
		wpCli( [ 'post', 'delete', pageId, '--force' ] );
	}
} );

/**
 * Scroll so the date filter sits comfortably inside the viewport and return the
 * settled scroll position. Interacting with an element that is already visible
 * gives Playwright no reason to scroll on our behalf.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<number>} The settled scroll position.
 */
async function openDatePopover( page ) {
	await page
		.locator( '.blockendar-filter-date-range .blockendar-filter__trigger' )
		.click();
	await expect(
		page.locator(
			'.blockendar-filter-date-range .blockendar-filter__panel'
		)
	).toBeVisible();
}

async function scrollFilterIntoView( page ) {
	await page
		.locator( '.blockendar-filter-date-range' )
		.scrollIntoViewIfNeeded();
	await page.evaluate( () => window.scrollBy( 0, -200 ) );
	await page.waitForTimeout( 200 );
	return page.evaluate( () => window.scrollY );
}

test( 'opening and closing the date filter leaves the page where it was', async ( {
	page,
} ) => {
	const navigations = [];
	page.on(
		'framenavigated',
		( f ) => f === page.mainFrame() && navigations.push( f.url() )
	);

	await page.goto( `/?p=${ pageId }` );

	const before = await scrollFilterIntoView( page );
	expect(
		before,
		'the page must actually be scrolled for this to prove anything'
	).toBeGreaterThan( 0 );

	navigations.length = 0;

	await openDatePopover( page );
	await expect( page.locator( '.flatpickr-calendar.inline' ) ).toBeVisible();
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 800 );

	expect(
		navigations,
		'closing an untouched filter must not navigate'
	).toEqual( [] );
	expect( await page.evaluate( () => window.scrollY ) ).toBe( before );
} );

test( 'picking dates does not navigate until Apply is pressed', async ( {
	page,
} ) => {
	const navigations = [];
	page.on(
		'framenavigated',
		( f ) => f === page.mainFrame() && navigations.push( f.url() )
	);

	await page.goto( `/?p=${ pageId }` );
	await openDatePopover( page );
	await expect( page.locator( '.flatpickr-calendar.inline' ) ).toBeVisible();

	navigations.length = 0;

	const days = page.locator(
		'.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay):not(.flatpickr-disabled)'
	);

	// Half a range: the visitor is mid-decision, so navigating would be wrong.
	await days.nth( 5 ).click();
	await page.waitForTimeout( 400 );
	expect( navigations, 'a half-finished range must not submit' ).toEqual(
		[]
	);

	// A complete range still waits for Apply, as the reference UI does.
	await days.nth( 12 ).click();
	await page.waitForTimeout( 600 );
	expect(
		navigations,
		'a complete range is committed by Apply, not on the second click'
	).toEqual( [] );
} );

test( 'Apply commits the chosen range to the URL', async ( { page } ) => {
	await page.goto( `/?p=${ pageId }` );
	await openDatePopover( page );
	await expect( page.locator( '.flatpickr-calendar.inline' ) ).toBeVisible();

	const days = page.locator(
		'.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay):not(.flatpickr-disabled)'
	);
	await days.nth( 5 ).click();
	await days.nth( 12 ).click();

	await page
		.locator( '.blockendar-filter-date-range .blockendar-filter__submit' )
		.click();

	await page.waitForURL( /blockendar_date_start=/, { timeout: 15000 } );
	expect( page.url() ).toMatch( /blockendar_date_end=/ );
} );
