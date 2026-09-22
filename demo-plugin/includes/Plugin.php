<?php
/**
 * Demo plugin loader.
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

namespace Blockendar\Demo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Demo\CLI\DemoCommand;

/**
 * Bootstraps the demo plugin's components.
 */
class Plugin {

	/**
	 * Option set at activation and consumed on the next `init` to trigger seeding.
	 */
	public const SEED_FLAG = 'blockendar_demo_pending_seed';

	/**
	 * Option holding every seeded object ID plus the prior front-page config.
	 */
	public const STATE_OPTION = 'blockendar_demo_seeded';

	/**
	 * Attach hooks.
	 */
	public function boot(): void {
		// enforce() must run before render_notice() so a refusal notice is
		// available on the same page load.
		add_action( 'admin_init', [ Dependency::class, 'enforce' ] );
		add_action( 'admin_notices', [ Dependency::class, 'render_notice' ] );

		// Priority 99: the main plugin registers its post type, taxonomies and
		// the recurrence Generator on `init` at the default priority, and the
		// seeder needs all three.
		add_action( 'init', [ $this, 'maybe_seed' ], 99 );

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'blockendar-demo', DemoCommand::class );
		}
	}

	/**
	 * Run the deferred activation seed, once.
	 *
	 * Activation itself only sets a flag. Seeding there would run before the main
	 * plugin had booted, so recurring events would be written with a rule row but
	 * no occurrence rows, and the dependency guard would have nothing to check.
	 */
	public function maybe_seed(): void {
		if ( ! get_option( self::SEED_FLAG ) ) {
			return;
		}

		// Clear first: a fatal part-way through must not retry on every request.
		delete_option( self::SEED_FLAG );

		if ( ! Dependency::is_satisfied() ) {
			set_transient( Dependency::NOTICE_KEY, Dependency::failure_reason(), MINUTE_IN_SECONDS );
			return;
		}

		( new Seeder() )->seed();
	}
}
