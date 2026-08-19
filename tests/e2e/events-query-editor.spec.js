/**
 * The Events Query block inside the editor.
 *
 * Covers what the block looks like while being edited: that it previews real
 * events rather than placeholder bars, and that the toolbar switches layout.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );
const { loginAsAdmin, openEditor } = require( './editor' );

let postId;
const created = [];

test.beforeAll( () => {
	const year = new Date().getFullYear();

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

			const day = String( 3 + i * 7 ).padStart( 2, '0' );

			Object.entries( {
				blockendar_start_date: `${ year }-09-${ day }`,
				blockendar_end_date: `${ year }-09-${ day }`,
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
} );

test.afterAll( () => {
	created.forEach( ( id ) => wpCli( [ 'post', 'delete', id, '--force' ] ) );
} );

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
