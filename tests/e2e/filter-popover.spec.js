/**
 * The shared filter popover: trigger, panel, and the ways it closes.
 *
 * The dropdown display style used to render <select multiple>, which browsers
 * draw as a multi-row listbox. These tests assert the control is a single-row
 * trigger with a panel anchored beneath it, and that none of the interactions
 * navigate — the filter form surrounds these controls, so a stray submit is a
 * live risk rather than a theoretical one.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );

let pageId;
const created = [];

test.beforeAll( () => {
	[ 'Music', 'Comedy', 'Theatre' ].forEach( ( name ) => {
		const existing = wpCli( [
			'term',
			'list',
			'event_type',
			`--name=${ name }`,
			'--field=term_id',
		] ).trim();

		if ( ! existing ) {
			wpCli( [ 'term', 'create', 'event_type', name, '--porcelain' ] );
		}
	} );

	pageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Popover Page',
		'--post_status=publish',
		// showEmptyTerms, because these fixtures create terms without assigning
		// events to them and the block hides empty terms by default — without it
		// there is nothing to render and every assertion here fails on an absent
		// element rather than on the behaviour under test.
		'--post_content=<!-- wp:blockendar/filter-event-type {"displayStyle":"dropdown","showEmptyTerms":true} /-->',
		'--porcelain',
	] );
	created.push( pageId );
} );

test.afterAll( () => {
	created.forEach( ( id ) => wpCli( [ 'post', 'delete', id, '--force' ] ) );
} );

test( 'the dropdown style renders a single-row trigger, not a listbox', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	await expect(
		page.locator( '.blockendar-filter-event-type select' ),
		'a <select multiple> is a listbox, which is the bug'
	).toHaveCount( 0 );

	const trigger = page.locator( '.blockendar-filter__trigger' );
	await expect( trigger ).toBeVisible();

	const box = await trigger.boundingBox();
	expect( box.height, `trigger was ${ box.height }px tall` ).toBeLessThan(
		60
	);
} );

test( 'the panel opens flush beneath the trigger at its width', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	const trigger = page.locator( '.blockendar-filter__trigger' );
	const panel = page.locator( '.blockendar-filter__panel' );

	await expect( panel ).toBeHidden();

	const tb = await trigger.boundingBox();
	await trigger.click();
	await expect( panel ).toBeVisible();

	const pb = await panel.boundingBox();
	expect( Math.round( pb.y - ( tb.y + tb.height ) ) ).toBeLessThan( 8 );
	expect( Math.round( pb.width ) ).toBe( Math.round( tb.width ) );
	expect( Math.round( pb.x ) ).toBe( Math.round( tb.x ) );
} );

test( 'the panel closes by Escape, outside click, and re-clicking the trigger', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	const trigger = page.locator( '.blockendar-filter__trigger' );
	const panel = page.locator( '.blockendar-filter__panel' );

	// Escape, and focus comes back to the trigger.
	await trigger.click();
	await expect( panel ).toBeVisible();
	await page.keyboard.press( 'Escape' );
	await expect( panel ).toBeHidden();
	await expect( trigger ).toBeFocused();

	// Clicking away.
	await trigger.click();
	await expect( panel ).toBeVisible();
	await page.mouse.click( 10, 10 );
	await expect( panel ).toBeHidden();

	// Re-clicking the trigger.
	await trigger.click();
	await expect( panel ).toBeVisible();
	await trigger.click();
	await expect( panel ).toBeHidden();
} );

test( 'aria-expanded tracks the panel, and the trigger controls it', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	const trigger = page.locator( '.blockendar-filter__trigger' );
	const panel = page.locator( '.blockendar-filter__panel' );

	await expect( trigger ).toHaveAttribute( 'aria-expanded', 'false' );
	await expect( trigger ).toHaveAttribute(
		'aria-controls',
		await panel.getAttribute( 'id' )
	);

	await trigger.click();
	await expect( trigger ).toHaveAttribute( 'aria-expanded', 'true' );

	// Focus lands inside the panel rather than being left on the trigger.
	const focusInPanel = await page.evaluate(
		() => !! document.activeElement.closest( '.blockendar-filter__panel' )
	);
	expect( focusInPanel ).toBe( true );
} );

test( 'opening and closing the popover never navigates', async ( { page } ) => {
	const navigations = [];
	page.on(
		'framenavigated',
		( f ) => f === page.mainFrame() && navigations.push( f.url() )
	);

	await page.goto( `/?p=${ pageId }` );
	navigations.length = 0;

	const trigger = page.locator( '.blockendar-filter__trigger' );
	await trigger.click();
	await trigger.click();
	await trigger.click();
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 800 );

	expect(
		navigations,
		'the trigger sits inside the filter form; a bare button would submit'
	).toEqual( [] );
} );

test.describe( 'without JavaScript', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'the panel stays a plain visible list and still submits', async ( {
		page,
	} ) => {
		await page.goto( `/?p=${ pageId }` );

		const root = page.locator( '.blockendar-filter-event-type' );
		await expect( root ).not.toHaveClass( /is-enhanced/ );

		// The stylesheet loads regardless of JavaScript, so it must not hide the
		// controls of anyone who never ran it.
		await expect(
			page.locator( '.blockendar-filter__panel' )
		).toBeVisible();
		await expect(
			page.locator( '.blockendar-filter__trigger' )
		).toBeHidden();

		await page.locator( 'input[type="checkbox"]' ).first().check();
		await page.locator( '.blockendar-filter__submit' ).first().click();
		await page.waitForLoadState( 'load' );

		expect( page.url() ).toContain( 'blockendar_type' );
	} );
} );
