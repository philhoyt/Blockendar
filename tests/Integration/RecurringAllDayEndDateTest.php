<?php
/**
 * Integration coverage for what the index's end_date column means.
 *
 * The column holds the last day of an event, inclusive. The single-event path
 * always stored it that way, but the recurrence generator stored the day
 * after, so one column meant two things and every reader was wrong for one
 * kind of event: the feed and the date block ran a recurring all-day event a
 * day long, and the calendar ran a single multi-day one a day short.
 *
 * These tests go through the real tables because the bug lived between the
 * generator, the index and its readers, not in any one of them.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\ICS\Exporter;
use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\RuleRepository;
use Blockendar\REST\CalendarController;
use WP_REST_Request;
use WP_UnitTestCase;

class RecurringAllDayEndDateTest extends WP_UnitTestCase {

	private EventIndex $index;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();

		$this->index = new EventIndex();
		$this->index->flush_cache();

		global $wpdb;
		$events = Schema::events_table();
		$wpdb->query( "DELETE FROM {$events}" ); // phpcs:ignore WordPress.DB

		delete_option( 'blockendar_settings' );
	}

	public function tear_down(): void {
		unset( $GLOBALS['blockendar_current_occurrence'] );
		delete_option( 'blockendar_settings' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create an all-day event and index it.
	 *
	 * @param string     $start    First day, Y-m-d.
	 * @param string     $end      Last day, Y-m-d.
	 * @param array|null $rule     Recurrence rule, or null for a single event.
	 * @param string     $timezone Event timezone.
	 * @return int Post ID.
	 */
	private function make_all_day_event( string $start, string $end, ?array $rule = null, string $timezone = 'UTC' ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => $rule ? 'Weekly retreat' : 'One-off retreat',
				'meta_input'  => [
					'blockendar_start_date' => $start,
					'blockendar_end_date'   => $end,
					'blockendar_all_day'    => '1',
					'blockendar_timezone'   => $timezone,
				],
			]
		);

		if ( $rule ) {
			( new RuleRepository() )->upsert( $post_id, $rule );
			( new Generator() )->generate_for_post( $post_id );
		} else {
			( new IndexBuilder() )->build_for_post( $post_id );
		}

		$this->index->flush_cache();

		return $post_id;
	}

	/**
	 * A weekly rule that produces three occurrences.
	 */
	private function weekly(): array {
		return [
			'frequency' => 'weekly',
			'interval'  => 1,
			'count'     => 3,
		];
	}

	/**
	 * The index rows of one event, earliest first.
	 *
	 * @param int $post_id Event post ID.
	 * @return object[]
	 */
	private function rows( int $post_id ): array {
		$rows = $this->index->get_by_post_id( $post_id );

		usort( $rows, static fn( $a, $b ) => strcmp( $a->start_datetime, $b->start_datetime ) );

		return $rows;
	}

	/**
	 * The calendar route's JSON for November 2026, keyed by start date.
	 *
	 * @param int $post_id Event to pick out.
	 * @return array<string, array>
	 */
	private function calendar_events( int $post_id ): array {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'start', '2026-11-01 00:00:00' );
		$request->set_param( 'end', '2026-12-01 00:00:00' );
		$request->set_param( 'format', 'json' );

		$events = [];

		foreach ( ( new CalendarController() )->get_calendar_feed( $request )->get_data() as $event ) {
			if ( (int) $event['post_id'] === $post_id ) {
				$events[ $event['start'] ] = $event;
			}
		}

		return $events;
	}

	// -------------------------------------------------------------------------
	// Storage
	// -------------------------------------------------------------------------

	public function test_a_one_day_recurring_all_day_event_ends_on_the_day_it_starts(): void {
		$rows = $this->rows( $this->make_all_day_event( '2026-11-02', '2026-11-02', $this->weekly() ) );

		$this->assertCount( 3, $rows );

		foreach ( $rows as $row ) {
			$this->assertSame( $row->start_date, $row->end_date );
		}
	}

	public function test_a_multi_day_recurring_all_day_event_ends_on_its_last_day(): void {
		$rows = $this->rows( $this->make_all_day_event( '2026-11-02', '2026-11-04', $this->weekly() ) );

		$this->assertSame( '2026-11-02', $rows[0]->start_date );
		$this->assertSame( '2026-11-04', $rows[0]->end_date );
		$this->assertSame( '2026-11-09', $rows[1]->start_date );
		$this->assertSame( '2026-11-11', $rows[1]->end_date );
	}

	/**
	 * The single-event path is the reference: a recurring event with the same
	 * dates has to store the same thing.
	 */
	public function test_a_recurring_and_a_single_event_store_the_same_end_date(): void {
		$single    = $this->rows( $this->make_all_day_event( '2026-11-02', '2026-11-04' ) );
		$recurring = $this->rows( $this->make_all_day_event( '2026-11-02', '2026-11-04', $this->weekly() ) );

		$this->assertSame( '2026-11-04', $single[0]->end_date );
		$this->assertSame( $single[0]->end_date, $recurring[0]->end_date );
		$this->assertSame( $single[0]->end_datetime, $recurring[0]->end_datetime );
	}

	/**
	 * end_datetime is the instant the event stops, which for an all-day event
	 * is midnight after its last day in the event's own timezone. That was
	 * always right and must not move.
	 */
	public function test_the_end_instant_is_midnight_after_the_last_day(): void {
		$rows = $this->rows( $this->make_all_day_event( '2026-11-02', '2026-11-02', $this->weekly(), 'America/Chicago' ) );

		$this->assertSame( '2026-11-02 06:00:00', $rows[0]->start_datetime );
		$this->assertSame( '2026-11-03 06:00:00', $rows[0]->end_datetime );
	}

	public function test_a_manually_added_date_is_stored_the_same_way(): void {
		$post_id = $this->make_all_day_event( '2026-11-02', '2026-11-02', $this->weekly() );

		( new RuleRepository() )->add_extra_date( $post_id, '2026-12-25' );
		( new Generator() )->generate_for_post( $post_id );
		$this->index->flush_cache();

		$added = array_values( array_filter( $this->rows( $post_id ), static fn( $row ) => '2026-12-25' === $row->start_date ) );

		$this->assertCount( 1, $added );
		$this->assertSame( '2026-12-25', $added[0]->end_date );
	}

	// -------------------------------------------------------------------------
	// Readers
	// -------------------------------------------------------------------------

	/**
	 * iCalendar's all-day DTEND is exclusive, so the exporter adds a day. With
	 * a stored end that already had one added, a one-day event ran for two.
	 */
	public function test_the_feed_gives_a_one_day_recurring_event_one_day(): void {
		$this->make_all_day_event( '2026-11-02', '2026-11-02', $this->weekly() );

		$rows = $this->index->get_events_in_range( '2026-11-01 00:00:00', '2026-11-08 00:00:00' );
		$ics  = ( new Exporter() )->generate_feed( $rows );

		$this->assertStringContainsString( "DTSTART;VALUE=DATE:20261102\r\n", $ics );
		$this->assertStringContainsString( "DTEND;VALUE=DATE:20261103\r\n", $ics );
	}

	/**
	 * FullCalendar's all-day end is exclusive too. The calendar route handed
	 * it the stored end date as it was, which was only right for the recurring
	 * events whose stored date was wrong: a single three-day event was drawn
	 * across two days.
	 *
	 * @return array<string, array{array|null}>
	 */
	public function single_and_recurring(): array {
		return [
			'single'    => [ null ],
			'recurring' => [ $this->weekly() ],
		];
	}

	/**
	 * @dataProvider single_and_recurring
	 *
	 * @param array|null $rule Recurrence rule, or null for a single event.
	 */
	public function test_the_calendar_gets_the_day_after_the_last_as_a_three_day_events_end( ?array $rule ): void {
		$events = $this->calendar_events( $this->make_all_day_event( '2026-11-02', '2026-11-04', $rule ) );

		$this->assertTrue( $events['2026-11-02']['allDay'] );
		$this->assertSame( '2026-11-05', $events['2026-11-02']['end'] );
	}

	/**
	 * @dataProvider single_and_recurring
	 *
	 * @param array|null $rule Recurrence rule, or null for a single event.
	 */
	public function test_the_calendar_gets_the_next_day_as_a_one_day_events_end( ?array $rule ): void {
		$events = $this->calendar_events( $this->make_all_day_event( '2026-11-02', '2026-11-02', $rule ) );

		$this->assertSame( '2026-11-03', $events['2026-11-02']['end'] );
	}

	/**
	 * The date block prints an end date when it differs from the start. For a
	 * one-day recurring event it did, so the block showed a two-day range.
	 */
	public function test_the_date_block_shows_one_date_for_a_one_day_recurring_event(): void {
		$post_id = $this->make_all_day_event( '2026-11-02', '2026-11-02', $this->weekly() );

		$GLOBALS['blockendar_current_occurrence'] = $this->rows( $post_id )[0];

		$html = ( new \WP_Block(
			[
				'blockName' => 'blockendar/event-datetime',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		) )->render();

		$this->assertStringContainsString( 'blockendar-event-datetime__start', $html );
		$this->assertStringNotContainsString( 'blockendar-event-datetime__end', $html );
	}

	// -------------------------------------------------------------------------
	// Upgrade
	// -------------------------------------------------------------------------

	/**
	 * Rows written by an earlier version hold the day after. A version change
	 * queues a full rebuild, and that rebuild has to put them right.
	 */
	public function test_a_rebuild_corrects_rows_stored_the_old_way(): void {
		$post_id = $this->make_all_day_event( '2026-11-02', '2026-11-02', $this->weekly() );

		global $wpdb;
		$events = Schema::events_table();
		// phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "UPDATE {$events} SET end_date = DATE_ADD( end_date, INTERVAL 1 DAY ) WHERE post_id = %d", $post_id ) );
		$this->index->flush_cache();

		$this->assertSame( '2026-11-03', $this->rows( $post_id )[0]->end_date, 'Precondition: the row holds the old, exclusive date.' );

		( new IndexBuilder() )->rebuild_all();
		$this->index->flush_cache();

		$rows = $this->rows( $post_id );

		$this->assertCount( 3, $rows );
		$this->assertSame( '2026-11-02', $rows[0]->end_date );
	}
}
