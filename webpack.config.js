const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * Single webpack config that:
 * 1. Preserves block auto-discovery from @wordpress/scripts (via the entry function)
 * 2. Adds editor/index and admin/index as additional entry points
 *
 * We wrap the default entry function so auto-discovered block entries are merged
 * with our custom ones — avoiding the output.clean collision that occurs when
 * using an array of separate webpack configs sharing the same output.path.
 */
const originalEntry = defaultConfig.entry;

module.exports = {
	...defaultConfig,
	entry: () => {
		const discovered =
			typeof originalEntry === 'function' ? originalEntry() : originalEntry;

		return {
			...discovered,
			'editor/index': './src/editor/index.js',
			'admin/index':  './src/admin/index.js',
		};
	},
};
