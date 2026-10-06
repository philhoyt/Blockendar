/**
 * End-to-end coverage for the calendar-view block's display features added in
 * 2.3.0: the year view, nav links, week numbers, the events-per-day limit,
 * the venue and cost line in chips, and the week and day view settings.
 */

const { test, expect } = require( '@playwright/test' );
const { wpCli, wpCliId } = require( './wp-cli' );
const { ensureTerm, deleteTerm } = require( './terms' );

const VENUE_TAXONOMY = 'blockendar_event_venue';
const VENUE_NAME = 'Rock & Roll Hall';

/**
 * Noon on a day of the current month, as Y-m-d. The 15th sits in every month
 * view; the next ones follow it so all stay inside the month.
 *
 * @param {number} day Day of the month.
 * @return {string} Y-m-d.
 */
function thisMonth( day ) {
	const today = new Date();
	const date = new Date(
		Date.UTC( today.getFullYear(), today.getMonth(), day )
	);
	return date.toISOString().slice( 0, 10 );
}

/**
 * Create a published, indexed event on a date.
 *
 * @param {string} title  Post title.
 * @param {string} date   Y-m-d.
 * @param {Object} [meta] Extra meta.
 * @return {string} Post ID.
 */
function createEvent( title, date, meta = {} ) {
	const id = wpCliId( [
		'post',
		'create',
		'--post_type=blockendar_event',
		`--post_title=${ title }`,
		'--post_status=publish',
		'--porcelain',
	] );

	Object.entries( {
		blockendar_start_date: date,
		blockendar_end_date: date,
		blockendar_start_time: '09:00',
		blockendar_end_time: '11:00',
		blockendar_timezone: 'UTC',
		blockendar_status: 'scheduled',
		...meta,
	} ).forEach( ( [ key, value ] ) => {
		wpCli( [ 'post', 'meta', 'update', id, key, String( value ) ] );
	} );

	// Re-save so the index builder picks up the meta.
	wpCli( [ 'post', 'update', id, `--post_title=${ title }` ] );

	return id;
}

/**
 * Create a page holding one calendar block.
 *
 * @param {string} title Page title.
 * @param {Object} attrs Block attributes.
 * @return {string} Page ID.
 */
function createCalendarPage( title, attrs ) {
	return wpCliId( [
		'post',
		'create',
		'--post_type=page',
		`--post_title=${ title }`,
		'--post_status=publish',
		`--post_content=<!-- wp:blockendar/calendar-view ${ JSON.stringify(
			attrs
		) } /-->`,
		'--porcelain',
	] );
}

/**
 * Write the plugin settings option as JSON; an empty object restores defaults.
 *
 * @param {Object} settings Settings to store.
 */
function setSettings( settings ) {
	wpCli( [
		'option',
		'update',
		'blockendar_settings',
		JSON.stringify( settings ),
		'--format=json',
	] );
}

const posts = [];
let venueId;
let allViewsPage;
let yearAndDayPage;
let noLinksPage;

test.beforeAll( () => {
	venueId = ensureTerm( VENUE_TAXONOMY, VENUE_NAME, 'rock-roll-hall' );

	// One event with a venue and a cost, one cancelled, and four on the same
	// day to overflow a two-per-day limit.
	const venueEvent = createEvent( 'E2E Venue Event', thisMonth( 15 ), {
		blockendar_cost: '$12',
	} );
	wpCli( [
		'post',
		'term',
		'set',
		venueEvent,
		VENUE_TAXONOMY,
		'rock-roll-hall',
	] );
	// The term was set after the index row was written; save again.
	wpCli( [ 'post', 'update', venueEvent, '--post_title=E2E Venue Event' ] );
	posts.push( venueEvent );

	posts.push(
		createEvent( 'E2E Cancelled Event', thisMonth( 16 ), {
			blockendar_status: 'cancelled',
		} )
	);

	for ( let i = 1; i <= 4; i++ ) {
		posts.push( createEvent( `E2E Crowd Event ${ i }`, thisMonth( 20 ) ) );
	}

	allViewsPage = createCalendarPage( 'E2E Display All Views', {
		enabledViews: [
			'dayGridMonth',
			'timeGridWeek',
			'timeGridDay',
			'listNextMonth',
			'multiMonthYear',
		],
		weekNumbers: true,
		eventsPerDay: 2,
	} );
	yearAndDayPage = createCalendarPage( 'E2E Display Year And Day', {
		enabledViews: [ 'multiMonthYear', 'timeGridDay' ],
		defaultView: 'multiMonthYear',
	} );
	noLinksPage = createCalendarPage( 'E2E Display No Links', {
		enabledViews: [ 'dayGridMonth', 'listNextMonth' ],
	} );
	posts.push( allViewsPage, yearAndDayPage, noLinksPage );
} );

