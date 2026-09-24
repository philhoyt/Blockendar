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
}
