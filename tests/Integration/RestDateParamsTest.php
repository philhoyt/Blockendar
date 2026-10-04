<?php
/**
 * Integration coverage for the start and end parameters of the REST read routes.
 *
 * A date with no time means a calendar day, and a day is a day in the site's
 * timezone. The index is in UTC, so a date-only value has to be turned into
 * the two UTC instants that bound that local day. Treated as UTC midnight at
 * both ends it is a window with no width, moved by the site's offset.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\REST\CalendarController;
use Blockendar\REST\EventsController;
use WP_REST_Request;
use WP_UnitTestCase;

class RestDateParamsTest extends WP_UnitTestCase {

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
		delete_option( 'timezone_string' );
		delete_option( 'gmt_offset' );
		delete_option( 'blockendar_settings' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Index a two-hour event from a UTC start.
	 *
	 * @param string $title     Post title.
	 * @param string $start_utc Start, Y-m-d H:i:s in UTC.
	 * @return int Post ID.
	 */
	private function seed( string $title, string $start_utc ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);

		$end_utc = gmdate( 'Y-m-d H:i:s', (int) date_create( $start_utc . ' UTC' )->getTimestamp() + 2 * HOUR_IN_SECONDS );

		$this->index->insert(
			[
				'post_id'        => $post_id,
				'start_datetime' => $start_utc,
				'end_datetime'   => $end_utc,
				'start_date'     => substr( $start_utc, 0, 10 ),
				'end_date'       => substr( $end_utc, 0, 10 ),
				'all_day'        => 0,
				'status'         => 'scheduled',
			]
		);

		return $post_id;
	}

	/**
	 * A Chicago site in October, when Chicago is UTC−5, with one event on the
	 * evening of the 3rd and one just after midnight on the 4th, local time.
	 */
	private function chicago(): void {
		update_option( 'timezone_string', 'America/Chicago' );

		$this->seed( 'Evening of the 3rd', '2026-10-04 01:00:00' );
		$this->seed( 'Small hours of the 4th', '2026-10-04 05:30:00' );

		$this->index->flush_cache();
	}

	/**
	 * Titles the events collection returns for a start and end.
	 *
	 * @param string $start start parameter.
	 * @param string $end   end parameter.
	 * @return string[]|\WP_Error
	 */
	private function events( string $start, string $end ) {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/events' );
		$request->set_param( 'start', $start );
		$request->set_param( 'end', $end );

		$response = ( new EventsController() )->get_events( $request );

		return is_wp_error( $response ) ? $response : wp_list_pluck( $response->get_data(), 'title' );
	}

	// -------------------------------------------------------------------------
	// A date with no time
	// -------------------------------------------------------------------------

	public function test_one_date_as_both_bounds_is_that_whole_local_day(): void {
		$this->chicago();

		$this->assertSame( [ 'Evening of the 3rd' ], $this->events( '2026-10-03', '2026-10-03' ) );
	}

	public function test_the_end_date_is_included(): void {
		$this->chicago();

		$this->assertSame(
			[ 'Evening of the 3rd', 'Small hours of the 4th' ],
			$this->events( '2026-10-03', '2026-10-04' )
		);
	}

	public function test_the_start_date_begins_at_local_midnight(): void {
		$this->chicago();

		$this->assertSame( [ 'Small hours of the 4th' ], $this->events( '2026-10-04', '2026-10-04' ) );
	}

	/**
	 * A site set to a manual offset has no named timezone; the day still has
	 * to be the site's.
	 */
	public function test_a_manual_offset_site_gets_its_own_day(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 5.5 );

		// 23:00 on the 3rd and 00:30 on the 4th at UTC+5:30.
		$this->seed( 'Late on the 3rd', '2026-10-03 17:30:00' );
		$this->seed( 'Early on the 4th', '2026-10-03 19:00:00' );
		$this->index->flush_cache();

		$this->assertSame( [ 'Late on the 3rd' ], $this->events( '2026-10-03', '2026-10-03' ) );
	}

	public function test_the_calendar_route_reads_a_date_the_same_way(): void {
		$this->chicago();

		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'start', '2026-10-03' );
		$request->set_param( 'end', '2026-10-03' );
		$request->set_param( 'format', 'json' );

		$titles = wp_list_pluck( ( new CalendarController() )->get_calendar_feed( $request )->get_data(), 'title' );

		$this->assertSame( [ 'Evening of the 3rd' ], $titles );
	}

	/**
	 * The pattern used to let any eight digits through, and the value went to
	 * the database as a datetime that does not exist.
	 */
	public function test_a_date_that_does_not_exist_is_refused(): void {
		$this->chicago();

		$result = $this->events( '2026-02-30', '2026-03-01' );

		$this->assertWPError( $result );
		$this->assertSame( 'blockendar_invalid_datetime', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------------
	// A value with a time
	// -------------------------------------------------------------------------

	/**
	 * Y-m-d H:i:s is UTC, as it always was: only the date-only form changed.
	 */
	public function test_a_datetime_is_still_utc(): void {
		$this->chicago();

		$this->assertSame( [ 'Evening of the 3rd' ], $this->events( '2026-10-04 01:30:00', '2026-10-04 02:00:00' ) );
		$this->assertSame( [], $this->events( '2026-10-03 20:00:00', '2026-10-03 22:00:00' ) );
	}

	public function test_an_iso_value_with_an_offset_is_honoured(): void {
		$this->chicago();

		$this->assertSame(
			[ 'Evening of the 3rd' ],
			$this->events( '2026-10-03T19:00:00-05:00', '2026-10-03T23:00:00-05:00' )
		);
	}
}
