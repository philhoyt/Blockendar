/**
 * blockendar/filter-event-type — block editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { displayStyle, showCount, showEmptyTerms, label } = attributes;
	const blockProps = useBlockProps( {
		className: 'blockendar-filter-event-type',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Filter Settings', 'blockendar' ) }>
					<TextControl
						label={ __( 'Label', 'blockendar' ) }
						help={ __(
							'Optional heading above the filter.',
							'blockendar'
						) }
						value={ label }
						onChange={ ( val ) => setAttributes( { label: val } ) }
					/>
					<SelectControl
						label={ __( 'Display style', 'blockendar' ) }
						value={ displayStyle }
						options={ [
							{
								label: __( 'List (checkboxes)', 'blockendar' ),
								value: 'list',
							},
							{
								label: __( 'Dropdown', 'blockendar' ),
								value: 'dropdown',
							},
						] }
						onChange={ ( val ) =>
							setAttributes( { displayStyle: val } )
						}
					/>
					<ToggleControl
						label={ __( 'Show event counts', 'blockendar' ) }
						help={ __(
							'Displays the number of events per type. Adds a query per term — use only with small term lists.',
							'blockendar'
						) }
						checked={ showCount }
						onChange={ ( val ) =>
							setAttributes( { showCount: val } )
						}
					/>
					<ToggleControl
						label={ __( 'Show empty terms', 'blockendar' ) }
						checked={ showEmptyTerms }
						onChange={ ( val ) =>
							setAttributes( { showEmptyTerms: val } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="blockendar-filter__placeholder">
					<span className="blockendar-filter__placeholder-icon">
						🏷
					</span>
					<span>
						{ __( 'Filter by Event Type', 'blockendar' ) }
						{ label && ` — ${ label }` }
						{ ' · ' }
						{ 'dropdown' === displayStyle
							? __( 'dropdown', 'blockendar' )
							: __( 'checkboxes', 'blockendar' ) }
					</span>
				</div>
			</div>
		</>
	);
}
