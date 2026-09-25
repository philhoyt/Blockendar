/**
 * The Events Query block inside the editor.
 *
 * Covers what the block looks like while being edited: that it previews real
 * events rather than placeholder bars, and that the toolbar switches layout.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );
const { daysFromNow } = require( './dates' );
const { loginAsAdmin, openEditor } = require( './editor' );
const { ensureTerm, deleteTerm } = require( './terms' );

let postId;
let switcherPostId;
let filterTypeId;
const created = [];

test.beforeAll( () => {
	filterTypeId = ensureTerm(
		'blockendar_event_type',
		'E2E Filter Type',
		'e2e-filter-type'
	);

	[ 'Editor E2E One', 'Editor E2E Two', 'Editor E2E Three' ].forEach(
		( title, i ) => {
			const id = wpCliId( [
				'post',
				'create',
				'--post_type=blockendar_event',
				`--post_title=${ title }`,
				'--post_status=publish',
				'--porcelain',
			] );

			const ymd = daysFromNow( 3 + i * 7 );

			Object.entries( {
				blockendar_start_date: ymd,
				blockendar_end_date: ymd,
				blockendar_start_time: '19:00',
				blockendar_end_time: '21:00',
				blockendar_timezone: 'UTC',
				blockendar_status: 'scheduled',
			} ).forEach( ( [ key, value ] ) => {
				wpCli( [ 'post', 'meta', 'update', id, key, value ] );
			} );

			wpCli( [ 'post', 'update', id, `--post_title=${ title }` ] );
			created.push( id );
		}
	);

	postId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Editor Page',
		'--post_status=draft',
		'--post_content=<!-- wp:blockendar/events-query {"perPage":3} --><!-- wp:post-title {"isLink":true,"level":3} /--><!-- /wp:blockendar/events-query -->',
		'--porcelain',
	] );
	created.push( postId );

	/*
	 * A second page where a switcher sits beside the query under a shared
	 * ancestor, which is how the switcher finds the query it belongs to. The
	 * first fixture has no switcher, so it cannot exercise the sync between them.
	 */
	switcherPostId = wpCliId( [
		'post',
		'create',
		'--post_type=page',
		'--post_title=E2E Switcher Page',
		'--post_status=draft',
		'--post_content=<!-- wp:blockendar/query-filters --><!-- wp:blockendar/query-view-switcher /--><!-- wp:blockendar/events-query {"perPage":3} --><!-- wp:post-title {"level":3} /--><!-- /wp:blockendar/events-query --><!-- /wp:blockendar/query-filters -->',
		'--porcelain',
	] );
	created.push( switcherPostId );
} );

test.afterAll( () => {
	created.forEach( ( id ) => wpCli( [ 'post', 'delete', id, '--force' ] ) );
	deleteTerm( 'blockendar_event_type', filterTypeId );
} );

/**
 * Select the first Events Query block through the data store and open the
 * block sidebar. Clicking through the iframed canvas is intercepted by the
 * block's own overlays, so selection goes through wp.data instead.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function selectEventsQueryBlock( page ) {
	await page.evaluate( () => {
		const query = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.find( ( block ) => block.name === 'blockendar/events-query' );

		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( query.clientId );
		window.wp.data
			.dispatch( 'core/edit-post' )
			?.openGeneralSidebar?.( 'edit-post/block' );
	} );
}

test( 'the block previews real events rather than placeholder bars', async ( {
	page,
} ) => {
	await loginAsAdmin( page );
	const canvas = await openEditor( page, postId );

	const block = canvas.locator( '.blockendar-events-query' ).first();
	await expect( block ).toBeVisible( { timeout: 30000 } );

	// One editable item plus perPage-1 previews, and no leftover skeletons.
	await expect(
		canvas.locator( '.blockendar-events-query__preview' )
	).toHaveCount( 2, { timeout: 30000 } );
	await expect(
		canvas.locator( '.blockendar-events-query__ghost' )
	).toHaveCount( 0 );

	// The previews carry real event titles, which placeholders never did.
	const text = await block.innerText();
	expect( text ).toContain( 'Editor E2E' );
} );

test( 'previews render at the same scale as the editable item', async ( {
	page,
} ) => {
	await loginAsAdmin( page );
	const canvas = await openEditor( page, postId );

	const content = canvas
		.locator(
			'.blockendar-events-query__preview .block-editor-block-preview__content'
		)
		.first();
	await expect( content ).toBeVisible( { timeout: 30000 } );

	// A fixed viewportWidth left these at 0.92 of the item above them.
	const transform = await content.evaluate(
		( n ) => getComputedStyle( n ).transform
	);
	expect(
		transform === 'none' || transform.startsWith( 'matrix(1,' ),
		`preview transform was ${ transform }`
	).toBe( true );
} );

/*
 * Not covered here: the list/grid toolbar toggle.
 *
 * Selecting a block from Playwright means either clicking through the iframed
 * canvas — where the click is intercepted by the block's own overlays — or
 * driving the editor chrome, whose selectors differ between WordPress versions.
 * Both proved brittle enough that the test would fail for harness reasons rather
 * than product ones, which is worse than no test.
 *
 * The toggle also predates this work: it was already implemented in edit.jsx and
 * is unchanged. What this work actually introduced — the previews and their
 * scale — is covered above. Flagged in the plan's Notes as a real gap.
 */