test.afterAll( () => {
	posts.forEach( ( id ) => wpCli( [ 'post', 'delete', id, '--force' ] ) );
	deleteTerm( VENUE_TAXONOMY, venueId );
} );

/**
 * Open a calendar page and wait for FullCalendar to mount.
 *
 * @param {import('@playwright/test').Page} page   Playwright page.
 * @param {string}                          pageId Page to open.
 */
async function openCalendar( page, pageId ) {
	await page.goto( `/?p=${ pageId }` );
	await expect( page.locator( '.fc' ) ).toBeVisible( { timeout: 15000 } );
}

test( 'the year view renders twelve months from its own chunk', async ( {
	page,
} ) => {
	const chunks = [];

	page.on( 'response', ( res ) => {
		const path = new URL( res.url() ).pathname;
		if ( path.includes( '/build/' ) && path.endsWith( '.js' ) ) {
			chunks.push( path.split( '/' ).pop() );
		}
	} );

	await openCalendar( page, allViewsPage );

	const yearButton = page.locator( '.fc-multiMonthYear-button' );
	await expect( yearButton ).toHaveText( 'year' );
	await yearButton.click();

	await expect( page.locator( '.fc-multimonth-month' ) ).toHaveCount( 12 );
	expect( chunks ).toContain( 'fullcalendar-multimonth.js' );
} );

test( 'a day with more events than the limit folds the rest into a popover', async ( {
	page,
} ) => {
	await openCalendar( page, allViewsPage );

	const cell = page.locator(
		`.fc-daygrid-day[data-date="${ thisMonth( 20 ) }"]`
	);

	// FullCalendar keeps the folded chips in the DOM, hidden, so count what
	// the visitor can see.
	await expect( cell.locator( '.fc-event:visible' ) ).toHaveCount( 2, {
		timeout: 15000,
	} );

	const more = cell.locator( '.fc-daygrid-more-link' );
	await expect( more ).toHaveText( '+2 more' );
	await more.click();

	// The popover lists the whole day, not only the two that were folded.
	await expect( page.locator( '.fc-popover .fc-event' ) ).toHaveCount( 4 );
} );

test( 'a week number links to its week', async ( { page } ) => {
	await openCalendar( page, allViewsPage );

	const weekNumber = page.locator( '.fc-daygrid-week-number' ).first();
	await expect( weekNumber ).toBeVisible();
	await weekNumber.click();

	await expect( page.locator( '.fc-timegrid' ) ).toBeVisible();
	await expect( page.locator( '.fc-timeGridWeek-button' ) ).toHaveClass(
		/fc-button-active/
	);
} );

test( 'a day number in the year view opens the day view', async ( {
	page,
} ) => {
	await openCalendar( page, yearAndDayPage );
	await expect( page.locator( '.fc-multimonth-month' ) ).toHaveCount( 12 );

	// The year view comes from the multimonth plugin and the day view from
	// timegrid, so this is a link across plugins.
	await page
		.locator(
			`.fc-daygrid-day[data-date="${ thisMonth(
				15
			) }"] .fc-daygrid-day-number`
		)
		.click();

	await expect( page.locator( '.fc-timegrid' ) ).toBeVisible();
	await expect( page.locator( '.fc-timeGridDay-button' ) ).toHaveClass(
		/fc-button-active/
	);
} );

test( 'day numbers are not links when there is no day or week view', async ( {
	page,
} ) => {
	await openCalendar( page, noLinksPage );

	const dayNumber = page.locator( '.fc-daygrid-day-number' ).first();
	await expect( dayNumber ).toBeVisible();
	await expect( dayNumber ).not.toHaveAttribute( 'data-navlink' );
} );

