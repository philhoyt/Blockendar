<?php
/**
 * Plugin Name:       Blockendar
 * Plugin URI:        https://github.com/blockendar/blockendar
 * Description:       A block-native WordPress events plugin. No shortcodes. No legacy widgets. The block editor is the UI.
 * Version:           0.2.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Blockendar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blockendar
 * Domain Path:       /languages
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants.
define( 'BLOCKENDAR_VERSION', '0.2.0' );
define( 'BLOCKENDAR_FILE', __FILE__ );
define( 'BLOCKENDAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOCKENDAR_URL', plugin_dir_url( __FILE__ ) );
define( 'BLOCKENDAR_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader.
spl_autoload_register(
	// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames
	function ( string $class ): void {
		// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames
		$prefix   = 'Blockendar\\';
		$base_dir = BLOCKENDAR_DIR . 'includes/';

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

// Activation / deactivation hooks — registered before the plugin loads.
register_activation_hook( __FILE__, [ 'Blockendar\\DB\\Schema', 'create_tables' ] );
register_deactivation_hook( __FILE__, [ 'Blockendar\\Recurrence\\Cron', 'unschedule' ] );

// Boot the plugin.
add_action(
	'plugins_loaded',
	function (): void {
		( new Blockendar\Plugin() )->boot();
	}
);
