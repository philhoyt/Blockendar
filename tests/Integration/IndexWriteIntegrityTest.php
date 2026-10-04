<?php
/**
 * Integration coverage for how an event's rows are written to the index.
 *
 * An event's rows were deleted and then inserted one statement at a time, with
 * nothing tying the two together. A ten-year daily series was 3,650 inserts,
 * each followed by one more per event type; a failure partway left the event
 * with some of its rows or none.
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
use WP_UnitTestCase;

class IndexWriteIntegrityTest extends WP_UnitTestCase {

	private EventIndex $index;

	private IndexBuilder $builder;

	private RuleRepository $rules;

	/**
	 * Filters this test put on 'query', to take off again.
	 *
	 * @var callable[]
	 */
	private array $query_filters = [];

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();

		$this->index   = new EventIndex();
		$this->builder = new IndexBuilder();
		$this->rules   = new RuleRepository();

		_set_cron_array( [] );

		delete_option( SettingsPage::OPTION_NAME );
		IndexBuilder::forget_dirty();
		$this->index->flush_cache();
	}

	public function tear_down(): void {
		global $wpdb;

		$wpdb->suppress_errors( false );

		foreach ( $this->query_filters as $filter ) {
			remove_filter( 'query', $filter );
		}

		delete_option( SettingsPage::OPTION_NAME );
		IndexBuilder::forget_dirty();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create and index a published event.
	 *
	 * @param array      $meta Meta to override the 9 March 2027, 19:00–21:00 default.
	 * @param array|null $rule Repeat rule, or null for a single event.
	 * @return int Post ID.
	 */
	private function make_event( array $meta = [], ?array $rule = null ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => array_merge(
					[
						'blockendar_start_date' => '2027-03-09',
						'blockendar_end_date'   => '2027-03-09',
						'blockendar_start_time' => '19:00',
						'blockendar_end_time'   => '21:00',
						'blockendar_timezone'   => 'UTC',
					],
					$meta
				),
			]
		);

		if ( null !== $rule ) {
			$this->rules->upsert( $post_id, $rule );
		}

		$this->builder->build_for_post( $post_id );

		return $post_id;
	}

	/**
	 * The event's index rows in start order, read from the table.
	 *
	 * @param int $post_id Post ID.
	 * @return object[]
	 */
	private function rows( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY start_datetime, id', Schema::events_table(), $post_id ) );
	}

	/**
	 * How many junction rows the event's index rows have.
	 *
	 * @param int $post_id Post ID.
	 */
	private function junction_rows( int $post_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i t INNER JOIN %i e ON e.id = t.event_index_id WHERE e.post_id = %d',
				Schema::type_terms_table(),
				Schema::events_table(),
				$post_id
			)
		);
	}

	/**
	 * Make inserts into a table fail from the nth one on, without a database
	 * error: wpdb answers false to an empty query and logs nothing.
	 *
	 * @param string $table Table name.
	 * @param int    $nth   First insert to fail, counting from 1.
	 */
	private function fail_inserts_into( string $table, int $nth ): void {
		$seen   = 0;
		$filter = static function ( $query ) use ( $table, $nth, &$seen ) {
			if ( ! preg_match( '/^\s*INSERT INTO\s+`?' . preg_quote( $table, '/' ) . '`?[\s(]/i', (string) $query ) ) {
				return $query;
			}

			++$seen;

			return $seen >= $nth ? '' : $query;
		};

		$this->query_filters[] = $filter;
		add_filter( 'query', $filter );
	}

	/**
	 * Count the inserts into the events table while a callback runs.
	 *
	 * @param callable $callback What to run.
	 */
	private function count_inserts( callable $callback ): int {
		$count   = 0;
		$table   = Schema::events_table();
		$counter = static function ( $query ) use ( &$count, $table ) {
			if ( preg_match( '/^\s*INSERT INTO\s+`?' . preg_quote( $table, '/' ) . '`?[\s(]/i', (string) $query ) ) {
				++$count;
			}

			return $query;
		};

		add_filter( 'query', $counter );
		$callback();
		remove_filter( 'query', $counter );

		return $count;
	}

	// -------------------------------------------------------------------------
	// Rows written in batches
	// -------------------------------------------------------------------------

	public function test_a_ten_year_daily_series_is_written_in_at_most_eighty_inserts(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 3650 ] );

		$start   = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
		$type    = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_type' ] );
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => [
					'blockendar_start_date' => $start,
					'blockendar_end_date'   => $start,
					'blockendar_start_time' => '09:00',
					'blockendar_end_time'   => '10:00',
					'blockendar_timezone'   => 'UTC',
				],
			]
		);

		wp_set_object_terms( $post_id, [ $type ], 'blockendar_event_type' );
		$this->rules->upsert( $post_id, [ 'frequency' => 'daily' ] );

		$inserts = $this->count_inserts( fn() => $this->builder->build_for_post( $post_id ) );
		$rows    = $this->rows( $post_id );

		$this->assertCount( 3650, $rows );
		$this->assertLessThanOrEqual( 80, $inserts );
		$this->assertSame( 3650, $this->junction_rows( $post_id ), 'Every occurrence is filed under the event type.' );
		$this->assertSame( $start . ' 09:00:00', $rows[0]->start_datetime );
		$this->assertSame( wp_json_encode( [ $type ] ), $rows[3649]->type_term_ids );
	}

	/**
	 * The batched insert builds its own SQL, so the columns that may be empty
	 * have to arrive as NULL and not as an empty string or a zero.
	 */
	public function test_columns_with_no_value_are_stored_as_null(): void {
		$post_id = $this->make_event(
			[],
			[
				'frequency' => 'daily',
				'count'     => 2,
			]
		);
		$row     = $this->rows( $post_id )[0];

		$this->assertNull( $row->venue_term_id );
		$this->assertNull( $row->type_term_ids );
		$this->assertNotNull( $row->recurrence_id );
		$this->assertSame( 'scheduled', $row->status );
		$this->assertSame( 0, $this->junction_rows( $post_id ) );
	}

	public function test_occurrences_added_by_the_nightly_roll_are_filed_under_the_event_type(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 30 ] );

		$start   = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
		$type    = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_type' ] );
		$post_id = $this->make_event(
			[
				'blockendar_start_date' => $start,
				'blockendar_end_date'   => $start,
			]
		);

		wp_set_object_terms( $post_id, [ $type ], 'blockendar_event_type' );
		$this->rules->upsert( $post_id, [ 'frequency' => 'daily' ] );
		$this->builder->build_for_post( $post_id );

		$before = count( $this->rows( $post_id ) );

		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 60 ] );
		( new Generator() )->roll_horizon();

		$after = count( $this->rows( $post_id ) );

		$this->assertGreaterThan( $before, $after );
		$this->assertSame( $after, $this->junction_rows( $post_id ) );
	}

	// -------------------------------------------------------------------------
	// One row per occurrence
	// -------------------------------------------------------------------------

	/**
	 * A date added by hand that the rule produces anyway was indexed twice,
	 * and the event showed twice on that day.
	 */
	public function test_an_added_date_the_rule_already_produces_is_indexed_once(): void {
		$post_id = $this->make_event(
			[],
			[
				'frequency' => 'weekly',
				'count'     => 4,
				'additions' => [ '2027-03-16', '2027-03-18', '2027-03-18' ],
			]
		);

		$dates = array_column( $this->rows( $post_id ), 'start_date' );

		$this->assertSame( [ '2027-03-09', '2027-03-16', '2027-03-18', '2027-03-23', '2027-03-30' ], $dates );
	}

	// -------------------------------------------------------------------------
	// An end before the start
	// -------------------------------------------------------------------------

	/**
	 * Every range query asks for rows that end after the range begins. A row
	 * that ended before it started matched some ranges it was not in and
	 * missed some it was.
	 */
	public function test_an_event_that_ends_before_it_starts_is_indexed_as_ending_when_it_starts(): void {
		$time    = $this->make_event( [ 'blockendar_end_time' => '18:00' ] );
		$date    = $this->make_event( [ 'blockendar_end_date' => '2027-03-07' ] );
		$all_day = $this->make_event(
			[
				'blockendar_end_date' => '2027-03-07',
				'blockendar_all_day'  => '1',
			]
		);

		$row = $this->rows( $time )[0];
		$this->assertSame( '2027-03-09 19:00:00', $row->start_datetime );
		$this->assertSame( '2027-03-09 19:00:00', $row->end_datetime );

		$row = $this->rows( $date )[0];
		$this->assertSame( '2027-03-09 19:00:00', $row->end_datetime );
		$this->assertSame( '2027-03-09', $row->end_date );

		// An all-day event's shortest length is its one day.
		$row = $this->rows( $all_day )[0];
		$this->assertSame( '2027-03-09 00:00:00', $row->start_datetime );
		$this->assertSame( '2027-03-10 00:00:00', $row->end_datetime );
		$this->assertSame( '2027-03-09', $row->end_date );
	}

	public function test_a_recurring_event_that_ends_before_it_starts_gets_the_same_treatment(): void {
		$post_id = $this->make_event(
			[
				'blockendar_end_date' => '2027-03-07',
				'blockendar_end_time' => '18:00',
			],
			[
				'frequency' => 'weekly',
				'count'     => 2,
			]
		);

		$rows = $this->rows( $post_id );

		$this->assertSame( '2027-03-16 19:00:00', $rows[1]->start_datetime );
		$this->assertSame( '2027-03-16 19:00:00', $rows[1]->end_datetime );
		$this->assertSame( '2027-03-16', $rows[1]->end_date );
	}

	// -------------------------------------------------------------------------
	// All of the new rows, or the old ones
	// -------------------------------------------------------------------------

	public function test_a_single_event_keeps_its_row_when_the_new_one_cannot_be_written(): void {
		global $wpdb;

		$post_id = $this->make_event();
		$before  = $this->rows( $post_id );

		update_post_meta( $post_id, 'blockendar_start_date', '2027-04-01' );
		update_post_meta( $post_id, 'blockendar_end_date', '2027-04-01' );

		$wpdb->suppress_errors( true );
		$this->fail_inserts_into( Schema::events_table(), 1 );

		$this->builder->build_for_post( $post_id );

		$this->assertEquals( $before, $this->rows( $post_id ) );
	}

	public function test_a_series_keeps_its_rows_when_a_later_batch_cannot_be_written(): void {
		global $wpdb;

		// 120 occurrences: more than two batches.
		$post_id = $this->make_event(
			[],
			[
				'frequency' => 'daily',
				'count'     => 120,
			]
		);
		$before  = $this->rows( $post_id );

		$this->assertCount( 120, $before, 'Precondition.' );

		// Still three batches, so the first is written before the second fails.
		$this->rules->upsert( $post_id, [ 'count' => 110 ] );

		$wpdb->suppress_errors( true );
		$this->fail_inserts_into( Schema::events_table(), 2 );

		$this->builder->build_for_post( $post_id );

		$this->assertEquals( $before, $this->rows( $post_id ), 'The first batch went in and must have come out again.' );
	}

	/**
	 * The rows that say which event types an occurrence has are written after
	 * the occurrences. If they fail, the occurrences would be in the index but
	 * invisible to every filter by type.
	 */
	public function test_a_series_keeps_its_rows_when_its_event_types_cannot_be_filed(): void {
		global $wpdb;

		$type    = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_type' ] );
		$post_id = $this->make_event(
			[],
			[
				'frequency' => 'weekly',
				'count'     => 4,
			]
		);
		$before  = $this->rows( $post_id );

		wp_set_object_terms( $post_id, [ $type ], 'blockendar_event_type' );

		$wpdb->suppress_errors( true );
		$this->fail_inserts_into( Schema::type_terms_table(), 1 );

		$this->builder->build_for_post( $post_id );

		$this->assertEquals( $before, $this->rows( $post_id ) );
		$this->assertSame( 0, $this->junction_rows( $post_id ) );
	}

	public function test_inserting_one_row_reports_failure_when_its_event_types_cannot_be_filed(): void {
		global $wpdb;

		$post_id = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );

		$wpdb->suppress_errors( true );
		$this->fail_inserts_into( Schema::type_terms_table(), 1 );

		$result = $this->index->insert(
			[
				'post_id'        => $post_id,
				'start_datetime' => '2027-03-09 19:00:00',
				'end_datetime'   => '2027-03-09 21:00:00',
				'start_date'     => '2027-03-09',
				'end_date'       => '2027-03-09',
				'type_term_ids'  => [ 41 ],
			]
		);

		$this->assertFalse( $result );
		$this->assertSame( [], $this->rows( $post_id ), 'A row no type filter can find is not left behind.' );
	}

	// -------------------------------------------------------------------------
	// Deleting a site on a network
	// -------------------------------------------------------------------------

	/**
	 * WordPress drops a deleted site's core tables and asks plugins for theirs.
	 * Without an answer the three tables stayed in the database for good.
	 */
	public function test_a_deleted_sites_tables_are_named_for_dropping(): void {
		global $wpdb;

		$this->assertNotFalse( has_filter( 'wpmu_drop_tables', [ Schema::class, 'tables_to_drop' ] ) );

		$tables = Schema::tables_to_drop( [ 'wp_7_posts' ], 7 );
		$prefix = $wpdb->get_blog_prefix( 7 );

		$this->assertSame(
			[
				'wp_7_posts',
				$prefix . 'blockendar_events',
				$prefix . 'blockendar_event_type_terms',
				$prefix . 'blockendar_recurrence',
			],
			$tables
		);
	}
}
