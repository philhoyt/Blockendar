<?php
/**
 * Plugin uninstall routine.
 *
 * Runs when the plugin is deleted (not deactivated) from the WordPress admin.
 * Removes custom tables and options — does NOT remove CPT posts or taxonomy terms
 * (user data is preserved on uninstall by convention).
 *
 * @package Blockendar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Autoloader needed to reference Schema.
spl_autoload_register(
	// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames
	function ( string $class ): void {
		// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames
		$prefix   = 'Blockendar\\';
		$base_dir = __DIR__ . '/includes/';

		if ( ! str_starts_with( $class, $prefix ) ) {
				return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

Blockendar\DB\Schema::drop_tables();

// Remove plugin options.
$options = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	'blockendar_db_version',
	'blockendar_last_index_rebuild',
	'blockendar_settings',
];

foreach ( $options as $option ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	delete_option( $option );
}
