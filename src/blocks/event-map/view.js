/**
 * event-map — frontend view script.
 *
 * Initialises an OpenStreetMap / Leaflet map for each .blockendar-event-map element.
 * Leaflet CSS/JS are loaded lazily only when map blocks are present.
 */
( function () {
	const maps = document.querySelectorAll( '.blockendar-event-map[data-lat]' );

	if ( ! maps.length ) {
		return;
	}

	function loadLeaflet() {
		return new Promise( ( resolve ) => {
			if ( window.L ) {
				resolve( window.L );
				return;
			}

			const link = document.createElement( 'link' );
			link.rel = 'stylesheet';
			link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
			document.head.appendChild( link );

			const script = document.createElement( 'script' );
			script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
			script.onload = () => resolve( window.L );
			document.head.appendChild( script );
		} );
	}

	loadLeaflet().then( ( L ) => {
		maps.forEach( ( el ) => {
			const lat = parseFloat( el.dataset.lat );
			const lng = parseFloat( el.dataset.lng );
			const zoom = parseInt( el.dataset.zoom ?? '14', 10 );
			const name = el.dataset.name ?? '';

			const map = L.map( el, { scrollWheelZoom: false } ).setView(
				[ lat, lng ],
				zoom
			);

			L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				attribution:
					'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				maxZoom: 19,
			} ).addTo( map );

			if ( name ) {
				L.marker( [ lat, lng ] ).addTo( map ).bindPopup( name );
			}
		} );
	} );
} )();
