module.exports = {
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	rules: {
		// Experimental APIs are intentionally used here because the WordPress
		// version in use does not yet export the stable equivalents at runtime.
		'@wordpress/no-unsafe-wp-apis': 'off',
	},
};
