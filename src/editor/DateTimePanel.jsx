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
import apiFetch from '@wordpress/api-fetch';
import {
	ToggleControl,
	SelectControl,
	BaseControl,
	TextControl,
	DatePicker,
	RadioControl,
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const {
	timezones = [],
	siteTimezone = 'UTC',
	is12Hour = true,
} = window.blockendarEditor ?? {};

// ---------------------------------------------------------------------------
// DateInput — native <input type="date"> styled to match WP components
// ---------------------------------------------------------------------------

function DateInput( { value, onChange, min } ) {
	return (
		<input
			type="date"
			value={ value }
			min={ min }
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

const MINUTE_OPTIONS = Array.from( { length: 12 }, ( _, i ) => {
	const v = String( i * 5 ).padStart( 2, '0' );
	return { label: v, value: v };
} );

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
	const roundedM = ( Math.round( m / 5 ) * 5 ) % 60;
	const minuteStr = String( roundedM ).padStart( 2, '0' );

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
			onChange( toHHMM( h24n, roundedM ) );
		};

		const onAmPm = ( ampm ) => {
			const pm = ampm === 'PM';
			const h12c = parseInt( h12str, 10 );
			let h24n = h12c % 12;
			if ( pm ) {
				h24n += 12;
			}
			onChange( toHHMM( h24n, roundedM ) );
		};

		return (
			<HStack
				spacing={ 1 }
				alignment="left"
				style={ { flexWrap: 'nowrap' } }
			>
				<div style={ { width: 64 } }>
					<SelectControl
						value={ h12str }
						options={ HOUR_OPTIONS_12 }
						onChange={ onHour }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
				<div style={ { width: 64 } }>
					<SelectControl
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
					value={ String( h24 ).padStart( 2, '0' ) }
					options={ HOUR_OPTIONS_24 }
					onChange={ ( h ) =>
						onChange( toHHMM( parseInt( h, 10 ), roundedM ) )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>
			<div style={ { width: 72 } }>
				<SelectControl
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

const BYDAY_CODES = [ 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ];
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
	if ( ! dateStr ) {
		return null;
	}
	const d = new Date( dateStr + 'T12:00:00' );
	const dow = d.getDay();
	const dom = d.getDate();
	const nth = Math.min( Math.ceil( dom / 7 ), 5 );
	return {
		byday: BYDAY_CODES[ dow ],
		dayName: WEEKDAY_NAMES[ dow ],
		nth,
		nthLabel: NTH_LABELS[ nth ],
		monthName: MONTH_NAMES[ d.getMonth() ],
		dom,
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

function presetToPayload( preset, startDate ) {
	switch ( preset ) {
		case 'daily':
			return {
				frequency: 'daily',
				interval_val: 1,
				byday: null,
				bymonthday: null,
				bysetpos: null,
			};
		case 'weekly_day': {
			const p = getDateParts( startDate );
			return {
				frequency: 'weekly',
				interval_val: 1,
				byday: p?.byday ?? null,
				bymonthday: null,
				bysetpos: null,
			};
		}
		case 'monthly_weekday': {
			const p = getDateParts( startDate );
			return {
				frequency: 'monthly',
				interval_val: 1,
				byday: p?.byday ?? null,
				bymonthday: null,
				bysetpos: p?.nth?.toString() ?? null,
			};
		}
		case 'yearly_date':
			return {
				frequency: 'yearly',
				interval_val: 1,
				byday: null,
				bymonthday: null,
				bysetpos: null,
			};
		default:
			return null;
	}
}

function ruleToPreset( r ) {
	switch ( r?.frequency ) {
		case 'daily':
			return 'daily';
		case 'weekly':
			return 'weekly_day';
		case 'monthly':
			return 'monthly_weekday';
		case 'yearly':
			return 'yearly_date';
		default:
			return 'none';
	}
}

// ---------------------------------------------------------------------------
// RecurrenceSection — rendered inside DateTimePanel
// ---------------------------------------------------------------------------

function RecurrenceSection( { postId, startDate } ) {
	const [ preset, setPreset ] = useState( 'none' );
	const [ endType, setEndType ] = useState( 'never' );
	const [ untilDate, setUntilDate ] = useState( '' );
	const [ count, setCount ] = useState( '' );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState( '' );

	const { editPost } = useDispatch( editorStore );

	// Load existing recurrence rule on mount.
	useEffect( () => {
		if ( ! postId ) {
			return;
		}

		apiFetch( { path: `/blockendar/v1/events/${ postId }` } )
			.then( ( data ) => {
				if ( data?.recurrence ) {
					const r = data.recurrence;
					const p = ruleToPreset( r );
					setPreset( p );
					setEndType(
						// eslint-disable-next-line no-nested-ternary
						r.until_date ? 'date' : r.count ? 'count' : 'never'
					);
					setUntilDate( r.until_date ?? '' );
					setCount( r.count?.toString() ?? '' );
				}
			} )
			.catch( () => {} );
	}, [ postId ] );

	// Detect when the post finishes saving and auto-save the recurrence rule.
	const isSaving = useSelect(
		( select ) =>
			select( editorStore ).isSavingPost() &&
			! select( editorStore ).isAutosavingPost()
	);
	const prevSavingRef = useRef( false );
	// Keep a ref to current state values to avoid stale closures in the effect.
	const stateRef = useRef( { preset, startDate, endType, untilDate, count } );
	stateRef.current = { preset, startDate, endType, untilDate, count };

	useEffect( () => {
		const justFinishedSaving = prevSavingRef.current && ! isSaving;
		prevSavingRef.current = isSaving;

		if ( ! justFinishedSaving || ! postId ) {
			return;
		}

		const {
			preset: p,
			startDate: sd,
			endType: et,
			untilDate: ud,
			count: c,
		} = stateRef.current;

		if ( p === 'none' ) {
			apiFetch( {
				path: `/blockendar/v1/events/${ postId }/recurrence`,
				method: 'DELETE',
			} ).catch( () => {} );
			return;
		}

		const base = presetToPayload( p, sd );

		if ( ! base ) {
			return;
		}

		apiFetch( {
			path: `/blockendar/v1/events/${ postId }/recurrence`,
			method: 'POST',
			data: {
				...base,
				until_date: et === 'date' ? ud : null,
				count: et === 'count' ? parseInt( c, 10 ) || null : null,
			},
		} ).catch( () => {} );
	}, [ isSaving, postId ] );

	const freqOptions = buildFreqOptions( startDate );

	// Core save logic — accepts explicit values so it can be called from
	// onChange handlers (before React state has updated) as well as the button.
	const doSave = async ( p, et, ud, c ) => {
		setError( '' );
		setSaved( false );

		if ( p === 'none' ) {
			try {
				await apiFetch( {
					path: `/blockendar/v1/events/${ postId }/recurrence`,
					method: 'DELETE',
				} );
				setSaved( true );
			} catch ( e ) {
				setError( e?.message ?? __( 'Save failed.', 'blockendar' ) );
			}
			return;
		}

		const base = presetToPayload( p, startDate );

		if ( ! base ) {
			return;
		}

		try {
			await apiFetch( {
				path: `/blockendar/v1/events/${ postId }/recurrence`,
				method: 'POST',
				data: {
					...base,
					until_date: et === 'date' ? ud : null,
					count: et === 'count' ? parseInt( c, 10 ) || null : null,
				},
			} );
			setSaved( true );
		} catch ( e ) {
			setError( e?.message ?? __( 'Save failed.', 'blockendar' ) );
		}
	};

	return (
		<VStack spacing={ 4 }>
			<SelectControl
				label={ __( 'Repeats', 'blockendar' ) }
				value={ preset }
				options={ freqOptions }
				onChange={ ( val ) => {
					setPreset( val );
					editPost( { meta: { blockendar_recurrence_preset: val } } );
					doSave( val, endType, untilDate, count );
				} }
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
						onChange={ ( val ) => {
							setEndType( val );
							doSave( preset, val, untilDate, count );
						} }
					/>

					{ endType === 'date' && (
						<DatePicker
							currentDate={ untilDate || undefined }
							onChange={ ( val ) => {
								const d = val?.split( 'T' )[ 0 ] ?? '';
								setUntilDate( d );
								doSave( preset, endType, d, count );
							} }
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
							onChange={ setCount }
							__nextHasNoMarginBottom
						/>
					) }

					<Button
						variant="secondary"
						onClick={ () =>
							doSave( preset, endType, untilDate, count )
						}
					>
						{ __( 'Save recurrence', 'blockendar' ) }
					</Button>

					{ saved && (
						<p style={ { color: 'green', margin: 0 } }>
							{ __( 'Saved.', 'blockendar' ) }
						</p>
					) }

					{ error && (
						<p style={ { color: 'red', margin: 0 } }>{ error }</p>
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
	const { meta, postId } = useSelect( ( select ) => ( {
		meta: select( editorStore ).getEditedPostAttribute( 'meta' ) ?? {},
		postId: select( editorStore ).getCurrentPostId(),
	} ) );
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

	const now = new Date();
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	const today = `${ now.getFullYear() }-${ pad( now.getMonth() + 1 ) }-${ pad(
		now.getDate()
	) }`;

	const allDay = !! meta.blockendar_all_day;
	const startDate = meta.blockendar_start_date ?? '';
	const endDate = meta.blockendar_end_date ?? '';
	const startTime = meta.blockendar_start_time || '09:00';
	const endTime = meta.blockendar_end_time || '10:00';
	const timezone = meta.blockendar_timezone || siteTimezone;

	const tzOptions = timezones.map( ( tz ) => ( { label: tz, value: tz } ) );

	// Returns hhmm advanced by `mins` minutes (wraps at midnight).
	const addMinutes = ( hhmm, mins ) => {
		const [ h, m ] = hhmm.split( ':' ).map( Number );
		const total = h * 60 + m + mins;
		return toHHMM( Math.floor( total / 60 ) % 24, total % 60 );
	};

	const sameDay = startDate && endDate && startDate === endDate;

	// When start date changes, pull end date forward if it would precede start.
	const handleStartDateChange = ( val ) => {
		const updates = { blockendar_start_date: val };
		if ( ! endDate || endDate === startDate || val > endDate ) {
			updates.blockendar_end_date = val;
		}
		setMeta( updates );
	};

	// When start time changes, keep end time at least 5 min ahead on the same day.
	const handleStartTimeChange = ( val ) => {
		const updates = { blockendar_start_time: val };
		if ( sameDay && endTime <= val ) {
			updates.blockendar_end_time = addMinutes( val, 60 );
		}
		setMeta( updates );
	};

	// When end date changes, clamp to start date if it would precede it.
	const handleEndDateChange = ( val ) => {
		setMeta( { blockendar_end_date: val < startDate ? startDate : val } );
	};

	// When end time changes, clamp to start time + 5 min on the same day.
	const handleEndTimeChange = ( val ) => {
		if ( sameDay && val <= startTime ) {
			setMeta( { blockendar_end_time: addMinutes( startTime, 5 ) } );
			return;
		}
		setMeta( { blockendar_end_time: val } );
	};

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
						min={ today }
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

				{ ! allDay && (
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

				{ ! allDay && (
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

				<RecurrenceSection postId={ postId } startDate={ startDate } />
			</VStack>
		</PluginDocumentSettingPanel>
	);
}
