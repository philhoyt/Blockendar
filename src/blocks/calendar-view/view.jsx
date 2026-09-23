/**
 * calendar-view block — frontend view script.
 *
 * Renders a FullCalendar instance hydrated from the blockendar/v1/calendar endpoint.
 * Mounted into every .wp-block-blockendar-calendar-view element on the page.
 * Configuration is read from data-* attributes set by render.php.
 *
 * FullCalendar and its view plugins are loaded with dynamic import() so webpack
 * emits them as separate chunks: the entry script stays small, and a calendar
 * configured for month view alone never downloads the timeGrid or list code.
 */
import { createRoot, useRef, useEffect, useState } from '@wordpress/element';

const MOBILE_MQ = '(max-width: 767px)';
const MOBILE_VIEW = 'listNextMonth';
const DEFAULT_VIEWS = [ 'dayGridMonth', 'timeGridWeek', 'listNextMonth' ];

/**
 * Map a FullCalendar view name to the plugin package that provides it.
 *
 * @param {string} view View name, e.g. 'dayGridMonth' or 'listNextMonth'.
 * @return {string|null} Plugin key, or null when the view is unrecognised.
 */
function pluginForView( view ) {
	if ( view.startsWith( 'dayGrid' ) ) {
		return 'dayGrid';
	}
	if ( view.startsWith( 'timeGrid' ) ) {
		return 'timeGrid';
	}
	if ( view.startsWith( 'list' ) ) {
		return 'list';
	}
	return null;
}

/**
 * Dynamically load FullCalendar plus only the plugins the given views require.
 *
 * @param {string[]} views View names that must be renderable.
 * @return {Promise<{Calendar: Object, plugins: Object[]}>} Loaded module refs.
 */
async function loadCalendar( views ) {
	const needed = new Set();

	views.forEach( ( view ) => {
		const plugin = pluginForView( view );
		if ( plugin ) {
			needed.add( plugin );
		}
	} );

	// The mobile breakpoint always switches to a list view, so its plugin is
	// required regardless of which views the editor enabled.
	needed.add( pluginForView( MOBILE_VIEW ) );

	const [ { default: Calendar }, ...plugins ] = await Promise.all( [
		import( '@fullcalendar/react' ),
		...[ ...needed ].map( ( plugin ) => {
			if ( 'dayGrid' === plugin ) {
				return import( '@fullcalendar/daygrid' );
			}
			if ( 'timeGrid' === plugin ) {
				return import( '@fullcalendar/timegrid' );
			}
			return import( '@fullcalendar/list' );
		} ),
	] );

	return {
		Calendar,
		plugins: plugins.map( ( mod ) => mod.default ),
	};
}

/**
 * Read a JSON array out of a data attribute.
 *
 * render.php always writes these, but a hand-edited block or a truncated
 * response would otherwise throw during render and leave the container empty
 * with no calendar and no message.
 *
 * @param {string|undefined} raw      The data attribute value.
 * @param {Array}            fallback Value to use when parsing fails.
 * @return {Array} The parsed array, or the fallback.
 */
function parseList( raw, fallback = [] ) {
	if ( ! raw ) {
		return fallback;
	}

	try {
		const parsed = JSON.parse( raw );
		return Array.isArray( parsed ) ? parsed : fallback;
	} catch {
		return fallback;
	}
}

