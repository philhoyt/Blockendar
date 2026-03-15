/**
 * blockendar/filter-date-range — block editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { label, labelStart, labelEnd, minDate, maxDate } = attributes;
	const blockProps = useBlockProps( {
		className: 'blockendar-filter-date-range',
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
					<TextControl
						label={ __( 'Start date label', 'blockendar' ) }
						value={ labelStart }
						onChange={ ( val ) =>
							setAttributes( { labelStart: val } )
						}
					/>
					<TextControl
						label={ __( 'End date label', 'blockendar' ) }
						value={ labelEnd }
						onChange={ ( val ) =>
							setAttributes( { labelEnd: val } )
						}
					/>
					<TextControl
						label={ __( 'Minimum date (Y-m-d)', 'blockendar' ) }
						help={ __(
							'Prevent selection before this date. Leave blank for no limit.',
							'blockendar'
						) }
						value={ minDate }
						placeholder="2026-01-01"
						onChange={ ( val ) =>
							setAttributes( { minDate: val } )
						}
					/>
					<TextControl
						label={ __( 'Maximum date (Y-m-d)', 'blockendar' ) }
						help={ __(
							'Prevent selection after this date. Leave blank for no limit.',
							'blockendar'
						) }
						value={ maxDate }
						placeholder="2027-12-31"
						onChange={ ( val ) =>
							setAttributes( { maxDate: val } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="blockendar-filter__placeholder">
					<span className="blockendar-filter__placeholder-icon">
						📅
					</span>
					<span>
						{ __( 'Filter by Date Range', 'blockendar' ) }
						{ label && ` — ${ label }` }
						{ ` · ${ labelStart } / ${ labelEnd }` }
					</span>
				</div>
			</div>
		</>
	);
}
