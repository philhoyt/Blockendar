<?php
/**
 * WP-CLI: wp blockendar-demo seed|reset
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

namespace Blockendar\Demo\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Demo\Dependency;
use Blockendar\Demo\Seeder;

/**
 * Manages the Blockendar demo dataset.
 */
class DemoCommand {

	/**
	 * Creates the demo events, venues and guided tour pages.
	 *
	 * ## EXAMPLES
	 *
	 *   wp blockendar-demo seed
	 *
	 * @when after_wp_load
	 */
	public function seed(): void {
		$this->require_blockendar();

		$result = ( new Seeder() )->seed();

		if ( $result['skipped'] ) {
			$refreshed = (int) $result['refreshed'];

			if ( ! $refreshed ) {
				\WP_CLI::warning( 'Demo content is already installed, and no demo tour pages were found to rebuild. Run "wp blockendar-demo reset" first.' );
				return;
			}

			\WP_CLI::success(
				sprintf(
					'Demo content is already installed. Rebuilt %d tour page(s) with the current layout; the events were left alone. Run "wp blockendar-demo reset" for a fresh dataset.',
					$refreshed
				)
			);
			return;
		}

		foreach ( $result['errors'] as $error ) {
			\WP_CLI::warning( $error );
		}

		\WP_CLI::success(
			sprintf( 'Created %d events and %d pages.', $result['created'], $result['pages'] )
		);
	}

	/**
	 * Removes everything the demo seeder created.
	 *
	 * ## EXAMPLES
	 *
	 *   wp blockendar-demo reset
	 *
	 * @when after_wp_load
	 */
	public function reset(): void {
		$this->require_blockendar();

		$result = ( new Seeder() )->reset();

		\WP_CLI::success(
			sprintf(
				'Removed %d events, %d pages and %d terms.',
				$result['events'],
				$result['pages'],
				$result['terms']
			)
		);
	}

	/**
	 * Abort unless a compatible Blockendar is active.
	 *
	 * The admin-side guard runs on admin_init, which a CLI request never reaches.
	 */
	private function require_blockendar(): void {
		if ( ! Dependency::is_satisfied() ) {
			\WP_CLI::error( Dependency::failure_reason() );
		}
	}
}
