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
import {
	createRoot,
	useCallback,
	useRef,
	useEffect,
	useState,
} from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { eventTimeFormat, localeCandidates } from './locale';
import { LOCALE_LOADERS } from './locale-loaders';

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
 * Load FullCalendar's strings for the site's language.
 *
 * The first candidate FullCalendar ships is loaded. If it ships none of them,
 * the calendar stays in English.
 *
 * @param {string[]} candidates Locale file names, best first.
 * @return {Promise<Object|null>} A FullCalendar locale object, or null.
 */
async function loadLocale( candidates ) {
	for ( const code of candidates ) {
		const load = LOCALE_LOADERS[ code ];

		if ( ! load ) {
			continue;
		}

		try {
			return ( await load() ).default;
		} catch {
			// The chunk did not arrive. English is better than no calendar.
			return null;
		}
	}

	return null;
}

/**
 * Dynamically load FullCalendar plus only the plugins the given views require.
 *
 * @param {string[]} views            View names that must be renderable.
 * @param {string[]} localeCandidates Locale file names to try, best first.
 * @return {Promise<{Calendar: Object, plugins: Object[], locale: Object|null}>} Loaded module refs.
 */
async function loadCalendar( views, localeCandidates ) {
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

	const [ locale, { default: Calendar }, ...plugins ] = await Promise.all( [
		loadLocale( localeCandidates ),
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
		locale,
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
	const [ failed, setFailed ] = useState( false );

	const restUrl = dataset.restUrl ?? '/wp-json/blockendar/v1';
	const restNonce = dataset.restNonce;
	const venueIds = parseList( dataset.venueIds );
	const typeIds = parseList( dataset.typeIds );
	const featuredOnly = dataset.featuredOnly === 'true';
	const defaultView = dataset.defaultView || 'dayGridMonth';
	const firstDay = dataset.firstDay ? parseInt( dataset.firstDay, 10 ) : 0;
	const slotDuration = dataset.slotDuration || undefined;
	const timezone = dataset.timezone ?? 'UTC';
	const enabledViews = parseList( dataset.enabledViews, DEFAULT_VIEWS );

	const viewButtons = enabledViews.join( ',' );

	// Custom view: rolling 31-day list starting from today. FullCalendar has no
	// label of its own for a custom view, so it borrows the locale's for "list".
	const customViews = {
		listNextMonth: {
			type: 'list',
			duration: { days: 31 },
			buttonText:
				loaded?.locale?.buttonText?.list ?? __( 'list', 'blockendar' ),
		},
	};

	const isMobile = () => window.matchMedia( MOBILE_MQ ).matches;

	useEffect( () => {
		let cancelled = false;

		loadCalendar(
			[ ...enabledViews, defaultView ],
			localeCandidates( dataset.locale )
		)
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

	/*
	 * Memoised because it sets state. FullCalendar refetches whenever the
	 * function it is given changes, and a new one on every render would turn
	 * one failure into a loop. Everything it reads comes from the block's data
	 * attributes, which do not change.
	 */
	// eslint-disable-next-line react-hooks/exhaustive-deps
	const fetchEvents = useCallback(
		( fetchInfo, successCallback, failureCallback ) => {
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

			// Present only for a logged-in visitor on a site whose REST API is not
			// public; without it the cookie is ignored and the request is anonymous.
			const headers = restNonce ? { 'X-WP-Nonce': restNonce } : {};

			fetch( `${ restUrl }/calendar?${ params.toString() }`, { headers } )
				.then( ( r ) => {
					if ( ! r.ok ) {
						throw new Error(
							`Blockendar: calendar fetch failed (${ r.status })`
						);
					}
					return r.json();
				} )
				.then( ( events ) => {
					setFailed( false );
					successCallback( events );

					// The grid changes without the page reloading; say what
					// arrived to someone who cannot see it.
					speak(
						sprintf(
							/* translators: %d: number of events. */
							_n(
								'%d event loaded.',
								'%d events loaded.',
								events.length,
								'blockendar'
							),
							events.length
						)
					);
				} )
				.catch( ( error ) => {
					// The server-rendered list is gone by now, so without this
					// the visitor is left with an empty grid and no reason.
					setFailed( true );
					failureCallback( error );
				} );
		},
		[]
	);

	if ( ! loaded ) {
		return null;
	}

	const { Calendar, plugins, locale } = loaded;

	return (
		<>
			{ failed && (
				<div className="blockendar-calendar-error" role="alert">
					<p>
						{ __(
							'The events could not be loaded.',
							'blockendar'
						) }
					</p>
					<button
						type="button"
						className="wp-element-button"
						onClick={ () =>
							calendarRef.current?.getApi().refetchEvents()
						}
					>
						{ __( 'Try again', 'blockendar' ) }
					</button>
				</div>
			) }
			<Calendar
				ref={ calendarRef }
				plugins={ plugins }
				locale={ locale ?? undefined }
				direction={ 'rtl' === dataset.direction ? 'rtl' : 'ltr' }
				eventTimeFormat={ eventTimeFormat( dataset.timeFormat ) }
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
				eventClick={ ( info ) => {
					if ( info.event.url ) {
						info.jsEvent.preventDefault();
						window.location.href = info.event.url;
					}
				} }
				height="auto"
			/>
		</>
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
