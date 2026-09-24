<?php
/**
 * WP-CLI command: the 2.0.0 taxonomy rename.
 *
 * Usage:
 *   wp blockendar migrate-taxonomies --status
 *   wp blockendar migrate-taxonomies --dry-run
 *   wp blockendar migrate-taxonomies --run
 *   wp blockendar migrate-taxonomies --rollback
 *   wp blockendar migrate-taxonomies --clear-backups
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Migration\TaxonomyPrefixMigration;

/**
 * Moves event types, tags and venues to their prefixed taxonomy names.
 */
class MigrateTaxonomiesCommand {

	/**
	 * Inspects, performs or reverses the 2.0.0 taxonomy rename.
	 *
	 * Ordinary web requests run the migration on their own. Under WP-CLI it
	 * never runs unasked, so a site can be inspected with --status and --dry-run
	 * exactly as the upgrade left it, then migrated with --run.
	 *
	 * ## OPTIONS
	 *
	 * [--status]
	 * : Where this site stands. The default when no option is given.
	 *
	 * [--dry-run]
	 * : What a run would change, without changing anything.
	 *
	 * [--run]
	 * : Perform the migration now.
	 *
	 * [--rollback]
	 * : Reverse a completed migration, restoring exactly the rows it changed.
	 *
	 * [--clear-backups]
	 * : Delete the per-post originals kept for --rollback.
	 *
	 * ## EXAMPLES
	 *
	 *   wp blockendar migrate-taxonomies --dry-run
	 *   wp blockendar migrate-taxonomies --run
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function migrate( array $args, array $assoc_args ): void {
		$chosen = array_values( array_intersect( [ 'status', 'dry-run', 'run', 'rollback', 'clear-backups' ], array_keys( $assoc_args ) ) );

		if ( count( $chosen ) > 1 ) {
			\WP_CLI::error( 'Choose one of --status, --dry-run, --run, --rollback or --clear-backups.' );
		}

		$migration = new TaxonomyPrefixMigration();

		switch ( $chosen[0] ?? 'status' ) {
			case 'dry-run':
				$this->dry_run( $migration );
				break;
			case 'run':
				$this->run( $migration );
				break;
			case 'rollback':
				$this->rollback( $migration );
				break;
			case 'clear-backups':
				\WP_CLI::success( sprintf( 'Deleted %d backup(s). --rollback is no longer possible.', $migration->clear_backups() ) );
				break;
			default:
				$this->status( $migration );
		}
	}

	/**
	 * Where the site stands.
	 *
	 * @param TaxonomyPrefixMigration $migration The migration.
	 */
	private function status( TaxonomyPrefixMigration $migration ): void {
		$status = $migration->status();

		if ( $status['migrated'] ) {
			\WP_CLI::log( sprintf( 'Migrated by Blockendar %s.', $status['migrated_by'] ) );
		} elseif ( $status['in_progress'] ) {
			\WP_CLI::log( 'A migration is running in another request.' );
		} elseif ( $status['resume_from'] ) {
			\WP_CLI::log( sprintf( 'Interrupted; the next run resumes from post %d.', $status['resume_from'] ) );
		} else {
			\WP_CLI::log( 'Not migrated. The first ordinary web request will migrate this site; under WP-CLI only --run does.' );
		}

		$blocked = get_transient( TaxonomyPrefixMigration::BLOCKED_TRANSIENT );
		if ( is_array( $blocked ) ) {
			\WP_CLI::warning( sprintf( 'The last automatic attempt was refused (%s): %s', $blocked['code'], $blocked['message'] ) );
		}

		\WP_CLI::log( sprintf( 'Started: %s', '' !== $status['started_at'] ? $status['started_at'] . ' UTC' : '—' ) );
		\WP_CLI::log( sprintf( 'Term rows moved: %d', $status['terms_moved'] ) );
		\WP_CLI::log( sprintf( 'Posts rewritten: %d', $status['posts_rewritten'] ) );
		\WP_CLI::log( sprintf( 'Backups held: %d', $status['backups_held'] ) );
	}

	/**
	 * What a run would change.
	 *
	 * @param TaxonomyPrefixMigration $migration The migration.
	 */
	private function dry_run( TaxonomyPrefixMigration $migration ): void {
		if ( ! $migration->needs_migration() ) {
			\WP_CLI::success( 'Already migrated; a run would change nothing.' );
			return;
		}

		$report = $migration->dry_run();

		if ( is_wp_error( $report['preflight'] ) ) {
			foreach ( $report['preflight']->get_error_messages() as $message ) {
				\WP_CLI::warning( $message );
			}
			\WP_CLI::log( 'Preflight would refuse to run. Counts below are what it would otherwise change.' );
		} else {
			\WP_CLI::log( 'Preflight: clear to run.' );
		}

		\WP_CLI::log( sprintf( 'Term rows to move: %d', $report['terms'] ) );
		\WP_CLI::log( sprintf( 'Orphaned *_children options to delete: %d', $report['children_options'] ) );
		\WP_CLI::log( sprintf( 'Classic menu items to repoint: %d', $report['nav_menu_items'] ) );
		\WP_CLI::log( sprintf( 'Site Editor template slugs to rename: %d', $report['template_slugs'] ) );
		\WP_CLI::log( sprintf( 'Posts with block markup to rewrite: %d', $report['posts'] ) );
		\WP_CLI::log( sprintf( 'Block widget option to rewrite: %s', $report['block_widgets'] ? 'yes' : 'no' ) );
		\WP_CLI::log( 'Not covered: theme or snippet code naming the old taxonomies, hooks such as created_event_venue, revisions, per-user screen state, and SEO or translation plugin data keyed by taxonomy name.' );
	}

	/**
	 * Perform the migration.
	 *
	 * @param TaxonomyPrefixMigration $migration The migration.
	 */
	private function run( TaxonomyPrefixMigration $migration ): void {
		if ( ! $migration->needs_migration() ) {
			\WP_CLI::success( 'Already migrated.' );
			return;
		}

		$result = $migration->run();

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( implode( ' ', $result->get_error_messages() ) );
		}

		$status = $migration->status();
		\WP_CLI::success( sprintf( 'Migrated. Term rows moved: %d. Posts rewritten: %d. An index rebuild is scheduled.', $status['terms_moved'], $status['posts_rewritten'] ) );
	}

	/**
	 * Reverse the migration.
	 *
	 * @param TaxonomyPrefixMigration $migration The migration.
	 */
	private function rollback( TaxonomyPrefixMigration $migration ): void {
		if ( $migration->needs_migration() ) {
			\WP_CLI::error( 'Nothing to roll back: this site has not migrated.' );
		}

		$report = $migration->rollback();

		\WP_CLI::log( sprintf( 'Term rows restored: %d', $report['terms'] ) );
		\WP_CLI::log( sprintf( 'Posts restored: %d', $report['posts'] ) );

		if ( ! empty( $report['posts_skipped'] ) ) {
			\WP_CLI::warning( sprintf( 'Left as edited since the migration: post(s) %s. Their backups are kept.', implode( ', ', $report['posts_skipped'] ) ) );
		}

		\WP_CLI::success( 'Rolled back. The site will migrate again on its next ordinary request unless the plugin is downgraded first.' );
	}
}
