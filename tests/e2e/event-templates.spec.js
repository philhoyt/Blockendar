/**
 * Per-layout event templates.
 *
 * When list and grid use different templates the server renders both and hides
 * the inactive one, so the view switcher can still swap layouts without a round
 * trip. A query with a single template must not pay that cost.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );

let splitPageId;
let sharedPageId;
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
	createEvent( 'Template Event A', `${ year }-10-05` );
	createEvent( 'Template Event B', `${ year }-10-12` );

	// List shows the title, grid shows the date — deliberately disjoint so each
	// layout is identifiable from its content alone.
	const split =
		'<!-- wp:blockendar/query-filters -->' +
		'<!-- wp:blockendar/query-view-switcher /-->' +
		'<!-- wp:blockendar/events-query -->' +
		'<!-- wp:blockendar/event-template {"layout":"list"} -->' +
		'<!-- wp:post-title {"isLink":true,"level":3} /-->' +
		'<!-- /wp:blockendar/event-template -->' +
		'<!-- wp:blockendar/event-template {"layout":"grid"} -->' +
		'<!-- wp:blockendar/event-datetime /-->' +
		'<!-- /wp:blockendar/event-template -->' +
		'<!-- /wp:blockendar/events-query -->' +
		'<!-- /wp:blockendar/query-filters -->';

	splitPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Split Template Page',
		'--post_status=publish',
		`--post_content=${ split }`,
		'--porcelain',
	] );
	created.push( splitPageId );

	const shared =
		'<!-- wp:blockendar/query-filters -->' +
		'<!-- wp:blockendar/query-view-switcher /-->' +
		'<!-- wp:blockendar/events-query -->' +
		'<!-- wp:post-title {"isLink":true,"level":3} /-->' +
		'<!-- /wp:blockendar/events-query -->' +
		'<!-- /wp:blockendar/query-filters -->';

	sharedPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Shared Template Page',
		'--post_status=publish',
		`--post_content=${ shared }`,
		'--porcelain',
	] );
	created.push( sharedPageId );
} );

test.afterAll( () => {
	created.forEach( ( id ) => wpCli( [ 'post', 'delete', id, '--force' ] ) );
} );

test( 'both layouts are rendered, with only the active one visible', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ splitPageId }` );

	const first = page.locator( '.blockendar-events-query__item' ).first();

	await expect( first.locator( '[data-layout="list"]' ) ).toBeVisible();
	await expect( first.locator( '[data-layout="grid"]' ) ).toBeHidden();
} );

test( 'the inactive layout is hidden from assistive technology too', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ splitPageId }` );

	// The hidden attribute, not just a display rule — CSS alone would leave the
	// duplicate content exposed to screen readers.
	await expect(
		page
			.locator( '.blockendar-events-query__layout[data-layout="grid"]' )
			.first()
	).toHaveAttribute( 'hidden', /.*/ );
} );

test( 'switching layout swaps templates without reloading', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ splitPageId }` );

	await page.evaluate( () => {
		window.__blockendarTemplateSentinel = true;
	} );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();

	const first = page.locator( '.blockendar-events-query__item' ).first();

	await expect( first.locator( '[data-layout="grid"]' ) ).toBeVisible();
	await expect( first.locator( '[data-layout="list"]' ) ).toBeHidden();

	const survived = await page.evaluate(
		() => window.__blockendarTemplateSentinel === true
	);

	expect( survived, 'a full page load would have cleared it' ).toBe( true );
} );

test( 'the server picks the right template with no JavaScript', async ( {
	browser,
} ) => {
	const context = await browser.newContext( { javaScriptEnabled: false } );
	const page = await context.newPage();

	await page.goto( `/?p=${ splitPageId }&blockendar_view=grid` );

	const first = page.locator( '.blockendar-events-query__item' ).first();

	await expect( first.locator( '[data-layout="grid"]' ) ).toBeVisible();
	await expect( first.locator( '[data-layout="list"]' ) ).toBeHidden();

	await context.close();
} );

test( 'a query with one shared template renders each event once', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ sharedPageId }` );

	await expect(
		page.locator( '.blockendar-events-query__layout' ),
		'the duplicate-markup cost is only paid when templates actually differ'
	).toHaveCount( 0 );
} );
