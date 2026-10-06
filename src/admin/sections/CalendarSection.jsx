import {
	CheckboxControl,
	SelectControl,
	ToggleControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const VIEW_OPTIONS = [
	{ label: __( 'Month (Grid)', 'blockendar' ), value: 'dayGridMonth' },
	{ label: __( 'Week (Time)', 'blockendar' ), value: 'timeGridWeek' },
	{ label: __( 'Day (Time)', 'blockendar' ), value: 'timeGridDay' },
	{ label: __( 'List (Next 31 days)', 'blockendar' ), value: 'listNextMonth' },
	{ label: __( 'Year', 'blockendar' ), value: 'multiMonthYear' },
];

// All seven: the default follows WordPress's "Week Starts On", which can be
// any of them, and a default that is not in the list could not be reselected.
const FIRST_DAY_OPTIONS = [
	{ label: __( 'Sunday', 'blockendar' ), value: 0 },
	{ label: __( 'Monday', 'blockendar' ), value: 1 },
	{ label: __( 'Tuesday', 'blockendar' ), value: 2 },
	{ label: __( 'Wednesday', 'blockendar' ), value: 3 },
	{ label: __( 'Thursday', 'blockendar' ), value: 4 },
	{ label: __( 'Friday', 'blockendar' ), value: 5 },
	{ label: __( 'Saturday', 'blockendar' ), value: 6 },
];

const SLOT_DURATION_OPTIONS = [
	{ label: __( '15 minutes', 'blockendar' ), value: '00:15:00' },
	{ label: __( '30 minutes', 'blockendar' ), value: '00:30:00' },
	{ label: __( '1 hour', 'blockendar' ), value: '01:00:00' },
];

/*
 * Whole hours, 00:00 to 24:00. The sanitiser accepts any HH:MM:SS, but a
 * select of hours cannot produce a value it would reject, and nobody has
 * asked for a calendar that starts at twenty past.
 */
const HOUR_OPTIONS = Array.from( { length: 25 }, ( _, hour ) => ( {
	value: `${ String( hour ).padStart( 2, '0' ) }:00:00`,
	label:
		24 === hour
			? __( 'Midnight (end of day)', 'blockendar' )
			: sprintf(
					/* translators: %s: an hour of the day, 00 to 23. */
					__( '%s:00', 'blockendar' ),
					String( hour ).padStart( 2, '0' )
			  ),
} ) );

const DEFAULT_BUSINESS_DAYS = [ 1, 2, 3, 4, 5 ];

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

			<h3>{ __( 'Week and day views', 'blockendar' ) }</h3>

			<SelectControl
				label={ __( 'First hour shown', 'blockendar' ) }
				help={ __(
					'Earlier hours are cut off. An event that starts before this still appears, clipped to the first hour.',
					'blockendar'
				) }
				value={ settings.calendar_slot_min_time ?? '00:00:00' }
				options={ HOUR_OPTIONS.slice( 0, 24 ) }
				onChange={ ( val ) =>
					update( { calendar_slot_min_time: val } )
				}
				__nextHasNoMarginBottom
			/>

			<SelectControl
				label={ __( 'Last hour shown', 'blockendar' ) }
				help={ __(
					'Must be later than the first hour, or both go back to the full day.',
					'blockendar'
				) }
				value={ settings.calendar_slot_max_time ?? '24:00:00' }
				options={ HOUR_OPTIONS.slice( 1 ) }
				onChange={ ( val ) =>
					update( { calendar_slot_max_time: val } )
				}
				__nextHasNoMarginBottom
			/>

			<ToggleControl
				label={ __( 'Show the all-day row', 'blockendar' ) }
				help={ __(
					'The row above the hours that holds all-day events. Turning it off removes all-day events from the week and day views entirely; they have nowhere else to go.',
					'blockendar'
				) }
				checked={ settings.calendar_all_day_slot ?? true }
				onChange={ ( val ) => update( { calendar_all_day_slot: val } ) }
				__nextHasNoMarginBottom
			/>

			<ToggleControl
				label={ __( 'Shade hours outside business hours', 'blockendar' ) }
				help={ __(
					'Tints the hours and days you are not open, so the hours you are stand out.',
					'blockendar'
				) }
				checked={ settings.calendar_business_hours ?? false }
				onChange={ ( val ) =>
					update( { calendar_business_hours: val } )
				}
				__nextHasNoMarginBottom
			/>

			{ settings.calendar_business_hours && (
				<BusinessHours settings={ settings } update={ update } />
			) }
		</VStack>
	);
}

function BusinessHours( { settings, update } ) {
	const days = settings.calendar_business_days ?? DEFAULT_BUSINESS_DAYS;

	const toggleDay = ( day, checked ) => {
		const next = checked
			? [ ...days, day ].sort()
			: days.filter( ( d ) => d !== day );

		update( { calendar_business_days: next } );
	};

	return (
		<VStack spacing={ 3 }>
			<fieldset style={ { margin: 0, padding: 0, border: 'none' } }>
				<legend style={ { marginBottom: 6, fontWeight: 600 } }>
					{ __( 'Open on', 'blockendar' ) }
				</legend>
				{ FIRST_DAY_OPTIONS.map( ( { label, value } ) => (
					<CheckboxControl
						key={ value }
						label={ label }
						checked={ days.includes( value ) }
						onChange={ ( checked ) => toggleDay( value, checked ) }
						__nextHasNoMarginBottom
					/>
				) ) }
				{ 0 === days.length && (
					<p style={ { margin: '6px 0 0', color: '#757575' } }>
						{ __(
							'With no days chosen, nothing is shaded.',
							'blockendar'
						) }
					</p>
				) }
			</fieldset>

			<SelectControl
				label={ __( 'Open from', 'blockendar' ) }
				value={ settings.calendar_business_start ?? '09:00:00' }
				options={ HOUR_OPTIONS.slice( 0, 24 ) }
				onChange={ ( val ) =>
					update( { calendar_business_start: val } )
				}
				__nextHasNoMarginBottom
			/>

			<SelectControl
				label={ __( 'Open until', 'blockendar' ) }
				help={ __(
					'Must be later than the opening hour, or both go back to 09:00 to 17:00.',
					'blockendar'
				) }
				value={ settings.calendar_business_end ?? '17:00:00' }
				options={ HOUR_OPTIONS.slice( 1 ) }
				onChange={ ( val ) =>
					update( { calendar_business_end: val } )
				}
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}