function BlockendarCalendar( { dataset, onReady } ) {
	const calendarRef = useRef( null );
	const [ loaded, setLoaded ] = useState( null );

	const restUrl = dataset.restUrl ?? '/wp-json/blockendar/v1';
	const venueIds = parseList( dataset.venueIds );
	const typeIds = parseList( dataset.typeIds );
	const featuredOnly = dataset.featuredOnly === 'true';
	const defaultView = dataset.defaultView || 'dayGridMonth';
	const firstDay = dataset.firstDay ? parseInt( dataset.firstDay, 10 ) : 0;
	const slotDuration = dataset.slotDuration || undefined;
	const timezone = dataset.timezone ?? 'UTC';
	const enabledViews = parseList( dataset.enabledViews, DEFAULT_VIEWS );

	const viewButtons = enabledViews.join( ',' );

	// Custom view: rolling 31-day list starting from today.
	const customViews = {
		listNextMonth: {
			type: 'list',
			duration: { days: 31 },
			buttonText: 'list',
		},
	};

	const isMobile = () => window.matchMedia( MOBILE_MQ ).matches;

	useEffect( () => {
		let cancelled = false;

		loadCalendar( [ ...enabledViews, defaultView ] )
			.then( ( result ) => {
				if ( ! cancelled ) {
					setLoaded( result );
					onReady?.();
				}
			} )
			.catch( () => {
				/*
				 * Deliberately no state change. The server-rendered list of
				 * upcoming events is still in the DOM — onReady() is what
				 * removes it — so a failed chunk load leaves the visitor with
				 * a usable list rather than an empty box.
				 */
			} );

		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		const mq = window.matchMedia( MOBILE_MQ );
		const onChange = ( e ) => {
			const api = calendarRef.current?.getApi();
			if ( ! api ) {
				return;
			}
			api.changeView( e.matches ? MOBILE_VIEW : defaultView );
		};
		mq.addEventListener( 'change', onChange );
		return () => mq.removeEventListener( 'change', onChange );
	}, [ defaultView ] );

	const fetchEvents = ( fetchInfo, successCallback, failureCallback ) => {
		const params = new URLSearchParams( {
			start: fetchInfo.startStr,
			end: fetchInfo.endStr,
			per_page: 500,
		} );

		if ( venueIds.length ) {
			params.set( 'venue', venueIds.join( ',' ) );
		}
		if ( typeIds.length ) {
			params.set( 'type', typeIds.join( ',' ) );
		}
		if ( featuredOnly ) {
			params.set( 'featured', '1' );
		}

		fetch( `${ restUrl }/calendar?${ params.toString() }` )
			.then( ( r ) => {
				if ( ! r.ok ) {
					throw new Error(
						`Blockendar: calendar fetch failed (${ r.status })`
					);
				}
				return r.json();
			} )
			.then( ( events ) => successCallback( events ) )
			.catch( failureCallback );
	};

	if ( ! loaded ) {
		return null;
	}

	const { Calendar, plugins } = loaded;

	return (
		<Calendar
			ref={ calendarRef }
			plugins={ plugins }
			timeZone={ timezone }
			initialView={ isMobile() ? MOBILE_VIEW : defaultView }
			firstDay={ firstDay }
			slotDuration={ slotDuration }
			views={ customViews }
			headerToolbar={ {
				left: 'prev,next today',
				center: 'title',
				right: viewButtons,
			} }
			events={ fetchEvents }
			dayMaxEvents={ 3 }
			eventDidMount={ ( info ) => {
				const color = info.event.backgroundColor;
				if ( color ) {
					info.el.style.setProperty(
						'--blockendar-event-color',
						color
					);
				}
			} }
			eventClick={ ( info ) => {
				if ( info.event.url ) {
					info.jsEvent.preventDefault();
					window.location.href = info.event.url;
				}
			} }
			height="auto"
		/>
	);
}

document
	.querySelectorAll( '.wp-block-blockendar-calendar-view' )
	.forEach( ( el ) => {
		/*
		 * React is given its own child node rather than the block wrapper.
		 * Rendering into the wrapper would make React the owner of its
		 * children and wipe the server-rendered fallback on the first pass —
		 * which returns null until the chunks arrive — so the page would go
		 * blank while loading and stay blank if loading failed.
		 */
		const fallback = el.querySelector(
			'.blockendar-calendar-fallback, .blockendar-calendar-fallback__empty'
		);
		const mount = document.createElement( 'div' );
		el.appendChild( mount );

		createRoot( mount ).render(
			<BlockendarCalendar
				dataset={ el.dataset }
				onReady={ () => fallback?.remove() }
			/>
		);
	} );
