/**
 * event-venue block — editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

const PLACEHOLDER = {
	name: 'The Grand Ballroom',
	address: '123 Main St, New York, NY',
	virtual: false,
	stream: '',
};

export function Edit( { attributes, setAttributes, context } ) {
	const { showAddress, showMap } = attributes;
	const postId = context?.postId;

	// Get the term IDs assigned to this post.
	const termIds = useSelect(
		( select ) => {
			if ( ! postId ) {
				return [];
			}
			const post = select( coreStore ).getEditedEntityRecord(
				'postType',
				'blockendar_event',
				postId
			);
			return post?.event_venue ?? [];
		},
		[ postId ]
	);

	// Fetch the first term's full record (includes meta when show_in_rest is set).
	const term = useSelect(
		( select ) => {
			const id = termIds?.[ 0 ];
			if ( ! id ) {
				return null;
			}
			return select( coreStore ).getEntityRecord(
				'taxonomy',
				'event_venue',
				id
			);
		},
		[ termIds ]
	);

	const isPlaceholder = ! term;

	const name = term?.name ?? PLACEHOLDER.name;
	const meta = term?.meta ?? {};
	const virtual = isPlaceholder
		? PLACEHOLDER.virtual
		: !! meta.blockendar_venue_virtual;
	const stream = isPlaceholder
		? PLACEHOLDER.stream
		: meta.blockendar_venue_stream_url ?? '';

	const addressParts = isPlaceholder
		? [ PLACEHOLDER.address ]
		: [
				meta.blockendar_venue_address ?? '',
				meta.blockendar_venue_city ?? '',
				meta.blockendar_venue_state ?? '',
				meta.blockendar_venue_country ?? '',
		  ].filter( Boolean );

	const addressStr = addressParts.join( ', ' );

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
					<ToggleControl
						label={ __( 'Show map', 'blockendar' ) }
						checked={ showMap }
						onChange={ ( val ) =>
							setAttributes( { showMap: val } )
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div
				{ ...blockProps }
				style={ isPlaceholder ? { opacity: 0.5 } : undefined }
			>
				<div className="blockendar-event-venue__body">
					<span className="blockendar-event-venue__name">
						{ name }
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
			</div>
		</>
	);
}
