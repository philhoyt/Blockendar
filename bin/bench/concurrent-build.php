<?php
/**
 * Do two builds of one event at the same time leave duplicate rows?
 *
 * Run with no argument to create a daily event and print its ID. Then start
 * two or more of these at once with that ID; each rebuilds the event's rows
 * repeatedly and reports how many (post, start) pairs appear more than once.
 * Run with the ID and "delete" to remove the event again.
 *
 *   F=wp-content/plugins/blockendar/bin/bench/concurrent-build.php
 *   npx wp-env run cli -- wp eval-file $F
 *   npx wp-env run cli -- sh -c "wp eval-file $F <id> & wp eval-file $F <id> & wp eval-file $F <id> & wait"
 *   npx wp-env run cli -- wp eval-file $F <id> delete
 *
 * The concurrent processes are started inside one `wp-env run`. Several
 * `wp-env run` commands at once overwrite wp-env's own cache file and leave it
 * reporting that the environment is not initialised.
 *
 * Uses the dev site. The event it creates is deleted by the last command.
 *
 * @package Blockendar
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

global $wpdb;

$blockendar_bench_id     = (int) ( $args[0] ?? 0 );
$blockendar_bench_action = (string) ( $args[1] ?? '' );
$blockendar_bench_table  = \Blockendar\DB\Schema::events_table();

if ( 0 === $blockendar_bench_id ) {
	$blockendar_bench_start = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
	$blockendar_bench_id    = wp_insert_post(
		[
			'post_type'   => 'blockendar_event',
			'post_status' => 'publish',
			'post_title'  => 'Bench: concurrent build',
			'meta_input'  => [
				'blockendar_start_date' => $blockendar_bench_start,
				'blockendar_end_date'   => $blockendar_bench_start,
				'blockendar_start_time' => '09:00',
				'blockendar_end_time'   => '10:00',
			],
		]
	);

	( new \Blockendar\Recurrence\RuleRepository() )->upsert( $blockendar_bench_id, [ 'frequency' => 'daily' ] );
	( new \Blockendar\DB\IndexBuilder() )->build_for_post( $blockendar_bench_id );

	WP_CLI::log( (string) $blockendar_bench_id );
	return;
}

if ( 'delete' === $blockendar_bench_action ) {
	wp_delete_post( $blockendar_bench_id, true );
	WP_CLI::log( 'Deleted.' );
	return;
}

$blockendar_bench_builder = new \Blockendar\DB\IndexBuilder();

for ( $blockendar_bench_i = 0; $blockendar_bench_i < 15; $blockendar_bench_i++ ) {
	$blockendar_bench_builder->build_for_post( $blockendar_bench_id );
}

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$blockendar_bench_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$blockendar_bench_table} WHERE post_id = %d", $blockendar_bench_id ) );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$blockendar_bench_dupes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT 1 FROM {$blockendar_bench_table} WHERE post_id = %d GROUP BY start_datetime HAVING COUNT(*) > 1 ) d", $blockendar_bench_id ) );

WP_CLI::log( sprintf( '%d rows, %d starts indexed more than once', $blockendar_bench_rows, $blockendar_bench_dupes ) );
