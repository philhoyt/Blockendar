/**
 * The Date & Time sidebar panel on an event's edit screen.
 *
 * The panel is where an event gets its dates and its repeat rule, and nothing
 * else in the test suite opens it. These tests drive the real controls and
 * then read what reached the database through WP-CLI, because the bugs they
 * guard were all between the two: a rule written before the post was saved, a
 * date the picker would not accept, a timezone seeded with the wrong value.
 *
 * The browser is pinned to a zone that is not the site's. The panel works in
 * wall-clock values, and one that only works when the author and the site
 * share a timezone is broken for everyone else.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );
const { loginAsAdmin, openEditor } = require( './editor' );

test.use( { timezoneId: 'Asia/Tokyo' } );

const created = [];

/**
 * Create a published single event some weeks ahead.
 *
 * @param {string} title Event title.
 * @return {string} Post ID.
 */
function createEvent( title ) {
	const id = wpCliId( [
		'post',
		'create',
		'--post_type=blockendar_event',
		`--post_title=${ title }`,
		'--post_status=publish',
		'--porcelain',
	] );

	Object.entries( {
		blockendar_start_date: '2027-03-09',
		blockendar_end_date: '2027-03-09',
		blockendar_start_time: '19:00',
		blockendar_end_time: '21:00',
		blockendar_timezone: 'UTC',
	} ).forEach( ( [ key, value ] ) => {
		wpCli( [ 'post', 'meta', 'update', id, key, value ] );
	} );

	wpCli( [ 'post', 'update', id, `--post_title=${ title }` ] );

	created.push( id );
	return id;
}

/**
 * What the database holds for an event's repeat rule.
 *
 * @param {string} id Post ID.
 * @return {string} The rule's frequency, or "none".
 */
function storedFrequency( id ) {
	return wpCli( [
		'eval',
		`$rule = ( new \\Blockendar\\Recurrence\\RuleRepository() )->get( ${ id } ); echo $rule ? $rule->frequency : 'none';`,
	] );
}

/**
 * How many occurrences of an event are indexed.
 *
 * @param {string} id Post ID.
 * @return {number} Row count.
 */
function indexedOccurrences( id ) {
	return parseInt(
		wpCli( [
			'eval',
			`echo count( ( new \\Blockendar\\DB\\EventIndex() )->get_by_post_id( ${ id } ) );`,
		] ),
		10
	);
}

/**
 * Open the document sidebar with the Date & Time panel expanded.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<Object>} Locator for the panel.
 */
async function openPanel( page ) {
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/edit-post' )
			.openGeneralSidebar( 'edit-post/document' )
	);

	const panel = page.locator( '.blockendar-panel-datetime' );
	await expect( panel ).toBeVisible( { timeout: 30000 } );

	const toggle = panel.locator( '.components-panel__body-toggle' );
	if ( 'false' === ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
		await toggle.click();
	}

	await expect( panel.getByLabel( 'Repeats' ) ).toBeVisible( {
		timeout: 30000,
	} );

	return panel;
}

/**
 * Save the post the way the Save button does, and wait for it to finish.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function savePost( page ) {
	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/editor' ).savePost()
	);

	await page.waitForFunction(
		() => {
			const editor = window.wp.data.select( 'core/editor' );
			return ! editor.isSavingPost() && ! editor.isEditedPostDirty();
		},
		null,
		{ timeout: 30000 }
	);
}

test.afterAll( () => {
	created.forEach( ( id ) => wpCli( [ 'post', 'delete', id, '--force' ] ) );
} );

/*
 * Changing "Repeats" used to write the rule to the database there and then.
 * An author who changed their mind and left without saving had still turned
 * the event into a series.
 */
test( 'a repeat rule chosen and then abandoned is not saved', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );

	const id = createEvent( 'E2E Panel Abandoned Rule' );

	await loginAsAdmin( page );
	await openEditor( page, id );

	const panel = await openPanel( page );
	await panel.getByLabel( 'Repeats' ).selectOption( 'daily' );

	// Long enough for a request fired on change to have landed.
	await page.waitForTimeout( 2000 );

	expect( storedFrequency( id ) ).toBe( 'none' );
	expect( indexedOccurrences( id ) ).toBe( 1 );

	// The change is still there to be saved: the post is dirty.
	expect(
		await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).isEditedPostDirty()
		)
	).toBe( true );
} );

test( 'a repeat rule is stored when the event is saved', async ( { page } ) => {
	test.setTimeout( 120000 );

	const id = createEvent( 'E2E Panel Saved Rule' );

	await loginAsAdmin( page );
	await openEditor( page, id );

	const panel = await openPanel( page );
	await panel.getByLabel( 'Repeats' ).selectOption( 'weekly_day' );
	await panel.getByLabel( 'After N times' ).check();
	await panel.getByLabel( 'Number of occurrences' ).fill( '4' );

	await savePost( page );

	expect( storedFrequency( id ) ).toBe( 'weekly' );
	expect( indexedOccurrences( id ) ).toBe( 4 );

	// And it reads back into the controls on the next visit.
	await openEditor( page, id );
	const reopened = await openPanel( page );

	await expect( reopened.getByLabel( 'Repeats' ) ).toHaveValue(
		'weekly_day'
	);
	await expect( reopened.getByLabel( 'After N times' ) ).toBeChecked();
	await expect( reopened.getByLabel( 'Number of occurrences' ) ).toHaveValue(
		'4'
	);

	// Turning it off again is saved the same way.
	await reopened.getByLabel( 'Repeats' ).selectOption( 'none' );
	await savePost( page );

	expect( storedFrequency( id ) ).toBe( 'none' );
	expect( indexedOccurrences( id ) ).toBe( 1 );
} );

