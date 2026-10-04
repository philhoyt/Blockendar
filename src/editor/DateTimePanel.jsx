/**
 * Date & Time sidebar panel for blockendar_event.
 * Includes recurrence settings.
 */
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	ToggleControl,
	SelectControl,
	BaseControl,
	TextControl,
	DatePicker,
	RadioControl,
	Notice,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	endDateUpdates,
	endTimeUpdates,
	minuteOptions,
	startTimeUpdates,
} from './datetime';
import { getOngoingMetaUpdates } from './ongoing';
import { dateParts, formFromRule, ruleFromForm } from './recurrence';

const {
	timezones = [],
	siteTimezone = 'UTC',
	is12Hour = true,
} = window.blockendarEditor ?? {};

// ---------------------------------------------------------------------------
// DateInput — native <input type="date"> styled to match WP components
// ---------------------------------------------------------------------------

function DateInput( { value, onChange } ) {
	return (
		<input
			type="date"
			value={ value }
			onChange={ ( e ) => onChange( e.target.value ) }
			style={ {
				display: 'block',
				width: '100%',
				boxSizing: 'border-box',
				padding: '6px 10px',
				border: '1px solid #757575',
				borderRadius: '2px',
				fontFamily: 'inherit',
				fontSize: '13px',
				lineHeight: '1.4',
				color: 'inherit',
				background: '#fff',
			} }
		/>
	);
}

// ---------------------------------------------------------------------------
// TimeSelect — clean select-based time picker
// ---------------------------------------------------------------------------

const HOUR_OPTIONS_12 = Array.from( { length: 12 }, ( _, i ) => {
	const v = String( i + 1 );
	return { label: v, value: v };
} );

const HOUR_OPTIONS_24 = Array.from( { length: 24 }, ( _, i ) => {
	const v = String( i ).padStart( 2, '0' );
	return { label: v, value: v };
} );

const AMPM_OPTIONS = [
	{ label: 'AM', value: 'AM' },
	{ label: 'PM', value: 'PM' },
];

function parseTime( hhmm ) {
	if ( ! hhmm ) {
		return { h24: 9, m: 0 };
	}
	const [ hStr, mStr ] = hhmm.split( ':' );
	return {
		h24: parseInt( hStr ?? '9', 10 ),
		m: parseInt( mStr ?? '0', 10 ),
	};
}

function toHHMM( h24, m ) {
	return `${ String( h24 ).padStart( 2, '0' ) }:${ String( m ).padStart(
		2,
		'0'
	) }`;
}

