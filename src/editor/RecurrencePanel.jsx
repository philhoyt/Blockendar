/**
 * Recurrence sidebar panel for blockendar_event.
 *
 * Mirrors Google Calendar / Outlook recurrence conventions.
 * Saves to the blockendar/v1/recurrence endpoint on change.
 */
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	SelectControl,
	TextControl,
	CheckboxControl,
	DatePicker,
	RadioControl,
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

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

export function RecurrencePanel() {
	const postId = useSelect( ( select ) =>
		select( editorStore ).getCurrentPostId()
	);
	const [ rule, setRule ] = useState( defaultRule );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState( '' );

	// Load existing rule on mount.
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
			// Delete rule if set to none.
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
		<PluginDocumentSettingPanel
			name="blockendar-recurrence"
			title={ __( 'Recurrence', 'blockendar' ) }
			className="blockendar-panel-recurrence"
		>
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
											checked={ rule.byday.includes(
												code
											) }
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
										until_date:
											val?.split( 'T' )[ 0 ] ?? '',
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
							<p style={ { color: 'red', margin: 0 } }>
								{ error }
							</p>
						) }
					</>
				) }
			</VStack>
		</PluginDocumentSettingPanel>
	);
}
