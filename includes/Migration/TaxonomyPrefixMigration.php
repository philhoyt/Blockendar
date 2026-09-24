<?php
/**
 * One-shot migration from the unprefixed taxonomy names to the prefixed ones.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\CPT\EventPostType;
use Blockendar\Taxonomy\EventTag;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;
use WP_Error;

/**
 * Moves a site's data from `event_type`, `event_tag` and `event_venue` to
 * `blockendar_event_type`, `blockendar_event_tag` and `blockendar_event_venue`.
 *
 * A taxonomy name is a global registry key and these three are exactly the names
 * another events plugin would pick, so 2.0.0 renames them. Term relationships
 * and term meta follow the term row and need nothing; permalinks come from the
 * rewrite slug, not the name, and need nothing. What does have to move is every
 * place WordPress persists the name itself, and each is its own step below.
 *
 * The migration runs once per site, behind an atomic lock, after a preflight
 * that refuses to run when another plugin's data shares the old names. It
 * records every row it changes so that rollback reverses only its own work, and
 * it stores each rewritten post's original content in post meta.
 *
 * It fires on `init` at priority 30 for ordinary requests. Under WP-CLI it never
 * runs on its own: `wp blockendar migrate-taxonomies` owns it there, so
 * `--dry-run` can report a site that has not migrated yet.
 */
class TaxonomyPrefixMigration {

	/**
	 * Old taxonomy name => new taxonomy name.
	 */
	const MAP = [
		'event_type'  => 'blockendar_event_type',
		'event_tag'   => 'blockendar_event_tag',
		'event_venue' => 'blockendar_event_venue',
	];

	/**
	 * Set once the migration has completed on this site (or on activation of a
	 * fresh install, which has nothing to migrate). Holds the plugin version that
	 * wrote it.
	 */
	const GATE_OPTION = 'blockendar_taxonomy_prefix_migrated';

	/**
	 * Held while a run is in progress. Written with add_option(), whose INSERT
	 * against the option name's unique key succeeds for exactly one caller.
	 */
	const LOCK_OPTION = 'blockendar_taxonomy_prefix_migration_lock';

	/**
	 * The last post ID the content sweep finished, so a run that dies partway
	 * resumes instead of starting over.
	 */
	const CURSOR_OPTION = 'blockendar_taxonomy_prefix_migration_cursor';

	/**
	 * Every row the migration changed, keyed by step, so rollback can reverse
	 * exactly those and nothing created since.
	 */
	const LOG_OPTION = 'blockendar_taxonomy_prefix_migration_log';

	/**
	 * Post meta holding a rewritten post's original content.
	 */
	const BACKUP_META = '_blockendar_taxonomy_migration_backup';

	/**
	 * Whether this site still holds data under the old names.
	 *
	 * @return bool True until the gate option has been written.
	 */
	public function needs_migration(): bool {
		return '' === (string) get_option( self::GATE_OPTION, '' );
	}

	/**
	 * Whether the `init` hook should run the migration on this request.
	 *
	 * False under WP-CLI even when the site needs migrating: WP-CLI boots
	 * WordPress and fires `init` before it dispatches to any command, so an
	 * automatic run here would migrate the site out from under
	 * `wp blockendar migrate-taxonomies --dry-run`. The command performs the
	 * migration itself when asked with `--run`.
	 *
	 * @return bool
	 */
	public function should_run_on_init(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		return $this->needs_migration();
	}

	/**
	 * Mark the site migrated (or, on a fresh install, as having nothing to
	 * migrate). Activation calls this so a new 2.0.0 site never runs the
	 * migration at all — which matters when another plugin owns `event_type`.
	 */
	public function mark_migrated(): void {
		update_option( self::GATE_OPTION, BLOCKENDAR_VERSION, false );
	}

	/**
	 * Refuse to run when this site's data cannot be moved safely.
	 *
	 * Two conditions abort, each under its own error code so a notice or the
	 * CLI can say which:
	 *
	 * - `target_occupied` — rows already exist under a new name. Any rows, not
	 *   only ones sharing a term_id with something about to move: someone has
	 *   been here, and moving more rows on top would merge two datasets.
	 * - `shared_bucket` — another plugin's data lives under an old name. A term
	 *   carries no record of who created it, so a shared bucket cannot be split
	 *   and has to be resolved by hand. Detected two ways, because neither alone
	 *   is enough: the old name is still a registered taxonomy once Blockendar
	 *   has stopped registering it itself (which misses a plugin that registers
	 *   later than init 30), and any term in the bucket attached to a post of
	 *   another type (evidence in the data, whatever the load order).
	 *
	 * Pure read; nothing is written. Safe to call from `--dry-run`.
	 *
	 * @return true|WP_Error True when every taxonomy can move, else every reason.
	 */
	public function preflight() {
		global $wpdb;

		$errors = new WP_Error();

		foreach ( self::MAP as $old => $new ) {
			$occupied = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
					$new
				)
			);

