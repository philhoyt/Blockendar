<?php
/**
 * Plugin Name:       Blockendar - Events and Calendars
 * Plugin URI:        https://github.com/philhoyt/Blockendar
 * Description:       A block-native WordPress events plugin. No shortcodes. No legacy widgets. The block editor is the UI.
 * Version:           0.10.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            philhoyt
 * Author URI:        https://philhoyt.com
 * Contributors:      philhoyt
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blockendar
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/philhoyt/Blockendar
 * Primary Branch:    main
 * Release Asset:     true
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants.
define( 'BLOCKENDAR_VERSION', '0.10.0' );
define( 'BLOCKENDAR_FILE', __FILE__ );
define( 'BLOCKENDAR_DIR', plugin_dir_path( __FILE__ ) );

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

// Global helper functions (loaded directly — not via autoloader).
require_once BLOCKENDAR_DIR . 'includes/Helpers.php';

// Plugin Update Checker — GitHub release-based auto-updates.
require_once BLOCKENDAR_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$blockendar_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/philhoyt/Blockendar/',
	__FILE__,
	'blockendar'
);
$blockendar_update_checker->getVcsApi()->enableReleaseAssets();

// Activation / deactivation hooks — registered before the plugin loads.
register_activation_hook(
	__FILE__,
	function (): void {
		Blockendar\DB\Schema::create_tables();
		// Register CPT and taxonomies so their rewrite rules exist before flush.
		( new Blockendar\CPT\EventPostType() )->register_post_type();
		( new Blockendar\Taxonomy\EventType() )->register_taxonomy();
		( new Blockendar\Taxonomy\EventTag() )->register_taxonomy();
		( new Blockendar\Taxonomy\Venue() )->register_taxonomy();
		flush_rewrite_rules();
	}
);
register_deactivation_hook(
	__FILE__,
	function (): void {
		Blockendar\Recurrence\Cron::unschedule();
		flush_rewrite_rules();
	}
);

// Boot the plugin.
add_action(
	'plugins_loaded',
	function (): void {
		( new Blockendar\Plugin() )->boot();
	}
);
