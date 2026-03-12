/**
 * Date & Time sidebar panel for blockendar_event.
 * Includes recurrence settings.
 */
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	ToggleControl,
	SelectControl,
	BaseControl,
	TextControl,
	CheckboxControl,
	DatePicker,
	RadioControl,
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalDivider as Divider,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

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
					/>
				</div>
				<div style={ { width: 74 } }>
					<SelectControl
						value={ isPm ? 'PM' : 'AM' }
						options={ AMPM_OPTIONS }
						onChange={ onAmPm }
						__nextHasNoMarginBottom
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
				/>
			</div>
		</HStack>
	);
}

// ---------------------------------------------------------------------------
// Section card
// ---------------------------------------------------------------------------

const sectionStyle = {
	padding: '12px',
	border: '1px solid #e2e4e7',
	borderRadius: '4px',
	background: '#f9f9f9',
};

const sectionLabelStyle = {
	margin: 0,
	marginBottom: 8,
	fontWeight: 600,
	fontSize: '11px',
	textTransform: 'uppercase',
	color: '#757575',
	letterSpacing: '0.5px',
};

// ---------------------------------------------------------------------------
// Recurrence constants
// ---------------------------------------------------------------------------

const FREQ_NONE = 'none';
const FREQ_DAILY = 'daily';
const FREQ_WEEKLY = 'weekly';
const FREQ_MONTHLY = 'monthly';
const FREQ_YEARLY = 'yearly';

const WEEKDAYS = [
	{ code: 'MO', label: __( 'Mon', 'blockendar' ) },
	{ code: 'TU', label: __( 'Tue', 'blockendar' ) },
	{ code: 'WE', label: __( 'Wed', 'blockendar' ) },
	{ code: 'TH', label: __( 'Thu', 'blockendar' ) },
	{ code: 'FR', label: __( 'Fri', 'blockendar' ) },
	{ code: 'SA', label: __( 'Sat', 'blockendar' ) },
	{ code: 'SU', label: __( 'Sun', 'blockendar' ) },
];

const FREQ_OPTIONS = [
	{ label: __( 'Does not repeat', 'blockendar' ), value: FREQ_NONE },
	{ label: __( 'Daily', 'blockendar' ), value: FREQ_DAILY },
	{ label: __( 'Weekly', 'blockendar' ), value: FREQ_WEEKLY },
	{ label: __( 'Monthly', 'blockendar' ), value: FREQ_MONTHLY },
	{ label: __( 'Yearly', 'blockendar' ), value: FREQ_YEARLY },
];

const defaultRule = {
	frequency: FREQ_NONE,
	interval: 1,
	byday: [],
	bymonthday: '',
	bysetpos: '',
	endType: 'never', // never | date | count
	until_date: '',
	count: '',
	exceptions: [],
};

// ---------------------------------------------------------------------------
// RecurrenceSection — rendered inside DateTimePanel
// ---------------------------------------------------------------------------

