<?php
/**
 * Integration coverage for IndexBuilder::build_for_post().
 *
 * The builder used to insert without clearing, so any caller other than the
 * save_post path (which happened to delete first) appended a row per call —
 * a site indexed twice listed every event twice. build_for_post() must be
 * idempotent on both the single-row and the recurrence path.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\RuleRepository;
use WP_UnitTestCase;

class IndexBuilderTest extends WP_UnitTestCase {

	private EventIndex $index;

	private IndexBuilder $builder;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		$this->index   = new EventIndex();
		$this->builder = new IndexBuilder();
		$this->index->flush_cache();

		global $wpdb;
		$events     = Schema::events_table();
		$recurrence = Schema::recurrence_table();
		$wpdb->query( "DELETE FROM {$events}" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$recurrence}" ); // phpcs:ignore WordPress.DB
	}

	private function create_event( array $meta = [] ): int {
		return self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => array_merge(
					[
						'blockendar_start_date' => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
						'blockendar_end_date'   => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
						'blockendar_start_time' => '10:00',
						'blockendar_end_time'   => '11:00',
						'blockendar_timezone'   => 'UTC',
					],
					$meta
				),
			]
		);
	}

	public function test_building_three_times_leaves_one_row(): void {
		$post_id = $this->create_event();

		$this->builder->build_for_post( $post_id );
		$this->builder->build_for_post( $post_id );
		$this->builder->build_for_post( $post_id );

		$this->assertCount( 1, $this->index->get_by_post_id( $post_id ) );
	}

	public function test_building_three_times_leaves_the_same_recurrence_rows(): void {
		$post_id = $this->create_event();
		( new RuleRepository() )->upsert(
			$post_id,
			[
				'frequency' => 'weekly',
				'count'     => 4,
			]
		);

		$this->builder->build_for_post( $post_id );
		$first = count( $this->index->get_by_post_id( $post_id ) );

		$this->builder->build_for_post( $post_id );
		$this->builder->build_for_post( $post_id );

		$this->assertSame( 4, $first, 'A four-occurrence rule materialises four rows.' );
		$this->assertCount( $first, $this->index->get_by_post_id( $post_id ) );
	}

	public function test_an_ongoing_event_builds_once(): void {
		$post_id = $this->create_event(
			[
				'blockendar_end_date' => '',
				'blockendar_end_time' => '',
				'blockendar_ongoing'  => true,
			]
		);

		$this->builder->build_for_post( $post_id );
		$this->builder->build_for_post( $post_id );

		$this->assertCount( 1, $this->index->get_by_post_id( $post_id ) );
	}

	public function test_building_a_post_that_lost_its_dates_clears_its_rows(): void {
		$post_id = $this->create_event();
		$this->builder->build_for_post( $post_id );
		$this->assertCount( 1, $this->index->get_by_post_id( $post_id ) );

		delete_post_meta( $post_id, 'blockendar_start_date' );
		$this->builder->build_for_post( $post_id );

		$this->assertCount( 0, $this->index->get_by_post_id( $post_id ), 'Stale rows must not survive a rebuild.' );
	}

	public function test_unpublishing_removes_the_rows(): void {
		$post_id = $this->create_event();
		$this->assertCount( 1, $this->index->get_by_post_id( $post_id ), 'Publishing indexes via save_post.' );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);

		$this->assertCount( 0, $this->index->get_by_post_id( $post_id ) );
	}

	public function test_rebuild_all_yields_one_row_per_event(): void {
		$a = $this->create_event();
		$b = $this->create_event();

		// Simulate a site indexed twice with the old, appending builder.
		$this->index->insert(
			[
				'post_id'        => $a,
				'start_datetime' => '2030-01-01 10:00:00',
				'end_datetime'   => '2030-01-01 11:00:00',
				'start_date'     => '2030-01-01',
				'end_date'       => '2030-01-01',
			]
		);
		$this->assertCount( 2, $this->index->get_by_post_id( $a ) );

		$result = $this->builder->rebuild_all();

		$this->assertSame( 2, $result['rebuilt'] );
		$this->assertCount( 1, $this->index->get_by_post_id( $a ) );
		$this->assertCount( 1, $this->index->get_by_post_id( $b ) );
	}
}
