<?php
/**
 * Block markup that still names the old taxonomies keeps rendering.
 *
 * Theme files, template parts, patterns and restored revisions are beyond the
 * migration's reach, so the render-time shim has to carry them. Each test
 * renders markup exactly as such a file would hold it — old names, nested in a
 * group as themes nest things — and asserts the block resolves the migrated
 * term. The Site Editor path is covered through get_block_templates() on a
 * throwaway theme file.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

// Building a throwaway theme is the point; see the file docblock.
// phpcs:disable WordPress.WP.AlternativeFunctions

use Blockendar\CPT\EventPostType;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;
use WP_UnitTestCase;

class LegacyBlockAttributesTest extends WP_UnitTestCase {

	private int $type_id;
	private int $venue_id;
	private int $event;
	private int $other;

	public function set_up(): void {
		parent::set_up();
		_set_cron_array( [] );

		$this->type_id  = (int) wp_insert_term( 'Concerts', EventType::TAXONOMY )['term_id'];
		$this->venue_id = (int) wp_insert_term( 'Town Hall', Venue::TAXONOMY )['term_id'];

		$this->event = self::factory()->post->create(
			[
				'post_type'   => EventPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Shim Subject',
			]
		);
		wp_set_object_terms( $this->event, [ $this->type_id ], EventType::TAXONOMY );
		wp_set_object_terms( $this->event, [ $this->venue_id ], Venue::TAXONOMY );

		$this->other = self::factory()->post->create(
			[
				'post_type'   => EventPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Shim Stranger',
			]
		);

		$GLOBALS['post'] = get_post( $this->event ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $GLOBALS['post'] );
	}

	public function test_a_nested_post_terms_block_naming_the_old_taxonomy_renders_the_term(): void {
		$html = do_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-terms {"term":"event_type"} /--></div><!-- /wp:group -->' );

		$this->assertStringContainsString( 'Concerts', $html );
		$this->assertStringContainsString( 'taxonomy-blockendar_event_type', $html );
		$this->assertStringNotContainsString( 'taxonomy-event_type', $html );
	}

	public function test_a_query_loop_filtered_by_the_old_taxonomy_still_filters(): void {
		$html = do_blocks(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:query {"query":{"postType":"blockendar_event","perPage":10,"taxQuery":{"event_type":[' . $this->type_id . ']}}} -->'
			. '<!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query --></div><!-- /wp:group -->'
		);

		$this->assertStringContainsString( 'Shim Subject', $html );
		$this->assertStringNotContainsString( 'Shim Stranger', $html, 'an unfiltered loop would list every event' );
	}

	public function test_the_site_editor_sees_a_theme_file_with_its_names_translated(): void {
		$theme = 'blockendar-legacy-attrs-' . wp_generate_password( 6, false );
		$dir   = get_theme_root() . '/' . $theme;
		mkdir( $dir . '/templates', 0777, true );
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: Legacy Attrs\n*/\n" );
		file_put_contents( $dir . '/templates/index.html', '<!-- wp:paragraph --><p>i</p><!-- /wp:paragraph -->' );
		// A navigation link's front end prints the URL saved with it, which the
		// rename did not change; its `type` matters only to the editor, so this is
		// where it is checked.
		file_put_contents(
			$dir . '/templates/single-blockendar_event.html',
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:post-terms {"term":"event_type"} /-->'
			. '<!-- wp:navigation-link {"label":"Venue","kind":"taxonomy","type":"event_venue","id":' . $this->venue_id . '} /--></div><!-- /wp:group -->'
		);
		$previous = get_stylesheet();
		wp_clean_themes_cache();
		switch_theme( $theme );

		try {
			$templates = array_values( get_block_templates( [ 'slug__in' => [ 'single-blockendar_event' ] ] ) );
			$this->assertSame( 'theme', $templates[0]->source );
			$this->assertStringContainsString( '{"term":"blockendar_event_type"}', $templates[0]->content );

			$single = get_block_template( $theme . '//single-blockendar_event' );
			$this->assertStringContainsString( '{"term":"blockendar_event_type"}', $single->content );
			$this->assertStringContainsString( '"type":"blockendar_event_venue"', $single->content );
			$this->assertStringNotContainsString( 'event_venue"', str_replace( 'blockendar_event_venue"', '', $single->content ) );
		} finally {
			switch_theme( $previous );
			array_map( 'unlink', glob( $dir . '/templates/*' ) ?: [] );
			rmdir( $dir . '/templates' );
			unlink( $dir . '/style.css' );
			rmdir( $dir );
			wp_clean_themes_cache();
		}
	}

	public function test_database_templates_are_left_as_stored(): void {
		$id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'single-blockendar_event',
				'post_content' => '<!-- wp:post-terms {"term":"event_type"} /-->',
			]
		);
		wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );

		$template = get_block_template( get_stylesheet() . '//single-blockendar_event' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( '{"term":"event_type"}', $template->content, 'stored content is the sweep’s job, and a restored revision must stay visible' );
	}
}
