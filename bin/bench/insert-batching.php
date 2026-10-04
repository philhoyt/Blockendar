<?php
/**
 * One INSERT per occurrence against several occurrences per INSERT.
 *
 * Writes a ten-year daily series (3,650 rows, one event type each) both ways
 * and reports the time and the number of statements. The rows belong to a
 * post ID that does not exist and are removed afterwards, so the dev site's
 * own index is left as it was.
 *
 *   npx wp-env run cli -- wp eval-file wp-content/plugins/blockendar/bin/bench/insert-batching.php
 *
 * @package Blockendar
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

global $wpdb;

$blockendar_bench_index   = new \Blockendar\DB\EventIndex();
$blockendar_bench_post_id = 999999999;
$blockendar_bench_rows    = [];
$blockendar_bench_day     = new DateTimeImmutable( '2027-01-01', new DateTimeZone( 'UTC' ) );

for ( $blockendar_bench_i = 0; $blockendar_bench_i < 3650; $blockendar_bench_i++ ) {
	$blockendar_bench_date   = $blockendar_bench_day->modify( "+{$blockendar_bench_i} days" )->format( 'Y-m-d' );
	$blockendar_bench_rows[] = [
		'post_id'        => $blockendar_bench_post_id,
		'start_datetime' => $blockendar_bench_date . ' 09:00:00',
		'end_datetime'   => $blockendar_bench_date . ' 10:00:00',
		'start_date'     => $blockendar_bench_date,
		'end_date'       => $blockendar_bench_date,
		'recurrence_id'  => 1,
		'type_term_ids'  => [ 41 ],
	];
}

$blockendar_bench_index->delete_by_post_id( $blockendar_bench_post_id );

// One statement per row, and one more per event type: how rows were written before.
$blockendar_bench_queries = $wpdb->num_queries;
$blockendar_bench_start   = microtime( true );

foreach ( $blockendar_bench_rows as $blockendar_bench_row ) {
	$blockendar_bench_index->insert( $blockendar_bench_row, false );
}

WP_CLI::log(
	sprintf(
		'per row:  %6.0f ms, %d queries',
		( microtime( true ) - $blockendar_bench_start ) * 1000,
		$wpdb->num_queries - $blockendar_bench_queries
	)
);

$blockendar_bench_index->delete_by_post_id( $blockendar_bench_post_id );

// Fifty rows to a statement, in one transaction.
$blockendar_bench_queries = $wpdb->num_queries;
$blockendar_bench_start   = microtime( true );

$blockendar_bench_index->replace_for_post( $blockendar_bench_post_id, $blockendar_bench_rows );

WP_CLI::log(
	sprintf(
		'batched:  %6.0f ms, %d queries',
		( microtime( true ) - $blockendar_bench_start ) * 1000,
		$wpdb->num_queries - $blockendar_bench_queries
	)
);

$blockendar_bench_index->delete_by_post_id( $blockendar_bench_post_id );
