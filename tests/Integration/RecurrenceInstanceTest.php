<?php
/**
 * Integration coverage for changes to one occurrence of a recurring event.
 *
 * Cancelling an occurrence wrote "cancelled" to its index row and nowhere
 * else. The index is rebuilt from the event and its rule on every save, so the
 * next save put the occurrence back as scheduled. The cancellation now lives
 * on the rule, where a rebuild can find it.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\SettingsPage;
use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\RuleRepository;
use WP_REST_Request;
use WP_UnitTestCase;

class RecurrenceInstanceTest extends WP_UnitTestCase {

	private EventIndex $index;

	private RuleRepository $rules;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();

		$this->index = new EventIndex();
		$this->rules = new RuleRepository();

		// A rebuild queued by an earlier test would satisfy assertions here.
		_set_cron_array( [] );

		delete_option( SettingsPage::OPTION_NAME );
		delete_option( IndexBuilder::CURSOR_OPTION );
		$this->index->flush_cache();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		delete_option( SettingsPage::OPTION_NAME );
		delete_option( IndexBuilder::CURSOR_OPTION );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create and index a published event.
	 *
	 * @param string     $start_date First day, Y-m-d.
	 * @param array|null $rule       Repeat rule, or null for a single event.
	 * @return int Post ID.
	 */
	private function make_event( string $start_date, ?array $rule ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => [
					'blockendar_start_date' => $start_date,
					'blockendar_end_date'   => $start_date,
					'blockendar_start_time' => '19:00',
					'blockendar_end_time'   => '21:00',
					'blockendar_timezone'   => 'UTC',
				],
			]
		);

		if ( null !== $rule ) {
			$this->rules->upsert( $post_id, $rule );
		}

		( new IndexBuilder() )->build_for_post( $post_id );

		return $post_id;
	}

	/**
	 * A weekly event with four occurrences from 9 March 2027.
	 *
	 * @return int Post ID.
	 */
	private function make_weekly_event(): int {
		return $this->make_event(
			'2027-03-09',
			[
				'frequency' => 'weekly',
				'interval'  => 1,
				'count'     => 4,
			]
		);
	}

	/**
	 * A day some way ahead of today, Y-m-d.
	 *
	 * @param int $days Days from now.
	 */
	private function day( int $days ): string {
		return gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
	}

	/**
	 * Each occurrence's status, keyed by its start date, read from the table.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	private function statuses( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT start_date, status FROM %i WHERE post_id = %d ORDER BY start_datetime', Schema::events_table(), $post_id )
		);

		return array_column( $rows, 'status', 'start_date' );
	}

	/**
	 * Post to one of the per-occurrence routes.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $date    Occurrence date, Y-m-d.
	 * @param string $action  "cancel" or "exception".
	 */
	private function post_instance( int $post_id, string $date, string $action ): \WP_REST_Response {
		return rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', "/blockendar/v1/events/{$post_id}/instances/{$date}/{$action}" )
		);
	}

	/**
	 * What a visitor's query returns for March 2027, through the cache.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string> Status by start date.
	 */
	private function as_queried( int $post_id ): array {
		$rows = $this->index->get_events_in_range( '2027-03-01 00:00:00', '2027-04-30 00:00:00' );
		$rows = array_filter( $rows, static fn( $row ) => (int) $row->post_id === $post_id );

		return array_column( $rows, 'status', 'start_date' );
	}

	// -------------------------------------------------------------------------
	// A cancellation that lasts
	// -------------------------------------------------------------------------

	public function test_a_cancelled_occurrence_is_still_cancelled_after_the_event_is_saved(): void {
		$post_id = $this->make_weekly_event();

		$this->assertSame( 200, $this->post_instance( $post_id, '2027-03-16', 'cancel' )->get_status() );
		$this->assertSame( 'cancelled', $this->statuses( $post_id )['2027-03-16'], 'Precondition: the route cancelled it.' );

		// What every save of the event does.
		( new IndexBuilder() )->build_for_post( $post_id );

		$this->assertSame(
			[
				'2027-03-09' => 'scheduled',
				'2027-03-16' => 'cancelled',
				'2027-03-23' => 'scheduled',
				'2027-03-30' => 'scheduled',
			],
			$this->statuses( $post_id )
		);
	}

	public function test_a_cancelled_occurrence_is_still_cancelled_after_a_full_rebuild(): void {
		$post_id = $this->make_weekly_event();

		$this->post_instance( $post_id, '2027-03-16', 'cancel' );

		( new IndexBuilder() )->rebuild_all();

		$this->assertSame( 'cancelled', $this->statuses( $post_id )['2027-03-16'] );
		$this->assertSame( 'scheduled', $this->statuses( $post_id )['2027-03-23'] );
	}

	/**
	 * The occurrence may not be in the index yet. The nightly roll adds rows as
	 * they come inside the horizon, and has to add this one cancelled.
	 */
	public function test_an_occurrence_cancelled_ahead_of_the_horizon_arrives_cancelled(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 30 ] );

		$post_id = $this->make_event( $this->day( 1 ), [ 'frequency' => 'daily' ] );
		$ahead   = $this->day( 45 );

		$this->assertArrayNotHasKey( $ahead, $this->statuses( $post_id ), 'Precondition: beyond the horizon.' );

		$this->post_instance( $post_id, $ahead, 'cancel' );

		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 60 ] );
		( new Generator() )->roll_horizon();

		$statuses = $this->statuses( $post_id );

		$this->assertSame( 'cancelled', $statuses[ $ahead ] );
		$this->assertSame( 'scheduled', $statuses[ $this->day( 46 ) ] );
	}

	public function test_the_rule_says_which_occurrences_are_cancelled(): void {
		$post_id = $this->make_weekly_event();

		$this->post_instance( $post_id, '2027-03-16', 'cancel' );
		$this->post_instance( $post_id, '2027-03-16', 'cancel' );

		$this->assertSame( [ '2027-03-16' ], $this->rules->get( $post_id )->cancellations, 'Cancelling twice records it once.' );

		$event = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/blockendar/v1/events/{$post_id}" ) )->get_data();

		$this->assertSame( [ '2027-03-16' ], $event['recurrence']['cancellations'] );
	}

	/**
	 * The recurrence route and the editor both write a rule without naming its
	 * date lists. That must not clear the cancellations.
	 */
	public function test_changing_the_schedule_keeps_the_cancellations(): void {
		$post_id = $this->make_weekly_event();

		$this->post_instance( $post_id, '2027-03-16', 'cancel' );

		$this->rules->upsert(
			$post_id,
			[
				'frequency' => 'weekly',
				'count'     => 6,
			]
		);
		( new IndexBuilder() )->build_for_post( $post_id );

		$statuses = $this->statuses( $post_id );

		$this->assertCount( 6, $statuses );
		$this->assertSame( 'cancelled', $statuses['2027-03-16'] );
	}

	/**
	 * A single event has no rule to hold the date. Its own status field is the
	 * way to cancel it, and the route says so instead of writing to a row the
	 * next save replaces.
	 */
	public function test_cancelling_an_occurrence_of_a_single_event_is_refused(): void {
		$post_id = $this->make_event( '2027-03-09', null );

		$response = $this->post_instance( $post_id, '2027-03-09', 'cancel' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'blockendar_not_recurring', $response->get_data()['code'] );
		$this->assertSame( 'scheduled', $this->statuses( $post_id )['2027-03-09'] );
	}

	// -------------------------------------------------------------------------
	// Readers see the change
	// -------------------------------------------------------------------------

	/**
	 * Both routes wrote to the table directly and left the read cache alone. On
	 * a site with a persistent object cache the calendar kept serving the
	 * occurrence as it was.
	 */
	public function test_a_cancellation_reaches_a_query_that_was_already_cached(): void {
		$post_id = $this->make_weekly_event();

		$this->assertSame( 'scheduled', $this->as_queried( $post_id )['2027-03-16'], 'Precondition, and the cached read.' );

		$this->post_instance( $post_id, '2027-03-16', 'cancel' );

		$this->assertSame( 'cancelled', $this->as_queried( $post_id )['2027-03-16'] );
	}

	public function test_a_skipped_date_leaves_a_query_that_was_already_cached(): void {
		$post_id = $this->make_weekly_event();

		$this->assertArrayHasKey( '2027-03-16', $this->as_queried( $post_id ), 'Precondition, and the cached read.' );

		$this->assertSame( 200, $this->post_instance( $post_id, '2027-03-16', 'exception' )->get_status() );

		$this->assertArrayNotHasKey( '2027-03-16', $this->as_queried( $post_id ) );
	}

	// -------------------------------------------------------------------------
	// The nightly roll and a date added by hand
	// -------------------------------------------------------------------------

	/**
	 * The roll adds what falls after the last row the event has. A date added
	 * by hand three years out was that last row, so nothing the rule produced
	 * was ever after it and the series stopped growing.
	 */
	public function test_a_far_off_added_date_does_not_stop_the_series_growing(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 30 ] );

		$post_id = $this->make_event( $this->day( 1 ), [ 'frequency' => 'daily' ] );
		$far_off = $this->day( 3 * 365 );

		$this->rules->add_extra_date( $post_id, $far_off );
		( new IndexBuilder() )->build_for_post( $post_id );

		$before = $this->statuses( $post_id );

		$this->assertArrayHasKey( $far_off, $before, 'Precondition: the added date is indexed.' );
		$this->assertArrayNotHasKey( $this->day( 45 ), $before, 'Precondition: beyond the horizon.' );

		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 60 ] );
		$generator = new Generator();
		$generator->roll_horizon();

		$after = $this->statuses( $post_id );

		$this->assertArrayHasKey( $this->day( 45 ), $after );
		$this->assertGreaterThan( count( $before ), count( $after ) );

		// And a second roll adds nothing: the added date is not written again.
		$generator->roll_horizon();

		$this->assertSame( count( $after ), count( $this->statuses( $post_id ) ) );
	}

	/**
	 * The roll decides what is new by date. An event with no timezone of its
	 * own takes the site's, so after the site's timezone changes every start
	 * it works out is at a new instant; told apart by instant, the whole
	 * series looked new and was written a second time.
	 */
	public function test_a_change_of_site_timezone_does_not_make_the_roll_write_the_series_again(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 30 ] );
		update_option( 'timezone_string', 'UTC' );

		$post_id = $this->make_event( $this->day( 1 ), [ 'frequency' => 'daily' ] );

		delete_post_meta( $post_id, 'blockendar_timezone' );
		( new IndexBuilder() )->build_for_post( $post_id );

		$before = count( $this->statuses( $post_id ) );

		update_option( 'timezone_string', 'America/Chicago' );
		( new Generator() )->roll_horizon();

		$this->assertSame( $before, count( $this->statuses( $post_id ) ), 'One row per day, as before.' );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d', Schema::events_table(), $post_id ) );

		delete_option( 'timezone_string' );

		$this->assertSame( $before, $total );
	}

	// -------------------------------------------------------------------------
	// Cancellations made before they were kept on the rule
	// -------------------------------------------------------------------------

	/**
	 * Until now a cancellation existed only as the status of an index row. The
	 * upgrade that adds the column also rebuilds the index, from rules that
	 * know nothing of those cancellations. They are copied to the rules first.
	 */
	public function test_cancellations_that_exist_only_in_the_index_are_moved_to_the_rule(): void {
		global $wpdb;

		$series    = $this->make_weekly_event();
		$cancelled = $this->make_weekly_event();
		$single    = $this->make_event( '2027-03-09', null );

		// As the old route left things: the row says cancelled, the rule nothing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'cancelled' WHERE post_id = %d AND start_date = %s", Schema::events_table(), $series, '2027-03-16' ) );

		// A series cancelled as a whole has every row cancelled, and no single
		// occurrence of it was.
		update_post_meta( $cancelled, 'blockendar_status', 'cancelled' );
		( new IndexBuilder() )->build_for_post( $cancelled );

		update_post_meta( $single, 'blockendar_status', 'cancelled' );
		( new IndexBuilder() )->build_for_post( $single );

		$this->assertSame( 1, $this->rules->adopt_index_cancellations() );

		$this->assertSame( [ '2027-03-16' ], $this->rules->get( $series )->cancellations );
		$this->assertSame( [], $this->rules->get( $cancelled )->cancellations );

		( new IndexBuilder() )->rebuild_all();

		$this->assertSame( 'cancelled', $this->statuses( $series )['2027-03-16'] );
		$this->assertSame( 'scheduled', $this->statuses( $series )['2027-03-23'] );
	}
}