/*
 * Changing only how a series ends has to be saveable. The end settings mark
 * the post as changed on their own, without "Repeats" being touched.
 */
test( 'changing only how a series ends can be saved', async ( { page } ) => {
	test.setTimeout( 120000 );

	const id = createEvent( 'E2E Panel End Change' );

	wpCli( [
		'eval',
		`( new \\Blockendar\\Recurrence\\RuleRepository() )->upsert( ${ id }, [ 'frequency' => 'daily', 'interval' => 1, 'count' => 5 ] ); ( new \\Blockendar\\DB\\IndexBuilder() )->build_for_post( ${ id } );`,
	] );
	expect( indexedOccurrences( id ) ).toBe( 5 );

	await loginAsAdmin( page );
	await openEditor( page, id );

	const panel = await openPanel( page );
	await expect( panel.getByLabel( 'Repeats' ) ).toHaveValue( 'daily' );

	// Opening a series must not edit it: the rule the controls hold is the
	// rule that was loaded.
	expect(
		await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).isEditedPostDirty()
		)
	).toBe( false );

	await panel.getByLabel( 'Number of occurrences' ).fill( '3' );

	// Without this the Save button stays disabled and the change is lost.
	expect(
		await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).isEditedPostDirty()
		)
	).toBe( true );

	await savePost( page );

	expect( indexedOccurrences( id ) ).toBe( 3 );
} );

/*
 * The start date picker refused anything before today, so an event that had
 * already happened could not be entered, and opening one left the field in an
 * invalid state.
 */
/*
 * Each label pointed at an id its field did not have, so the two date inputs
 * had no name at all, and the start and end times were two identical sets of
 * "Hour", "Minute", "AM or PM". Finding a field by its name is what a screen
 * reader user does.
 */
test( 'the date and time fields can be found by their names', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );

	const id = createEvent( 'E2E Panel Field Names' );

	await loginAsAdmin( page );
	await openEditor( page, id );

	const panel = await openPanel( page );

	// Exact, because "Ongoing, no end date" contains "end date" too.

	await expect(
		panel.getByLabel( 'Start Date', { exact: true } )
	).toHaveAttribute( 'type', 'date' );
	await expect(
		panel.getByLabel( 'End Date', { exact: true } )
	).toHaveAttribute( 'type', 'date' );

	const start = panel.getByRole( 'group', { name: 'Start Time' } );
	const end = panel.getByRole( 'group', { name: 'End Time' } );

	await expect( start.getByLabel( 'Hour' ) ).toBeVisible();
	await expect( end.getByLabel( 'Hour' ) ).toBeVisible();
	await expect( end.getByLabel( 'Minute' ) ).toBeVisible();

	// A click on the visible label lands in its field.
	await panel.getByText( 'End Date', { exact: true } ).click();
	await expect(
		panel.getByLabel( 'End Date', { exact: true } )
	).toBeFocused();
} );

test( 'an event can be given a date in the past', async ( { page } ) => {
	test.setTimeout( 120000 );

	const id = createEvent( 'E2E Panel Past Date' );

	await loginAsAdmin( page );
	await openEditor( page, id );

	const panel = await openPanel( page );
	const start = panel.locator( 'input[type="date"]' ).first();

	await expect( start ).not.toHaveAttribute( 'min', /.+/ );

	await start.fill( '2020-02-03' );
	expect( await start.evaluate( ( input ) => input.validity.valid ) ).toBe(
		true
	);

	await savePost( page );

	expect(
		wpCli( [ 'post', 'meta', 'get', id, 'blockendar_start_date' ] )
	).toBe( '2020-02-03' );
} );

/*
 * A site set to "UTC+5:30" has no named timezone. A new event has to be
 * seeded with that offset: seeded with UTC, 7 pm was indexed as 7 pm UTC.
 */
test.describe( 'on a site with a manual UTC offset', () => {
	let previousZone;
	let previousOffset;

	test.beforeAll( () => {
		previousZone = wpCli( [ 'option', 'get', 'timezone_string' ] );
		previousOffset = wpCli( [ 'option', 'get', 'gmt_offset' ] );

		wpCli( [ 'option', 'update', 'timezone_string', '' ] );
		wpCli( [ 'option', 'update', 'gmt_offset', '5.5' ] );
	} );

	test.afterAll( () => {
		wpCli( [ 'option', 'update', 'timezone_string', previousZone ] );
		wpCli( [ 'option', 'update', 'gmt_offset', previousOffset || '0' ] );
	} );

	test( 'a new event is seeded with the offset, and the picker shows it', async ( {
		page,
	} ) => {
		test.setTimeout( 120000 );

		await loginAsAdmin( page );
		await page.goto( '/wp-admin/post-new.php?post_type=blockendar_event', {
			waitUntil: 'domcontentloaded',
		} );
		await page.waitForFunction(
			() => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId(),
			null,
			{ timeout: 30000 }
		);

		const panel = await openPanel( page );

		await expect( panel.getByLabel( 'Timezone' ) ).toHaveValue( '+05:30' );
		expect(
			await page.evaluate(
				() =>
					window.wp.data
						.select( 'core/editor' )
						.getEditedPostAttribute( 'meta' ).blockendar_timezone
			)
		).toBe( '+05:30' );
	} );
} );
