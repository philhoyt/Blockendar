/**
 * event-countdown — frontend view script.
 * Hydrates .blockendar-event-countdown elements with a live ticker.
 */
document.querySelectorAll( '.blockendar-event-countdown' ).forEach( ( el ) => {
	const target       = new Date( el.dataset.target );
	const expiredLabel = el.dataset.expiredLabel ?? 'This event has started.';
	const format       = el.dataset.format ?? 'd:h:m:s';
	const segments     = new Set( format.split( ':' ) );

	const labels = { d: 'days', h: 'hours', m: 'minutes', s: 'seconds' };
	const pad    = ( n ) => String( n ).padStart( 2, '0' );

	let timer;
	const tick = () => {
		if ( ! el.isConnected ) {
			clearTimeout( timer );
			return;
		}

		const diff = target - Date.now();

		if ( diff <= 0 ) {
			el.textContent = expiredLabel;
			return;
		}

		const days    = Math.floor( diff / 86_400_000 );
		const hours   = Math.floor( ( diff % 86_400_000 ) / 3_600_000 );
		const minutes = Math.floor( ( diff % 3_600_000 ) / 60_000 );
		const seconds = Math.floor( ( diff % 60_000 ) / 1_000 );

		const allSegments = [
			{ key: 'd', value: String( days ) },
			{ key: 'h', value: pad( hours ) },
			{ key: 'm', value: pad( minutes ) },
			{ key: 's', value: pad( seconds ) },
		];

		el.innerHTML = allSegments
			.filter( ( { key } ) => segments.has( key ) )
			.map(
				( { key, value } ) =>
					`<span class="blockendar-countdown__segment">` +
					`<strong>${ value }</strong>` +
					` <span class="blockendar-countdown__unit">${ labels[ key ] }</span>` +
					`</span>`
			)
			.join( ' ' );

		timer = setTimeout( tick, 1_000 );
	};

	tick();
} );