test( 'the list view shows the venue and cost and keeps the link', async ( {
	page,
} ) => {
	await openCalendar( page, allViewsPage );
	await page.locator( '.fc-listNextMonth-button' ).click();

	const row = page.locator( '.fc-list-event', {
		hasText: 'E2E Venue Event',
	} );
	await expect( row ).toBeVisible( { timeout: 15000 } );

	// The ampersand must come through as itself: neither escaped nor doubled.
	await expect(
		row.locator( '.blockendar-calendar-event__venue' )
	).toHaveText( VENUE_NAME );
	await expect(
		row.locator( '.blockendar-calendar-event__cost' )
	).toHaveText( '$12' );

	const link = row.locator( '.fc-list-event-title a' );
	await expect( link ).toHaveAttribute( 'href', /e2e-venue-event/ );

	const cancelled = page.locator( '.fc-list-event', {
		hasText: 'E2E Cancelled Event',
	} );
	await expect( cancelled.locator( '.fc-list-event-title' ) ).toHaveCSS(
		'text-decoration-line',
		'line-through'
	);

	await link.click();
	await page.waitForURL( /e2e-venue-event/, { timeout: 15000 } );
} );

test( 'the week view shows the venue and cost under the title', async ( {
	page,
} ) => {
	await openCalendar( page, allViewsPage );

	// Jump to the week holding the venue event by way of its day number.
	await page
		.locator(
			`.fc-daygrid-day[data-date="${ thisMonth(
				15
			) }"] .fc-daygrid-day-number`
		)
		.click();
	await page.locator( '.fc-timeGridWeek-button' ).click();

	const chip = page.locator( '.fc-timegrid-event', {
		hasText: 'E2E Venue Event',
	} );
	await expect( chip ).toBeVisible( { timeout: 15000 } );
	await expect( chip.locator( '.fc-event-title' ) ).toHaveText(
		'E2E Venue Event'
	);
	await expect(
		chip.locator( '.blockendar-calendar-event__venue' )
	).toHaveText( VENUE_NAME );
} );

/*
 * The week and day view settings. The tests site has no named timezone, so
 * the block tells FullCalendar to use the browser's clock, which Playwright
 * pins to a Wednesday at ten so the now line falls inside the visible hours.
 */
test.describe( 'with the hour range and business hours set', () => {
	test.beforeAll( () => {
		setSettings( {
			calendar_slot_min_time: '08:00:00',
			calendar_slot_max_time: '20:00:00',
			calendar_business_hours: true,
			calendar_business_days: [ 1, 2, 3, 4, 5 ],
			calendar_business_start: '09:00:00',
			calendar_business_end: '17:00:00',
		} );
	} );

	test.afterAll( () => {
		setSettings( {} );
	} );

	test( 'the week view is clipped, shaded outside business hours and shows the now line', async ( {
		page,
	} ) => {
		// The Wednesday of the current week, at 10:00 local time.
		const now = new Date();
		now.setDate( now.getDate() - now.getDay() + 3 );
		now.setHours( 10, 0, 0, 0 );
		await page.clock.setFixedTime( now );

		const ymd = ( d ) =>
			`${ d.getFullYear() }-${ String( d.getMonth() + 1 ).padStart(
				2,
				'0'
			) }-${ String( d.getDate() ).padStart( 2, '0' ) }`;
		const wednesday = ymd( now );
		const saturday = new Date( now );
		saturday.setDate( now.getDate() + 3 );

		await openCalendar( page, allViewsPage );
		await page.locator( '.fc-timeGridWeek-button' ).click();
		await expect( page.locator( '.fc-timegrid' ) ).toBeVisible();

		await expect(
			page.locator( '.fc-timegrid-slot-label-cushion' ).first()
		).toHaveText( '8am' );
		await expect(
			page.locator( '.fc-timegrid-slot-label-cushion' ).last()
		).toHaveText( '7pm' );

		// A weekday is shaded before nine and after five; a weekend day all day.
		await expect(
			page.locator(
				`.fc-timegrid-col[data-date="${ wednesday }"] .fc-non-business`
			)
		).toHaveCount( 2 );
		await expect(
			page.locator(
				`.fc-timegrid-col[data-date="${ ymd(
					saturday
				) }"] .fc-non-business`
			)
		).toHaveCount( 1 );

		await expect(
			page.locator( '.fc-timegrid-now-indicator-line' )
		).toBeVisible();
	} );
} );
