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
		add_action( 'admin_notices', [ Dependency::class, 'render_notice' ] );
	}
}
