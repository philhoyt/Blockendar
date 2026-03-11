import {
	SelectControl,
	TextControl,
	RangeControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const PROVIDER_OPTIONS = [
	{ label: __( 'OpenStreetMap / Leaflet (free, no key required)', 'blockendar' ), value: 'openstreetmap' },
	{ label: __( 'Google Maps (requires API key)',                   'blockendar' ), value: 'google' },
];

export function MapSection( { settings, update } ) {
	const provider = settings.map_provider ?? 'openstreetmap';

	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'Map', 'blockendar' ) }</h2>

			<SelectControl
				label={ __( 'Map provider', 'blockendar' ) }
				value={ provider }
				options={ PROVIDER_OPTIONS }
				onChange={ ( val ) => update( { map_provider: val } ) }
				__nextHasNoMarginBottom
			/>

			{ provider === 'google' && (
				<TextControl
					label={ __( 'Google Maps API key', 'blockendar' ) }
					help={ __( 'Required for Google Maps embed. Get a key from the Google Cloud Console.', 'blockendar' ) }
					value={ settings.google_maps_api_key ?? '' }
					type="password"
					onChange={ ( val ) => update( { google_maps_api_key: val } ) }
					__nextHasNoMarginBottom
				/>
			) }

			<RangeControl
				label={ __( 'Default zoom level', 'blockendar' ) }
				help={ __( '1 = world, 14 = city block, 20 = building.', 'blockendar' ) }
				value={ settings.map_default_zoom ?? 14 }
				min={ 1 }
				max={ 20 }
				onChange={ ( val ) => update( { map_default_zoom: val } ) }
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}
