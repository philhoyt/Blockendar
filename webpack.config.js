const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: async () => {
		const discovered =
			typeof defaultConfig.entry === 'function'
				? await defaultConfig.entry()
				: defaultConfig.entry;

		return {
			...discovered,
			'editor/index': './src/editor/index.js',
			'admin/index': './src/admin/index.js',
		};
	},
};