test( 'splitting the template leaves the editor in a valid state', async ( {
	page,
} ) => {
	// Logging in and booting the editor eats most of the default budget before
	// this test does any of its own work.
	test.setTimeout( 120000 );

	const pageErrors = [];
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	await loginAsAdmin( page );
	const canvas = await openEditor( page, postId );
	await expect(
		canvas.locator( '.blockendar-events-query' ).first()
	).toBeVisible( { timeout: 30000 } );

	/*
	 * Select through the data store rather than clicking the canvas: the block's
	 * own overlays intercept clicks inside the editor iframe, which is what made
	 * earlier attempts at this fail for harness reasons rather than product ones.
	 */
	await page.evaluate( () => {
		const query = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.find( ( block ) => block.name === 'blockendar/events-query' );

		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( query.clientId );
	} );

	// Selecting through the store does not open the settings sidebar, and the
	// Layout panel may be collapsed from a previous session's preferences.
	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/edit-post' )
			?.openGeneralSidebar?.( 'edit-post/block' );
	} );

	const layoutPanel = page.getByRole( 'button', {
		name: 'Layout',
		exact: true,
	} );

	if ( await layoutPanel.count() ) {
		if (
			'false' ===
			( await layoutPanel.first().getAttribute( 'aria-expanded' ) )
		) {
			await layoutPanel.first().click();
		}
	}

	/*
	 * Located by text rather than by role: opening the sidebar from the data
	 * store leaves an ancestor out of the accessibility tree, so getByRole finds
	 * nothing even though the button is on screen and clickable.
	 */
	const splitButton = page.locator( 'button.components-button', {
		hasText: 'Use a separate template per layout',
	} );

	await splitButton.waitFor( { state: 'visible', timeout: 20000 } );
	await splitButton.click();

	const result = await page.evaluate( () => {
		const query = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.find( ( block ) => block.name === 'blockendar/events-query' );

		const ids = [];
		const walk = ( blocks ) =>
			blocks.forEach( ( block ) => {
				ids.push( block.clientId );
				walk( block.innerBlocks ?? [] );
			} );
		walk( [ query ] );

		return {
			templates: query.innerBlocks.filter(
				( block ) => block.name === 'blockendar/event-template'
			).length,
			totalIds: ids.length,
			uniqueIds: new Set( ids ).size,
		};
	} );

	expect( result.templates ).toBe( 2 );

	// Reusing a clientId across both copies corrupts the block tree, which
	// surfaces as "Cannot read properties of undefined (reading 'name')".
	expect(
		result.uniqueIds,
		'every block in the tree needs its own clientId'
	).toBe( result.totalIds );

	expect( pageErrors, pageErrors.join( '\n' ) ).toEqual( [] );
} );

