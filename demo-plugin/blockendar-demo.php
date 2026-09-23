<?php
/**
 * Plugin Name:       Blockendar Demo Content
 * Plugin URI:        https://github.com/philhoyt/Blockendar
 * Description:       Seeds Blockendar with relative-dated demo events and a guided tour of the plugin's blocks. Built for WordPress Playground and local QA — not for production sites.
 * Version:           1.8.1
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            philhoyt
 * Author URI:        https://philhoyt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLOCKENDAR_DEMO_VERSION', '1.8.1' );
define( 'BLOCKENDAR_DEMO_FILE', __FILE__ );
define( 'BLOCKENDAR_DEMO_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOCKENDAR_DEMO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Lowest Blockendar version the seeder can build against.
 *
 * The APIs it calls -- IndexBuilder::build_for_post(),
 * EventIndex::delete_by_post_id() and the Recurrence\RuleRepository CRUD
 * methods -- have been in place since 1.4.0. What moved the floor to 1.7.0 is
 * the markup: the tour wraps the filter blocks in a core/group to lay them out
 * in a row, and before 1.7.0 those blocks declared "parent", which allows them
 * only as direct children of query-filters. On an older Blockendar the pages
 * still render, but the editor reports the filter blocks as not allowed there.
 */
define( 'BLOCKENDAR_DEMO_MIN_BLOCKENDAR', '1.7.0' );

// Autoloader.
spl_autoload_register(
	// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames
	function ( string $class ): void {
		// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames
		$prefix   = 'Blockendar\\Demo\\';
		$base_dir = BLOCKENDAR_DEMO_DIR . 'includes/';

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

/**
 * Activation.
 *
 * Seeding is deliberately NOT done here. At activation time the main plugin's
 * Recurrence\Generator may not have registered its listener for the
 * blockendar_generate_recurrence_index action, which would leave recurring
 * events with a rule but no occurrence rows. Instead this sets a one-shot flag
 * that Blockendar\Demo\Plugin consumes on a later `init`, by which point
 * Blockendar has fully booted.
 */
register_activation_hook(
	__FILE__,
	function (): void {
		if ( ! Blockendar\Demo\Dependency::is_satisfied() ) {
			Blockendar\Demo\Dependency::halt_activation();
			return;
		}

		update_option( Blockendar\Demo\Plugin::SEED_FLAG, 1, false );
	}
);

// Boot.
add_action(
	'plugins_loaded',
	function (): void {
		( new Blockendar\Demo\Plugin() )->boot();
	}
);
