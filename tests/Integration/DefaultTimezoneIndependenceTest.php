<?php
/**
 * Integration coverage for date handling that must not depend on PHP's default timezone.
 *
 * WordPress sets the default timezone to UTC and expects it to stay there, but
 * any plugin or theme can call date_default_timezone_set(). strtotime() and a
 * DateTimeImmutable built without a zone both read a bare date in whatever the
 * default is at that moment, so a date worked out that way moves when the
 * default does.
 *
 * Each test here changes the default and asserts that nothing else changes.
 * Three zones are used because they fail in different ways: Asia/Tokyo is an
 * ordinary positive offset, and Etc/GMT+12 (UTC−12) and Pacific/Kiritimati
 * (UTC+14) are the two ends of the range.
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
use WP_UnitTestCase;

class DefaultTimezoneIndependenceTest extends WP_UnitTestCase {

	private EventIndex $index;

	private string $original_timezone;

	public function set_up(): void {
		parent::set_up();

		$this->original_timezone = date_default_timezone_get();

		Schema::create_tables();

		$this->index = new EventIndex();
		$this->index->flush_cache();

		global $wpdb;
		$events = Schema::events_table();
		$wpdb->query( "DELETE FROM {$events}" ); // phpcs:ignore WordPress.DB

		delete_option( 'blockendar_settings' );
	}

	public function tear_down(): void {
		// Every other test in the run expects what WordPress set.
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set
		date_default_timezone_set( $this->original_timezone );

		delete_option( 'blockendar_settings' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Default timezones to run each test under.
	 *
	 * @return array<string, array{string}>
	 */
	public function default_timezones(): array {
		return [
			'UTC, as WordPress sets it' => [ 'UTC' ],
			'Asia/Tokyo'                => [ 'Asia/Tokyo' ],
			'Etc/GMT+12'                => [ 'Etc/GMT+12' ],
			'Pacific/Kiritimati'        => [ 'Pacific/Kiritimati' ],
		];
	}

	/**
	 * Do what a misbehaving plugin does.
	 *
	 * @param string $timezone Timezone identifier.
	 */
	private function change_default_timezone( string $timezone ): void {
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set
		date_default_timezone_set( $timezone );
	}

	/**
	 * Create an event in UTC without indexing it.
	 *
	 * @param array $meta Event meta, without the blockendar_ prefix.
	 * @return int Post ID.
	 */
	private function make_event( array $meta ): int {
		$meta_input = [ 'blockendar_timezone' => 'UTC' ];

		foreach ( $meta as $key => $value ) {
			$meta_input[ "blockendar_{$key}" ] = $value;
		}

		return self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => 'Spring Fair',
				'meta_input'  => $meta_input,
			]
		);
	}

	/**
	 * Start dates of an event's index rows, earliest first.
	 *
	 * @param int $post_id Event post ID.
	 * @return string[]
	 */
	private function start_dates( int $post_id ): array {
		$this->index->flush_cache();

		$dates = wp_list_pluck( $this->index->get_by_post_id( $post_id ), 'start_date' );

		sort( $dates );

		return $dates;
	}

	// -------------------------------------------------------------------------
	// The index
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider default_timezones
	 *
	 * @param string $timezone PHP default timezone.
	 */
	public function test_an_all_day_event_stops_at_the_midnight_after_its_last_day( string $timezone ): void {
		$post_id = $this->make_event(
			[
				'start_date' => '2026-03-10',
				'end_date'   => '2026-03-10',
				'all_day'    => '1',
			]
		);

		$this->change_default_timezone( $timezone );
		( new IndexBuilder() )->build_for_post( $post_id );

		$this->index->flush_cache();
		$rows = $this->index->get_by_post_id( $post_id );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2026-03-10 00:00:00', $rows[0]->start_datetime );
		$this->assertSame( '2026-03-11 00:00:00', $rows[0]->end_datetime );
	}

	/**
	 * The expansion compared a cursor built in the default timezone, at the
	 * current time of day, with an end date fixed in UTC. Which way that went
	 * wrong depended on the zone and the hour: behind UTC, the occurrence on
	 * the end date was dropped during the first part of the UTC day; ahead of
	 * it, one more was added after the end date during the last part. Between
	 * Etc/GMT+12 and Pacific/Kiritimati, one of the two fails at any hour.
	 *
	 * @dataProvider default_timezones
	 *
	 * @param string $timezone PHP default timezone.
	 */
	public function test_a_daily_series_ends_on_its_end_date( string $timezone ): void {
		$post_id = $this->make_event(
			[
				'start_date' => '2026-03-10',
				'end_date'   => '2026-03-10',
				'start_time' => '09:00',
				'end_time'   => '10:00',
			]
		);

		( new RuleRepository() )->upsert(
			$post_id,
			[
				'frequency'  => 'daily',
				'interval'   => 1,
				'until_date' => '2026-03-12',
			]
		);

		$this->change_default_timezone( $timezone );
		( new Generator() )->generate_for_post( $post_id );

		$this->assertSame( [ '2026-03-10', '2026-03-11', '2026-03-12' ], $this->start_dates( $post_id ) );
	}

	/**
	 * A multi-day event's length is the gap between two dates, and each
	 * occurrence has to keep it.
	 *
	 * @dataProvider default_timezones
	 *
	 * @param string $timezone PHP default timezone.
	 */
	public function test_a_multi_day_recurring_event_keeps_its_length( string $timezone ): void {
		$post_id = $this->make_event(
			[
				'start_date' => '2026-03-10',
				'end_date'   => '2026-03-12',
				'all_day'    => '1',
			]
		);

		( new RuleRepository() )->upsert(
			$post_id,
			[
				'frequency' => 'weekly',
				'interval'  => 1,
				'count'     => 2,
			]
		);

		$this->change_default_timezone( $timezone );
		( new Generator() )->generate_for_post( $post_id );

		$this->index->flush_cache();
		$rows = $this->index->get_by_post_id( $post_id );
		usort( $rows, static fn( $a, $b ) => strcmp( $a->start_datetime, $b->start_datetime ) );

		$this->assertCount( 2, $rows );
		$this->assertSame( '2026-03-10', $rows[0]->start_date );
		$this->assertSame( '2026-03-12', $rows[0]->end_date );
		$this->assertSame( '2026-03-13 00:00:00', $rows[0]->end_datetime );
		$this->assertSame( '2026-03-17', $rows[1]->start_date );
		$this->assertSame( '2026-03-19', $rows[1]->end_date );
	}

	/**
	 * Monthly rules build each next date from a string. 10 March 2026 is the
	 * second Tuesday of the month, and so are 14 April and 12 May.
	 *
	 * @dataProvider default_timezones
	 *
	 * @param string $timezone PHP default timezone.
	 */
	public function test_a_monthly_nth_weekday_series_ends_on_its_end_date( string $timezone ): void {
		$post_id = $this->make_event(
			[
				'start_date' => '2026-03-10',
				'end_date'   => '2026-03-10',
				'start_time' => '09:00',
				'end_time'   => '10:00',
			]
		);

		( new RuleRepository() )->upsert(
			$post_id,
			[
				'frequency'  => 'monthly',
				'interval'   => 1,
				'byday'      => [ 'TU' ],
				'bysetpos'   => [ 2 ],
				'until_date' => '2026-05-12',
			]
		);

		$this->change_default_timezone( $timezone );
		( new Generator() )->generate_for_post( $post_id );

		$this->assertSame( [ '2026-03-10', '2026-04-14', '2026-05-12' ], $this->start_dates( $post_id ) );
	}

	/**
	 * @dataProvider default_timezones
	 *
	 * @param string $timezone PHP default timezone.
	 */
	public function test_a_monthly_day_of_month_series_ends_on_its_end_date( string $timezone ): void {
		$post_id = $this->make_event(
			[
				'start_date' => '2026-03-10',
				'end_date'   => '2026-03-10',
				'start_time' => '09:00',
				'end_time'   => '10:00',
			]
		);

		( new RuleRepository() )->upsert(
			$post_id,
			[
				'frequency'  => 'monthly',
				'interval'   => 1,
				'bymonthday' => [ 10, 25 ],
				'until_date' => '2026-04-25',
			]
		);

		$this->change_default_timezone( $timezone );
		( new Generator() )->generate_for_post( $post_id );

		$this->assertSame( [ '2026-03-10', '2026-03-25', '2026-04-10', '2026-04-25' ], $this->start_dates( $post_id ) );
	}

	// -------------------------------------------------------------------------
	// The feed
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider default_timezones
	 *
	 * @param string $timezone PHP default timezone.
	 */
	public function test_the_feed_ends_an_all_day_event_the_day_after_its_last_day( string $timezone ): void {
		$post_id = $this->make_event(
			[
				'start_date' => '2026-03-10',
				'end_date'   => '2026-03-11',
				'all_day'    => '1',
			]
		);

		( new IndexBuilder() )->build_for_post( $post_id );
		$this->index->flush_cache();

		$rows = $this->index->get_events_in_range( '2026-03-01 00:00:00', '2026-04-01 00:00:00' );

		$this->change_default_timezone( $timezone );
		$ics = ( new Exporter() )->generate_feed( $rows );

		$this->assertStringContainsString( "DTSTART;VALUE=DATE:20260310\r\n", $ics );
		$this->assertStringContainsString( "DTEND;VALUE=DATE:20260312\r\n", $ics );
	}

	// -------------------------------------------------------------------------
	// Display
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider default_timezones
	 *
	 * @param string $timezone PHP default timezone.
	 */
	public function test_the_date_block_prints_the_date_and_time_it_was_given( string $timezone ): void {
		$post_id = $this->make_event(
			[
				'start_date' => '2026-03-10',
				'end_date'   => '2026-03-10',
				'start_time' => '19:00',
				'end_time'   => '21:00',
			]
		);

		( new IndexBuilder() )->build_for_post( $post_id );
		$this->index->flush_cache();

		$GLOBALS['blockendar_current_occurrence'] = $this->index->get_by_post_id( $post_id )[0];

		$this->change_default_timezone( $timezone );

		$html = ( new \WP_Block(
			[
				'blockName' => 'blockendar/event-datetime',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		) )->render();

		unset( $GLOBALS['blockendar_current_occurrence'] );

		$text = (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) );

		$this->assertStringContainsString( 'March 10, 2026', $text );
		$this->assertStringContainsString( '7:00 pm', $text );
		$this->assertStringContainsString( '9:00 pm', $text );
	}

	/**
	 * The helper the blocks format through: a wall-clock value comes out as it
	 * went in, whatever PHP's default is and wherever the site is. The noon
	 * trick the date-range filter relied on failed for a site at UTC+13 or +14.
	 *
	 * @dataProvider default_timezones
	 *
	 * @param string $timezone PHP default timezone.
	 */
	public function test_a_wall_clock_value_is_formatted_as_written( string $timezone ): void {
		foreach ( [ 'UTC', 'America/Chicago', 'Pacific/Kiritimati', 'Etc/GMT+12' ] as $site_timezone ) {
			update_option( 'timezone_string', $site_timezone );

			$this->change_default_timezone( $timezone );

			$this->assertSame( 'March 10, 2026', blockendar_format_wall_clock( '2026-03-10', 'F j, Y' ), "Site in {$site_timezone}." );
			$this->assertSame( '7:00 pm', blockendar_format_wall_clock( '2026-03-10 19:00', 'g:i a' ), "Site in {$site_timezone}." );

			$this->change_default_timezone( $this->original_timezone );
		}

		delete_option( 'timezone_string' );
	}

	public function test_a_value_that_is_not_a_date_formats_as_nothing(): void {
		$this->assertSame( '', blockendar_format_wall_clock( 'not a date', 'F j, Y' ) );
		$this->assertSame( '', blockendar_format_wall_clock( '', 'F j, Y' ) );
	}
}
