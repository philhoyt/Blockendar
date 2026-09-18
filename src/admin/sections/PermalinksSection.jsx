import {
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function PermalinksSection( { settings, update } ) {
	const slug = settings.events_slug ?? 'events';
	// An empty slug falls back to "events" on save; preview what will apply.
	const effectiveSlug = slug || 'events';
	const preview = `/${ effectiveSlug }/your-event-name/`;
	const typePreview = `/${ effectiveSlug }/type/exhibit/`;

	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'Permalinks', 'blockendar' ) }</h2>

			<TextControl
				label={ __( 'Events base slug', 'blockendar' ) }
				help={
					<>
						{ __(
							'The URL prefix for events and their type, venue and tag archives. Preview:',
							'blockendar'
						) }{ ' ' }
						<code>{ preview }</code>, <code>{ typePreview }</code>
						<br />
						{ __(
							'Rewrite rules are refreshed automatically when you save.',
							'blockendar'
						) }
					</>
				}
				value={ slug }
				onChange={ ( val ) => update( { events_slug: val } ) }
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}
