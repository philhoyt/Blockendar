/**
 * event-status block — editor component.
 */
import { useBlockProps }   from '@wordpress/block-editor';
import { useEntityProp }   from '@wordpress/core-data';
import { __ }              from '@wordpress/i18n';

const LABELS = {
	cancelled: __( 'Cancelled', 'blockendar' ),
	postponed: __( 'Postponed', 'blockendar' ),
	sold_out:  __( 'Sold Out',  'blockendar' ),
};

export function Edit( { context } ) {
	const postId   = context?.postId;
	const postType = context?.postType ?? 'blockendar_event';

	const [ meta ] = useEntityProp( 'postType', postType, 'meta', postId );

	const status        = meta?.blockendar_status ?? '';
	const isPlaceholder = ! status;
	const isScheduled   = ! isPlaceholder && status === 'scheduled';
	const displayStatus = isPlaceholder ? 'cancelled' : status;

	const blockProps = useBlockProps(
		isScheduled
			? {}
			: { className: `blockendar-event-status blockendar-status blockendar-status--${ displayStatus }` }
	);

	if ( isScheduled ) {
		return (
			<div { ...blockProps }>
				<span style={ { opacity: 0.4, fontSize: '0.8em', fontStyle: 'italic' } }>
					{ __( 'Status badge — hidden for scheduled events', 'blockendar' ) }
				</span>
			</div>
		);
	}

	return (
		<div { ...blockProps } style={ isPlaceholder ? { opacity: 0.5 } : undefined }>
			{ LABELS[ displayStatus ] ?? displayStatus }
		</div>
	);
}
