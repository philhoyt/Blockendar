/**
 * blockendar/filter-venue — block editor component.
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
	const { displayStyle, showEmpty, showVirtual, label } = attributes;
	const blockProps = useBlockProps( {
		className: 'blockendar-filter-venue',
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
								label: __(
									'List (radio buttons)',
									'blockendar'
								),
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
						label={ __( 'Show empty venues', 'blockendar' ) }
						checked={ showEmpty }
						onChange={ ( val ) =>
							setAttributes( { showEmpty: val } )
						}
					/>
					<ToggleControl
						label={ __( 'Show virtual venues', 'blockendar' ) }
						checked={ showVirtual }
						onChange={ ( val ) =>
							setAttributes( { showVirtual: val } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="blockendar-filter__placeholder">
					<span className="blockendar-filter__placeholder-icon">
						📍
					</span>
					<span>
						{ __( 'Filter by Venue', 'blockendar' ) }
						{ label && ` — ${ label }` }
						{ ' · ' }
						{ 'dropdown' === displayStyle
							? __( 'dropdown', 'blockendar' )
							: __( 'radio buttons', 'blockendar' ) }
					</span>
				</div>
			</div>
		</>
	);
}
