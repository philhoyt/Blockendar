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
 * @param int $post_id The event post ID.
 * @return object|null Index row, or null if no occurrence exists at all.
 */
function blockendar_resolve_occurrence( int $post_id ): ?object {
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
