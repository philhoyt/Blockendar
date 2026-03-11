import { registerBlockType } from '@wordpress/blocks';
import metadata              from './block.json';
import { Edit }              from './edit';
import './style.css';

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
