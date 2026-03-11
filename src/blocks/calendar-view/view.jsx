/**
 * calendar-view block — frontend view script.
 *
 * Renders a FullCalendar instance hydrated from the blockendar/v1/calendar endpoint.
 * Mounted into every .wp-block-blockendar-calendar-view element on the page.
 * Configuration is read from data-* attributes set by render.php.
 */
import { createRoot }    from '@wordpress/element';
import FullCalendar      from '@fullcalendar/react';
import dayGridPlugin     from '@fullcalendar/daygrid';
import timeGridPlugin    from '@fullcalendar/timegrid';
import listPlugin        from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';

function BlockendarCalendar( { dataset } ) {
	const restUrl      = dataset.restUrl      ?? '/wp-json/blockendar/v1';
	const venueId      = dataset.venueId      ? parseInt( dataset.venueId, 10 )    : undefined;
	const typeId       = dataset.typeId       ? parseInt( dataset.typeId, 10 )     : undefined;
	const featuredOnly = dataset.featuredOnly === 'true';
	const defaultView  = dataset.defaultView  ?? 'dayGridMonth';
	const firstDay     = dataset.firstDay     ? parseInt( dataset.firstDay, 10 )   : 0;
	const enabledViews = dataset.enabledViews
		? JSON.parse( dataset.enabledViews )
		: [ 'dayGridMonth', 'timeGridWeek', 'listNextMonth' ];

	const viewButtons = enabledViews.join( ',' );

	// Custom view: rolling 31-day list starting from today.
	const customViews = {
		listNextMonth: {
			type:       'list',
			duration:   { days: 31 },
			buttonText: 'list',
		},
	};

	const fetchEvents = ( fetchInfo, successCallback, failureCallback ) => {
		const params = new URLSearchParams( {
			start:    fetchInfo.startStr,
			end:      fetchInfo.endStr,
			per_page: 500,
		} );

		if ( venueId )      params.set( 'venue',    venueId );
		if ( typeId )       params.set( 'type',     typeId );
		if ( featuredOnly ) params.set( 'featured', '1' );

		fetch( `${ restUrl }/calendar?${ params.toString() }` )
			.then( ( r ) => r.json() )
			.then( ( events ) => successCallback( events ) )
			.catch( failureCallback );
	};

	return (
		<FullCalendar
			plugins={ [ dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin ] }
			initialView={ defaultView }
			firstDay={ firstDay }
			views={ customViews }
			headerToolbar={ {
				left:   'prev,next today',
				center: 'title',
				right:  viewButtons,
			} }
			events={ fetchEvents }
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

document.querySelectorAll( '.wp-block-blockendar-calendar-view' ).forEach( ( el ) => {
	createRoot( el ).render( <BlockendarCalendar dataset={ el.dataset } /> );
} );
