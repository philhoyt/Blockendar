/**
 * The Event Venue block inside the editor.
 *
 * The block reads its term IDs off the edited post entity. That record keys
 * taxonomy terms by the taxonomy's rest_base ('event-venues'), not by its name
 * ('blockendar_event_venue') — see WP_REST_Posts_Controller::get_item_schema(). Reading the
 * name returned undefined, so termIds was always empty and the block rendered
 * its hardcoded PLACEHOLDER ("The Grand Ballroom") no matter which venue was
 * assigned. Nothing caught it, because the placeholder looks like a real venue.
 *
 * This test asserts the editor shows the assigned venue, and explicitly that the
 * placeholder is absent — without the second half it would pass on a block that
 * renders both.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );
const { daysFromNow } = require( './dates' );
const { loginAsAdmin, openEditor } = require( './editor' );

const VENUE_NAME = 'E2E Riverside Hall';
const VENUE_CITY = 'Portland';
const PLACEHOLDER_NAME = 'The Grand Ballroom';

let eventId;
let venuelessEventId;
let venueTermId;
const createdPosts = [];

test.beforeAll( () => {
	venueTermId = wpCliId( [
		'term',
		'create',
		'blockendar_event_venue',
		VENUE_NAME,
		'--slug=e2e-riverside-hall',
		'--porcelain',
	] );

	// `wp term meta` keys off the term ID alone — it takes no taxonomy.
	wpCli( [
		'term',
		'meta',
		'update',
		venueTermId,
		'blockendar_venue_address',
		'42 River Road',
	] );
	wpCli( [
		'term',
		'meta',
		'update',
		venueTermId,
		'blockendar_venue_city',
		VENUE_CITY,
	] );
	wpCli( [
		'term',
		'meta',
		'update',
		venueTermId,
		'blockendar_venue_postal_code',
		'97201',
	] );

	eventId = wpCliId( [
		'post',
		'create',
		'--post_type=blockendar_event',
		'--post_title=E2E Venue Block Event',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/event-venue /-->',
		'--porcelain',
	] );
	createdPosts.push( eventId );

	const ymd = daysFromNow( 5 );

	Object.entries( {
		blockendar_start_date: ymd,
		blockendar_end_date: ymd,
		blockendar_start_time: '19:00',
		blockendar_end_time: '21:00',
		blockendar_timezone: 'UTC',
		blockendar_status: 'scheduled',
	} ).forEach( ( [ key, value ] ) => {
		wpCli( [ 'post', 'meta', 'update', eventId, key, value ] );
	} );

	wpCli( [
		'post',
		'term',
		'set',
		eventId,
		'blockendar_event_venue',
		'e2e-riverside-hall',
	] );

	// An event with no venue at all, to hold the placeholder branch in place.
	venuelessEventId = wpCliId( [
		'post',
		'create',
		'--post_type=blockendar_event',
		'--post_title=E2E Venueless Event',
		'--post_status=publish',
		'--post_content=<!-- wp:blockendar/event-venue /-->',
		'--porcelain',
	] );
	createdPosts.push( venuelessEventId );
} );

test.afterAll( () => {
	createdPosts.forEach( ( id ) =>
		wpCli( [ 'post', 'delete', id, '--force' ] )
	);
	wpCli( [ 'term', 'delete', 'blockendar_event_venue', venueTermId ] );
} );

test( 'the block shows the assigned venue, not the placeholder', async ( {
	page,
} ) => {
	await loginAsAdmin( page );
	const canvas = await openEditor( page, eventId );

	const block = canvas.locator( '.blockendar-event-venue' ).first();
	await expect( block ).toBeVisible( { timeout: 30000 } );

	await expect( block.locator( '.blockendar-event-venue__name' ) ).toHaveText(
		VENUE_NAME,
		{ timeout: 30000 }
	);

	// The bug rendered the placeholder in place of the real venue, so the
	// absence of it is the half of this test that actually fails on the bug.
	await expect( block ).not.toContainText( PLACEHOLDER_NAME );
} );

test( 'the assigned venue renders at full opacity', async ( { page } ) => {
	await loginAsAdmin( page );
	const canvas = await openEditor( page, eventId );

	const block = canvas.locator( '.blockendar-event-venue' ).first();
	await expect( block ).toBeVisible( { timeout: 30000 } );

	// isPlaceholder sets an inline opacity; a resolved venue must not. Read the
	// inline attribute, not the computed value: on this same element the editor
	// dims every unselected block to 0.2 under Spotlight mode — a persisted
	// preference — and to 0.1 while dragging.
	await expect( block ).not.toHaveAttribute( 'style', /opacity/ );
} );

test( 'the address from term meta reaches the editor', async ( { page } ) => {
	await loginAsAdmin( page );
	const canvas = await openEditor( page, eventId );

	const block = canvas.locator( '.blockendar-event-venue' ).first();
	await expect( block ).toBeVisible( { timeout: 30000 } );

	// showAddress defaults to true, and the address is built from term meta —
	// which is only reachable once the real term record resolves.
	await expect(
		block.locator( '.blockendar-event-venue__address' )
	).toContainText( VENUE_CITY, { timeout: 30000 } );
} );

test( 'the postal code is part of the address in the editor', async ( {
	page,
} ) => {
	await loginAsAdmin( page );
	const canvas = await openEditor( page, eventId );

	await expect(
		canvas.locator( '.blockendar-event-venue__address' ).first()
	).toHaveText( '42 River Road, Portland, 97201', { timeout: 30000 } );
} );

/*
 * The two options are off on every block that exists, so nothing changes
 * until an author turns one on.
 */
