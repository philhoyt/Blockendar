/**
 * event-cost block — editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl }           from '@wordpress/components';
import { useEntityProp }                    from '@wordpress/core-data';
import { __ }                               from '@wordpress/i18n';

export function Edit( { attributes, setAttributes, context } ) {
	const { buttonLabel } = attributes;
	const postId   = context?.postId;
	const postType = context?.postType ?? 'blockendar_event';

	const [ meta ] = useEntityProp( 'postType', postType, 'meta', postId );

	const cost   = meta?.blockendar_cost             ?? '';
	const regUrl = meta?.blockendar_registration_url ?? '';

	const isPlaceholder = ! cost && ! regUrl;
	const displayCost   = isPlaceholder ? '$25.00' : cost;
	const showButton    = isPlaceholder || !! regUrl;
	const displayLabel  = buttonLabel || __( 'Register / Get Tickets', 'blockendar' );

	const blockProps = useBlockProps( { className: 'blockendar-event-cost' } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'blockendar' ) }>
					<TextControl
						label={ __( 'Button label', 'blockendar' ) }
						value={ buttonLabel }
						onChange={ ( val ) => setAttributes( { buttonLabel: val } ) }
						placeholder={ __( 'Register / Get Tickets', 'blockendar' ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps } style={ isPlaceholder ? { opacity: 0.5 } : undefined }>
				{ displayCost && (
					<span className="blockendar-event-cost__amount">
						{ displayCost }
					</span>
				) }
				{ showButton && (
					<a className="blockendar-event-cost__cta wp-element-button">
						{ displayLabel }
					</a>
				) }
			</div>
		</>
	);
}