function RecurrenceSection( { postId } ) {
	const [ rule, setRule ] = useState( defaultRule );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( ! postId ) {
			return;
		}

		apiFetch( { path: `/blockendar/v1/events/${ postId }` } )
			.then( ( data ) => {
				if ( data?.recurrence ) {
					const r = data.recurrence;
					setRule( {
						frequency: r.frequency,
						interval: r.interval ?? 1,
						byday: r.byday ?? [],
						bymonthday: r.bymonthday?.[ 0 ]?.toString() ?? '',
						bysetpos: r.bysetpos?.[ 0 ]?.toString() ?? '',
						// eslint-disable-next-line no-nested-ternary
						endType: r.until_date
							? 'date'
							: r.count
							? 'count'
							: 'never',
						until_date: r.until_date ?? '',
						count: r.count?.toString() ?? '',
						exceptions: r.exceptions ?? [],
					} );
				}
			} )
			.catch( () => {} );
	}, [ postId ] );

	const update = ( partial ) => {
		setRule( ( prev ) => ( { ...prev, ...partial } ) );
		setSaved( false );
	};

	const toggleDay = ( code ) => {
		const next = rule.byday.includes( code )
			? rule.byday.filter( ( d ) => d !== code )
			: [ ...rule.byday, code ];
		update( { byday: next } );
	};

	const handleSave = async () => {
		setError( '' );

		if ( rule.frequency === FREQ_NONE ) {
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

		const payload = {
			frequency: rule.frequency,
			interval_val: parseInt( rule.interval, 10 ) || 1,
			byday: rule.byday.join( ',' ) || null,
			bymonthday: rule.bymonthday || null,
			bysetpos: rule.bysetpos || null,
			until_date: rule.endType === 'date' ? rule.until_date : null,
			count:
				rule.endType === 'count'
					? parseInt( rule.count, 10 ) || null
					: null,
		};

		try {
			await apiFetch( {
				path: `/blockendar/v1/events/${ postId }/recurrence`,
				method: 'POST',
				data: payload,
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
				value={ rule.frequency }
				options={ FREQ_OPTIONS }
				onChange={ ( val ) => update( { frequency: val } ) }
				__nextHasNoMarginBottom
			/>

			{ rule.frequency !== FREQ_NONE && (
				<>
					<TextControl
						label={ __( 'Repeat every', 'blockendar' ) }
						type="number"
						min={ 1 }
						value={ rule.interval }
						onChange={ ( val ) => update( { interval: val } ) }
						help={ `${ rule.interval } ${ rule.frequency }(s)` }
						__nextHasNoMarginBottom
					/>

					{ rule.frequency === FREQ_WEEKLY && (
						<fieldset
							style={ {
								margin: 0,
								padding: 0,
								border: 'none',
							} }
						>
							<legend style={ { marginBottom: 4 } }>
								{ __( 'Repeat on', 'blockendar' ) }
							</legend>
							<HStack spacing={ 1 } wrap>
								{ WEEKDAYS.map( ( { code, label } ) => (
									<CheckboxControl
										key={ code }
										label={ label }
										checked={ rule.byday.includes( code ) }
										onChange={ () => toggleDay( code ) }
										__nextHasNoMarginBottom
									/>
								) ) }
							</HStack>
						</fieldset>
					) }

					{ rule.frequency === FREQ_MONTHLY && (
						<VStack spacing={ 2 }>
							<TextControl
								label={ __( 'Day of month', 'blockendar' ) }
								type="number"
								min={ 1 }
								max={ 31 }
								value={ rule.bymonthday }
								onChange={ ( val ) =>
									update( {
										bymonthday: val,
										bysetpos: '',
									} )
								}
								help={ __(
									'Leave blank to use the event start date day.',
									'blockendar'
								) }
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __(
									'Nth weekday position (BYSETPOS)',
									'blockendar'
								) }
								type="number"
								value={ rule.bysetpos }
								onChange={ ( val ) =>
									update( {
										bysetpos: val,
										bymonthday: '',
									} )
								}
								help={ __(
									'1 = first, 2 = second, -1 = last',
									'blockendar'
								) }
								__nextHasNoMarginBottom
							/>
						</VStack>
					) }

					<RadioControl
						label={ __( 'Ends', 'blockendar' ) }
						selected={ rule.endType }
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
						onChange={ ( val ) => update( { endType: val } ) }
					/>

					{ rule.endType === 'date' && (
						<DatePicker
							currentDate={ rule.until_date || undefined }
							onChange={ ( val ) =>
								update( {
									until_date: val?.split( 'T' )[ 0 ] ?? '',
								} )
							}
							__nextRemoveHelpButton
						/>
					) }

					{ rule.endType === 'count' && (
						<TextControl
							label={ __(
								'Number of occurrences',
								'blockendar'
							) }
							type="number"
							min={ 1 }
							value={ rule.count }
							onChange={ ( val ) => update( { count: val } ) }
							__nextHasNoMarginBottom
						/>
					) }

					<Button variant="secondary" onClick={ handleSave }>
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

	const allDay = !! meta.blockendar_all_day;
	const startDate = meta.blockendar_start_date ?? '';
	const endDate = meta.blockendar_end_date ?? '';
	const startTime = meta.blockendar_start_time || '09:00';
	const endTime = meta.blockendar_end_time || '10:00';
	const timezone = meta.blockendar_timezone || siteTimezone;

	const tzOptions = timezones.map( ( tz ) => ( { label: tz, value: tz } ) );

	return (
		<PluginDocumentSettingPanel
			name="blockendar-datetime"
			title={ __( 'Date & Time', 'blockendar' ) }
			className="blockendar-panel-datetime"
		>
			<VStack spacing={ 4 }>
				<ToggleControl
					label={ __( 'All-day event', 'blockendar' ) }
					checked={ allDay }
					onChange={ ( val ) =>
						setMeta( { blockendar_all_day: val } )
					}
				/>

				{ /* ── Start ── */ }
				<div style={ sectionStyle }>
					<p style={ sectionLabelStyle }>
						{ __( 'Start', 'blockendar' ) }
					</p>
					<VStack spacing={ 2 }>
						<BaseControl __nextHasNoMarginBottom>
							<DateInput
								value={ startDate }
								onChange={ ( val ) =>
									setMeta( { blockendar_start_date: val } )
								}
							/>
						</BaseControl>
						{ ! allDay && (
							<TimeSelect
								value={ startTime }
								onChange={ ( t ) =>
									setMeta( { blockendar_start_time: t } )
								}
							/>
						) }
					</VStack>
				</div>

				{ /* ── End ── */ }
				<div style={ sectionStyle }>
					<p style={ sectionLabelStyle }>
						{ __( 'End', 'blockendar' ) }
					</p>
					<VStack spacing={ 2 }>
						<BaseControl __nextHasNoMarginBottom>
							<DateInput
								value={ endDate }
								onChange={ ( val ) =>
									setMeta( { blockendar_end_date: val } )
								}
							/>
						</BaseControl>
						{ ! allDay && (
							<TimeSelect
								value={ endTime }
								onChange={ ( t ) =>
									setMeta( { blockendar_end_time: t } )
								}
							/>
						) }
					</VStack>
				</div>

				{ ! allDay && (
					<SelectControl
						label={ __( 'Timezone', 'blockendar' ) }
						value={ timezone }
						options={ tzOptions }
						onChange={ ( val ) =>
							setMeta( { blockendar_timezone: val } )
						}
						__nextHasNoMarginBottom
					/>
				) }

				<Divider />

				{ /* ── Recurrence ── */ }
				<p style={ { ...sectionLabelStyle, marginBottom: 0 } }>
					{ __( 'Recurrence', 'blockendar' ) }
				</p>
				<RecurrenceSection postId={ postId } />
			</VStack>
		</PluginDocumentSettingPanel>
	);
}