test( 'the Hide events control offers three rules and a slider for the hours one', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );

	await loginAsAdmin( page );
	const canvas = await openEditor( page, postId );
	await expect(
		canvas.locator( '.blockendar-events-query' ).first()
	).toBeVisible( { timeout: 30000 } );

	await selectEventsQueryBlock( page );

	const queryPanel = page.getByRole( 'button', {
		name: 'Query',
		exact: true,
	} );

	if (
		( await queryPanel.count() ) &&
		'false' === ( await queryPanel.first().getAttribute( 'aria-expanded' ) )
	) {
		await queryPanel.first().click();
	}

	// Located by the select's label text, for the reason given above.
	const select = page.locator( 'select', {
		has: page.locator( 'option', { hasText: 'At the end of their day' } ),
	} );
	await select.waitFor( { state: 'visible', timeout: 20000 } );

	await expect( select ).toHaveValue( 'day' );
	await expect( select.locator( 'option' ) ).toHaveText( [
		'When they end',
		'At the end of their day',
		'A number of hours after they end',
	] );

	const hoursSlider = page.getByRole( 'slider', {
		name: 'Hours after the event ends',
	} );

	await expect( hoursSlider ).toHaveCount( 0 );

	await select.selectOption( 'hours' );
	await expect( hoursSlider ).toHaveCount( 1 );
	await expect( hoursSlider ).toHaveValue( '3' );

	await hoursSlider.fill( '6' );

	const saved = await page.evaluate( () => {
		const query = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.find( ( block ) => block.name === 'blockendar/events-query' );

		return {
			hideAfter: query.attributes.hideAfter,
			hideAfterHours: query.attributes.hideAfterHours,
			serialized: window.wp.blocks.serialize( [ query ] ),
		};
	} );

	expect( saved.hideAfter ).toBe( 'hours' );
	expect( saved.hideAfterHours ).toBe( 6 );
	expect( saved.serialized ).toContain( '"hideAfter":"hours"' );
	expect( saved.serialized ).toContain( '"hideAfterHours":6' );

	// Back to the default: the slider goes and the default is not serialised.
	await select.selectOption( 'day' );
	await expect( hoursSlider ).toHaveCount( 0 );
} );

test( 'each layout gets its own template that the toolbar switches between', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );

	await loginAsAdmin( page );
	const canvas = await openEditor( page, postId );
	await expect(
		canvas.locator( '.blockendar-events-query' ).first()
	).toBeVisible( { timeout: 30000 } );

	const queryId = await page.evaluate( () => {
		const query = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.find( ( block ) => block.name === 'blockendar/events-query' );

		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( query.clientId );

		return query.clientId;
	} );

	await page
		.locator( 'button.components-button', {
			hasText: 'Use a separate template per layout',
		} )
		.click();

	// The two containers must claim different layouts, or the server sees one
	// template and renders every event through it.
	const layouts = await page.evaluate( ( id ) => {
		return window.wp.data
			.select( 'core/block-editor' )
			.getBlock( id )
			.innerBlocks.filter(
				( block ) => block.name === 'blockendar/event-template'
			)
			.map( ( block ) => block.attributes.layout );
	}, queryId );

	expect( layouts ).toEqual( [ 'list', 'grid' ] );

	// Editing one template must not touch the other.
	await page.evaluate( ( id ) => {
		const grid = window.wp.data
			.select( 'core/block-editor' )
			.getBlock( id )
			.innerBlocks.find(
				( block ) =>
					block.name === 'blockendar/event-template' &&
					block.attributes.layout === 'grid'
			);

		window.wp.data
			.dispatch( 'core/block-editor' )
			.insertBlock(
				window.wp.blocks.createBlock( 'blockendar/event-cost' ),
				0,
				grid.clientId,
				false
			);
	}, queryId );

	const counts = await page.evaluate( ( id ) => {
		const templates = window.wp.data
			.select( 'core/block-editor' )
			.getBlock( id )
			.innerBlocks.filter(
				( block ) => block.name === 'blockendar/event-template'
			);

		return templates.map( ( block ) => block.innerBlocks.length );
	}, queryId );

	expect(
		counts[ 0 ],
		'adding a block to the grid template changed the list template'
	).not.toBe( counts[ 1 ] );

	// Only the layout selected in the toolbar is shown.
	await expect(
		canvas.locator( '.blockendar-event-template.is-inactive-layout' )
	).toHaveCount( 1 );

	await page.evaluate( ( id ) => {
		window.wp.data
			.dispatch( 'core/block-editor' )
			.updateBlockAttributes( id, {
				displayLayout: { type: 'grid' },
			} );
	}, queryId );

	await expect(
		canvas.locator( '.blockendar-event-template.is-inactive-layout' )
	).toHaveCount( 1 );
} );

