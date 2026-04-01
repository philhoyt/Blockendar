/**
 * event-map — frontend view script.
 *
 * Initialises an OpenStreetMap / Leaflet map for each .blockendar-event-map element.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

// Webpack breaks Leaflet's default icon URL resolution; supply the assets explicitly.
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions( {
	iconUrl: markerIcon,
	iconRetinaUrl: markerIcon2x,
	shadowUrl: markerShadow,
} );

const escapeHtml = ( str ) =>
	str.replace(
		/[&<>"']/g,
		( c ) =>
			( {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;',
			} )[ c ]
	);

document.querySelectorAll( '.blockendar-event-map[data-pins]' ).forEach( ( el ) => {
	let pins;
	try {
		pins = JSON.parse( el.dataset.pins );
	} catch {
		return;
	}

	if ( ! pins.length ) return;

	const zoom = parseInt( el.dataset.zoom ?? '14', 10 );
	const map = L.map( el, { scrollWheelZoom: false } );

	L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		attribution:
			'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		maxZoom: 19,
	} ).addTo( map );

	const markers = pins.map( ( pin ) => {
		const marker = L.marker( [ pin.lat, pin.lng ] ).addTo( map );
		if ( pin.name ) {
			marker.bindPopup( escapeHtml( pin.name ) );
		}
		return marker;
	} );

	if ( pins.length === 1 ) {
		map.setView( [ pins[ 0 ].lat, pins[ 0 ].lng ], zoom );
	} else {
		const group = L.featureGroup( markers );
		map.fitBounds( group.getBounds(), { padding: [ 40, 40 ] } );
	}
} );
