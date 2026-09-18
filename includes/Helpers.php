<?php
/**
 * Global helper functions for Blockendar.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the occurrence to display on a single-event page or in a query loop.
 *
 * Resolution order:
 *  1. $GLOBALS['blockendar_current_occurrence'] — set by events-query render.php
 *     while rendering inner blocks, so each list item shows its own occurrence.
 *  2. ?occurrence_date=YYYY-MM-DD query string param — set by calendar links in
 *     CalendarController so clicking a chip shows that specific occurrence.
 *  3. next_occurrence() fallback for bare permalink visits.
 *
 * @param int|false|null $post_id The event post ID. get_the_ID() returns false
 *                                outside a post context; that resolves to null.
 * @return object|null Index row, or null if no occurrence exists at all.
 */
function blockendar_resolve_occurrence( int|false|null $post_id ): ?object {
	$post_id = (int) $post_id;

	if ( $post_id <= 0 ) {
		return null;
	}

	// Check for occurrence injected by events-query render loop.
	if ( isset( $GLOBALS['blockendar_current_occurrence'] ) &&
		(int) $GLOBALS['blockendar_current_occurrence']->post_id === $post_id ) {
		return $GLOBALS['blockendar_current_occurrence'];
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$raw = isset( $_GET['occurrence_date'] ) ? sanitize_text_field( wp_unslash( $_GET['occurrence_date'] ) ) : '';
	// phpcs:enable

	if ( '' !== $raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
		[ $y, $m, $d ] = explode( '-', $raw );
		if ( checkdate( (int) $m, (int) $d, (int) $y ) ) {
			$occurrence = \Blockendar\DB\EventIndex::get_occurrence_by_date( $post_id, $raw );
			if ( null !== $occurrence ) {
				return $occurrence;
			}
		}
	}

	return \Blockendar\DB\EventIndex::next_occurrence( $post_id );
}

/**
 * Resolve the event a single-event block should render.
 *
 * Blocks take the post from block context (inside an events-query loop or a
 * single event template) and fall back to the global post. Outside any post
 * context — a block dropped on a page, a template part, do_blocks() from
 * WP-CLI — there is nothing to render, and the block must bail rather than
 * hand a false ID to helpers that expect an event.
 *
 * @param WP_Block $block   The block being rendered.
 * @param int      $post_id Optional explicit post ID (e.g. a pinned event).
 * @return int The event post ID, or 0 when there is no event to render.
 */
function blockendar_block_event_id( WP_Block $block, int $post_id = 0 ): int {
	if ( $post_id <= 0 ) {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
	}

	if ( $post_id <= 0 || \Blockendar\CPT\EventPostType::POST_TYPE !== get_post_type( $post_id ) ) {
		return 0;
	}

	return $post_id;
}

/**
 * Append ?occurrence_date= to a CPT permalink while events-query renders inner blocks.
 *
 * Hooked to post_type_link during the render loop and removed immediately after,
 * ensuring core/post-title (and any other link) navigates to the correct occurrence.
 *
 * @param string  $permalink The post permalink.
 * @param WP_Post $post      The post object.
 * @return string Modified permalink.
 */
function blockendar_occurrence_permalink_filter( string $permalink, WP_Post $post ): string {
	if ( isset( $GLOBALS['blockendar_current_occurrence'] ) &&
		(int) $GLOBALS['blockendar_current_occurrence']->post_id === (int) $post->ID ) {
		return add_query_arg( 'occurrence_date', $GLOBALS['blockendar_current_occurrence']->start_date, $permalink );
	}
	return $permalink;
}
