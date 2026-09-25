<?php
/**
 * Seeds a site the way it looks before the 2.0.0 taxonomy rename.
 *
 * Everything goes in through raw $wpdb writes on purpose. Once the plugin
 * registers the prefixed names, wp_insert_term( …, 'event_type' ) and the term
 * factory both return invalid_taxonomy — so a fixture built on the API would
 * stop working at exactly the commit it exists to test. Raw rows work before
 * and after the rename, and each helper asserts the row it wrote is there, so a
 * silently empty fixture cannot make a test pass for the wrong reason.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

// Raw table access is the whole point of these fixtures; see the file docblock.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

trait LegacyTaxonomyFixtures {

	/**
	 * Insert a term row directly under an old taxonomy name.
	 *
	 * @param string $taxonomy Old taxonomy name, e.g. 'event_type'.
	 * @param string $name     Term name.
	 * @return array{term_id: int, term_taxonomy_id: int}
	 */
	protected function seed_legacy_term( string $taxonomy, string $name ): array {
		global $wpdb;

		$wpdb->insert(
			$wpdb->terms,
			[
				'name' => $name,
				'slug' => sanitize_title( $name ),
			]
		);
		$term_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->term_taxonomy,
			[
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
				'count'    => 0,
			]
		);
		$tt_id = (int) $wpdb->insert_id;

		$this->assertGreaterThan( 0, $term_id, "term row for {$name} was not written" );
		$this->assertSame(
			$taxonomy,
			$wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) ),
			'term_taxonomy row is not under the old name'
		);

		return [
			'term_id'          => $term_id,
			'term_taxonomy_id' => $tt_id,
		];
	}

	/**
	 * Relate a post to a term row directly.
	 *
	 * @param int $post_id          Post ID.
	 * @param int $term_taxonomy_id Term taxonomy ID.
	 */
	protected function seed_legacy_relationship( int $post_id, int $term_taxonomy_id ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->term_relationships,
			[
				'object_id'        => $post_id,
				'term_taxonomy_id' => $term_taxonomy_id,
			]
		);

		$this->assertSame( 1, $wpdb->rows_affected, 'relationship row was not written' );
	}

	/**
	 * A classic nav-menu item pointing at an old taxonomy's term archive.
	 *
	 * @param string $taxonomy Old taxonomy name.
	 * @param int    $term_id  Term ID.
	 * @return int Menu item post ID.
	 */
	protected function seed_legacy_menu_item( string $taxonomy, int $term_id ): int {
		$item = self::factory()->post->create(
			[
				'post_type'   => 'nav_menu_item',
				'post_status' => 'publish',
			]
		);

		update_post_meta( $item, '_menu_item_type', 'taxonomy' );
		update_post_meta( $item, '_menu_item_object', $taxonomy );
		update_post_meta( $item, '_menu_item_object_id', $term_id );

		$this->assertSame( $taxonomy, get_post_meta( $item, '_menu_item_object', true ) );

		return $item;
	}

	/**
	 * A Site Editor template customisation with the given slug.
	 *
	 * @param string $slug  Template slug, e.g. 'taxonomy-event_type-concerts'.
	 * @param string $theme Theme the copy belongs to.
	 * @return int Template post ID.
	 */
	protected function seed_legacy_template( string $slug, string $theme = 'twentytwentyfive' ): int {
		$id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_content' => '<!-- wp:post-terms {"term":"event_type"} /-->',
			]
		);

		wp_set_object_terms( $id, $theme, 'wp_theme' );

		$this->assertSame( $slug, get_post( $id )->post_name );

		return $id;
	}

	/**
	 * A post of the given type with the given block markup.
	 *
	 * @param string $content   Block markup.
	 * @param string $post_type Post type.
	 * @return int Post ID.
	 */
	protected function seed_markup_post( string $content, string $post_type = 'page' ): int {
		global $wpdb;

		$id = self::factory()->post->create(
			[
				'post_type'   => $post_type,
				'post_status' => 'publish',
			]
		);

		// Written directly: the test user has no unfiltered_html, so wp_insert_post()
		// would run kses and strip the iframe and inline SVG some samples carry —
		// exactly the content the migration must be shown to preserve.
		$wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => $id ], [ '%s' ], [ '%d' ] );
		clean_post_cache( $id );

		$this->assertSame( $content, get_post( $id )->post_content, 'markup was not written verbatim' );

		return $id;
	}

	/**
	 * Block markup carrying every core attribute the migration rewrites, once.
	 *
	 * @param int $type_id  A term ID to use in the Query Loop shapes.
	 * @param int $venue_id A second term ID.
	 * @return array<string, string> Keyed by what each snippet exercises.
	 */
	protected function legacy_markup_samples( int $type_id, int $venue_id ): array {
		return [
			'post_terms'           => '<!-- wp:post-terms {"term":"event_type"} /-->',
			'categories'           => '<!-- wp:categories {"taxonomy":"event_venue","showCounts":true} /-->',
			'tag_cloud'            => '<!-- wp:tag-cloud {"taxonomy":"event_tag"} /-->',
			'post_navigation_link' => '<!-- wp:post-navigation-link {"taxonomy":"event_type","inSameTerm":true} /-->',
			'query_old_shape'      => '<!-- wp:query {"query":{"postType":"blockendar_event","taxQuery":{"event_type":[' . $type_id . ']}}} --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->',
			'query_new_shape'      => '<!-- wp:query {"query":{"taxQuery":{"include":{"event_venue":[' . $venue_id . '],"category":[1]},"exclude":{"event_tag":[2]}}}} --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->',
			'navigation'           => '<!-- wp:navigation-submenu {"label":"Types","kind":"taxonomy","type":"event_type","id":' . $type_id . '} --><!-- wp:navigation-link {"label":"Venue","kind":"taxonomy","type":"event_venue","id":' . $venue_id . '} /--><!-- wp:navigation-link {"label":"Post","kind":"post-type","type":"event_type"} /--><!-- /wp:navigation-submenu -->',
			'html_iframe_svg'      => '<!-- wp:post-terms {"term":"event_type"} /--><!-- wp:html --><iframe src="https://example.com/embed" title="x"></iframe><svg viewBox="0 0 2 2"><path d="M0 0h2v2H0z" style="fill:#000"/></svg><!-- /wp:html -->',
			'untouched'            => '<!-- wp:paragraph --><p>event_type appears in prose only</p><!-- /wp:paragraph --><!-- wp:post-terms {"term":"category"} /-->',
		];
	}
}
