/**
 * Venue geocoding — "Look up coordinates" button for the venue admin form.
 *
 * Reads blockendarGeocode (localized by VenueGeocode.php) and fires against
 * Nominatim (OSM) or the Google Geocoding API depending on the map provider.
 */
( function () {
	'use strict';

	var cfg = window.blockendarGeocode || {};

	function getAddressParts() {
		return {
			address: ( document.getElementById( 'blockendar_venue_address' ) || {} ).value || '',
			city:    ( document.getElementById( 'blockendar_venue_city' )    || {} ).value || '',
			state:   ( document.getElementById( 'blockendar_venue_state' )   || {} ).value || '',
			country: ( document.getElementById( 'blockendar_venue_country' ) || {} ).value || '',
		};
	}

	function setCoords( lat, lng ) {
		var latEl = document.getElementById( 'blockendar_venue_lat' );
		var lngEl = document.getElementById( 'blockendar_venue_lng' );
		if ( latEl ) latEl.value = lat;
		if ( lngEl ) lngEl.value = lng;
	}

	function showMessage( btn, msg ) {
		var existing = btn.parentNode.querySelector( '.blockendar-geocode-msg' );
		if ( existing ) existing.remove();
		var span = document.createElement( 'span' );
		span.className = 'blockendar-geocode-msg';
		span.style.marginLeft = '8px';
		span.style.color = '#d63638';
		span.textContent = msg;
		btn.parentNode.appendChild( span );
	}

	function clearMessage( btn ) {
		var existing = btn.parentNode.querySelector( '.blockendar-geocode-msg' );
		if ( existing ) existing.remove();
	}

	function geocodeNominatim( parts, btn ) {
		var query = [ parts.address, parts.city, parts.state, parts.country ]
			.filter( Boolean )
			.join( ', ' );

		var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q='
			+ encodeURIComponent( query );

		fetch( url, { headers: { 'Accept-Language': navigator.language || 'en' } } )
			.then( function ( r ) {
				if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
				return r.json();
			} )
			.then( function ( data ) {
				if ( ! data.length ) {
					showMessage( btn, cfg.msgNotFound || 'Address not found.' );
					return;
				}
				clearMessage( btn );
				setCoords(
					parseFloat( data[ 0 ].lat ).toFixed( 6 ),
					parseFloat( data[ 0 ].lon ).toFixed( 6 )
				);
			} )
			.catch( function () {
				showMessage( btn, cfg.msgError || 'Geocoding failed.' );
			} )
			.finally( function () {
				btn.disabled = false;
				btn.textContent = cfg.labelLookup || 'Look up coordinates';
			} );
	}

	function geocodeGoogle( parts, btn ) {
		var query = [ parts.address, parts.city, parts.state, parts.country ]
			.filter( Boolean )
			.join( ', ' );

		var url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
			+ encodeURIComponent( query )
			+ '&key=' + encodeURIComponent( cfg.googleApiKey || '' );

		fetch( url )
			.then( function ( r ) {
				if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
				return r.json();
			} )
			.then( function ( data ) {
				if ( data.status !== 'OK' || ! data.results.length ) {
					showMessage( btn, cfg.msgNotFound || 'Address not found.' );
					return;
				}
				clearMessage( btn );
				var loc = data.results[ 0 ].geometry.location;
				setCoords(
					loc.lat.toFixed( 6 ),
					loc.lng.toFixed( 6 )
				);
			} )
			.catch( function () {
				showMessage( btn, cfg.msgError || 'Geocoding failed.' );
			} )
			.finally( function () {
				btn.disabled = false;
				btn.textContent = cfg.labelLookup || 'Look up coordinates';
			} );
	}

	function onLookup( btn ) {
		var parts = getAddressParts();

		if ( ! parts.address && ! parts.city ) {
			showMessage( btn, cfg.msgNoAddress || 'Please enter an address or city first.' );
			return;
		}

		clearMessage( btn );
		btn.disabled = true;
		btn.textContent = cfg.labelLooking || 'Looking up…';

		if ( cfg.mapProvider === 'google' && cfg.googleApiKey ) {
			geocodeGoogle( parts, btn );
		} else {
			geocodeNominatim( parts, btn );
		}
	}

	function insertButton( afterId ) {
		var anchor = document.getElementById( afterId );
		if ( ! anchor ) return;

		// Walk up to the row container (tr or div.form-field).
		var row = anchor.closest( 'tr, .form-field' );
		if ( ! row ) return;

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button';
		btn.style.marginTop = '6px';
		btn.textContent = cfg.labelLookup || 'Look up coordinates';
		btn.addEventListener( 'click', function () { onLookup( btn ); } );

		// Insert after the row.
		row.parentNode.insertBefore( btn, row.nextSibling );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		// Insert after the country row (last address field).
		insertButton( 'blockendar_venue_country' );
	} );
}() );
