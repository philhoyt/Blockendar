<?php
/**
 * Ad-hoc verification for the EventIndex read cache.
 *
 * Run with: npx wp-env run cli -- wp eval-file wp-content/plugins/blockendar/bin/verify-cache.php
 *
 * Checks, inside a single request, that repeated reads hit the cache and that a
 * write invalidates them. Without a persistent object cache the entries live for
 * one request only, which is exactly the window this script exercises.
 *
 * @package Blockendar
 */

use Blockendar\DB\EventIndex;

$index = new EventIndex();
$start = gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) );
$end   = gmdate( 'Y-m-d H:i:s', strtotime( '+2 years' ) );

// Counters live in $GLOBALS explicitly: wp eval-file executes this file inside a
// function, so plain top-level variables here are not globals and `global $pass`
// inside the helper would silently bind to a different, always-zero variable.
$GLOBALS['bl_pass'] = 0;
$GLOBALS['bl_fail'] = 0;

/**
 * Report a single assertion.
 *
 * @param string $label     What is being asserted.
 * @param bool   $condition Result of the assertion.
 */
function bl_check( string $label, bool $condition ): void {
	if ( $condition ) {
		++$GLOBALS['bl_pass'];
		WP_CLI::log( "  PASS  {$label}" );
		return;
	}
	++$GLOBALS['bl_fail'];
	WP_CLI::log( "  FAIL  {$label}" );
}

WP_CLI::log( 'EventIndex cache verification' );
WP_CLI::log( '' );

// 1. Cold read populates the cache.
$before   = get_num_queries();
$first    = $index->get_events_in_range( $start, $end );
$cold_qty = get_num_queries() - $before;

// 2. Warm read should issue no further queries.
$before   = get_num_queries();
$second   = $index->get_events_in_range( $start, $end );
$warm_qty = get_num_queries() - $before;

bl_check( "cold read issued queries (got {$cold_qty})", $cold_qty > 0 );
bl_check( "warm read issued no queries (got {$warm_qty})", 0 === $warm_qty );
bl_check( 'warm read returned identical rows', $first == $second ); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- comparing arrays of stdClass rows by value.

// 3. A differing argument must not collide with the cached entry.
$before = get_num_queries();
$index->get_events_in_range( $start, $end, [ 'per_page' => 5 ] );
$other_qty = get_num_queries() - $before;
bl_check( "different filters miss the cache (got {$other_qty})", $other_qty > 0 );

// 4. Counts cache independently.
$index->count_events_in_range( $start, $end );
$before = get_num_queries();
$index->count_events_in_range( $start, $end );
$count_warm = get_num_queries() - $before;
bl_check( "warm count issued no queries (got {$count_warm})", 0 === $count_warm );

// 5. A write must invalidate everything.
$index->flush_cache();
$before = get_num_queries();
$index->get_events_in_range( $start, $end );
$after_flush = get_num_queries() - $before;
bl_check( "read after flush hits the database again (got {$after_flush})", $after_flush > 0 );

// 6. Invalidation must also fire from a real write, not just a manual flush.
$index->get_events_in_range( $start, $end );
$index->delete_by_post_id( 999999 );
$before = get_num_queries();
$index->get_events_in_range( $start, $end );
$after_delete = get_num_queries() - $before;
bl_check( "delete_by_post_id() invalidated the cache (got {$after_delete})", $after_delete > 0 );

WP_CLI::log( '' );
WP_CLI::log( "{$GLOBALS['bl_pass']} passed, {$GLOBALS['bl_fail']} failed" );

if ( $GLOBALS['bl_fail'] > 0 ) {
	WP_CLI::error( 'Cache verification failed.' );
}

WP_CLI::success( 'Cache verification passed.' );
