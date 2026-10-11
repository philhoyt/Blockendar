/**
 * event-status block — editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

// The event edit screen is told the full list, filter included; elsewhere
// (a template in the Site Editor) only the shipped labels are known.
const LABELS = Object.fromEntries(
	( window.blockendarEditor?.statuses ?? [] )
		.filter( ( option ) => option && typeof option.value === 'string' )
		.map( ( option ) => [ option.value, option.label ?? option.value ] )
);

LABELS.cancelled ??= __( 'Cancelled', 'blockendar' );
LABELS.postponed ??= __( 'Postponed', 'blockendar' );
LABELS.sold_out ??= __( 'Sold Out', 'blockendar' );

export function Edit( { attributes, setAttributes, context } ) {
	const postId = context?.postId;
	const postType = context?.postType ?? 'blockendar_event';

	const [ meta ] = useEntityProp( 'postType', postType, 'meta', postId );

	const showReason = attributes.showReason ?? true;
	const status = meta?.blockendar_status ?? '';
	const reason = meta?.blockendar_status_reason ?? '';

	const controls = (
		<InspectorControls>
			<PanelBody title={ __( 'Display', 'blockendar' ) }>
				<ToggleControl
					label={ __( 'Show reason', 'blockendar' ) }
					help={ __(
						'Shows the reason entered with the status, when there is one.',
						'blockendar'
					) }
					checked={ showReason }
					onChange={ ( val ) => setAttributes( { showReason: val } ) }
					__nextHasNoMarginBottom
				/>
			</PanelBody>
		</InspectorControls>
	);
	const isPlaceholder = ! status;
	const isScheduled = ! isPlaceholder && status === 'scheduled';
	const displayStatus = isPlaceholder ? 'cancelled' : status;

	const blockProps = useBlockProps(
		isScheduled
			? {}
			: {
					className: `blockendar-event-status blockendar-status blockendar-status--${ displayStatus }`,
			  }
	);

	if ( isScheduled ) {
		return (
			<>
				{ controls }
				<div { ...blockProps }>
					<span
						style={ {
							opacity: 0.4,
							fontSize: '0.8em',
							fontStyle: 'italic',
						} }
					>
						{ __(
							'Status badge — hidden for scheduled events',
							'blockendar'
						) }
					</span>
				</div>
			</>
		);
	}

	return (
		<>
			{ controls }
			<div
				{ ...blockProps }
				style={ isPlaceholder ? { opacity: 0.5 } : undefined }
			>
				{ LABELS[ displayStatus ] ?? displayStatus }
				{ showReason && ! isPlaceholder && reason && (
					<>
						{ ' ' }
						<span className="blockendar-status__reason">
							{ reason }
						</span>
					</>
				) }
			</div>
		</>
	);
}
