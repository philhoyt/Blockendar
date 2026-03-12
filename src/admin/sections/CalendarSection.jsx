import {
	SelectControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const VIEW_OPTIONS = [
	{ label: __( 'Month (Grid)', 'blockendar' ), value: 'dayGridMonth' },
	{ label: __( 'Week (Time)', 'blockendar' ), value: 'timeGridWeek' },
	{ label: __( 'Day (Time)', 'blockendar' ), value: 'timeGridDay' },
	{ label: __( 'List (Week)', 'blockendar' ), value: 'listWeek' },
];

const FIRST_DAY_OPTIONS = [
	{ label: __( 'Sunday', 'blockendar' ), value: 0 },
	{ label: __( 'Monday', 'blockendar' ), value: 1 },
	{ label: __( 'Saturday', 'blockendar' ), value: 6 },
];

const SLOT_DURATION_OPTIONS = [
	{ label: __( '15 minutes', 'blockendar' ), value: '00:15:00' },
	{ label: __( '30 minutes', 'blockendar' ), value: '00:30:00' },
	{ label: __( '1 hour', 'blockendar' ), value: '01:00:00' },
];

export function CalendarSection( { settings, update } ) {
	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'Calendar Display', 'blockendar' ) }</h2>

			<SelectControl
				label={ __( 'Default view', 'blockendar' ) }
				help={ __(
					'The view shown when a visitor first loads the calendar block.',
					'blockendar'
				) }
				value={ settings.calendar_default_view ?? 'dayGridMonth' }
				options={ VIEW_OPTIONS }
				onChange={ ( val ) => update( { calendar_default_view: val } ) }
				__nextHasNoMarginBottom
			/>

			<SelectControl
				label={ __( 'First day of week', 'blockendar' ) }
				value={ settings.calendar_first_day ?? 0 }
				options={ FIRST_DAY_OPTIONS }
				onChange={ ( val ) =>
					update( { calendar_first_day: parseInt( val, 10 ) } )
				}
				__nextHasNoMarginBottom
			/>

			<SelectControl
				label={ __( 'Time slot duration', 'blockendar' ) }
				help={ __(
					'Height of each time slot in week/day views.',
					'blockendar'
				) }
				value={ settings.calendar_slot_duration ?? '00:30:00' }
				options={ SLOT_DURATION_OPTIONS }
				onChange={ ( val ) =>
					update( { calendar_slot_duration: val } )
				}
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}
