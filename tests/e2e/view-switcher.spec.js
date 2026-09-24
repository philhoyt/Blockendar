/**
 * The view switcher: list/grid selection carried in the URL.
 *
 * The control is built from links rather than buttons, so the JS-disabled cases
 * below are not a fallback path — they are the same code, and any divergence
 * between the two would mean something has crept in that should not have.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );
const { daysFromNow } = require( './dates' );
const { ensureTerm } = require( './terms' );

let pageId;
let mismatchPageId;
let pagedPageId;
let typePageId;
let twoGroupsPageId;
const created = [];

const QUERY_TEMPLATE = '<!-- wp:post-title {"isLink":true,"level":3} /-->';
const PAGED_QUERY =
	'<!-- wp:blockendar/events-query {"perPage":1,"showPagination":true} -->' +
	QUERY_TEMPLATE +
	'<!-- /wp:blockendar/events-query -->';

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
	return id;
}

/**
 * Open the date filter's popover and submit the given start date through the
 * form's own Apply button — the same native GET submit a visitor performs.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {string}                          ymd  Y-m-d start date.
 */
async function submitDateFilter( page, ymd ) {
	const trigger = page.locator(
		'.blockendar-filter-date-range .blockendar-filter__trigger'
	);

	// Without JavaScript the panel is already open and the trigger is hidden.
	if ( await trigger.isVisible() ) {
		await trigger.click();
	}

	await page
		.locator( 'input[name="blockendar_date_start"]' )
		.evaluate( ( input, value ) => {
			input.value = value;
		}, ymd );

	await Promise.all( [
		page.waitForLoadState( 'load' ),
		page
			.locator(
				'.blockendar-filter-date-range .blockendar-filter__submit'
			)
			.click(),
	] );
}

const NEXT_LINK = '.blockendar-events-query__pagination .next';
const HIDDEN_VIEW = 'form input[type="hidden"][name="blockendar_view"]';

test.beforeAll( () => {
	const eventA = createEvent( 'Switcher Event A', daysFromNow( 7 ) );
	createEvent( 'Switcher Event B', daysFromNow( 14 ) );

	ensureTerm( 'event_type', 'Switcher Type', 'switcher-type' );
	wpCli( [ 'post', 'term', 'set', eventA, 'event_type', 'Switcher Type' ] );
	// Re-save so the index builder picks up the term.
	wpCli( [ 'post', 'update', eventA, '--post_title=Switcher Event A' ] );

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
		'--post_content=<!-- wp:blockendar/query-filters --><!-- wp:blockendar/query-view-switcher {"defaultView":"list"} /--><!-- wp:blockendar/events-query {"displayLayout":{"type":"grid"},"perPage":1,"showPagination":true} -->' +
			QUERY_TEMPLATE +
			'<!-- /wp:blockendar/events-query --><!-- /wp:blockendar/query-filters -->',
		'--porcelain',
	] );
	created.push( mismatchPageId );

	// One event per page, so every navigation away from page one is real, plus
	// a date filter whose form and clear link must carry the view too.
	pagedPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Switcher Paged Page',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/query-filters --><!-- wp:blockendar/query-view-switcher /--><!-- wp:blockendar/filter-date-range /-->' +
			PAGED_QUERY +
			'<!-- /wp:blockendar/query-filters -->',
		'--porcelain',
	] );
	created.push( pagedPageId );

	// The dropdown type filter commits with a native submit rather than the
	// auto-submit its list style uses.
	typePageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Switcher Type Filter Page',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/query-filters --><!-- wp:blockendar/query-view-switcher /--><!-- wp:blockendar/filter-event-type {"displayStyle":"dropdown","showEmptyTerms":true} /-->' +
			PAGED_QUERY +
			'<!-- /wp:blockendar/query-filters -->',
		'--porcelain',
	] );
	created.push( typePageId );

	twoGroupsPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Switcher Two Groups Page',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/query-filters {"queryId":"one"} --><!-- wp:blockendar/query-view-switcher /-->' +
			PAGED_QUERY +
			'<!-- /wp:blockendar/query-filters --><!-- wp:blockendar/query-filters {"queryId":"two"} --><!-- wp:blockendar/query-view-switcher /-->' +
			PAGED_QUERY +
			'<!-- /wp:blockendar/query-filters -->',
		'--porcelain',
	] );
	created.push( twoGroupsPageId );
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
	await page.goto( `/?p=${ pagedPageId }` );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();
	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);
	await expect( page.locator( NEXT_LINK ) ).toHaveAttribute(
		'href',
		/blockendar_view=grid/
	);
	await expect( page.locator( HIDDEN_VIEW ) ).toHaveValue( 'grid' );

	await page.goBack();

	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-list-view/
	);
	await expect(
		page.locator( '.blockendar-view-switcher__button[data-view="list"]' )
	).toHaveAttribute( 'aria-current', 'true' );

	// The controls follow the address bar back too, or the next click would
	// navigate to the view the visitor just left.
	await expect( page.locator( NEXT_LINK ) ).not.toHaveAttribute(
		'href',
		/blockendar_view/
	);
	await expect( page.locator( HIDDEN_VIEW ) ).toHaveCount( 0 );
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

	// Adopting the rendered layout as the default is not a change of view, so
	// the pagination keeps the clean URLs the server gave it.
	await expect( page.locator( NEXT_LINK ) ).not.toHaveAttribute(
		'href',
		/blockendar_view/
	);
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

