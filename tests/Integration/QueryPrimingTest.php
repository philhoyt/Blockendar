<?php
/**
 * Integration coverage for cache priming on the hot read paths.
 *
 * Index rows come from custom SQL, so WordPress has never seen those posts and
 * none of its caches are warm. Every consumer then pays a query per row. These
 * tests count queries and assert the total does not scale with the number of
 * events — the only way a priming regression shows up, since the output is
 * identical either way.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\ICS\Exporter;
use WP_UnitTestCase;

class QueryPrimingTest extends WP_UnitTestCase {

	private EventIndex $index;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();

		$this->index = new EventIndex();
		$this->index->flush_cache();

		global $wpdb;
		$table = Schema::events_table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Create $count published events, each with meta and a venue term.
	 *
	 * @param int $count How many events to seed.
	 * @return array<object> The index rows for them.
	 */
	private function seed_events( int $count ): array {
		$venue_id = self::factory()->term->create( [ 'taxonomy' => 'event_venue' ] );

		for ( $i = 0; $i < $count; $i++ ) {
			$day     = str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT );
			$post_id = self::factory()->post->create(
				[
					'post_type'    => 'blockendar_event',
					'post_status'  => 'publish',
					'post_title'   => "Event {$i}",
					'post_content' => 'Body copy for the description.',
					// The factory invents a post_excerpt otherwise, which would
					// take the manual-excerpt branch and never exercise the
					// content trimming this test is about.
					'post_excerpt' => '',
				]
			);

			wp_set_object_terms( $post_id, [ $venue_id ], 'event_venue' );
			update_post_meta( $post_id, 'blockendar_cost', '10' );

			$this->index->insert(
				[
					'post_id'        => $post_id,
					'start_datetime' => "2026-12-{$day} 09:00:00",
					'end_datetime'   => "2026-12-{$day} 10:00:00",
					'start_date'     => "2026-12-{$day}",
					'end_date'       => "2026-12-{$day}",
					'all_day'        => 0,
					'status'         => 'scheduled',
					'venue_term_id'  => $venue_id,
				]
			);
		}

		$this->index->flush_cache();

		return $this->index->get_events_in_range( '2026-11-01 00:00:00', '2027-01-31 00:00:00' );
	}

	/**
	 * Run a callable and report how many database queries it issued.
	 *
	 * @param callable $work The code under measurement.
	 */
	private function count_queries( callable $work ): int {
		global $wpdb;

		$before = $wpdb->num_queries;
		$work();

		return $wpdb->num_queries - $before;
	}

	/**
	 * Priming is about the shape of the cost, not an exact number, so this
	 * compares a small set against a larger one. Without priming the delta
	 * grows with the row count; with it the work is a fixed handful of queries
	 * regardless.
	 */
	public function test_priming_keeps_permalink_and_meta_reads_flat(): void {
		$few = $this->seed_events( 2 );

		$cost_few = $this->count_queries(
			function () use ( $few ) {
				blockendar_prime_event_caches( $few );

				foreach ( $few as $row ) {
					get_permalink( (int) $row->post_id );
					get_post_meta( (int) $row->post_id, 'blockendar_cost', true );
					get_the_terms( (int) $row->post_id, 'event_venue' );
				}
			}
		);

		// Fresh set, fresh caches.
		$this->set_up();
		$many = $this->seed_events( 12 );

		$cost_many = $this->count_queries(
			function () use ( $many ) {
				blockendar_prime_event_caches( $many );

				foreach ( $many as $row ) {
					get_permalink( (int) $row->post_id );
					get_post_meta( (int) $row->post_id, 'blockendar_cost', true );
					get_the_terms( (int) $row->post_id, 'event_venue' );
				}
			}
		);

		$this->assertCount( 2, $few );
		$this->assertCount( 12, $many );

		/*
		 * Six times the rows must not mean six times the queries. Unprimed this
		 * grows by roughly three queries per extra event — 30 for the ten extra
		 * events here — so any real regression blows well past this bound.
		 */
		$this->assertLessThanOrEqual(
			$cost_few + 2,
			$cost_many,
			"Query count scaled with row count: {$cost_few} queries for 2 events, {$cost_many} for 12."
		);
	}

	/**
	 * The ICS description must not run the_content per event.
	 *
	 * get_the_excerpt() falls through to wp_trim_excerpt() on a post with no
	 * manual excerpt, which applies the whole the_content filter chain. On a
	 * 2000-event feed that is 2000 full content renders.
	 */
	public function test_ics_export_does_not_run_the_content_per_event(): void {
		$rows = $this->seed_events( 6 );

		$ran = 0;
		add_filter(
			'the_content',
			static function ( $content ) use ( &$ran ) {
				++$ran;
				return $content;
			}
		);

		blockendar_prime_event_caches( $rows );
		$ics = ( new Exporter() )->generate_feed( $rows );

		$this->assertSame( 0, $ran, 'Building the feed must not apply the_content.' );
		$this->assertStringContainsString( 'BEGIN:VEVENT', $ics );
		$this->assertStringContainsString( 'Body copy for the description.', $ics );
	}

	/**
	 * A manual excerpt still wins over the trimmed content.
	 */
	public function test_ics_description_prefers_a_manual_excerpt(): void {
		$rows = $this->seed_events( 1 );
		$row  = $rows[0];

		wp_update_post(
			[
				'ID'           => (int) $row->post_id,
				'post_excerpt' => 'Hand-written summary.',
			]
		);

		$ics = ( new Exporter() )->generate_feed( $rows );

		$this->assertStringContainsString( 'Hand-written summary.', $ics );
		$this->assertStringNotContainsString( 'Body copy for the description.', $ics );
	}
}
