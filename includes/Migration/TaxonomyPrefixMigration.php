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

	/**
	 * Claim the in-progress lock, or return false when another request holds it.
	 *
	 * MySQL's GET_LOCK() rather than add_option(). Core's add_option() is an
	 * INSERT … ON DUPLICATE KEY UPDATE behind a get_option() pre-check
	 * (wp-includes/option.php), so two requests that both miss the pre-check
	 * both succeed — it is not atomic. GET_LOCK() is: exactly one connection
	 * holds a named lock, and the server releases it when that connection ends,
	 * so a run that dies partway leaves nothing behind for the next one to wait
	 * on. The name carries the table prefix so sites on a network lock
	 * independently.
	 *
	 * An informational option is written alongside so `--status` can report a
	 * run in progress, and since when, without a database session of its own.
	 *
	 * @return bool True when this request now holds the lock.
	 */
	private function acquire_lock(): bool {
		global $wpdb;

		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $this->lock_name() ) );

		if ( '1' !== (string) $got ) {
			return false;
		}

		update_option( self::LOCK_OPTION, (string) time(), false );

		return true;
	}

	/**
	 * Release the lock taken by acquire_lock().
	 */
	private function release_lock(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $this->lock_name() ) );
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Per-site name for the database lock (GET_LOCK names are limited to 64 bytes).
	 *
	 * @return string
	 */
	private function lock_name(): string {
		global $wpdb;

		return substr( 'blockendar_tax_migration_' . $wpdb->prefix, 0, 64 );
	}

	/**
	 * Delete the `{taxonomy}_children` options that belonged to the old names.
	 *
	 * clean_taxonomy_cache() deletes and regenerates the option for the *new*
	 * name from the database at the end of the run, so renaming these would be
	 * thrown away immediately. Rollback needs no record: clean_taxonomy_cache()
	 * on the old names regenerates them the same way.
	 *
	 * @param bool $dry_run Count without deleting.
	 * @return int Options deleted (or that would be).
	 */
	private function delete_orphan_children_options( bool $dry_run = false ): int {
		$deleted = 0;

		foreach ( array_keys( self::MAP ) as $old ) {
			if ( false === get_option( "{$old}_children" ) ) {
				continue;
			}

			++$deleted;

			if ( ! $dry_run ) {
				delete_option( "{$old}_children" );
			}
		}

		return $deleted;
	}

	/**
	 * Point classic nav-menu items at the new taxonomy names.
	 *
	 * A menu item linking to a term archive stores the taxonomy name in
	 * `_menu_item_object` and is resolved with get_term( $object_id, $object ),
	 * so a stale name breaks the link. Only rows whose sibling
	 * `_menu_item_type` is `taxonomy` are touched. Each meta_id changed is
	 * recorded for rollback, and the post's meta cache is cleared so the edited
	 * value is what the next read sees.
	 *
	 * @param bool $dry_run Count without writing.
	 * @return int Menu items updated (or that would be).
	 */
	private function migrate_nav_menu_items( bool $dry_run = false ): int {
		global $wpdb;

		$log     = $this->log();
		$updated = 0;

		foreach ( self::MAP as $old => $new ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_id, pm.post_id FROM {$wpdb->postmeta} pm
					JOIN {$wpdb->postmeta} t ON t.post_id = pm.post_id
						AND t.meta_key = '_menu_item_type' AND t.meta_value = 'taxonomy'
					WHERE pm.meta_key = '_menu_item_object' AND pm.meta_value = %s",
					$old
				)
			);

			if ( empty( $rows ) ) {
				continue;
			}

			$updated += count( $rows );

			if ( $dry_run ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$wpdb->update(
					$wpdb->postmeta,
					// One-time, per-site write to a handful of menu-item rows, keyed by meta_id.
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					[ 'meta_value' => $new ],
					[ 'meta_id' => (int) $row->meta_id ],
					[ '%s' ],
					[ '%d' ]
				);
				wp_cache_delete( (int) $row->post_id, 'post_meta' );

				$log['nav_menu_items'][ $new ][] = (int) $row->meta_id;
			}
		}

		if ( ! $dry_run ) {
			$this->save_log( $log );
		}

		return $updated;
	}

	/**
	 * Rename Site Editor template customisations that follow the taxonomy name.
	 *
	 * A user's customised copy of a template is a `wp_template` post whose slug
	 * the template hierarchy resolves: `taxonomy-{name}`, and per-term forms
	 * such as `taxonomy-{name}-{slug}`. The editor lets a user create any of
	 * these for any of the three taxonomies, whether or not the plugin ships a
	 * file for it, and keeps a copy per theme — so the match is a prefix over
	 * all three names and is not scoped to one theme. The old name's `_` is a
	 * LIKE wildcard and is escaped.
	 *
	 * @param bool $dry_run Count without writing.
	 * @return int Templates renamed (or that would be).
	 */
	private function migrate_template_slugs( bool $dry_run = false ): int {
		global $wpdb;

		$log     = $this->log();
		$renamed = 0;

		foreach ( self::MAP as $old => $new ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_name FROM {$wpdb->posts}
					WHERE post_type = 'wp_template' AND ( post_name = %s OR post_name LIKE %s )",
					"taxonomy-{$old}",
					$wpdb->esc_like( "taxonomy-{$old}-" ) . '%'
				)
			);

			if ( empty( $rows ) ) {
				continue;
			}

			$renamed += count( $rows );

			if ( $dry_run ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$to = "taxonomy-{$new}" . substr( $row->post_name, strlen( "taxonomy-{$old}" ) );

				$wpdb->update( $wpdb->posts, [ 'post_name' => $to ], [ 'ID' => (int) $row->ID ], [ '%s' ], [ '%d' ] );
				clean_post_cache( (int) $row->ID );

				$log['template_slugs'][] = [
					'ID'   => (int) $row->ID,
					'from' => $row->post_name,
					'to'   => $to,
				];
			}
		}

		if ( ! $dry_run ) {
			$this->save_log( $log );
		}

		return $renamed;
	}

	/**
	 * Rewrite the taxonomy names inside a post's block markup.
	 *
	 * Seven core block attributes carry a taxonomy name: `core/post-terms`
	 * (`term`); `core/categories`, `core/tag-cloud` and
	 * `core/post-navigation-link` (`taxonomy`); `core/navigation-link` and
	 * `core/navigation-submenu` (`type`, when `kind` is `taxonomy` — a submenu
	 * is what every navigation item with children serialises to); and
	 * `core/query`, whose `taxQuery` lives *inside* its `query` attribute in
	 * either the pre-7.0 shape `{"event_type":[4]}` or the 7.0+ shape
	 * `{"include":{"event_type":[4]},"exclude":{…}}`. Inner blocks are walked.
	 *
	 * Returns null when nothing in the markup referred to an old name, so a
	 * caller never rewrites a post that did not need it — serialize_blocks() can
	 * normalise attribute order and whitespace, and that churn is only worth
	 * paying where a rename actually happened.
	 *
	 * @param string $content Raw post_content.
	 * @return string|null Rewritten markup, or null when unchanged.
	 */
	public function rewrite_block_markup( string $content ): ?string {
		if ( ! has_blocks( $content ) ) {
			return null;
		}

		$changed = false;
		$blocks  = $this->rewrite_blocks( parse_blocks( $content ), $changed );

		return $changed ? serialize_blocks( $blocks ) : null;
	}

	/**
	 * Walk a parsed block tree, renaming taxonomy references in place.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param bool                             $changed Set true when any rename happens.
	 * @return array<int, array<string, mixed>>
	 */
	private function rewrite_blocks( array $blocks, bool &$changed ): array {
		foreach ( $blocks as &$block ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			switch ( $block['blockName'] ?? '' ) {
				case 'core/post-terms':
					$this->rename_attribute( $attrs, 'term', $changed );
					break;

				case 'core/categories':
				case 'core/tag-cloud':
				case 'core/post-navigation-link':
					$this->rename_attribute( $attrs, 'taxonomy', $changed );
					break;

				case 'core/navigation-link':
				case 'core/navigation-submenu':
					if ( 'taxonomy' === ( $attrs['kind'] ?? '' ) ) {
						$this->rename_attribute( $attrs, 'type', $changed );
					}
					break;

				case 'core/query':
					if ( isset( $attrs['query']['taxQuery'] ) && is_array( $attrs['query']['taxQuery'] ) ) {
						$attrs['query']['taxQuery'] = $this->rename_tax_query( $attrs['query']['taxQuery'], $changed );
					}
					break;
			}

			if ( [] !== $attrs || isset( $block['attrs'] ) ) {
				$block['attrs'] = $attrs;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->rewrite_blocks( $block['innerBlocks'], $changed );
			}
		}
		unset( $block );

		return $blocks;
	}

	/**
	 * Rename one string attribute when it holds an old taxonomy name.
	 *
	 * @param array<string, mixed> $attrs   Block attributes, modified in place.
	 * @param string               $key     Attribute name.
	 * @param bool                 $changed Set true on rename.
	 */
	private function rename_attribute( array &$attrs, string $key, bool &$changed ): void {
		$value = $attrs[ $key ] ?? null;

		if ( is_string( $value ) && isset( self::MAP[ $value ] ) ) {
			$attrs[ $key ] = self::MAP[ $value ];
			$changed       = true;
		}
	}

	/**
	 * Rename the taxonomy keys of a Query Loop `taxQuery`, in either shape.
	 *
	 * Core tells the shapes apart the same way (build_query_vars_from_query_block):
	 * keys other than `include`/`exclude` mean the old flat map.
	 *
	 * @param array<string, mixed> $tax_query The taxQuery value.
	 * @param bool                 $changed   Set true on rename.
	 * @return array<string, mixed>
	 */
	private function rename_tax_query( array $tax_query, bool &$changed ): array {
		$is_new_shape = [] === array_diff( array_keys( $tax_query ), [ 'include', 'exclude' ] );

		if ( ! $is_new_shape ) {
			return $this->rename_keys( $tax_query, $changed );
		}

		foreach ( [ 'include', 'exclude' ] as $side ) {
			if ( isset( $tax_query[ $side ] ) && is_array( $tax_query[ $side ] ) ) {
				$tax_query[ $side ] = $this->rename_keys( $tax_query[ $side ], $changed );
			}
		}

		return $tax_query;
	}

	/**
	 * Rename the keys of a taxonomy => terms map, preserving order and values.
	 *
	 * @param array<string, mixed> $map     Taxonomy name => term IDs.
	 * @param bool                 $changed Set true on rename.
	 * @return array<string, mixed>
	 */
	private function rename_keys( array $map, bool &$changed ): array {
		$out = [];

		foreach ( $map as $taxonomy => $terms ) {
			if ( is_string( $taxonomy ) && isset( self::MAP[ $taxonomy ] ) ) {
				$out[ self::MAP[ $taxonomy ] ] = $terms;
				$changed                       = true;
			} else {
				$out[ $taxonomy ] = $terms;
			}
		}

		return $out;
	}

	/**
	 * Post types whose content is swept: the block-theme storage types plus every
	 * public type. Revisions are deliberately absent — restoring a pre-2.0.0
	 * revision re-injects the old names, and the upgrade notice says so.
	 *
	 * @return string[]
	 */
	private function swept_post_types(): array {
		$types = array_merge(
			[ 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' ],
			array_values( get_post_types( [ 'public' => true ] ) )
		);

		return array_values( array_unique( $types ) );
	}

	/**
	 * Rewrite every post whose block markup refers to an old taxonomy name.
	 *
	 * Walks the swept post types in ID order, in batches, resuming from the
	 * cursor a previous run left if it died partway. SQL pre-filters to posts
	 * that contain block markup and one of the old names, so the rewriter only
	 * parses candidates; the rewriter then decides, returning null for a post
	 * whose match was in prose rather than an attribute.
	 *
	 * The original content is kept in post meta before the write. add_post_meta()
	 * with $unique = true refuses to add a second copy, so a resumed or repeated
	 * pass can never overwrite a real backup with already-migrated content. The
	 * write itself is $wpdb->update(): wp_update_post() would run kses on any
	 * request without unfiltered_html (every anonymous front-end request where
	 * init fires) and strip scripts, iframes, style attributes and inline SVG;
	 * it would also bump post_modified, fire save_post and write revisions.
	 *
	 * @param bool $dry_run Count posts that would change without writing.
	 * @return int Posts rewritten (or that would be).
	 */
	private function migrate_post_content( bool $dry_run = false ): int {
		global $wpdb;

		$batch     = 200;
		$rewritten = 0;
		$log       = $this->log();
		$cursor    = $dry_run ? 0 : (int) get_option( self::CURSOR_OPTION, 0 );

		$type_list = implode( ', ', array_fill( 0, count( $this->swept_post_types() ), '%s' ) );
		$name_like = array_map( fn( $old ) => '%' . $wpdb->esc_like( $old ) . '%', array_keys( self::MAP ) );
		$name_list = implode( ' OR ', array_fill( 0, count( $name_like ), 'post_content LIKE %s' ) );

		do {
			// prepare() takes its values as one array here: PHP does not allow a positional
			// argument after `...` unpacking, and there are two runtime-sized lists to bind.
			$args = array_merge( [ $cursor ], $this->swept_post_types(), [ '%<!-- wp:%' ], $name_like, [ $batch ] );

			// The two placeholder lists are built above from constants and every value is
			// bound through $args; phpcs cannot count runtime placeholder lists.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts}
					WHERE ID > %d AND post_type IN ( {$type_list} ) AND post_status <> 'auto-draft'
					AND post_content LIKE %s AND ( {$name_list} )
					ORDER BY ID ASC LIMIT %d",
					$args
				)
			);
			// phpcs:enable

			$fetched = count( $rows );

			foreach ( $rows as $row ) {
				$id     = (int) $row->ID;
				$cursor = $id;
				$new    = $this->rewrite_block_markup( (string) $row->post_content );

				if ( null === $new ) {
					continue;
				}

				++$rewritten;

				if ( $dry_run ) {
					continue;
				}

				add_post_meta( $id, self::BACKUP_META, $row->post_content, true );
				$wpdb->update( $wpdb->posts, [ 'post_content' => $new ], [ 'ID' => $id ], [ '%s' ], [ '%d' ] );
				clean_post_cache( $id );

				$log['posts'][] = $id;
			}

			if ( ! $dry_run && ! empty( $rows ) ) {
				$this->save_log( $log );
				update_option( self::CURSOR_OPTION, $cursor, false );
			}
		} while ( $fetched === $batch );

		return $rewritten;
	}

	/**
	 * Rewrite block widgets, which live in the `widget_block` option, not in posts.
	 *
	 * WP_Widget_Block stores every block widget's markup under this one option,
	 * so a Query Loop or Categories block in a widget area is invisible to the
	 * post sweep. The option's original value is kept in the change record once,
	 * before the first write, so rollback restores it whole.
	 *
	 * @param bool $dry_run Count widgets that would change without writing.
	 * @return int Widgets rewritten (or that would be).
	 */
	private function migrate_block_widgets( bool $dry_run = false ): int {
		$widgets = get_option( 'widget_block', [] );

		if ( ! is_array( $widgets ) ) {
			return 0;
		}

		$changed = 0;

		foreach ( $widgets as $key => $widget ) {
			if ( ! is_array( $widget ) || ! is_string( $widget['content'] ?? null ) ) {
				continue;
			}

			$new = $this->rewrite_block_markup( $widget['content'] );

			if ( null === $new ) {
				continue;
			}

			++$changed;
			$widgets[ $key ]['content'] = $new;
		}

		if ( 0 === $changed || $dry_run ) {
			return $changed;
		}

		$log = $this->log();

		if ( ! array_key_exists( 'widget_block_original', $log ) ) {
			$log['widget_block_original'] = get_option( 'widget_block', [] );
			$this->save_log( $log );
		}

		update_option( 'widget_block', $widgets );

		return $changed;
	}

	/**
	 * Perform the migration.
	 *
	 * Idempotent: a migrated site returns true at once. A site another request
	 * is migrating returns a `locked` error. A first pass runs the preflight and
	 * records when it started; a resumed pass — one whose predecessor died after
	 * moving some rows — skips the preflight, which would now see its own moved
	 * rows under the new names and refuse, and relies on every step being a
	 * no-op for what has already moved. The gate is written last, so anything
	 * short of completion leaves the site due for another attempt.
	 *
	 * Term IDs do not change, so the occurrence index needs no rebuild for the
	 * rename itself. One is queued anyway: a rebuild that ran before the term
	 * rows moved (external cron, `wp cron event run`) read the new names against
	 * old rows and wrote empty venue and type columns.
	 *
	 * @return bool|WP_Error True on completion (or when already migrated).
	 */
	public function run() {
		if ( ! $this->needs_migration() ) {
			return true;
		}

		if ( ! $this->acquire_lock() ) {
			return new WP_Error(
				'locked',
				__( 'Another request is migrating the taxonomies right now.', 'blockendar' )
			);
		}

		try {
			$resuming = [] !== $this->log() || false !== get_option( self::CURSOR_OPTION );

			if ( ! $resuming ) {
				$preflight = $this->preflight();

				if ( is_wp_error( $preflight ) ) {
					return $preflight;
				}

				$this->save_log(
					[
						'started_at' => current_time( 'mysql', true ),
						'version'    => BLOCKENDAR_VERSION,
					]
				);
			}

			$this->migrate_term_taxonomy();
			$this->delete_orphan_children_options();
			$this->migrate_nav_menu_items();
			$this->migrate_template_slugs();
			$this->migrate_post_content();
			$this->migrate_block_widgets();

			$this->clean_caches();
			flush_rewrite_rules( false );

			if ( ! wp_next_scheduled( 'blockendar_index_rebuild_after_upgrade' ) ) {
				wp_schedule_single_event( time(), 'blockendar_index_rebuild_after_upgrade' );
			}

			delete_option( self::CURSOR_OPTION );
			$this->mark_migrated();

			return true;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * What a run would change, without changing it.
	 *
	 * @return array<string, mixed> Preflight result plus a count per surface.
	 */
	public function dry_run(): array {
		return [
			'preflight'        => $this->preflight(),
			'terms'            => $this->migrate_term_taxonomy( true ),
			'children_options' => $this->delete_orphan_children_options( true ),
			'nav_menu_items'   => $this->migrate_nav_menu_items( true ),
			'template_slugs'   => $this->migrate_template_slugs( true ),
			'posts'            => $this->migrate_post_content( true ),
			'block_widgets'    => $this->migrate_block_widgets( true ),
		];
	}

	/**
	 * Where this site stands, for `--status`.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		global $wpdb;

		$log = $this->log();

		return [
			'migrated'        => ! $this->needs_migration(),
			'migrated_by'     => (string) get_option( self::GATE_OPTION, '' ),
			'in_progress'     => (int) get_option( self::LOCK_OPTION, 0 ),
			'resume_from'     => (int) get_option( self::CURSOR_OPTION, 0 ),
			'started_at'      => (string) ( $log['started_at'] ?? '' ),
			'terms_moved'     => array_sum( array_map( fn( $t ) => count( $t['term_taxonomy_ids'] ?? [] ), $log['term_taxonomy'] ?? [] ) ),
			'posts_rewritten' => count( $log['posts'] ?? [] ),
			'backups_held'    => (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BACKUP_META )
			),
		];
	}

	/**
	 * Drop every cache that could still serve a term under its old taxonomy.
	 *
	 * The UPDATEs bypass the object cache, so without this a persistent backend
	 * keeps handing out WP_Term objects that name a taxonomy no longer
	 * registered. clean_term_cache() clears each moved term and, through it,
	 * the taxonomy's own entries; the old names are cleaned too so nothing
	 * lingers under them.
	 */
	private function clean_caches(): void {
		$log = $this->log();

		foreach ( self::MAP as $old => $new ) {
			$term_ids = $log['term_taxonomy'][ $new ]['term_ids'] ?? [];

			if ( ! empty( $term_ids ) ) {
				clean_term_cache( $term_ids, $new );
			}

			clean_taxonomy_cache( $old );
			clean_taxonomy_cache( $new );
		}
	}
}
