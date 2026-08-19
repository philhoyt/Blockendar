/**
 * blockendar/query-filters — block editor component.
 *
 * Provides a wrapper that distributes the queryId as block context to all
 * child blocks. The only inspector control is the queryId field, which only
 * needs to be set when multiple filter+query groups exist on the same page.
 */
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const TEMPLATE = [
	[ 'blockendar/filter-event-type', {} ],
	[ 'blockendar/filter-venue', {} ],
	[ 'blockendar/filter-date-range', {} ],
	[ 'blockendar/events-query', {} ],
];

export default function Edit( { attributes, setAttributes } ) {
	const { queryId } = attributes;
	const blockProps = useBlockProps( {
		className: 'blockendar-query-filters',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Filter Group', 'blockendar' ) }>
					<TextControl
						label={ __( 'Query ID', 'blockendar' ) }
						help={ __(
							'Leave blank for a single filter group. Set a unique ID (e.g. "sidebar") only when you have multiple Events Query + Filter groups on the same page.',
							'blockendar'
						) }
						value={ queryId }
						onChange={ ( val ) =>
							setAttributes( {
								queryId: val
									.toLowerCase()
									.replace( /[^a-z0-9_-]/g, '' ),
							} )
						}
					/>
					{ queryId && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'All filter blocks and the Events Query block inside this group must share this Query ID.',
								'blockendar'
							) }
						</Notice>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<InnerBlocks template={ TEMPLATE } />
			</div>
		</>
	);
}
