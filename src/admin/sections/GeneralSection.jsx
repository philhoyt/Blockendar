import {
	TextControl,
	RadioControl,
	SelectControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const TIMEZONE_OPTIONS = [
	{ label: __( 'Use each event\'s own timezone', 'blockendar' ), value: 'event' },
	{ label: __( 'Always use the site timezone',   'blockendar' ), value: 'site' },
];

const TIME_FORMAT_OPTIONS = [
	{
		label: __( '12-hour  (9:30 am / 2:15 pm)', 'blockendar' ),
		value: 'g:i a',
	},
	{
		label: __( '24-hour  (09:30 / 14:15)', 'blockendar' ),
		value: 'H:i',
	},
];

export function GeneralSection( { settings, update } ) {
	// Detect current mode from whatever format string is stored.
	const stored      = settings.time_format ?? 'g:i a';
	const timeFormatValue = ( stored.includes( 'g' ) || stored.includes( 'h' ) )
		? 'g:i a'
		: 'H:i';

	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'General', 'blockendar' ) }</h2>

			<TextControl
				label={ __( 'Date format', 'blockendar' ) }
				help={
					<>
						{ __( 'PHP date format string. Preview: ', 'blockendar' ) }
						<code>{ new Date().toLocaleDateString() }</code>
					</>
				}
				value={ settings.date_format ?? '' }
				onChange={ ( val ) => update( { date_format: val } ) }
				__nextHasNoMarginBottom
			/>

			<RadioControl
				label={ __( 'Time format', 'blockendar' ) }
				help={ __( 'Used in the event editor time picker and on the frontend.', 'blockendar' ) }
				selected={ timeFormatValue }
				options={ TIME_FORMAT_OPTIONS }
				onChange={ ( val ) => update( { time_format: val } ) }
			/>

			<SelectControl
				label={ __( 'Timezone display', 'blockendar' ) }
				help={ __( 'Controls how event times are displayed to visitors.', 'blockendar' ) }
				value={ settings.timezone_mode ?? 'event' }
				options={ TIMEZONE_OPTIONS }
				onChange={ ( val ) => update( { timezone_mode: val } ) }
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}
