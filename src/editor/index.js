/**
 * Blockendar editor sidebar panels.
 *
 * Registers four PluginDocumentSettingPanel components that appear in the
 * block editor sidebar when editing a blockendar_event post.
 */
import { registerPlugin } from '@wordpress/plugins';
import './editor.css';
import { DateTimePanel } from './DateTimePanel';
import { RecurrencePanel } from './RecurrencePanel';
import { EventDetailsPanel } from './EventDetailsPanel';
import { VenuePanel } from './VenuePanel';

registerPlugin( 'blockendar-datetime', { render: DateTimePanel } );
registerPlugin( 'blockendar-recurrence', { render: RecurrencePanel } );
registerPlugin( 'blockendar-event-details', { render: EventDetailsPanel } );
registerPlugin( 'blockendar-venue', { render: VenuePanel } );
