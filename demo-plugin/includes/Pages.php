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
