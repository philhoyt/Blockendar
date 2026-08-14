<?php
/**
 * Integration coverage for the EventIndex query layer.
 *
 * This is where the plugin builds SQL by hand, and where phpcs's SQL sniffs are
 * relaxed by line-level annotations. These tests exercise the parts a static
 * analyser cannot judge: that the ORDER BY allow-list holds, that filters bind as
 * parameters, and that the read cache invalidates on write.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use WP_UnitTestCase;

class EventIndexTest extends WP_UnitTestCase {

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
	 * Insert an indexed occurrence for a freshly created event post.
	 *
	 * @param string $date  Y-m-d date for the occurrence.
	 * @param array  $extra Extra column overrides.
	 * @return int Post ID.
	 */
	private function seed_event( string $date, array $extra = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => "Event {$date}",
			]
		);

		$this->index->insert(
			array_merge(
				[
					'post_id'        => $post_id,
					'start_datetime' => "{$date} 09:00:00",
					'end_datetime'   => "{$date} 10:00:00",
					'start_date'     => $date,
					'end_date'       => $date,
					'all_day'        => 0,
					'status'         => 'scheduled',
				],
				$extra
			)
		);

		return $post_id;
	}

	// -------------------------------------------------------------------------
	// ORDER BY allow-list
	// -------------------------------------------------------------------------

	public function test_malicious_orderby_falls_back_to_default(): void {
		$this->seed_event( '2026-09-02' );
		$this->seed_event( '2026-09-01' );

		$rows = $this->index->get_events_in_range(
			'2026-08-01 00:00:00',
			'2026-10-01 00:00:00',
			[ 'orderby' => 'start_datetime; DROP TABLE wp_posts;--' ]
		);

		// The query must still succeed and fall back to the default ordering
		// rather than interpolating the attacker's fragment.
		$this->assertCount( 2, $rows );
		$this->assertSame( '2026-09-01', $rows[0]->start_date );

		global $wpdb;
		$this->assertNotEmpty(
			$wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->posts}'" ), // phpcs:ignore WordPress.DB
			'wp_posts must still exist.'
		);
	}

	public function test_order_direction_is_constrained(): void {
		$this->seed_event( '2026-09-01' );
		$this->seed_event( '2026-09-02' );

		$rows = $this->index->get_events_in_range(
			'2026-08-01 00:00:00',
			'2026-10-01 00:00:00',
			[ 'order' => 'ASC; DELETE FROM wp_options;--' ]
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( '2026-09-01', $rows[0]->start_date );
	}

	public function test_allowed_orderby_is_honoured(): void {
		$this->seed_event( '2026-09-01' );
		$this->seed_event( '2026-09-02' );

		$rows = $this->index->get_events_in_range(
			'2026-08-01 00:00:00',
			'2026-10-01 00:00:00',
			[
				'orderby' => 'start_datetime',
				'order'   => 'DESC',
			]
		);

		$this->assertSame( '2026-09-02', $rows[0]->start_date );
	}

	// -------------------------------------------------------------------------
	// Filters bind as parameters
	// -------------------------------------------------------------------------

	public function test_status_filter_binds_safely(): void {
		$this->seed_event( '2026-09-01' );

		$rows = $this->index->get_events_in_range(
			'2026-08-01 00:00:00',
			'2026-10-01 00:00:00',
			[ 'status' => "scheduled' OR '1'='1" ]
		);

		$this->assertSame(
			[],
			$rows,
			'A quoted status must be bound as a value, not widen the result set.'
		);
	}

	public function test_range_bounds_exclude_events_outside_the_window(): void {
		$this->seed_event( '2026-09-01' );
		$this->seed_event( '2026-12-25' );

		$rows = $this->index->get_events_in_range(
			'2026-08-01 00:00:00',
			'2026-10-01 00:00:00'
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( '2026-09-01', $rows[0]->start_date );
	}

	// -------------------------------------------------------------------------
	// Cache correctness
	// -------------------------------------------------------------------------

	public function test_repeat_read_is_served_from_cache(): void {
		$this->seed_event( '2026-09-01' );

		$this->index->get_events_in_range( '2026-08-01 00:00:00', '2026-10-01 00:00:00' );

		$before = get_num_queries();
		$this->index->get_events_in_range( '2026-08-01 00:00:00', '2026-10-01 00:00:00' );

		$this->assertSame( $before, get_num_queries(), 'A warm read should not query.' );
	}

	public function test_insert_invalidates_the_cache(): void {
		$this->seed_event( '2026-09-01' );

		$first = $this->index->get_events_in_range( '2026-08-01 00:00:00', '2026-10-01 00:00:00' );
		$this->assertCount( 1, $first );

		$this->seed_event( '2026-09-15' );

		$second = $this->index->get_events_in_range( '2026-08-01 00:00:00', '2026-10-01 00:00:00' );
		$this->assertCount(
			2,
			$second,
			'A newly inserted occurrence must not be hidden behind a stale cache entry.'
		);
	}

	public function test_delete_invalidates_the_cache(): void {
		$post_id = $this->seed_event( '2026-09-01' );

		$this->assertCount(
			1,
			$this->index->get_events_in_range( '2026-08-01 00:00:00', '2026-10-01 00:00:00' )
		);

		$this->index->delete_by_post_id( $post_id );

		$this->assertCount(
			0,
			$this->index->get_events_in_range( '2026-08-01 00:00:00', '2026-10-01 00:00:00' ),
			'A deleted occurrence must not survive in the cache.'
		);
	}

	public function test_differing_filters_do_not_share_a_cache_entry(): void {
		$this->seed_event( '2026-09-01' );
		$this->seed_event( '2026-12-25' );

		$narrow = $this->index->get_events_in_range( '2026-08-01 00:00:00', '2026-10-01 00:00:00' );
		$wide   = $this->index->get_events_in_range( '2026-01-01 00:00:00', '2027-01-01 00:00:00' );

		$this->assertCount( 1, $narrow );
		$this->assertCount( 2, $wide );
	}

	public function test_count_matches_the_row_query(): void {
		$this->seed_event( '2026-09-01' );
		$this->seed_event( '2026-09-15' );

		$rows  = $this->index->get_events_in_range( '2026-08-01 00:00:00', '2026-10-01 00:00:00' );
		$count = $this->index->count_events_in_range( '2026-08-01 00:00:00', '2026-10-01 00:00:00' );

		$this->assertSame( count( $rows ), $count );
	}
}
