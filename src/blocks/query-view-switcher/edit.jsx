/**
 * blockendar/query-view-switcher — block editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { showLabels, defaultView } = attributes;
	const blockProps = useBlockProps( {
		className: 'blockendar-view-switcher',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'View Switcher', 'blockendar' ) }>
					<SelectControl
						label={ __( 'Default view', 'blockendar' ) }
						help={ __(
							'The layout used until a visitor chooses another. Selecting it leaves no parameter in the URL.',
							'blockendar'
						) }
						value={ defaultView }
						options={ [
							{ label: __( 'List', 'blockendar' ), value: 'list' },
							{ label: __( 'Grid', 'blockendar' ), value: 'grid' },
						] }
						onChange={ ( val ) =>
							setAttributes( { defaultView: val } )
						}
					/>
					<ToggleControl
						label={ __( 'Show labels', 'blockendar' ) }
						help={ __(
							'Shows text beside each icon. When off the icons carry an accessible label instead.',
							'blockendar'
						) }
						checked={ showLabels }
						onChange={ ( val ) =>
							setAttributes( { showLabels: val } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<span className="blockendar-view-switcher__button is-active">
					<span
						className="blockendar-view-switcher__icon"
						aria-hidden="true"
					/>
					{ showLabels && (
						<span className="blockendar-view-switcher__label">
							{ __( 'List view', 'blockendar' ) }
						</span>
					) }
				</span>
				<span className="blockendar-view-switcher__button">
					<span
						className="blockendar-view-switcher__icon"
						aria-hidden="true"
					/>
					{ showLabels && (
						<span className="blockendar-view-switcher__label">
							{ __( 'Grid view', 'blockendar' ) }
						</span>
					) }
				</span>
			</div>
		</>
	);
}
