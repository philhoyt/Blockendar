<?php
/**
 * Integration coverage for ongoing events (no end date).
 *
 * An ongoing event is indexed with a far-future sentinel end so the existing
 * overlap queries keep matching it, and flagged so consumers never display the
 * sentinel. These tests pin the index behaviour: the row shape, the range and
 * ongoing filters, and the guards that stop a stale recurrence rule from
 * replacing the single ongoing row.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\RuleRepository;
use Blockendar\REST\EventsController;
use WP_REST_Request;
use WP_UnitTestCase;

class OngoingEventTest extends WP_UnitTestCase {

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

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a published event with the given meta. Publishing fires the
	 * save_post hook, so the row is indexed the way the editor would do it.
	 *
	 * @param array $meta Meta overrides (keys without the blockendar_ prefix).
	 * @return int Post ID.
	 */
	private function create_event( array $meta ): int {
		$defaults = [
			'start_date' => '2025-09-13',
			'start_time' => '10:00',
			'end_date'   => '',
			'end_time'   => '',
			'all_day'    => false,
			'timezone'   => 'UTC',
			'ongoing'    => false,
		];

		$meta_input = [];
		foreach ( array_merge( $defaults, $meta ) as $key => $value ) {
			$meta_input[ "blockendar_{$key}" ] = $value;
		}

		return self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => 'Front gallery exhibit',
				'meta_input'  => $meta_input,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Indexing
	// -------------------------------------------------------------------------

	public function test_an_ongoing_event_is_indexed_without_an_end_date(): void {
		$post_id = $this->create_event( [ 'ongoing' => true ] );

		$rows = $this->index->get_by_post_id( $post_id );

		$this->assertCount( 1, $rows, 'An ongoing event should produce exactly one index row.' );
		$this->assertSame( 1, (int) $rows[0]->ongoing );
		$this->assertSame( EventIndex::ONGOING_END, $rows[0]->end_datetime );
		$this->assertSame( EventIndex::ONGOING_END_DATE, $rows[0]->end_date );
		$this->assertSame( '2025-09-13 10:00:00', $rows[0]->start_datetime );
	}

	public function test_a_normal_event_without_an_end_date_is_still_skipped(): void {
		$post_id = $this->create_event( [ 'ongoing' => false ] );

		$this->assertCount( 0, $this->index->get_by_post_id( $post_id ) );
	}

	public function test_turning_ongoing_off_with_an_end_date_reindexes_normally(): void {
		$post_id = $this->create_event( [ 'ongoing' => true ] );

		update_post_meta( $post_id, 'blockendar_ongoing', false );
		update_post_meta( $post_id, 'blockendar_end_date', '2025-09-20' );
		update_post_meta( $post_id, 'blockendar_end_time', '17:00' );
		$this->index->delete_by_post_id( $post_id );
		$this->builder->build_for_post( $post_id );

		$rows = $this->index->get_by_post_id( $post_id );

		$this->assertCount( 1, $rows );
		$this->assertSame( 0, (int) $rows[0]->ongoing );
		$this->assertSame( '2025-09-20 17:00:00', $rows[0]->end_datetime );
		$this->assertSame( '2025-09-20', $rows[0]->end_date );
	}

	// -------------------------------------------------------------------------
	// Range queries
	// -------------------------------------------------------------------------

	public function test_an_ongoing_event_matches_any_future_window(): void {
		$post_id = $this->create_event( [ 'ongoing' => true ] );

		$rows = $this->index->get_events_in_range( '2030-01-01 00:00:00', '2030-02-01 00:00:00' );

		$this->assertCount( 1, $rows );
		$this->assertSame( $post_id, (int) $rows[0]->post_id );
		$this->assertSame( 1, (int) $rows[0]->ongoing, 'The range query should surface the ongoing flag.' );
	}

	public function test_the_ongoing_filter_excludes_or_isolates_ongoing_events(): void {
		$ongoing = $this->create_event( [ 'ongoing' => true ] );
		$normal  = $this->create_event(
			[
				'start_date' => '2025-09-14',
				'end_date'   => '2025-09-14',
				'end_time'   => '11:00',
			]
		);

		$window = [ '2025-09-01 00:00:00', '2025-10-01 00:00:00' ];

		$all = $this->index->get_events_in_range( ...$window );
		$this->assertCount( 2, $all, 'With no filter both events overlap the window.' );

		$without = $this->index->get_events_in_range( $window[0], $window[1], [ 'ongoing' => false ] );
		$this->assertCount( 1, $without );
		$this->assertSame( $normal, (int) $without[0]->post_id );

		$only = $this->index->get_events_in_range( $window[0], $window[1], [ 'ongoing' => true ] );
		$this->assertCount( 1, $only );
		$this->assertSame( $ongoing, (int) $only[0]->post_id );

		$this->assertSame( 1, $this->index->count_events_in_range( $window[0], $window[1], [ 'ongoing' => false ] ) );
		$this->assertSame( 1, $this->index->count_events_in_range( $window[0], $window[1], [ 'ongoing' => true ] ) );
		$this->assertSame( 2, $this->index->count_events_in_range( ...$window ) );
	}

	public function test_a_past_listing_window_excludes_ongoing_events_when_filtered(): void {
		// Mirrors the events-query "show past" window: 2000-01-01 → now.
		$this->create_event(
			[
				'ongoing'    => true,
				'start_date' => '2020-01-01',
			]
		);

		$now  = gmdate( 'Y-m-d H:i:s' );
		$rows = $this->index->get_events_in_range( '2000-01-01 00:00:00', $now, [ 'ongoing' => false ] );

		$this->assertCount( 0, $rows );
	}

	// -------------------------------------------------------------------------
	// Recurrence guards
	// -------------------------------------------------------------------------

	public function test_generator_leaves_the_ongoing_row_alone_when_a_rule_exists(): void {
		$post_id = $this->create_event( [ 'ongoing' => true ] );

		( new RuleRepository() )->upsert(
			$post_id,
			[
				'frequency' => 'weekly',
				'byday'     => 'SA',
			]
		);

		// This is what the nightly roll_horizon() cron calls for every rule.
		( new Generator() )->generate_for_post( $post_id );

		$rows = $this->index->get_by_post_id( $post_id );

		$this->assertCount( 1, $rows, 'The rule must not expand into recurrence rows.' );
		$this->assertSame( 1, (int) $rows[0]->ongoing );
	}

	public function test_saving_a_recurrence_rule_over_rest_keeps_the_ongoing_row(): void {
		$post_id = $this->create_event( [ 'ongoing' => true ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'POST', "/blockendar/v1/events/{$post_id}/recurrence" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'frequency' => 'weekly',
					'byday'     => 'SA',
				]
			)
		);
		$request->set_param( 'id', $post_id );

		$response = ( new EventsController() )->save_recurrence( $request );

		$this->assertSame( 200, $response->get_status() );

		$rows = $this->index->get_by_post_id( $post_id );

		$this->assertCount( 1, $rows, 'The REST handler rebuilds the index; it must still be one ongoing row.' );
		$this->assertSame( 1, (int) $rows[0]->ongoing );
		$this->assertSame( EventIndex::ONGOING_END, $rows[0]->end_datetime );
	}
}
