/**
 * Date & Time sidebar panel for blockendar_event.
 */
import { PluginDocumentSettingPanel }    from '@wordpress/editor';
import { useSelect, useDispatch }        from '@wordpress/data';
import { store as editorStore }          from '@wordpress/editor';
import {
	ToggleControl,
	SelectControl,
	BaseControl,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const {
	timezones    = [],
	siteTimezone = 'UTC',
	is12Hour     = true,
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
				display:     'block',
				width:       '100%',
				boxSizing:   'border-box',
				padding:     '6px 10px',
				border:      '1px solid #757575',
				borderRadius: '2px',
				fontFamily:  'inherit',
				fontSize:    '13px',
				lineHeight:  '1.4',
				color:       'inherit',
				background:  '#fff',
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
	if ( ! hhmm ) return { h24: 9, m: 0 };
	const [ hStr, mStr ] = hhmm.split( ':' );
	return {
		h24: parseInt( hStr ?? '9', 10 ),
		m:   parseInt( mStr ?? '0', 10 ),
	};
}

function toHHMM( h24, m ) {
	return `${ String( h24 ).padStart( 2, '0' ) }:${ String( m ).padStart( 2, '0' ) }`;
}

function TimeSelect( { value, onChange } ) {
	const { h24, m } = parseTime( value );
	const roundedM   = Math.round( m / 5 ) * 5 % 60;
	const minuteStr  = String( roundedM ).padStart( 2, '0' );

	if ( is12Hour ) {
		const isPm   = h24 >= 12;
		const h12raw = h24 % 12;
		const h12str = String( h12raw === 0 ? 12 : h12raw );

		const onHour = ( newH12str ) => {
			const h12  = parseInt( newH12str, 10 );
			let   h24n = h12 % 12;
			if ( isPm ) h24n += 12;
			onChange( toHHMM( h24n, roundedM ) );
		};

		const onAmPm = ( ampm ) => {
			const pm   = ampm === 'PM';
			const h12c = parseInt( h12str, 10 );
			let   h24n = h12c % 12;
			if ( pm ) h24n += 12;
			onChange( toHHMM( h24n, roundedM ) );
		};

		return (
			<HStack spacing={ 1 } alignment="left" style={ { flexWrap: 'nowrap' } }>
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
						onChange={ ( min ) => onChange( toHHMM( h24, parseInt( min, 10 ) ) ) }
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
					onChange={ ( h ) => onChange( toHHMM( parseInt( h, 10 ), roundedM ) ) }
					__nextHasNoMarginBottom
				/>
			</div>
			<div style={ { width: 72 } }>
				<SelectControl
					value={ minuteStr }
					options={ MINUTE_OPTIONS }
					onChange={ ( min ) => onChange( toHHMM( h24, parseInt( min, 10 ) ) ) }
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
	padding:      '12px',
	border:       '1px solid #e2e4e7',
	borderRadius: '4px',
	background:   '#f9f9f9',
};

const sectionLabelStyle = {
	margin:        0,
	marginBottom:  8,
	fontWeight:    600,
	fontSize:      '11px',
	textTransform: 'uppercase',
	color:         '#757575',
	letterSpacing: '0.5px',
};

// ---------------------------------------------------------------------------
// DateTimePanel
// ---------------------------------------------------------------------------

export function DateTimePanel() {
	const meta         = useSelect( ( select ) => select( editorStore ).getEditedPostAttribute( 'meta' ) ?? {} );
	const { editPost } = useDispatch( editorStore );

	const setMeta = ( updates ) => editPost( { meta: { ...meta, ...updates } } );

	const allDay    = !! meta.blockendar_all_day;
	const startDate = meta.blockendar_start_date ?? '';
	const endDate   = meta.blockendar_end_date   ?? '';
	const startTime = meta.blockendar_start_time  || '09:00';
	const endTime   = meta.blockendar_end_time    || '10:00';
	const timezone  = meta.blockendar_timezone    || siteTimezone;

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
					onChange={ ( val ) => setMeta( { blockendar_all_day: val } ) }
				/>

				{ /* ── Start ── */ }
				<div style={ sectionStyle }>
					<p style={ sectionLabelStyle }>{ __( 'Start', 'blockendar' ) }</p>
					<VStack spacing={ 2 }>
						<BaseControl __nextHasNoMarginBottom>
							<DateInput
								value={ startDate }
								onChange={ ( val ) => setMeta( { blockendar_start_date: val } ) }
							/>
						</BaseControl>
						{ ! allDay && (
							<TimeSelect
								value={ startTime }
								onChange={ ( t ) => setMeta( { blockendar_start_time: t } ) }
							/>
						) }
					</VStack>
				</div>

				{ /* ── End ── */ }
				<div style={ sectionStyle }>
					<p style={ sectionLabelStyle }>{ __( 'End', 'blockendar' ) }</p>
					<VStack spacing={ 2 }>
						<BaseControl __nextHasNoMarginBottom>
							<DateInput
								value={ endDate }
								onChange={ ( val ) => setMeta( { blockendar_end_date: val } ) }
							/>
						</BaseControl>
						{ ! allDay && (
							<TimeSelect
								value={ endTime }
								onChange={ ( t ) => setMeta( { blockendar_end_time: t } ) }
							/>
						) }
					</VStack>
				</div>

				{ ! allDay && (
					<SelectControl
						label={ __( 'Timezone', 'blockendar' ) }
						value={ timezone }
						options={ tzOptions }
						onChange={ ( val ) => setMeta( { blockendar_timezone: val } ) }
						__nextHasNoMarginBottom
					/>
				) }

			</VStack>
		</PluginDocumentSettingPanel>
	);
}