test( 'the directions link and the name link appear when switched on', async ( {
	page,
} ) => {
	test.setTimeout( 120000 );

	await loginAsAdmin( page );
	const canvas = await openEditor( page, eventId );

	const block = canvas.locator( '.blockendar-event-venue' ).first();
	await expect( block ).toBeVisible( { timeout: 30000 } );
	await expect( block.locator( '.blockendar-event-venue__name' ) ).toHaveText(
		VENUE_NAME,
		{ timeout: 30000 }
	);

	await expect(
		block.locator( '.blockendar-event-venue__directions' )
	).toHaveCount( 0 );
	await expect(
		block.locator( '.blockendar-event-venue__name a' )
	).toHaveCount( 0 );

	// Select the block and open its settings, as clicking it would.
	await page.evaluate( () => {
		const { select, dispatch } = window.wp.data;
		const venue = select( 'core/block-editor' )
			.getBlocks()
			.find( ( b ) => b.name === 'blockendar/event-venue' );

		dispatch( 'core/block-editor' ).selectBlock( venue.clientId );
		dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/block' );
	} );

	await page.getByLabel( 'Show directions link' ).check();
	await expect(
		block.locator( '.blockendar-event-venue__directions' )
	).toHaveText( 'Get directions' );

	await page.getByLabel( 'Link venue name' ).check();
	await expect(
		block.locator( '.blockendar-event-venue__name a' )
	).toHaveText( VENUE_NAME );
} );

/*
 * The other branch of the conditional the fix touched. This one passed before
 * the fix too — it is a regression guard, not proof of the bug: reading the
 * right field must not cost the placeholder that an unassigned block relies on.
 */
test( 'an event with no venue still shows the placeholder', async ( {
	page,
} ) => {
	await loginAsAdmin( page );
	const canvas = await openEditor( page, venuelessEventId );

	const block = canvas.locator( '.blockendar-event-venue' ).first();
	await expect( block ).toBeVisible( { timeout: 30000 } );

	await expect( block ).toContainText( PLACEHOLDER_NAME, { timeout: 30000 } );

	// The placeholder is the faded state, which is how an author tells it from
	// a real venue. Inline attribute, for the reason given above.
	await expect( block ).toHaveAttribute( 'style', /opacity:\s*0\.5/ );
} );