function TimeSelect( { value, onChange } ) {
	const { h24, m } = parseTime( value );

	// The stored minute, as it is. Rounding it to the five-minute grid showed a
	// time the event did not have, and changing the hour then saved it.
	const minuteStr = String( m ).padStart( 2, '0' );
	const MINUTE_OPTIONS = minuteOptions( m );

	if ( is12Hour ) {
		const isPm = h24 >= 12;
		const h12raw = h24 % 12;
		const h12str = String( h12raw === 0 ? 12 : h12raw );

		const onHour = ( newH12str ) => {
			const h12 = parseInt( newH12str, 10 );
			let h24n = h12 % 12;
			if ( isPm ) {
				h24n += 12;
			}
			onChange( toHHMM( h24n, m ) );
		};

		const onAmPm = ( ampm ) => {
			const pm = ampm === 'PM';
			const h12c = parseInt( h12str, 10 );
			let h24n = h12c % 12;
			if ( pm ) {
				h24n += 12;
			}
			onChange( toHHMM( h24n, m ) );
		};

		return (
			<HStack
				spacing={ 1 }
				alignment="left"
				style={ { flexWrap: 'nowrap' } }
			>
				<div style={ { width: 64 } }>
					<SelectControl
						label={ __( 'Hour', 'blockendar' ) }
						hideLabelFromVision
						value={ h12str }
						options={ HOUR_OPTIONS_12 }
						onChange={ onHour }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
				<div style={ { width: 64 } }>
					<SelectControl
						label={ __( 'Minute', 'blockendar' ) }
						hideLabelFromVision
						value={ minuteStr }
						options={ MINUTE_OPTIONS }
						onChange={ ( min ) =>
							onChange( toHHMM( h24, parseInt( min, 10 ) ) )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
				<div style={ { width: 74 } }>
					<SelectControl
						label={ __( 'AM or PM', 'blockendar' ) }
						hideLabelFromVision
						value={ isPm ? 'PM' : 'AM' }
						options={ AMPM_OPTIONS }
						onChange={ onAmPm }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
			</HStack>
		);
	}

	return (
		<HStack spacing={ 1 } alignment="left" style={ { flexWrap: 'nowrap' } }>
			<div style={ { width: 72 } }>
				<SelectControl
					label={ __( 'Hour', 'blockendar' ) }
					hideLabelFromVision
					value={ String( h24 ).padStart( 2, '0' ) }
					options={ HOUR_OPTIONS_24 }
					onChange={ ( h ) =>
						onChange( toHHMM( parseInt( h, 10 ), m ) )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>
			<div style={ { width: 72 } }>
				<SelectControl
					label={ __( 'Minute', 'blockendar' ) }
					hideLabelFromVision
					value={ minuteStr }
					options={ MINUTE_OPTIONS }
					onChange={ ( min ) =>
						onChange( toHHMM( h24, parseInt( min, 10 ) ) )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>
		</HStack>
	);
}

// ---------------------------------------------------------------------------
// Recurrence helpers
// ---------------------------------------------------------------------------

const WEEKDAY_NAMES = [
	__( 'Sunday', 'blockendar' ),
	__( 'Monday', 'blockendar' ),
	__( 'Tuesday', 'blockendar' ),
	__( 'Wednesday', 'blockendar' ),
	__( 'Thursday', 'blockendar' ),
	__( 'Friday', 'blockendar' ),
	__( 'Saturday', 'blockendar' ),
];
const NTH_LABELS = [
	'',
	__( 'first', 'blockendar' ),
	__( 'second', 'blockendar' ),
	__( 'third', 'blockendar' ),
	__( 'fourth', 'blockendar' ),
	__( 'fifth', 'blockendar' ),
];
const MONTH_NAMES = [
	__( 'January', 'blockendar' ),
	__( 'February', 'blockendar' ),
	__( 'March', 'blockendar' ),
	__( 'April', 'blockendar' ),
	__( 'May', 'blockendar' ),
	__( 'June', 'blockendar' ),
	__( 'July', 'blockendar' ),
	__( 'August', 'blockendar' ),
	__( 'September', 'blockendar' ),
	__( 'October', 'blockendar' ),
	__( 'November', 'blockendar' ),
	__( 'December', 'blockendar' ),
];

function getDateParts( dateStr ) {
	const parts = dateParts( dateStr );

	if ( ! parts ) {
		return null;
	}

	return {
		...parts,
		dayName: WEEKDAY_NAMES[ parts.dow ],
		nthLabel: NTH_LABELS[ parts.nth ],
		monthName: MONTH_NAMES[ parts.month ],
	};
}

function buildFreqOptions( startDate ) {
	const p = getDateParts( startDate );
	let weeklyLabel, monthlyLabel, yearlyLabel;
	if ( p ) {
		// translators: %s: day name e.g. "Monday"
		weeklyLabel = sprintf( __( 'Weekly on %s', 'blockendar' ), p.dayName );
		monthlyLabel = sprintf(
			// translators: 1: ordinal e.g. "second" 2: day name e.g. "Monday"
			__( 'Monthly on the %1$s %2$s', 'blockendar' ),
			p.nthLabel,
			p.dayName
		);
		yearlyLabel = sprintf(
			// translators: 1: month name 2: day number
			__( 'Annually on %1$s %2$d', 'blockendar' ),
			p.monthName,
			p.dom
		);
	} else {
		weeklyLabel = __( 'Weekly', 'blockendar' );
		monthlyLabel = __( 'Monthly', 'blockendar' );
		yearlyLabel = __( 'Annually', 'blockendar' );
	}
	return [
		{ label: __( 'Does not repeat', 'blockendar' ), value: 'none' },
		{ label: __( 'Daily', 'blockendar' ), value: 'daily' },
		{ label: weeklyLabel, value: 'weekly_day' },
		{ label: monthlyLabel, value: 'monthly_weekday' },
		{ label: yearlyLabel, value: 'yearly_date' },
	];
}

// ---------------------------------------------------------------------------
// RecurrenceSection — rendered inside DateTimePanel
// ---------------------------------------------------------------------------

/*
 * The rule is a field of the event, `blockendar_recurrence`, edited with
 * editPost() like the event's meta. It is saved in the same request as the
 * event, marks the event as changed, and is thrown away with everything else
 * when the author leaves without saving.
 *
 * It used to be sent to its own route the moment a control changed, so a rule
 * the author tried and abandoned was already in the database.
 */
function RecurrenceSection( { startDate, ongoing } ) {
	const rule = useSelect( ( select ) =>
		select( editorStore ).getEditedPostAttribute( 'blockendar_recurrence' )
	);
	const { editPost } = useDispatch( editorStore );

	// The controls keep their own state because they can say more than a rule
	// can: "On date" has to stay selected while no date has been picked yet.
	const [ form, setForm ] = useState( () => formFromRule( rule ) );

	// Follow the rule when it changes from outside the controls — the event
	// finishing loading, an undo — unless the controls already describe it.
	useEffect( () => {
		setForm( ( current ) => {
			const stored = formFromRule( rule );
			const written = ruleFromForm(
				{ ...current, preset: stored.preset },
				startDate
			);
			const sameEnd =
				( written.until_date ?? null ) ===
					( rule?.until_date ?? null ) &&
				( written.count ?? null ) === ( rule?.count ?? null );

			if ( ! sameEnd ) {
				return stored;
			}

			return current.preset === stored.preset
				? current
				: { ...current, preset: stored.preset };
		} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ rule ] );

	const apply = ( next ) => {
		setForm( next );
		editPost( {
			blockendar_recurrence: ruleFromForm( next, startDate ),
			meta: { blockendar_recurrence_preset: next.preset },
		} );
	};

	// "Weekly" means weekly on the start date's weekday, so a new start date is
	// a new rule. Not on mount: opening an event must not edit it.
	const previousStartDate = useRef( startDate );
	useEffect( () => {
		if ( previousStartDate.current === startDate ) {
			return;
		}

		previousStartDate.current = startDate;

		if ( form.preset !== 'none' ) {
			editPost( {
				blockendar_recurrence: ruleFromForm( form, startDate ),
			} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ startDate ] );

	const { preset, endType, untilDate, count } = form;
	const freqOptions = buildFreqOptions( startDate );

	// Ongoing events are never recurring. Keep this section mounted so the
	// loaded rule survives a round trip, but replace the controls with a notice
	// when a rule exists — the server ignores it while the event is ongoing.
	if ( ongoing ) {
		if ( preset === 'none' ) {
			return null;
		}

		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'This event has a repeat rule, which is ignored while it is marked ongoing. Turn off "Ongoing, no end date" to use it again.',
					'blockendar'
				) }
			</Notice>
		);
	}

	return (
		<VStack spacing={ 4 }>
			<SelectControl
				label={ __( 'Repeats', 'blockendar' ) }
				value={ preset }
				options={ freqOptions }
				onChange={ ( val ) => apply( { ...form, preset: val } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			{ preset !== 'none' && (
				<>
					<RadioControl
						label={ __( 'Ends', 'blockendar' ) }
						selected={ endType }
						options={ [
							{
								label: __( 'Never', 'blockendar' ),
								value: 'never',
							},
							{
								label: __( 'On date', 'blockendar' ),
								value: 'date',
							},
							{
								label: __( 'After N times', 'blockendar' ),
								value: 'count',
							},
						] }
						onChange={ ( val ) =>
							apply( { ...form, endType: val } )
						}
					/>

					{ endType === 'date' && (
						<DatePicker
							currentDate={ untilDate || undefined }
							onChange={ ( val ) =>
								apply( {
									...form,
									untilDate: val?.split( 'T' )[ 0 ] ?? '',
								} )
							}
							__nextRemoveHelpButton
						/>
					) }

					{ endType === 'count' && (
						<TextControl
							label={ __(
								'Number of occurrences',
								'blockendar'
							) }
							type="number"
							min={ 1 }
							value={ count }
							onChange={ ( val ) =>
								apply( { ...form, count: val } )
							}
							__nextHasNoMarginBottom
						/>
					) }
				</>
			) }
		</VStack>
	);
}

// ---------------------------------------------------------------------------
// DateTimePanel
// ---------------------------------------------------------------------------

export function DateTimePanel() {
	const meta = useSelect(
		( select ) =>
			select( editorStore ).getEditedPostAttribute( 'meta' ) ?? {}
	);
	const { editPost } = useDispatch( editorStore );

	const setMeta = ( updates ) =>
		editPost( { meta: { ...meta, ...updates } } );

	// Seed defaults for new events on first open.
	useEffect( () => {
		const updates = {};

		if ( ! meta.blockendar_start_date ) {
			const seedNow = new Date();
			const seedPad = ( n ) => String( n ).padStart( 2, '0' );
			const seedToday = `${ seedNow.getFullYear() }-${ seedPad(
				seedNow.getMonth() + 1
			) }-${ seedPad( seedNow.getDate() ) }`;

			// Round up to the next full hour.
			const next = new Date( seedNow );
			next.setMinutes( 0, 0, 0 );
			next.setHours( next.getHours() + 1 );

			updates.blockendar_start_date = seedToday;
			updates.blockendar_end_date = seedToday;
			updates.blockendar_start_time = `${ seedPad(
				next.getHours()
			) }:00`;
			updates.blockendar_end_time = `${ seedPad(
				( next.getHours() + 1 ) % 24
			) }:00`;
		}

		if ( ! meta.blockendar_timezone ) {
			updates.blockendar_timezone = siteTimezone;
		}

		if ( Object.keys( updates ).length ) {
			editPost( { meta: { ...meta, ...updates } } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const allDay = !! meta.blockendar_all_day;
	const ongoing = !! meta.blockendar_ongoing;
	const startDate = meta.blockendar_start_date ?? '';
	const endDate = meta.blockendar_end_date ?? '';
	const startTime = meta.blockendar_start_time || '09:00';
	const endTime = meta.blockendar_end_time || '10:00';
	const timezone = meta.blockendar_timezone || siteTimezone;

	// A site on a manual offset is listed as "+05:30"; label it the way the
	// WordPress settings screen does.
	const tzOptions = timezones.map( ( tz ) => ( {
		label: /^[+-]/.test( tz ) ? `UTC${ tz }` : tz,
		value: tz,
	} ) );

	const event = { startDate, endDate, startTime, endTime };

	// When start date changes, pull end date forward if it would precede start.
	const handleStartDateChange = ( val ) => {
		const updates = { blockendar_start_date: val };
		// Ongoing events keep an empty end; the toggle seeds it when turned off.
		if (
			! ongoing &&
			( ! endDate || endDate === startDate || val > endDate )
		) {
			updates.blockendar_end_date = val;
		}
		setMeta( updates );
	};

	// The rules for the other three are in ./datetime, where they are tested.
	const handleStartTimeChange = ( val ) =>
		setMeta( startTimeUpdates( event, val ) );
	const handleEndDateChange = ( val ) =>
		setMeta( endDateUpdates( event, val ) );
	const handleEndTimeChange = ( val ) =>
		setMeta( endTimeUpdates( event, val ) );

	return (
		<PluginDocumentSettingPanel
			name="blockendar-datetime"
			title={ __( 'Date & Time', 'blockendar' ) }
			className="blockendar-panel-datetime"
		>
			<VStack spacing={ 3 }>
				<BaseControl
					id="blockendar-start-date"
					label={ __( 'Start Date', 'blockendar' ) }
					__nextHasNoMarginBottom
				>
					<DateInput
						value={ startDate }
						onChange={ handleStartDateChange }
					/>
				</BaseControl>

				{ ! allDay && (
					<BaseControl
						id="blockendar-start-time"
						label={ __( 'Start Time', 'blockendar' ) }
						__nextHasNoMarginBottom
					>
						<TimeSelect
							value={ startTime }
							onChange={ handleStartTimeChange }
						/>
					</BaseControl>
				) }

				{ ! allDay && ! ongoing && (
					<div
						style={ {
							textAlign: 'center',
							color: '#757575',
							fontSize: '12px',
							padding: '2px 0',
						} }
					>
						{ __( 'to', 'blockendar' ) }
					</div>
				) }

				{ ! allDay && ! ongoing && (
					<BaseControl
						id="blockendar-end-time"
						label={ __( 'End Time', 'blockendar' ) }
						__nextHasNoMarginBottom
					>
						<TimeSelect
							value={ endTime }
							onChange={ handleEndTimeChange }
						/>
					</BaseControl>
				) }

				{ ! ongoing && (
					<BaseControl
						id="blockendar-end-date"
						label={ __( 'End Date', 'blockendar' ) }
						__nextHasNoMarginBottom
					>
						<DateInput
							value={ endDate }
							onChange={ handleEndDateChange }
						/>
					</BaseControl>
				) }

				<ToggleControl
					label={ __( 'Ongoing, no end date', 'blockendar' ) }
					help={
						ongoing
							? __(
									'Stays in listings until you set an end date or unpublish it.',
									'blockendar'
							  )
							: undefined
					}
					checked={ ongoing }
					onChange={ ( val ) =>
						setMeta( getOngoingMetaUpdates( meta, val ) )
					}
					__nextHasNoMarginBottom
				/>

				<SelectControl
					label={ __( 'Timezone', 'blockendar' ) }
					value={ timezone }
					options={ tzOptions }
					onChange={ ( val ) =>
						setMeta( { blockendar_timezone: val } )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>

				<ToggleControl
					label={ __( 'All Day', 'blockendar' ) }
					checked={ allDay }
					onChange={ ( val ) =>
						setMeta( { blockendar_all_day: val } )
					}
					__nextHasNoMarginBottom
				/>

				<RecurrenceSection
					startDate={ startDate }
					ongoing={ ongoing }
				/>
			</VStack>
		</PluginDocumentSettingPanel>
	);
}
