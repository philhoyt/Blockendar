/**
 * calendar-view block — editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	CheckboxControl,
	ToggleControl,
	SelectControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useSelect }          from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ }                 from '@wordpress/i18n';

const VIEW_OPTIONS = [
	{ label: __( 'Month', 'blockendar' ), value: 'dayGridMonth' },
	{ label: __( 'Week',  'blockendar' ), value: 'timeGridWeek' },
	{ label: __( 'Day',   'blockendar' ), value: 'timeGridDay' },
	{ label: __( 'List',  'blockendar' ), value: 'listNextMonth' },
];

const FIRST_DAY_OPTIONS = [
	{ label: __( 'Sunday',   'blockendar' ), value: 0 },
	{ label: __( 'Monday',   'blockendar' ), value: 1 },
	{ label: __( 'Saturday', 'blockendar' ), value: 6 },
];

function TermCheckboxList( { terms, selected, onChange, emptyLabel } ) {
	if ( ! terms.length ) {
		return <p style={ { margin: 0, color: '#757575', fontSize: '12px' } }>{ emptyLabel }</p>;
	}

	// Empty array means "all selected" — no filter applied.
	const isChecked = ( id ) => selected.length === 0 || selected.includes( id );

	const toggle = ( id, checked ) => {
		if ( checked ) {
			const next = [ ...selected, id ];
			// If every term is now checked, reset to empty (= all, no filter).
			onChange( next.length === terms.length ? [] : next );
		} else {
			// If we were showing all, uncheck one → select all others.
			const base = selected.length === 0 ? terms.map( ( t ) => t.id ) : selected;
			onChange( base.filter( ( v ) => v !== id ) );
		}
	};

	return (
		<VStack spacing={ 0 }>
			{ terms.map( ( term ) => (
				<div key={ term.id } style={ { marginBottom: 4 } }>
					<CheckboxControl
						label={ term.name }
						checked={ isChecked( term.id ) }
						onChange={ ( checked ) => toggle( term.id, checked ) }
						__nextHasNoMarginBottom
					/>
				</div>
			) ) }
		</VStack>
	);
}

export function Edit( { attributes, setAttributes } ) {
	const {
		venueIds, typeIds, featuredOnly,
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
					<VStack spacing={ 4 }>

						<fieldset style={ { margin: 0, padding: 0, border: 'none' } }>
							<legend style={ { marginBottom: 6, fontWeight: 600 } }>
								{ __( 'Event type', 'blockendar' ) }
							</legend>
							<TermCheckboxList
								terms={ types }
								selected={ typeIds }
								onChange={ ( next ) => setAttributes( { typeIds: next } ) }
								emptyLabel={ __( 'No event types found.', 'blockendar' ) }
							/>
						</fieldset>

						<fieldset style={ { margin: 0, padding: 0, border: 'none' } }>
							<legend style={ { marginBottom: 6, fontWeight: 600 } }>
								{ __( 'Venue', 'blockendar' ) }
							</legend>
							<TermCheckboxList
								terms={ venues }
								selected={ venueIds }
								onChange={ ( next ) => setAttributes( { venueIds: next } ) }
								emptyLabel={ __( 'No venues found.', 'blockendar' ) }
							/>
						</fieldset>

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
