<?php
/**
 * Benchmark template — copy, rename, edit.
 *
 *   npx wp-env run cli -- wp eval-file wp-content/plugins/blockendar/bin/bench/<name>.php
 *
 * The first statement must be the WP_CLI guard. This directory is inside the
 * plugin, which wp-env serves over HTTP; the guard makes a direct request exit
 * before anything runs, and .htaccess denies it outright where honoured.
 *
 * @package Blockendar
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

global $wpdb;

$blockendar_bench_table = \Blockendar\DB\Schema::events_table();
$blockendar_bench_start = microtime( true );
$blockendar_bench_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$blockendar_bench_table}" );
$blockendar_bench_ms    = ( microtime( true ) - $blockendar_bench_start ) * 1000;

WP_CLI::log( sprintf( '%d index rows counted in %.1f ms', $blockendar_bench_count, $blockendar_bench_ms ) );
