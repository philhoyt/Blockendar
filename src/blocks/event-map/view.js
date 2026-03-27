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

const maps = document.querySelectorAll( '.blockendar-event-map[data-lat]' );

maps.forEach( ( el ) => {
	const lat = parseFloat( el.dataset.lat );
	const lng = parseFloat( el.dataset.lng );
	const zoom = parseInt( el.dataset.zoom ?? '14', 10 );
	const name = el.dataset.name ?? '';

	const map = L.map( el, { scrollWheelZoom: false } ).setView( [ lat, lng ], zoom );

	L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		attribution:
			'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		maxZoom: 19,
	} ).addTo( map );

	if ( name ) {
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
		L.marker( [ lat, lng ] ).addTo( map ).bindPopup( escapeHtml( name ) );
	}
} );
