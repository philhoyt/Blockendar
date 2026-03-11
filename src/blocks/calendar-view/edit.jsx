/**
 * calendar-view block — editor component.
 *
 * Shows a static preview placeholder in the editor with inspector controls.
 * The live FullCalendar renders via view.jsx on the frontend.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	SelectControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useSelect }  from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ }         from '@wordpress/i18n';

const VIEW_OPTIONS = [
	{ label: __( 'Month',     'blockendar' ), value: 'dayGridMonth' },
	{ label: __( 'Week',      'blockendar' ), value: 'timeGridWeek' },
	{ label: __( 'Day',       'blockendar' ), value: 'timeGridDay' },
	{ label: __( 'List',      'blockendar' ), value: 'listNextMonth' },
];

const FIRST_DAY_OPTIONS = [
	{ label: __( 'Sunday',    'blockendar' ), value: 0 },
	{ label: __( 'Monday',    'blockendar' ), value: 1 },
	{ label: __( 'Saturday',  'blockendar' ), value: 6 },
];

export function Edit( { attributes, setAttributes } ) {
	const {
		venueId, typeId, featuredOnly,
		enabledViews, defaultView, firstDay,
	} = attributes;

	const blockProps = useBlockProps( { className: 'blockendar-calendar-editor-preview' } );

	const venues = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'event_venue', {
				per_page: 100, hide_empty: false,
			} ) ?? [],
		[]
	);

	const types = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'event_type', {
				per_page: 100, hide_empty: false,
			} ) ?? [],
		[]
	);

	const venueOptions = [
		{ label: __( 'All venues', 'blockendar' ), value: 0 },
		...venues.map( ( v ) => ( { label: v.name, value: v.id } ) ),
	];

	const typeOptions = [
		{ label: __( 'All types', 'blockendar' ), value: 0 },
		...types.map( ( t ) => ( { label: t.name, value: t.id } ) ),
	];

	const toggleView = ( view ) => {
		const next = enabledViews.includes( view )
			? enabledViews.filter( ( v ) => v !== view )
			: [ ...enabledViews, view ];
		setAttributes( { enabledViews: next } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Filter', 'blockendar' ) }>
					<VStack spacing={ 3 }>
						<SelectControl
							label={ __( 'Venue', 'blockendar' ) }
							value={ venueId ?? 0 }
							options={ venueOptions }
							onChange={ ( val ) => setAttributes( { venueId: parseInt( val, 10 ) || undefined } ) }
							__nextHasNoMarginBottom
						/>
						<SelectControl
							label={ __( 'Event type', 'blockendar' ) }
							value={ typeId ?? 0 }
							options={ typeOptions }
							onChange={ ( val ) => setAttributes( { typeId: parseInt( val, 10 ) || undefined } ) }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Featured events only', 'blockendar' ) }
							checked={ featuredOnly }
							onChange={ ( val ) => setAttributes( { featuredOnly: val } ) }
						/>
					</VStack>
				</PanelBody>

				<PanelBody title={ __( 'Display', 'blockendar' ) }>
					<VStack spacing={ 3 }>
						<fieldset style={ { margin: 0, padding: 0, border: 'none' } }>
							<legend>{ __( 'Enabled views', 'blockendar' ) }</legend>
							{ VIEW_OPTIONS.map( ( { label, value } ) => (
								<ToggleControl
									key={ value }
									label={ label }
									checked={ enabledViews.includes( value ) }
									onChange={ () => toggleView( value ) }
								/>
							) ) }
						</fieldset>

						<SelectControl
							label={ __( 'Default view', 'blockendar' ) }
							value={ defaultView }
							options={ VIEW_OPTIONS.filter( ( v ) => enabledViews.includes( v.value ) ) }
							onChange={ ( val ) => setAttributes( { defaultView: val } ) }
							__nextHasNoMarginBottom
						/>

						<SelectControl
							label={ __( 'First day of week', 'blockendar' ) }
							value={ firstDay }
							options={ FIRST_DAY_OPTIONS }
							onChange={ ( val ) => setAttributes( { firstDay: parseInt( val, 10 ) } ) }
							__nextHasNoMarginBottom
						/>
					</VStack>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="blockendar-calendar-placeholder">
					<span className="dashicons dashicons-calendar-alt" />
					<p>{ __( 'Event Calendar', 'blockendar' ) }</p>
					<p className="blockendar-calendar-placeholder__hint">
						{ __( 'The interactive calendar renders on the frontend.', 'blockendar' ) }
					</p>
				</div>
			</div>
		</>
	);
}