			if ( $occupied > 0 ) {
				$errors->add(
					'target_occupied',
					sprintf(
						/* translators: 1: number of rows, 2: taxonomy name. */
						_n(
							'%1$d term already exists under the taxonomy "%2$s", so the migration cannot use that name.',
							'%1$d terms already exist under the taxonomy "%2$s", so the migration cannot use that name.',
							$occupied,
							'blockendar'
						),
						$occupied,
						$new
					)
				);
			}

			if ( ! in_array( $old, self::own_names(), true ) && taxonomy_exists( $old ) ) {
				$errors->add(
					'shared_bucket',
					sprintf(
						/* translators: %s: taxonomy name. */
						__( 'Another plugin registers the taxonomy "%s", so its terms and Blockendar\'s share one table and cannot be told apart.', 'blockendar' ),
						$old
					)
				);
			}

			$foreign = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt
					JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
					JOIN {$wpdb->posts} p ON p.ID = tr.object_id
					WHERE tt.taxonomy = %s AND p.post_type <> %s",
					$old,
					EventPostType::POST_TYPE
				)
			);

			if ( $foreign > 0 ) {
				$errors->add(
					'shared_bucket',
					sprintf(
						/* translators: 1: number of relationships, 2: taxonomy name, 3: post type. */
						_n(
							'%1$d relationship under the taxonomy "%2$s" belongs to a post that is not a %3$s, so another plugin appears to use that taxonomy too.',
							'%1$d relationships under the taxonomy "%2$s" belong to posts that are not a %3$s, so another plugin appears to use that taxonomy too.',
							$foreign,
							'blockendar'
						),
						$foreign,
						$old,
						EventPostType::POST_TYPE
					)
				);
			}
		}

		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * The taxonomy names Blockendar registers in this version of the plugin.
	 *
	 * Before the rename these are the old names, so "the old name is still
	 * registered" says nothing; after it, an old name that is still registered
	 * was registered by someone else.
	 *
	 * @return string[]
	 */
	private static function own_names(): array {
		return [ EventType::TAXONOMY, EventTag::TAXONOMY, Venue::TAXONOMY ];
	}

	/**
	 * Move every term row from an old taxonomy name to its new one.
	 *
	 * The row is the whole story for terms: relationships key off
	 * term_taxonomy_id and term meta off term_id, so both follow it. Every
	 * term_taxonomy_id moved is recorded under the new name, so that rollback
	 * reverses exactly these rows and never drags back a term created under the
	 * new name afterwards; every term_id is recorded so the term cache can be
	 * cleaned for exactly these terms at the end of the run.
	 *
	 * @param bool $dry_run Count what would move without writing anything.
	 * @return int Rows moved (or that would move).
	 */
	private function migrate_term_taxonomy( bool $dry_run = false ): int {
		global $wpdb;

		$log   = $this->log();
		$moved = 0;

		foreach ( self::MAP as $old => $new ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
					$old
				)
			);

			if ( empty( $rows ) ) {
				continue;
			}

			$moved += count( $rows );

			if ( $dry_run ) {
				continue;
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->term_taxonomy} SET taxonomy = %s WHERE taxonomy = %s",
					$new,
					$old
				)
			);

			$log['term_taxonomy'][ $new ] = [
				'term_taxonomy_ids' => array_map( fn( $r ) => (int) $r->term_taxonomy_id, $rows ),
				'term_ids'          => array_map( fn( $r ) => (int) $r->term_id, $rows ),
			];
		}

		if ( ! $dry_run ) {
			$this->save_log( $log );
		}

		return $moved;
	}

	/**
	 * The record of what this migration changed, for rollback and cache cleanup.
	 *
	 * @return array<string, mixed>
	 */
	private function log(): array {
		$log = get_option( self::LOG_OPTION, [] );

		return is_array( $log ) ? $log : [];
	}

	/**
	 * Persist the change record. Not autoloaded: it is read on rollback and at
	 * the end of a run, never on an ordinary request.
	 *
	 * @param array<string, mixed> $log The record.
	 */
	private function save_log( array $log ): void {
		update_option( self::LOG_OPTION, $log, false );
	}
}
