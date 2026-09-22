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

	/*
	 * Measured against its own line-height rather than a fixed pixel budget. The
	 * point is that this is one row of text plus padding, not the multi-row
	 * listbox a <select multiple> renders. A hard-coded threshold breaks as soon
	 * as the control's padding changes — which is exactly what happened when base
	 * styling was added.
	 */
	const { height, lineHeight } = await trigger.evaluate( ( node ) => {
		const styles = getComputedStyle( node );

		return {
			height: node.getBoundingClientRect().height,
			lineHeight: parseFloat( styles.lineHeight ) || 16,
		};
	} );

	expect(
		height,
		`trigger was ${ height }px against a ${ lineHeight }px line`
	).toBeLessThan( lineHeight * 3 );
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

test( 'the open panel paints above a later stacking context', async ( {
	page,
} ) => {
	await page.goto( `/?p=${ pageId }` );

	// Page builders wrap blocks in groups with isolation: isolate. A group
	// rendered after the filter used to paint over the open panel, because the
	// panel's z-index only counted inside the filter's own group.
	await page.evaluate( () => {
		const filter = document.querySelector(
			'.blockendar-filter-event-type'
		);
		const wrap = document.createElement( 'div' );
		filter.parentElement.insertBefore( wrap, filter );
		wrap.style.cssText = 'isolation:isolate;position:relative';
		wrap.appendChild( filter );

		const later = document.createElement( 'div' );
		later.style.cssText =
			'isolation:isolate;position:relative;background:#fff;min-height:600px';
		wrap.parentElement.insertBefore( later, wrap.nextSibling );
	} );

	await page.locator( '.blockendar-filter__trigger' ).click();

	const panel = page.locator( '.blockendar-filter__panel' );
	await expect( panel ).toBeVisible();

	const covered = await panel.evaluate( ( n ) => {
		const r = n.getBoundingClientRect();
		// Probe inside the viewport: with many terms the panel runs past the
		// fold, and elementFromPoint() returns null outside the viewport.
		const y = Math.min( r.bottom - 20, window.innerHeight - 5 );
		const hit = document.elementFromPoint( r.left + 20, y );
		return ! n.contains( hit );
	} );

	expect( covered, 'the panel must be the topmost element' ).toBe( false );
} );

test( 'a narrow trigger widens the panel to its options rather than wrapping them', async ( {
	page,
} ) => {
	// Wraps at the first word inside a label-width panel; fits on one line
	// once the panel widens to its options (capped at 24rem for safety).
	const longName = 'Unhinged Lounge Upstairs';
	const existing = wpCli( [
		'term',
		'list',
		'event_type',
		`--name=${ longName }`,
		'--field=term_id',
	] ).trim();

	if ( ! existing ) {
		wpCli( [ 'term', 'create', 'event_type', longName, '--porcelain' ] );
	}

	// A nowrap Row squeezes the filter to its trigger label's width.
	const rowPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Popover Row Page',
		'--post_status=publish',
		'--post_content=<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} --><div class="wp-block-group"><!-- wp:blockendar/filter-event-type {"displayStyle":"dropdown","showEmptyTerms":true} /--><!-- wp:paragraph --><p>Filler that takes the rest of the row.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
		'--porcelain',
	] );
	created.push( rowPageId );

	await page.goto( `/?p=${ rowPageId }` );

	const trigger = page.locator( '.blockendar-filter__trigger' );
	const tb = await trigger.boundingBox();
	await trigger.click();

	const panel = page.locator( '.blockendar-filter__panel' );
	await expect( panel ).toBeVisible();

	const pb = await panel.boundingBox();
	expect( pb.width ).toBeGreaterThan( tb.width );
	expect( Math.round( pb.x ) ).toBe( Math.round( tb.x ) );

	const lines = await page
		.locator( '.blockendar-filter__checkbox-label', { hasText: longName } )
		.evaluate( ( label ) => {
			const text = Array.from( label.childNodes ).find(
				( n ) => 3 === n.nodeType && n.textContent.trim()
			);
			const range = document.createRange();
			range.selectNodeContents( text );
			return range.getClientRects().length;
		} );

	expect( lines, 'the long option must sit on one line' ).toBe( 1 );
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

	// Focus lands on the first control in the panel rather than being left on the
	// trigger, so a keyboard user is placed where the choices are.
	await expect(
		page
			.locator( '.blockendar-filter__panel input[type="checkbox"]' )
			.first()
	).toBeFocused();
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

test( 'the closed state is painted before the view script runs', async ( {
	page,
} ) => {
	// With the view script never arriving, the page sits in exactly the state
	// the browser paints before a deferred script executes. That paint used to
	// show the whole list and collapse it a moment later.
	await page.route( '**/build/blocks/**/view.js*', ( route ) =>
		route.abort()
	);
	await page.goto( `/?p=${ pageId }` );

	const root = page.locator( '.blockendar-filter-event-type' );
	await expect( root ).not.toHaveClass( /is-enhanced/ );

	await expect( page.locator( 'html' ) ).toHaveClass( /blockendar-js/ );
	await expect( page.locator( '.blockendar-filter__trigger' ) ).toBeVisible();
	await expect( page.locator( '.blockendar-filter__panel' ) ).toBeHidden();

	// One probe per page, and it must precede the block it protects.
	const order = await page.evaluate( () => {
		const probes = Array.from( document.scripts ).filter( ( s ) =>
			s.textContent.includes( 'blockendar-js' )
		);
		const block = document.querySelector( '[data-blockendar-filter]' );

		// Source order: the probe's own script element must sit before the block.
		const all = Array.from(
			document.querySelectorAll( 'script, [data-blockendar-filter]' )
		);

		return {
			count: probes.length,
			precedes: all.indexOf( probes[ 0 ] ) < all.indexOf( block ),
		};
	} );

	expect( order ).toEqual( { count: 1, precedes: true } );
} );

test( 'the list style keeps its list visible with JavaScript on', async ( {
	page,
} ) => {
	const listPageId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Popover List Page',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/filter-event-type {"displayStyle":"list","showEmptyTerms":true} /-->',
		'--porcelain',
	] );
	created.push( listPageId );

	await page.goto( `/?p=${ listPageId }` );

	// The probe lands here too, but it only hides a panel that follows a
	// trigger, and the list style renders neither: the list sits in the form.
	await expect( page.locator( 'html' ) ).toHaveClass( /blockendar-js/ );
	await expect( page.locator( '.blockendar-filter__trigger' ) ).toHaveCount(
		0
	);
	await expect( page.locator( '.blockendar-filter__list' ) ).toBeVisible();
	await expect(
		page
			.locator( '.blockendar-filter__list input[type="checkbox"]' )
			.first()
	).toBeVisible();
} );

test.describe( 'without JavaScript', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'the panel stays a plain visible list and still submits', async ( {
		page,
	} ) => {
		await page.goto( `/?p=${ pageId }` );

		const root = page.locator( '.blockendar-filter-event-type' );
		await expect( root ).not.toHaveClass( /is-enhanced/ );

		// The probe is a script, so without JavaScript the class never lands
		// and nothing below may depend on it.
		await expect( page.locator( 'html' ) ).not.toHaveClass(
			/blockendar-js/
		);

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
