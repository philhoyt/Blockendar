<?php
/**
 * Integration coverage for "past" listings.
 *
 * The index matches by overlap, so a naive past window (2000 → now) also
 * catches anything that has started but not finished. Past mode instead uses
 * the `ended_before` filter: the event must have ended, and ongoing events
 * (sentinel end) never qualify.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use WP_UnitTestCase;

class PastListingTest extends WP_UnitTestCase {

	private EventIndex $index;

	private int $ended_id;

	private int $running_id;

	private int $ongoing_id;

	private string $now;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		$this->index = new EventIndex();
		$this->index->flush_cache();

		global $wpdb;
		$events = Schema::events_table();
		$wpdb->query( "DELETE FROM {$events}" ); // phpcs:ignore WordPress.DB

		$this->now = gmdate( 'Y-m-d H:i:s' );

		// Ended last month.
		$this->ended_id = $this->seed_event(
			'Ended',
			gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ),
			gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
		);

		// Started last year, closes next month — still running.
		$this->running_id = $this->seed_event(
			'Running',
			gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) ),
			gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) )
		);

		// Ongoing: opened last year, no end.
		$this->ongoing_id = $this->seed_event(
			'Ongoing',
			gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) ),
			EventIndex::ONGOING_END,
			[ 'ongoing' => 1 ]
		);
	}

	private function seed_event( string $title, string $start, string $end, array $extra = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);

		$this->index->insert(
			array_merge(
				[
					'post_id'        => $post_id,
					'start_datetime' => $start,
					'end_datetime'   => $end,
					'start_date'     => substr( $start, 0, 10 ),
					'end_date'       => substr( $end, 0, 10 ),
					'all_day'        => 0,
					'status'         => 'scheduled',
				],
				$extra
			)
		);

		return $post_id;
	}

	private function post_ids( array $rows ): array {
		return array_map( fn( $row ) => (int) $row->post_id, $rows );
	}

	public function test_past_mode_returns_only_events_that_have_finished(): void {
		$rows = $this->index->get_events_in_range(
			'2000-01-01 00:00:00',
			$this->now,
			[ 'ended_before' => $this->now ]
		);

		$this->assertSame( [ $this->ended_id ], $this->post_ids( $rows ) );
		$this->assertSame(
			1,
			$this->index->count_events_in_range( '2000-01-01 00:00:00', $this->now, [ 'ended_before' => $this->now ] )
		);
	}

	public function test_upcoming_mode_returns_the_running_and_ongoing_events(): void {
		$rows = $this->index->get_events_in_range(
			$this->now,
			gmdate( 'Y-m-d H:i:s', strtotime( '+3 years' ) )
		);

		$ids = $this->post_ids( $rows );
		sort( $ids );
		$expected = [ $this->running_id, $this->ongoing_id ];
		sort( $expected );

		$this->assertSame( $expected, $ids );
	}

	public function test_a_narrower_window_still_requires_the_event_to_have_ended(): void {
		// A range that the running event overlaps but did not finish inside.
		$rows = $this->index->get_events_in_range(
			gmdate( 'Y-m-d H:i:s', strtotime( '-45 days' ) ),
			gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
			[ 'ended_before' => $this->now ]
		);

		$this->assertSame( [ $this->ended_id ], $this->post_ids( $rows ) );

		// A range that ends before the ended event finished excludes it too.
		$rows = $this->index->get_events_in_range(
			gmdate( 'Y-m-d H:i:s', strtotime( '-45 days' ) ),
			gmdate( 'Y-m-d H:i:s', strtotime( '-35 days' ) ),
			[ 'ended_before' => $this->now ]
		);

		$this->assertSame( [], $this->post_ids( $rows ) );
	}

	public function test_ended_before_and_overlap_do_not_share_a_cache_entry(): void {
		$overlap = $this->index->get_events_in_range( '2000-01-01 00:00:00', $this->now );
		$ended   = $this->index->get_events_in_range( '2000-01-01 00:00:00', $this->now, [ 'ended_before' => $this->now ] );

		$this->assertCount( 3, $overlap, 'Plain overlap still catches everything that has started.' );
		$this->assertCount( 1, $ended );
	}

	private function render_query( string $attrs ): string {
		return do_blocks(
			'<!-- wp:blockendar/events-query ' . $attrs . ' -->'
			. '<!-- wp:blockendar/event-template --><!-- wp:post-title /--><!-- /wp:blockendar/event-template -->'
			. '<!-- /wp:blockendar/events-query -->'
		);
	}

	public function test_events_query_block_past_mode_lists_only_finished_events(): void {
		$html = $this->render_query( '{"showPast":true}' );

		$this->assertStringContainsString( 'Ended', $html );
		$this->assertStringNotContainsString( 'Running', $html );
		$this->assertStringNotContainsString( 'Ongoing', $html );
	}

	public function test_events_query_block_past_mode_defaults_to_most_recent_first(): void {
		$older = $this->seed_event(
			'Older',
			gmdate( 'Y-m-d H:i:s', strtotime( '-80 days' ) ),
			gmdate( 'Y-m-d H:i:s', strtotime( '-70 days' ) )
		);

		$html = $this->render_query( '{"showPast":true}' );
		$this->assertLessThan( strpos( $html, 'Older' ), strpos( $html, 'Ended' ), 'Auto order in past mode is DESC.' );

		$html = $this->render_query( '{"showPast":true,"order":"ASC"}' );
		$this->assertLessThan( strpos( $html, 'Ended' ), strpos( $html, 'Older' ), 'An explicit order is honoured.' );

		$this->assertGreaterThan( 0, $older );
	}
}
