<?php
/**
 * Creates the guided tour pages.
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

namespace Blockendar\Demo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inserts the tour pages and points the front page at the landing page.
 */
class Pages {

	/**
	 * Records which tour page an ID is, independently of its slug.
	 *
	 * wp_insert_post() suffixes a slug that real content already holds, so
	 * post_name is not a reliable way back to the Content::PAGES key.
	 */
	public const SLUG_META = '_blockendar_demo_page';

	/**
	 * Create every tour page.
	 *
	 * Runs in two passes. The first reserves each slug and collects the real
	 * permalinks; the second writes the content, which needs those permalinks for
	 * the nav row. A single pass cannot do this — a slug that collides with
	 * existing content gets suffixed by wp_insert_post(), and the nav would then
	 * link to URLs that do not exist.
	 *
	 * @param array $state Seed state, by reference.
	 * @return int Number of pages created.
	 */
	public function create( array &$state ): int {
		$ids = [];
		$nav = [];

		// Pass 1 — reserve slugs, collect permalinks.
		foreach ( Content::PAGES as $slug => $title ) {
			$page_id = wp_insert_post(
				[
					'post_type'    => 'page',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => '',
					'post_status'  => 'publish',
				],
				true
			);

			if ( is_wp_error( $page_id ) ) {
				continue;
			}

			$page_id = (int) $page_id;

			update_post_meta( $page_id, Seeder::MARKER, 1 );
			update_post_meta( $page_id, self::SLUG_META, $slug );

			$ids[ $slug ]     = $page_id;
			$nav[ $slug ]     = (string) get_permalink( $page_id );
			$state['pages'][] = $page_id;
		}

		// Pass 2 — write the content now that every permalink is known.
		$content = new Content();

		foreach ( $ids as $slug => $page_id ) {
			$markup = $content->for_slug( $slug, $nav );

			$this->assert_parses( $slug, $markup );

			wp_update_post(
				[
					'ID'           => $page_id,
					'post_content' => $markup,
				]
			);
		}

		// Front page.
		if ( isset( $ids['blockendar-demo'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $ids['blockendar-demo'] );
		}

		return count( $ids );
	}

	/**
	 * Rewrite the content of tour pages this demo already owns.
	 *
	 * Without this, a site seeded before a markup change keeps its old pages
	 * for good: seed() stops at is_seeded(), and only a reset — which deletes
	 * the events too — would rebuild them.
	 *
	 * Only pages recorded in the seed state, still present, and still carrying
	 * the demo marker are touched. Nothing is created here, so a page the user
	 * deleted stays deleted, and the front-page options are left alone.
	 *
	 * @param array $state Seed state.
	 * @return int Number of pages rewritten.
	 */
	public function refresh( array $state ): int {
		$ids = $this->resolve( $state );

		if ( ! $ids ) {
			return 0;
		}

		$nav = [];
		foreach ( $ids as $slug => $page_id ) {
			$nav[ $slug ] = (string) get_permalink( $page_id );
		}

		$content = new Content();
		$updated = 0;

		foreach ( $ids as $slug => $page_id ) {
			$markup = $content->for_slug( $slug, $nav );

			$this->assert_parses( $slug, $markup );

			$result = wp_update_post(
				[
					'ID'           => $page_id,
					'post_content' => $markup,
				],
				true
			);

			if ( is_wp_error( $result ) ) {
				continue;
			}

			// Backfill for demos seeded before SLUG_META existed.
			update_post_meta( $page_id, self::SLUG_META, $slug );
			++$updated;
		}

		return $updated;
	}

	/**
	 * Map the recorded page IDs back to their Content::PAGES slug.
	 *
	 * @param array $state Seed state.
	 * @return array<string, int> Slug => page ID.
	 */
	private function resolve( array $state ): array {
		$canonical = array_keys( Content::PAGES );
		$ids       = [];

		foreach ( (array) ( $state['pages'] ?? [] ) as $page_id ) {
			$page_id = (int) $page_id;
			$post    = get_post( $page_id );

			// An ID can be stale: the page may have been deleted, or deleted and
			// the ID reused by unrelated content. The marker is what says this is
			// still ours to overwrite.
			//
			// Published only. A trashed page is one the user put away, and its
			// permalink is a ?page_id= fallback that would make a dead link in
			// every other page's nav row.
			if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
				continue;
			}

			if ( ! get_post_meta( $page_id, Seeder::MARKER, true ) ) {
				continue;
			}

			$slug = (string) get_post_meta( $page_id, self::SLUG_META, true );

			if ( '' === $slug ) {
				$slug = $this->slug_from_name( $post->post_name, $canonical );
			}

			// An unrecognised slug means the page was renamed, or this demo
			// predates the marker and its slug was suffixed beyond recognition.
			// Leaving it alone beats writing another page's content over it.
			if ( '' === $slug || ! isset( Content::PAGES[ $slug ] ) || isset( $ids[ $slug ] ) ) {
				continue;
			}

			$ids[ $slug ] = $page_id;
		}

		return $ids;
	}

	/**
	 * Recover a canonical slug from a post_name, allowing for the "-2" suffix
	 * wp_insert_post() adds when a slug is already taken.
	 *
	 * @param string   $name      The page's post_name.
	 * @param string[] $canonical Known tour slugs.
	 */
	private function slug_from_name( string $name, array $canonical ): string {
		foreach ( $canonical as $candidate ) {
			if ( $name === $candidate ) {
				return $candidate;
			}

			if ( preg_match( '/^' . preg_quote( $candidate, '/' ) . '-\d+$/', $name ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Warn if a page's markup did not parse into real blocks.
	 *
	 * Serialized markup built by string concatenation is easy to get subtly
	 * wrong, and an unbalanced delimiter renders as a blank page rather than an
	 * error. This is deliberately not a serialize_blocks() round-trip equality
	 * check: the serializer normalises whitespace, so identical-meaning markup
	 * can differ byte for byte and the warning would fire on correct input.
	 *
	 * What it actually catches: markup that contains block delimiters but yields
	 * no named blocks, which only happens when the delimiters are malformed.
	 */
	private function assert_parses( string $slug, string $markup ): void {
		if ( ! str_contains( $markup, '<!-- wp:' ) ) {
			return;
		}

		$named = 0;

		foreach ( parse_blocks( $markup ) as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				++$named;
			}
		}

		if ( $named > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'Blockendar Demo: block markup for "%s" contains delimiters but parsed into no blocks.',
				$slug
			)
		);
	}
}
