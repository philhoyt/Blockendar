<?php
/**
 * Plugin update checker — wires up PUC to GitHub releases.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Admin;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Registers the Plugin Update Checker against the GitHub repository.
 */
class UpdateChecker {

	/**
	 * Configure PUC to track GitHub releases for this plugin.
	 */
	public function register(): void {
		PucFactory::buildUpdateChecker(
			'https://github.com/philhoyt/Blockendar/',
			BLOCKENDAR_FILE,
			'blockendar'
		)->setBranch( 'main' );
		// Uncomment and set a token if the repository is private:
		// ->setAuthentication( 'your-github-token' );
	}
}