test( 'pagination keeps the chosen view after an in-place switch', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pagedPageId }` );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();
	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);

	// The server rendered this link before the swap; the script must have
	// brought it up to date, or page two comes back as a list.
	await expect( page.locator( NEXT_LINK ) ).toHaveAttribute(
		'href',
		/blockendar_view=grid/
	);

	await page.locator( NEXT_LINK ).click();
	await page.waitForLoadState( 'load' );

	expect( page.url() ).toContain( 'blockendar_view=grid' );
	expect( page.url() ).toContain( 'blockendar_page=2' );
	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);
} );

test( 'returning to the default view cleans the pagination and the form too', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pagedPageId }&blockendar_view=grid` );

	// Rendered from a URL that names the view, so both carry it at first.
	await expect( page.locator( NEXT_LINK ) ).toHaveAttribute(
		'href',
		/blockendar_view=grid/
	);
	await expect( page.locator( HIDDEN_VIEW ) ).toHaveValue( 'grid' );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="list"]' )
		.click();
	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-list-view/
	);

	await expect( page.locator( NEXT_LINK ) ).not.toHaveAttribute(
		'href',
		/blockendar_view/
	);
	await expect(
		page.locator( HIDDEN_VIEW ),
		'the default is spelled as no parameter, so the input goes rather than saying "list"'
	).toHaveCount( 0 );
} );

test( 'a filter submitted from a fully loaded grid stays a grid', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pagedPageId }&blockendar_view=grid` );

	await submitDateFilter( page, daysFromNow( 10 ) );

	// A GET submit replaces the action's query string with the form's fields,
	// so the view can only survive as one of those fields.
	expect( page.url() ).toContain( 'blockendar_view=grid' );
	expect( page.url() ).toContain( 'blockendar_date_start=' );
	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);
} );

test( 'a filter submitted after an in-place switch keeps the new view', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pagedPageId }` );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();
	await expect( page.locator( HIDDEN_VIEW ) ).toHaveValue( 'grid' );

	await submitDateFilter( page, daysFromNow( 10 ) );

	expect( page.url() ).toContain( 'blockendar_view=grid' );
	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);
} );

test( 'the dropdown type filter keeps the view through its Apply button', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ typePageId }` );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();
	await expect( page.locator( HIDDEN_VIEW ) ).toHaveValue( 'grid' );

	await page
		.locator( '.blockendar-filter-event-type .blockendar-filter__trigger' )
		.click();
	// By name: other specs leave their own terms behind, and a term with no
	// events would filter the results away entirely.
	await page
		.locator( '.blockendar-filter__checkbox-label', {
			hasText: 'Switcher Type',
		} )
		.locator( 'input[type="checkbox"]' )
		.check();

	await Promise.all( [
		page.waitForLoadState( 'load' ),
		page
			.locator(
				'.blockendar-filter-event-type .blockendar-filter__submit'
			)
			.click(),
	] );

	expect( page.url() ).toContain( 'blockendar_type' );
	expect( page.url() ).toContain( 'blockendar_view=grid' );
	await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);
} );

test( 'the clear link of an active filter follows the view', async ( {
	page,
} ) => {
	// The link only renders while a filter is active.
	await page.goto(
		`/?p=${ pagedPageId }&blockendar_date_start=${ daysFromNow( 1 ) }`
	);

	const clear = page.locator( '.blockendar-filter__clear' );

	await expect( clear ).toHaveCount( 1 );
	await expect( clear ).not.toHaveAttribute( 'href', /blockendar_view/ );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();
	await expect( clear ).toHaveAttribute( 'href', /blockendar_view=grid/ );

	await page
		.locator( '.blockendar-view-switcher__button[data-view="list"]' )
		.click();
	await expect( clear ).not.toHaveAttribute( 'href', /blockendar_view/ );
} );

test( 'switching one query group leaves the other untouched', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ twoGroupsPageId }` );

	const groups = page.locator( '[data-blockendar-query-id]' );
	const groupOne = groups.filter( {
		has: page.locator( '[data-query-id="one"]' ),
	} );
	const groupTwo = groups.filter( {
		has: page.locator( '[data-query-id="two"]' ),
	} );

	await groupOne
		.locator( '.blockendar-view-switcher__button[data-view="grid"]' )
		.click();

	await expect( groupOne.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-grid-view/
	);
	await expect( groupOne.locator( NEXT_LINK ) ).toHaveAttribute(
		'href',
		/blockendar_view_one=grid/
	);

	await expect( groupTwo.locator( '.blockendar-events-query' ) ).toHaveClass(
		/is-list-view/
	);
	await expect( groupTwo.locator( NEXT_LINK ) ).not.toHaveAttribute(
		'href',
		/blockendar_view/
	);
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

	test( 'a filter submission keeps the view with plain HTML alone', async ( {
		page,
	} ) => {
		await page.goto( `/?p=${ pagedPageId }&blockendar_view=grid` );

		await submitDateFilter( page, daysFromNow( 10 ) );

		expect( page.url() ).toContain( 'blockendar_view=grid' );
		await expect( page.locator( '.blockendar-events-query' ) ).toHaveClass(
			/is-grid-view/
		);
	} );
} );
