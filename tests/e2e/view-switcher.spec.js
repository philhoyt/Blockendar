/**
 * The view switcher: list/grid selection carried in the URL.
 *
 * The control is built from links rather than buttons, so the JS-disabled cases
 * below are not a fallback path — they are the same code, and any divergence
 * between the two would mean something has crept in that should not have.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );

let pageId;
let mismatchPageId;
const created = [];

/**
 * Publish an indexed event on the given date.
 *
 * @param {string} title Event title.
 * @param {string} ymd   Y-m-d date.
 */
function createEvent( title, ymd ) {
	const id = wpCliId( [
		'post',
		'create',
		'--post_type=blockendar_event',
		`--post_title=${ title }`,
		'--post_status=publish',
		'--porcelain',
	] );

	Object.entries( {
		blockendar_start_date: ymd,
		blockendar_end_date: ymd,
		blockendar_start_time: '09:00',
		blockendar_end_time: '10:00',
		blockendar_timezone: 'UTC',
		blockendar_status: 'scheduled',
	} ).forEach( ( [ key, value ] ) => {
		wpCli( [ 'post', 'meta', 'update', id, key, value ] );
	} );

	wpCli( [ 'post', 'update', id, `--post_title=${ title }` ] );
	created.push( id );
}

test.beforeAll( () => {
	const year = new Date().getFullYear();
	createEvent( 'Switcher Event A', `${ year }-09-05` );
	createEvent( 'Switcher Event B', `${ year }-09-12` );

	pageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E View Switcher Page',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/query-filters --><!-- wp:blockendar/query-view-switcher /--><!-- wp:blockendar/events-query --><!-- wp:post-title {"isLink":true,"level":3} /--><!-- /wp:blockendar/events-query --><!-- /wp:blockendar/query-filters -->',
		'--porcelain',
	] );
	created.push( pageId );

	// The query is set to grid while the switcher still says its default is
	// list: the two were authored separately and could drift apart.
	mismatchPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Switcher Mismatch Page',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/query-filters --><!-- wp:blockendar/query-view-switcher {"defaultView":"list"} /--><!-- wp:blockendar/events-query {"displayLayout":{"type":"grid"}} --><!-- wp:post-title {"isLink":true,"level":3} /--><!-- /wp:blockendar/events-query --><!-- /wp:blockendar/query-filters -->',
		'--porcelain',
	] );
	created.push( mismatchPageId );
} );

test.afterAll( () => {
	created.forEach( ( id ) => wpCli( [ 'post', 'delete', id, '--force' ] ) );
} );

test( 'the results start in list view with no parameter in the URL', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-list-view/
	);
	expect( page.url() ).not.toContain( 'blockendar_view' );
} );

test( 'choosing grid re-renders the results and persists on reload', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();

	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);
	expect( page.url() ).toContain( 'blockendar_view=grid' );

	await page.reload();
	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);
} );

test( 'returning to the default view removes the parameter', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }&blockendar_view=grid` );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="list"]' )
		.click();

	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-list-view/
	);
	expect(
		page.url(),
		'the default view should leave a clean URL'
	).not.toContain( 'blockendar_view' );
} );

test( 'the active mode is marked for assistive technology', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	const list = page.locator(
		'.blockendar-view-switcher__button[data-view="list"]'
	);
	const grid = page.locator(
		'.blockendar-view-switcher__button[data-view="grid"]'
	);

	await expect( list ).toHaveAttribute( 'aria-current', 'true' );
	await expect( grid ).toHaveAttribute( 'aria-current', 'false' );

	await grid.click();

	await expect(
		page.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
	).toHaveAttribute( 'aria-current', 'true' );
} );

test( 'an unrecognised mode falls back to list rather than rendering it', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }&blockendar_view=masonry` );

	const classes = await page
		.locator( '.blockendar-events-query' )
		.getAttribute( 'class' );

	expect( classes ).toContain( 'is-list-view' );
	expect(
		classes,
		'the raw value must not reach the class attribute'
	).not.toContain( 'masonry' );
} );

test( 'switching view resets pagination', async ( { page } ) => {
	await page.goto( `/?p=${ pageId }&blockendar_page=2` );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();

	expect(
		page.url(),
		'page 4 of a list is not page 4 of a grid'
	).not.toContain( 'blockendar_page' );
} );

test( 'switching view swaps the layout without reloading the page', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	// Anything set on window is wiped by a real navigation, so its survival is
	// direct proof the swap happened in place.
	await page.evaluate( () => {
		window.__blockendarNoReloadSentinel = true;
	} );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();

	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);
	expect( page.url() ).toContain( 'blockendar_view=grid' );

	const survived = await page.evaluate(
		() => window.__blockendarNoReloadSentinel === true
	);

	expect( survived, 'a full page load would have cleared it' ).toBe( true );
} );

test( 'the back button restores the previous view', async ( { page } ) => {
	await page.goto( `/?p=${ pageId }` );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();
	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);

	await page.goBack();

	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-list-view/
	);
	await expect(
		page.locator( '.blockendar-view-switcher__button[data-view="list"]' )
	).toHaveAttribute( 'aria-current', 'true' );
} );

test( 'the grid column count is available while a list is showing', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	// Switching to grid on the client only swaps a class. If these custom
	// properties were emitted for grid alone, the first switch would silently
	// fall back to the stylesheet default instead of the editor's choice.
	const style = await page
		.locator( '.blockendar-events-query' )
		.getAttribute( 'style' );

	expect( style ).toContain( '--blockendar-columns:' );
} );

test( 'each mode renders a real icon', async ( { page } ) => {
	await page.goto( `/?p=${ pageId }` );

	await expect(
		page.locator(
			'.blockendar-view-switcher__button[data-view="list"] .blockendar-view-switcher__icon svg rect'
		)
	).toHaveCount( 3 );
	await expect(
		page.locator(
			'.blockendar-view-switcher__button[data-view="grid"] .blockendar-view-switcher__icon svg rect'
		)
	).toHaveCount( 4 );
} );

test( 'the switcher adopts the layout the query actually rendered', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ mismatchPageId }` );

	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);

	// Highlighting list against a grid of results is the visible symptom.
	await expect(
		page.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
	).toHaveAttribute( 'aria-current', 'true' );

	// And the other link has to carry the parameter, or it would claim to show
	// list while landing on a URL the server renders as grid.
	await expect(
		page.locator( '.blockendar-view-switcher__button[data-view="list"]' )
	).toHaveAttribute( 'href', /blockendar_view=list/ );
} );

test( 'an explicit choice is never second-guessed', async ( { page } ) => {
	await page.goto( `/?p=${ mismatchPageId }&blockendar_view=list` );

	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-list-view/
	);
	await expect(
		page.locator( '.blockendar-view-switcher__button[data-view="list"]' )
	).toHaveAttribute( 'aria-current', 'true' );
} );

test.describe( 'without JavaScript', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'the switcher works identically, being plain links', async ( {
		page,
	} ) => {
		await page.goto( `/?p=${ pageId }` );

		await page
			.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
			.click();

		await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
			/is-grid-view/
		);
		expect( page.url() ).toContain( 'blockendar_view=grid' );
	} );
} );
