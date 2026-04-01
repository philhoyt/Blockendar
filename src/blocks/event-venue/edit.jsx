/**
 * event-venue block — editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

const PLACEHOLDER = [
	{
		name: 'The Grand Ballroom',
		address: '123 Main St, New York, NY',
		virtual: false,
		stream: '',
	},
];

export function Edit( { attributes, setAttributes, context } ) {
	const { showAddress } = attributes;
	const postId = context?.postId;

	// Get all term IDs assigned to this post.
	const termIds = useSelect(
		( select ) => {
			if ( ! postId ) return [];
			const post = select( coreStore ).getEditedEntityRecord(
				'postType',
				'blockendar_event',
				postId
			);
			return post?.event_venue ?? [];
		},
		[ postId ]
	);

	// Fetch all term records.
	const terms = useSelect(
		( select ) => {
			if ( ! termIds?.length ) return null;
			const records = termIds.map( ( id ) =>
				select( coreStore ).getEntityRecord(
					'taxonomy',
					'event_venue',
					id
				)
			);
			// Return null if any are still loading.
			return records.some( ( r ) => r === undefined ) ? null : records;
		},
		[ termIds ]
	);

	const isPlaceholder = ! terms?.length;
	const venues = isPlaceholder ? PLACEHOLDER : terms;

	const blockProps = useBlockProps( { className: 'blockendar-event-venue' } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Display', 'blockendar' ) }>
					<ToggleControl
						label={ __( 'Show address', 'blockendar' ) }
						checked={ showAddress }
						onChange={ ( val ) =>
							setAttributes( { showAddress: val } )
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div
				{ ...blockProps }
				style={ isPlaceholder ? { opacity: 0.5 } : undefined }
			>
				{ venues.map( ( venue, i ) => {
					const isFirst = i === 0;
					const meta = venue?.meta ?? {};
					const virtual = isPlaceholder
						? venue.virtual
						: !! meta.blockendar_venue_virtual;
					const stream = isPlaceholder
						? venue.stream
						: meta.blockendar_venue_stream_url ?? '';
					const addressStr = isPlaceholder
						? venue.address
						: [
								meta.blockendar_venue_address ?? '',
								meta.blockendar_venue_city ?? '',
								meta.blockendar_venue_state ?? '',
								meta.blockendar_venue_country ?? '',
						  ]
								.filter( Boolean )
								.join( ', ' );

					return (
						<>
						{ ! isFirst && <hr className="blockendar-event-venue__divider" /> }
						<div key={ i } className="blockendar-event-venue__body">
							<span className="blockendar-event-venue__name">
								{ venue.name }
							</span>

							{ virtual ? (
								<>
									<span className="blockendar-event-venue__virtual-badge">
										{ __( 'Online', 'blockendar' ) }
									</span>
									{ stream && (
										<a
											className="blockendar-event-venue__stream"
											href={ stream }
										>
											{ __( 'Join stream', 'blockendar' ) }
										</a>
									) }
								</>
							) : (
								showAddress &&
								addressStr && (
									<address className="blockendar-event-venue__address">
										{ addressStr }
									</address>
								)
							) }
						</div>
						</>
					);
				} ) }
			</div>
		</>
	);
}
