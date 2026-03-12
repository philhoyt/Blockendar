import { createRoot } from '@wordpress/element';
import { SettingsApp } from './SettingsApp';
import './style.css';

const root = document.getElementById( 'blockendar-settings-root' );
if ( root ) {
	createRoot( root ).render( <SettingsApp /> );
}
