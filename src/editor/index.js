/**
 * Blockendar editor sidebar panels.
 *
 * Registers PluginDocumentSettingPanel components that appear in the
 * block editor sidebar when editing a blockendar_event post.
 */
import { registerPlugin } from '@wordpress/plugins';
import './editor.css';
import { DateTimePanel } from './DateTimePanel';
import { EventDetailsPanel } from './EventDetailsPanel';

registerPlugin( 'blockendar-datetime', { render: DateTimePanel } );
registerPlugin( 'blockendar-event-details', { render: EventDetailsPanel } );
