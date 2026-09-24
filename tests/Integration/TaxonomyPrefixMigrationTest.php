<?php
/**
 * The taxonomy-prefix migration engine, against a site seeded the old way.
 *
 * These run before and after the rename. Before it, the plugin still registers
 * the old names, so migrated terms sit under names nothing registers; the
 * assertions therefore read the database directly and only reach for the term
 * API where the new name is registered. The one check that depends on the
 * plugin having stopped registering the old names is skipped until it has.
 *
 * Each test runs inside WP_UnitTestCase's transaction, so options, posts and
 * term rows written here are rolled back — the gate included.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

// Assertions read the tables the migration writes, directly, before and after.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/LegacyTaxonomyFixtures.php';

use Blockendar\Migration\TaxonomyPrefixMigration;
use Blockendar\Taxonomy\EventType;
use WP_UnitTestCase;

class TaxonomyPrefixMigrationTest extends WP_UnitTestCase {

	use LegacyTaxonomyFixtures;

	private TaxonomyPrefixMigration $migration;

	public function set_up(): void {
		parent::set_up();
		$this->migration = new TaxonomyPrefixMigration();
		_set_cron_array( [] );
		delete_option( TaxonomyPrefixMigration::GATE_OPTION );
		delete_option( TaxonomyPrefixMigration::LOG_OPTION );
		delete_option( TaxonomyPrefixMigration::CURSOR_OPTION );
	}

	/**
	 * Rows under a taxonomy name.
	 */
	private function rows_under( string $taxonomy ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy )
		);
	}

	/**
	 * The attrs of the first block of a given name in a post's content.
	 *
	 * @return array<string, mixed>
	 */
	private function attrs_of( int $post_id, string $block_name ): array {
		$found = [];
		$walk  = function ( array $blocks ) use ( &$walk, &$found, $block_name ): void {
			foreach ( $blocks as $block ) {
				if ( $block_name === $block['blockName'] && [] === $found ) {
					$found = $block['attrs'];
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( get_post( $post_id )->post_content ) );

		return $found;
	}

	public function test_term_rows_move_and_relationships_survive(): void {
		global $wpdb;

		$type  = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$venue = $this->seed_legacy_term( 'event_venue', 'Town Hall' );
		$tag   = $this->seed_legacy_term( 'event_tag', 'Free' );
		$event = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );
		$this->seed_legacy_relationship( $event, $type['term_taxonomy_id'] );
		$this->seed_legacy_relationship( $event, $venue['term_taxonomy_id'] );
		update_term_meta( $type['term_id'], 'blockendar_type_color', '#123456' );

		$this->assertTrue( $this->migration->run() );

		foreach ( TaxonomyPrefixMigration::MAP as $old => $new ) {
			$this->assertSame( 0, $this->rows_under( $old ), "rows remain under {$old}" );
			$this->assertSame( 1, $this->rows_under( $new ), "row missing under {$new}" );
		}

		// Relationships key off term_taxonomy_id, which did not change.
		$related = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT tt.taxonomy FROM {$wpdb->term_relationships} tr
				JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id = %d ORDER BY tt.taxonomy",
				$event
			)
		);
		$this->assertSame( [ 'blockendar_event_type', 'blockendar_event_venue' ], $related );

		// Term meta keys off term_id, which did not change either.
		$this->assertSame( '#123456', get_term_meta( $type['term_id'], 'blockendar_type_color', true ) );

		if ( taxonomy_exists( 'blockendar_event_type' ) ) {
			$names = wp_list_pluck( get_the_terms( $event, 'blockendar_event_type' ), 'name' );
			$this->assertSame( [ 'Concerts' ], $names );
		}

		$this->assertFalse( $this->migration->needs_migration() );
		$this->assertSame( BLOCKENDAR_VERSION, get_option( TaxonomyPrefixMigration::GATE_OPTION ) );
	}

	public function test_rerunning_after_clearing_the_gate_changes_nothing(): void {
		global $wpdb;

		$type = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$post = $this->seed_markup_post( '<!-- wp:post-terms {"term":"event_type"} /-->' );

		$this->assertTrue( $this->migration->run() );
		$snapshot = [
			$wpdb->get_results( "SELECT term_taxonomy_id, term_id, taxonomy FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", ARRAY_A ),
			get_post( $post )->post_content,
			get_post_meta( $post, TaxonomyPrefixMigration::BACKUP_META, true ),
		];

		// The naive "second run is a no-op" is trivially true because of the gate.
		// Clear it and run again: every step must find nothing left to do.
		delete_option( TaxonomyPrefixMigration::GATE_OPTION );
		$this->assertTrue( $this->migration->run() );

		$this->assertSame( $snapshot[0], $wpdb->get_results( "SELECT term_taxonomy_id, term_id, taxonomy FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", ARRAY_A ) );
		$this->assertSame( $snapshot[1], get_post( $post )->post_content );
		$this->assertSame( $snapshot[2], get_post_meta( $post, TaxonomyPrefixMigration::BACKUP_META, true ), 'backup was overwritten by the second pass' );
		$this->assertSame( 1, $this->rows_under( 'blockendar_event_type' ) );
	}

	public function test_a_fresh_install_migrates_nothing(): void {
		// Activation marks a new site migrated before anything could run.
		$this->migration->mark_migrated();
		$type = $this->seed_legacy_term( 'event_type', 'Someone Elses Term' );

		$this->assertTrue( $this->migration->run() );

		$this->assertSame( 1, $this->rows_under( 'event_type' ), 'a marked site must not touch rows' );
		$this->assertSame( 0, $this->rows_under( 'blockendar_event_type' ) );
		$this->assertSame( [], get_option( TaxonomyPrefixMigration::LOG_OPTION, [] ) );
	}

	public function test_navigation_link_and_submenu_are_rewritten(): void {
		$type  = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$venue = $this->seed_legacy_term( 'event_venue', 'Town Hall' );
		$nav   = $this->seed_markup_post( $this->legacy_markup_samples( $type['term_id'], $venue['term_id'] )['navigation'], 'wp_navigation' );

		$this->assertTrue( $this->migration->run() );

		$submenu = $this->attrs_of( $nav, 'core/navigation-submenu' );
		$this->assertSame( 'blockendar_event_type', $submenu['type'], 'a submenu is what a menu item with children serialises to' );

		$blocks = parse_blocks( get_post( $nav )->post_content );
		$links  = $blocks[0]['innerBlocks'];
		$this->assertSame( 'blockendar_event_venue', $links[0]['attrs']['type'] );
		$this->assertSame( 'event_type', $links[1]['attrs']['type'], 'a post-type link whose type merely equals an old name must not change' );
	}

	public function test_classic_menu_items_are_repointed(): void {
		$type = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$item = $this->seed_legacy_menu_item( 'event_type', $type['term_id'] );

		$this->assertTrue( $this->migration->run() );

		$this->assertSame( 'blockendar_event_type', get_post_meta( $item, '_menu_item_object', true ) );
	}

	public function test_every_block_attribute_is_rewritten_and_an_unrelated_post_is_not(): void {
		$type    = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$venue   = $this->seed_legacy_term( 'event_venue', 'Town Hall' );
		$samples = $this->legacy_markup_samples( $type['term_id'], $venue['term_id'] );
		$posts   = [];
		foreach ( $samples as $key => $markup ) {
			$posts[ $key ] = $this->seed_markup_post( $markup );
		}

		$this->assertTrue( $this->migration->run() );

		$this->assertSame( 'blockendar_event_type', $this->attrs_of( $posts['post_terms'], 'core/post-terms' )['term'] );
		$categories = $this->attrs_of( $posts['categories'], 'core/categories' );
		$this->assertSame( 'blockendar_event_venue', $categories['taxonomy'] );
		$this->assertTrue( $categories['showCounts'], 'sibling attributes must survive' );
		$this->assertSame( 'blockendar_event_tag', $this->attrs_of( $posts['tag_cloud'], 'core/tag-cloud' )['taxonomy'] );
		$this->assertSame( 'blockendar_event_type', $this->attrs_of( $posts['post_navigation_link'], 'core/post-navigation-link' )['taxonomy'] );

		// The untouched post: same bytes, and no backup was taken for it.
		$this->assertSame( $samples['untouched'], get_post( $posts['untouched'] )->post_content );
		$this->assertSame( '', get_post_meta( $posts['untouched'], TaxonomyPrefixMigration::BACKUP_META, true ) );
		$this->assertNotContains( $posts['untouched'], get_option( TaxonomyPrefixMigration::LOG_OPTION )['posts'] );
	}

	public function test_query_loop_tax_query_is_rewritten_at_its_nested_path_in_both_shapes(): void {
		$type  = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$venue = $this->seed_legacy_term( 'event_venue', 'Town Hall' );
		$s     = $this->legacy_markup_samples( $type['term_id'], $venue['term_id'] );
		$old   = $this->seed_markup_post( $s['query_old_shape'] );
		$new   = $this->seed_markup_post( $s['query_new_shape'] );

		$this->assertTrue( $this->migration->run() );

		// taxQuery lives inside the query attribute, not at the top level.
		$old_attrs = $this->attrs_of( $old, 'core/query' );
		$this->assertArrayNotHasKey( 'taxQuery', $old_attrs );
		$this->assertSame( [ 'blockendar_event_type' => [ $type['term_id'] ] ], $old_attrs['query']['taxQuery'] );
		$this->assertSame( 'blockendar_event', $old_attrs['query']['postType'], 'sibling keys inside query must survive' );

		$new_attrs = $this->attrs_of( $new, 'core/query' );
		$this->assertSame(
			[
				'include' => [
					'blockendar_event_venue' => [ $venue['term_id'] ],
					'category'               => [ 1 ],
				],
				'exclude' => [ 'blockendar_event_tag' => [ 2 ] ],
			],
			$new_attrs['query']['taxQuery']
		);
	}

	public function test_block_widgets_are_rewritten_and_recorded(): void {
		$original = [
			2              => [ 'content' => '<!-- wp:categories {"taxonomy":"event_type"} /-->' ],
			3              => [ 'content' => '<!-- wp:search /-->' ],
			'_multiwidget' => 1,
		];
		update_option( 'widget_block', $original );

		$this->assertTrue( $this->migration->run() );

		$after = get_option( 'widget_block' );
		$this->assertStringContainsString( '"taxonomy":"blockendar_event_type"', $after[2]['content'] );
		$this->assertSame( '<!-- wp:search /-->', $after[3]['content'] );
		$this->assertSame( $original, get_option( TaxonomyPrefixMigration::LOG_OPTION )['widget_block_original'] );
	}

	public function test_template_slugs_are_renamed_exact_per_term_and_across_taxonomies(): void {
		$exact    = $this->seed_legacy_template( 'taxonomy-event_type' );
		$per_term = $this->seed_legacy_template( 'taxonomy-event_type-concerts', 'twentytwentyfour' );
		$venue    = $this->seed_legacy_template( 'taxonomy-event_venue' );
		$decoy    = $this->seed_legacy_template( 'taxonomy-event_typography' );

		$this->assertTrue( $this->migration->run() );

		$this->assertSame( 'taxonomy-blockendar_event_type', get_post( $exact )->post_name );
		$this->assertSame( 'taxonomy-blockendar_event_type-concerts', get_post( $per_term )->post_name );
		$this->assertSame( 'taxonomy-blockendar_event_venue', get_post( $venue )->post_name );
		$this->assertSame( 'taxonomy-event_typography', get_post( $decoy )->post_name, 'the LIKE must escape the underscore and anchor on the hyphen' );
	}

	public function test_iframe_and_inline_svg_survive_and_post_modified_is_unchanged(): void {
		global $wpdb;

		$type    = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$samples = $this->legacy_markup_samples( $type['term_id'], $type['term_id'] );
		$post    = $this->seed_markup_post( $samples['html_iframe_svg'] );
		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => '2020-01-01 00:00:00' ], [ 'ID' => $post ] );
		clean_post_cache( $post );

		$this->assertTrue( $this->migration->run() );

		$content = get_post( $post )->post_content;
		$this->assertStringContainsString( '<iframe src="https://example.com/embed" title="x"></iframe>', $content );
		$this->assertStringContainsString( '<svg viewBox="0 0 2 2"><path d="M0 0h2v2H0z" style="fill:#000"/></svg>', $content );
		$this->assertStringContainsString( '"term":"blockendar_event_type"', $content );
		$this->assertSame( '2020-01-01 00:00:00', get_post( $post )->post_modified_gmt, 'the write must not touch post_modified' );
		$this->assertSame( $samples['html_iframe_svg'], get_post_meta( $post, TaxonomyPrefixMigration::BACKUP_META, true ) );
	}

	public function test_any_row_under_a_target_name_aborts_the_run(): void {
		$mine   = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$theirs = $this->seed_legacy_term( 'blockendar_event_type', 'Already Here' );

		$result = $this->migration->run();

		$this->assertWPError( $result );
		$this->assertContains( 'target_occupied', $result->get_error_codes() );
		$this->assertSame( 1, $this->rows_under( 'event_type' ), 'nothing may move after an abort' );
		$this->assertTrue( $this->migration->needs_migration(), 'an abort must leave the site due for another attempt' );
		$this->assertFalse( get_option( TaxonomyPrefixMigration::LOCK_OPTION ), 'the lock must be released on abort' );
	}

	public function test_an_old_term_on_another_post_type_aborts_the_run(): void {
		$type = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$this->seed_legacy_relationship( $page, $type['term_taxonomy_id'] );

		$result = $this->migration->run();

		$this->assertWPError( $result );
		$this->assertContains( 'shared_bucket', $result->get_error_codes() );
		$this->assertSame( 1, $this->rows_under( 'event_type' ) );
	}

	public function test_an_old_name_still_registered_by_someone_else_aborts_the_run(): void {
		if ( 'event_type' === EventType::TAXONOMY ) {
			$this->markTestSkipped( 'Meaningful only once the plugin registers the prefixed names.' );
		}

		register_taxonomy( 'event_type', 'post' );
		$this->seed_legacy_term( 'event_type', 'Concerts' );

		$result = $this->migration->run();

		$this->assertWPError( $result );
		$this->assertContains( 'shared_bucket', $result->get_error_codes() );

		unregister_taxonomy( 'event_type' );
	}

	public function test_a_run_while_another_connection_holds_the_lock_writes_nothing(): void {
		global $wpdb;

		$type = $this->seed_legacy_term( 'event_type', 'Concerts' );
		// A second connection is the only way to hold GET_LOCK() against this one;
		// $wpdb has exactly one connection and a lock is re-entrant within it.
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$name  = substr( 'blockendar_tax_migration_' . $wpdb->prefix, 0, 64 );
		$held  = $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" )->fetch_row()[0];
		$this->assertSame( '1', (string) $held, 'the other connection must hold the lock first' );

		$result = $this->migration->run();

		$this->assertWPError( $result );
		$this->assertSame( 'locked', $result->get_error_code() );
		$this->assertSame( 1, $this->rows_under( 'event_type' ) );
		$this->assertTrue( $this->migration->needs_migration() );

		$other->close(); // Releases the lock with the session.
		$this->assertTrue( $this->migration->run(), 'once the holder is gone the run proceeds' );
	}

	public function test_a_second_pass_never_overwrites_a_real_backup(): void {
		$type     = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$original = '<!-- wp:post-terms {"term":"event_type"} /-->';
		$post     = $this->seed_markup_post( $original );

		$this->assertTrue( $this->migration->run() );
		$this->assertSame( $original, get_post_meta( $post, TaxonomyPrefixMigration::BACKUP_META, true ) );

		// Simulate a run that died after this post was rewritten but before the
		// gate was written: clear the gate, keep the record, run again.
		delete_option( TaxonomyPrefixMigration::GATE_OPTION );
		$this->assertTrue( $this->migration->run() );

		$this->assertSame( $original, get_post_meta( $post, TaxonomyPrefixMigration::BACKUP_META, true ), 'the backup must still hold the original, not already-migrated content' );
	}

	public function test_rollback_reverses_exactly_the_recorded_rows_and_refuses_edited_posts(): void {
		global $wpdb;

		$type     = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$item     = $this->seed_legacy_menu_item( 'event_type', $type['term_id'] );
		$template = $this->seed_legacy_template( 'taxonomy-event_type' );
		$original = '<!-- wp:post-terms {"term":"event_type"} /-->';
		$post     = $this->seed_markup_post( $original );
		$edited   = $this->seed_markup_post( $original );
		update_option( 'widget_block', [ 2 => [ 'content' => $original ] ] );

		$this->assertTrue( $this->migration->run() );

		// A term created under the new name AFTER the migration is not the migration's to undo.
		$later = $this->seed_legacy_term( 'blockendar_event_type', 'Created Later' );

		// An author edits one post after the migration; its backup is now stale.
		$wpdb->update(
			$wpdb->posts,
			[
				'post_content'      => '<!-- wp:paragraph --><p>edited since</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			],
			[ 'ID' => $edited ]
		);
		clean_post_cache( $edited );

		$report = $this->migration->rollback();

		$this->assertSame( 1, $report['terms'] );
		$this->assertSame( 'event_type', $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $type['term_taxonomy_id'] ) ) );
		$this->assertSame( 'blockendar_event_type', $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $later['term_taxonomy_id'] ) ), 'a later term must stay where it was created' );
		$this->assertSame( 'event_type', get_post_meta( $item, '_menu_item_object', true ) );
		$this->assertSame( 'taxonomy-event_type', get_post( $template )->post_name );
		$this->assertSame( $original, get_post( $post )->post_content );
		$this->assertSame( '', get_post_meta( $post, TaxonomyPrefixMigration::BACKUP_META, true ), 'a restored post loses its backup' );
		$this->assertSame( [ $edited ], $report['posts_skipped'] );
		$this->assertStringContainsString( 'edited since', get_post( $edited )->post_content, 'an edited post must keep its edit' );
		$this->assertTrue( $report['block_widgets'] );
		$this->assertSame( $original, get_option( 'widget_block' )[2]['content'] );
		$this->assertTrue( $this->migration->needs_migration() );
		$this->assertFalse( get_option( TaxonomyPrefixMigration::LOG_OPTION ) );
	}

	public function test_a_cached_term_reports_the_new_taxonomy_after_the_run(): void {
		$type = $this->seed_legacy_term( 'event_type', 'Concerts' );

		// Prime the term cache under the old name, as any earlier read in the
		// request would. Without cache cleanup this stale object survives the
		// UPDATE, which bypasses the object cache entirely.
		$before = get_term( $type['term_id'], 'event_type' );
		$this->assertSame( 'event_type', $before->taxonomy );
		$this->assertNotFalse( wp_cache_get( $type['term_id'], 'terms' ), 'the read must have primed the cache' );

		$this->assertTrue( $this->migration->run() );

		// The durable property, whatever is registered: the stale object is gone.
		$this->assertFalse( wp_cache_get( $type['term_id'], 'terms' ), 'the stale WP_Term must be evicted from the terms group' );

		// Reading it back through the API needs the new name registered.
		if ( taxonomy_exists( 'blockendar_event_type' ) ) {
			$this->assertSame( 'blockendar_event_type', get_term( $type['term_id'] )->taxonomy );
		}
	}

	public function test_dry_run_reports_without_writing(): void {
		global $wpdb;

		$type = $this->seed_legacy_term( 'event_type', 'Concerts' );
		$this->seed_legacy_menu_item( 'event_type', $type['term_id'] );
		$this->seed_legacy_template( 'taxonomy-event_type' );
		$this->seed_markup_post( '<!-- wp:post-terms {"term":"event_type"} /-->' );
		update_option( 'widget_block', [ 2 => [ 'content' => '<!-- wp:tag-cloud {"taxonomy":"event_tag"} /-->' ] ] );
		$snapshot = $wpdb->get_results( "SELECT * FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", ARRAY_A );

		$report = $this->migration->dry_run();

		$this->assertTrue( $report['preflight'] );
		$this->assertSame( 1, $report['terms'] );
		$this->assertSame( 1, $report['nav_menu_items'] );
		$this->assertSame( 1, $report['template_slugs'] );
		// The page, plus the template: wp_template content is swept too and the
		// fixture's template carries a post-terms block.
		$this->assertSame( 2, $report['posts'] );
		$this->assertSame( 1, $report['block_widgets'] );
		$this->assertSame( $snapshot, $wpdb->get_results( "SELECT * FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", ARRAY_A ) );
		$this->assertTrue( $this->migration->needs_migration() );
		$this->assertFalse( get_option( TaxonomyPrefixMigration::LOG_OPTION ) );
	}
}