test( 'undoing a layout change takes the switcher back with it', async ( {
	page,
} ) => {
	// Logging in and booting the editor eats most of the default budget before
	// this test does any of its own work.
	test.setTimeout( 120000 );

	await loginAsAdmin( page );
	const canvas = await openEditor( page, switcherPostId );
	await expect(
		canvas.locator( '.blockendar-events-query' ).first()
	).toBeVisible( { timeout: 30000 } );

	/*
	 * The switcher mirrors the query's layout into its own defaultView so the
	 * control and the results cannot drift. That write must merge into the change
	 * that caused it rather than landing as an undo step of its own: as a step of
	 * its own, undo pops the mirror, the query is still on the new layout, and the
	 * effect writes it straight back. The layout then never reverts and the post
	 * can never be returned to a clean state.
	 */
	const read = () =>
		page.evaluate( () => {
			const find = ( name, list ) => {
				for ( const block of list ) {
					if ( block.name === name ) {
						return block;
					}

					const found = find( name, block.innerBlocks ?? [] );

					if ( found ) {
						return found;
					}
				}

				return null;
			};

			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();

			return {
				layout:
					find( 'blockendar/events-query', blocks ).attributes
						.displayLayout?.type ?? 'list',
				defaultView: find( 'blockendar/query-view-switcher', blocks )
					.attributes.defaultView,
				dirty: window.wp.data
					.select( 'core/editor' )
					.isEditedPostDirty(),
			};
		} );

	expect( await read() ).toEqual( {
		layout: 'list',
		defaultView: 'list',
		dirty: false,
	} );

	await page.evaluate( () => {
		const find = ( list ) => {
			for ( const block of list ) {
				if ( block.name === 'blockendar/events-query' ) {
					return block;
				}

				const found = find( block.innerBlocks ?? [] );

				if ( found ) {
					return found;
				}
			}

			return null;
		};

		const query = find(
			window.wp.data.select( 'core/block-editor' ).getBlocks()
		);

		window.wp.data
			.dispatch( 'core/block-editor' )
			.updateBlockAttributes( query.clientId, {
				displayLayout: { type: 'grid' },
			} );
	} );

	// The mirror runs in an effect, so wait for it rather than assuming it has
	// already landed.
	await expect
		.poll( async () => ( await read() ).defaultView )
		.toBe( 'grid' );

	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/editor' ).undo()
	);

	await expect
		.poll( () => read() )
		.toEqual( {
			layout: 'list',
			defaultView: 'list',
			dirty: false,
		} );
} );

test( 'the type filter offers the seeded event type', async ( { page } ) => {
	test.setTimeout( 120000 );

	await loginAsAdmin( page );
	const canvas = await openEditor( page, postId );
	await expect(
		canvas.locator( '.blockendar-events-query' ).first()
	).toBeVisible( { timeout: 30000 } );

	await selectEventsQueryBlock( page );

	// The panel is closed by default and PanelBody renders its children only
	// while open. With the taxonomy fetch broken, `terms` stays null and the
	// whole panel is skipped — so the toggle is asserted, not guarded: this is
	// where the test fails under that mutation, by name.
	const toggle = page.getByRole( 'button', {
		name: 'Filter by Event Type',
		exact: true,
	} );
	await expect( toggle ).toBeVisible( { timeout: 20000 } );

	if ( 'false' === ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
		await toggle.click();
	}

	// Scoped to this panel: "Exclude Event Types" renders the same names.
	const panel = page.locator( '.components-panel__body', { has: toggle } );
	await expect(
		panel.getByRole( 'checkbox', { name: 'E2E Filter Type', exact: true } )
	).toHaveCount( 1, { timeout: 20000 } );
} );
