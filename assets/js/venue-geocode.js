/**
 * Venue geocoding — "Look up coordinates" button for the venue admin form.
 *
 * Uses Nominatim (OpenStreetMap) to resolve an address to lat/lng.
 * Reads blockendarGeocode (localized by VenueGeocode.php).
 */
( function () {
	'use strict';

	const cfg = window.blockendarGeocode || {};

	function getAddressParts() {
		return {
			address:
				( document.getElementById( 'blockendar_venue_address' ) || {} )
					.value || '',
			city:
				( document.getElementById( 'blockendar_venue_city' ) || {} )
					.value || '',
			state:
				( document.getElementById( 'blockendar_venue_state' ) || {} )
					.value || '',
			country:
				( document.getElementById( 'blockendar_venue_country' ) || {} )
					.value || '',
		};
	}

	function setCoords( lat, lng ) {
		const latEl = document.getElementById( 'blockendar_venue_lat' );
		const lngEl = document.getElementById( 'blockendar_venue_lng' );
		if ( latEl ) {
			latEl.value = lat;
		}
		if ( lngEl ) {
			lngEl.value = lng;
		}
	}

	function showMessage( btn, msg ) {
		const existing = btn.parentNode.querySelector(
			'.blockendar-geocode-msg'
		);
		if ( existing ) {
			existing.remove();
		}
		const span = document.createElement( 'span' );
		span.className = 'blockendar-geocode-msg';
		span.style.marginLeft = '8px';
		span.style.color = '#d63638';
		span.textContent = msg;
		btn.parentNode.appendChild( span );
	}

	function clearMessage( btn ) {
		const existing = btn.parentNode.querySelector(
			'.blockendar-geocode-msg'
		);
		if ( existing ) {
			existing.remove();
		}
	}

	function geocode( parts, btn ) {
		const query = [ parts.address, parts.city, parts.state, parts.country ]
			.filter( Boolean )
			.join( ', ' );

		const url =
			'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' +
			encodeURIComponent( query );

		fetch( url, {
			headers: { 'Accept-Language': navigator.language || 'en' },
		} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'HTTP ' + r.status );
				}
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

	function onLookup( btn ) {
		const parts = getAddressParts();

		if ( ! parts.address && ! parts.city ) {
			showMessage(
				btn,
				cfg.msgNoAddress || 'Please enter an address or city first.'
			);
			return;
		}

		clearMessage( btn );
		btn.disabled = true;
		btn.textContent = cfg.labelLooking || 'Looking up…';

		geocode( parts, btn );
	}

	function insertButton( afterId ) {
		const anchor = document.getElementById( afterId );
		if ( ! anchor ) {
			return;
		}

		const row = anchor.closest( 'tr, .form-field' );
		if ( ! row ) {
			return;
		}

		const btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button';
		btn.style.marginTop = '6px';
		btn.textContent = cfg.labelLookup || 'Look up coordinates';
		btn.addEventListener( 'click', function () {
			onLookup( btn );
		} );

		row.parentNode.insertBefore( btn, row.nextSibling );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		insertButton( 'blockendar_venue_country' );
	} );
} )();
