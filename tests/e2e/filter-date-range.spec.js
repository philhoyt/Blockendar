/**
 * End-to-end coverage for the date-range filter's progressive enhancement.
 *
 * Three of the bugs this covers were invisible without a browser:
 * - the block's style.css was never compiled, because index.js did not import it,
 *   so block.json pointed at a style-index.css that did not exist;
 * - the end field's `hidden` attribute was overridden by `display: flex` from
 *   that stylesheet, so both fields showed once the CSS started loading;
 * - Flatpickr's own stylesheet was never imported, so its calendar rendered
 *   full-size at the end of the document.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );

let pageId;

/**
 * Open the date filter's popover, which now wraps the fields.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function openDatePopover( page ) {
	await page.locator( '.blockendar-filter__trigger' ).click();
	await expect( page.locator( '.blockendar-filter__panel' ) ).toBeVisible();
}

test.beforeAll( () => {
	pageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Date Range Page',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/filter-date-range /-->',
		'--porcelain',
	] );
} );

test.afterAll( () => {
	if ( pageId ) {
		wpCli( [ 'post', 'delete', pageId, '--force' ] );
	}
} );

test( 'the block stylesheet is enqueued', async ( { page } ) => {
	await page.goto( `/?p=${ pageId }` );

	const sheets = await page.evaluate(
		() =>
			[
				...document.querySelectorAll(
					'style[id*=blockendar-filter-date-range], link[href*=filter-date-range]'
				),
			].length
	);

	expect(
		sheets,
		'block.json declares style-index.css, so it must actually be built and enqueued'
	).toBeGreaterThan( 0 );
} );

test.describe( 'without JavaScript', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'both fields render as a plain, submittable HTML form', async ( {
		page,
	} ) => {
		await page.goto( `/?p=${ pageId }` );

		const fields = page.locator( '.blockendar-filter-date-range__field' );
		await expect( fields ).toHaveCount( 2 );
		await expect( fields.nth( 0 ) ).toBeVisible();
		await expect( fields.nth( 1 ) ).toBeVisible();

		// Both labels stay put: with no range picker there is nothing to relabel.
		const labels = await page
			.locator( '.blockendar-filter-date-range label' )
			.allTextContents();
		expect( labels.map( ( s ) => s.trim() ) ).toEqual( [ 'From', 'To' ] );

		// The Apply button is the only way to submit without JS, so it must show.
		await expect(
			page.locator( '.blockendar-filter__submit' )
		).toBeVisible();
	} );
} );

test( 'the panel presents the calendar itself, not fields that open one', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );
	await openDatePopover( page );

	// The reference this follows shows a month grid on open. Fields that open a
	// second layer made the panel taller than it could show, forcing a scroll to
	// reach the grid.
	await expect( page.locator( '.flatpickr-calendar.inline' ) ).toBeVisible( {
		timeout: 15000,
	} );

	const visibleDateFields = page.locator(
		'.blockendar-filter-date-range__field:not([hidden])'
	);
	await expect( visibleDateFields ).toHaveCount( 0 );

	// Both named inputs stay in the DOM: they carry the values the form submits.
	await expect(
		page.locator( 'input[name="blockendar_date_start"]' )
	).toHaveCount( 1 );
	await expect(
		page.locator( 'input[name="blockendar_date_end"]' )
	).toHaveCount( 1 );
} );

test( 'the panel is sized to the calendar and does not scroll', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );
	await openDatePopover( page );
	await expect( page.locator( '.flatpickr-calendar.inline' ) ).toBeVisible( {
		timeout: 15000,
	} );

	const panel = page.locator( '.blockendar-filter__panel' );

	const scrolls = await panel.evaluate(
		( n ) => n.scrollHeight > n.clientHeight + 1
	);
	expect(
		scrolls,
		'the month grid must be reachable without scrolling'
	).toBe( false );
} );

test( 'the calendar mounts inside the block and is not a full-page blob', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );
	await openDatePopover( page );

	const cal = page.locator( '.flatpickr-calendar.inline' );
	await expect( cal ).toBeVisible( { timeout: 15000 } );

	// Mounted inside the block rather than at document.body — this is what lets
	// the block's own Flatpickr theme overrides apply. Checked by ancestry, not
	// by direct parent: static mode wraps the input in .flatpickr-wrapper and
	// puts the calendar there, so the immediate parent is Flatpickr's own node.
	const insideBlock = await cal.evaluate(
		( n ) => !! n.closest( '.blockendar-filter-date-range' )
	);
	expect( insideBlock ).toBe( true );

	const insidePanel = await cal.evaluate(
		( n ) => !! n.closest( '.blockendar-filter__panel' )
	);
	expect( insidePanel, 'the calendar belongs inside the popover panel' ).toBe(
		true
	);

	/*
	 * Without its stylesheet the calendar rendered as a sprawling unstyled block
	 * hundreds of pixels tall. Width is no longer the tell — the grid is meant to
	 * fill the panel — so this checks that it tracks the panel rather than
	 * exceeding it, and that its height stays that of a month grid.
	 */
	const panelBox = await page
		.locator( '.blockendar-filter__panel' )
		.boundingBox();
	const box = await cal.boundingBox();

	expect( box.height, `calendar height was ${ box?.height }` ).toBeLessThan(
		500
	);
	expect(
		box.width,
		`calendar ${ box?.width } vs panel ${ panelBox?.width }`
	).toBeLessThanOrEqual( panelBox.width + 1 );
} );
