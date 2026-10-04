<?php
/**
 * WP-CLI command: rebuild the Blockendar event index.
 *
 * Usage:
 *   wp blockendar rebuild-index
 *   wp blockendar rebuild-index --dry-run
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\DB\IndexBuilder;

/**
 * Manages the Blockendar event index.
 */
class RebuildIndexCommand {

	/**
	 * Rebuilds the event index from CPT post meta.
	 *
	 * Replaces every published event's rows in turn, including all
	 * recurring-event instances up to the horizon, then removes rows whose
	 * event is gone. The index stays in use while it runs.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show how many events would be rebuilt without writing to the index.
	 *
	 * ## EXAMPLES
	 *
	 *   wp blockendar rebuild-index
	 *   wp blockendar rebuild-index --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		$dry_run = (bool) ( $assoc_args['dry-run'] ?? false );

		if ( $dry_run ) {
			\WP_CLI::log( sprintf( 'Dry run: %d published event(s) would be rebuilt.', $this->published_count() ) );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Rebuilding Blockendar event index', $this->published_count() );
		$builder  = new IndexBuilder();
		$result   = $builder->rebuild_all( fn() => $progress->tick() );

		$progress->finish();

		\WP_CLI::success(
			sprintf(
				'Done. Rebuilt: %d, Skipped: %d.',
				$result['rebuilt'],
				$result['skipped']
			)
		);
	}

	/**
	 * How many published events a rebuild will go through.
	 */
	private function published_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				'blockendar_event'
			)
		);
	}
}
