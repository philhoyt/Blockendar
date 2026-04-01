import {
	RangeControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function MapSection( { settings, update } ) {
	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'Map', 'blockendar' ) }</h2>

			<RangeControl
				label={ __( 'Default zoom level', 'blockendar' ) }
				help={ __(
					'1 = world, 14 = city block, 20 = building.',
					'blockendar'
				) }
				value={ settings.map_default_zoom ?? 14 }
				min={ 1 }
				max={ 20 }
				onChange={ ( val ) => update( { map_default_zoom: val } ) }
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}
