<?php
/**
 * Integration coverage for the past-mode query plan.
 *
 * Past listings default to ORDER BY start_datetime DESC, which tempts the
 * optimizer onto idx_start_datetime: it can walk that index in sort order and
 * stop at the LIMIT. On a calendar the newest start_datetime values are future
 * or ongoing events, so it scans a long way before finding rows that satisfy
 * `end_datetime <= cutoff`. get_events_in_range() excludes that index on the
 * past branch so idx_visible_past can serve the query instead.
 *
 * These tests assert on the plan rather than on timings — a millisecond figure
 * from a container says nothing durable, but which index the optimizer reaches
 * for does.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use WP_UnitTestCase;

class PastQueryPlanTest extends WP_UnitTestCase {

	/**
	 * UTC cutoff every case in this file treats as "now".
	 */
	private const CUTOFF = '2026-09-23 00:00:00';

	private const WINDOW_START = '2000-01-01 00:00:00';
	private const WINDOW_END   = '2029-09-23 00:00:00';

	private EventIndex $index;

	/**
	 * Post IDs backing the seeded occurrences, rebuilt for every test.
	 *
	 * @var int[]
	 */
	private array $post_ids = [];

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		$this->index = new EventIndex();
		$this->index->flush_cache();

		$this->seed();
	}

	/**
	 * Seed a dataset big enough for the optimizer to make a considered choice.
	 *
	 * A handful of rows is not enough: with a tiny table every plan costs about
	 * the same and the chosen key is arbitrary, which is how an earlier audit
	 * concluded these queries sorted when they do not. The distribution matters
	 * as much as the size — `ongoing` and `hide_from_listings` are what make
	 * rows sort early on start_datetime and then fail the filter, which is the
	 * case idx_visible_past exists for.
	 */
	private function seed(): void {
		global $wpdb;

		$events_table = Schema::events_table();

		// Seeded fresh for every test rather than cached in a static. WP_UnitTestCase
		// runs each test in a transaction and rolls it back, so both the posts and
		// these rows disappear at tear_down. A static list of post IDs would survive
		// that rollback and point at posts that no longer exist, the JOIN would drop
		// every row, and the optimizer would pick a different plan — which is why
		// this file passed alone and failed in the full suite.
		//
		// Other suites also write to this table, so clear it first rather than
		// counting on it being empty.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$events_table}" );

		$this->post_ids = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$this->post_ids[] = self::factory()->post->create(
				[
					'post_type'   => 'blockendar_event',
					'post_status' => 'publish',
					'post_title'  => "Plan event {$i}",
				]
			);
		}

		// 6,000 occurrences spread evenly over six years centred on the cutoff.
		$base  = strtotime( self::CUTOFF ) - ( 3 * YEAR_IN_SECONDS );
		$step  = (int) ( ( 6 * YEAR_IN_SECONDS ) / 6000 );
		$rows  = [];
		$count = 0;

		for ( $n = 0; $n < 6000; $n++ ) {
			$post_id = $this->post_ids[ $n % count( $this->post_ids ) ];
			$start   = $base + ( $n * $step );

			// 50% ongoing, 25% hidden, decorrelated from each other: keying both
			// off the same modulus would make every hidden row an ongoing one,
			// so no past row would ever be hidden and the hide_from_listings
			// filter would never be exercised on this branch.
			$ongoing = ( 0 === $n % 2 ) ? 1 : 0;
			$hidden  = ( 0 === intdiv( $n, 2 ) % 4 ) ? 1 : 0;

			$end      = $ongoing ? EventIndex::ONGOING_END : gmdate( 'Y-m-d H:i:s', $start + HOUR_IN_SECONDS );
			$end_date = $ongoing ? EventIndex::ONGOING_END_DATE : gmdate( 'Y-m-d', $start + HOUR_IN_SECONDS );

			$rows[] = $wpdb->prepare(
				'(%d,%s,%s,%s,%s,0,NULL,%s,NULL,NULL,0,%d,%d)',
				$post_id,
				gmdate( 'Y-m-d H:i:s', $start ),
				$end,
				gmdate( 'Y-m-d', $start ),
				$end_date,
				'scheduled',
				$hidden,
				$ongoing
			);
			++$count;

			if ( count( $rows ) >= 1000 ) {
				$this->insert_rows( $events_table, $rows );
				$rows = [];
			}
		}

		if ( ! empty( $rows ) ) {
			$this->insert_rows( $events_table, $rows );
		}

		// Without fresh statistics the optimizer costs these plans from stale
		// or absent cardinality and the chosen key is not reproducible.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ANALYZE TABLE {$events_table}" );
		$wpdb->query( "ANALYZE TABLE {$wpdb->posts}" );
		// phpcs:enable
	}

	/**
	 * @param string   $table Fully qualified table name.
	 * @param string[] $rows  Prepared VALUES tuples.
	 */
	private function insert_rows( string $table, array $rows ): void {
		global $wpdb;

		// Bulk insert of a fixture: the table name comes from Schema and every
		// value in $rows was built with $wpdb->prepare() by the caller.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"INSERT INTO {$table}
			 (post_id,start_datetime,end_datetime,start_date,end_date,all_day,recurrence_id,status,
			  venue_term_id,type_term_ids,featured,hide_from_listings,ongoing)
			 VALUES " . implode( ',', $rows )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Capture the SELECT that a call to the index layer actually issues.
	 */
	private function capture_query( callable $call ): string {
		$captured = '';

		$recorder = static function ( $query ) use ( &$captured ) {
			$sql = (string) $query;

			if ( '' === $captured && 0 === stripos( ltrim( $sql ), 'SELECT' ) && false !== stripos( $sql, 'blockendar_events' ) ) {
				$captured = $sql;
			}

			return $query;
		};

		add_filter( 'query', $recorder );
		$call();
		remove_filter( 'query', $recorder );

		return $captured;
	}

	/**
	 * EXPLAIN rows for a query, as objects.
	 *
	 * @return object[]
	 */
	private function explain( string $sql ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( 'EXPLAIN ' . $sql );
	}

	/**
	 * Past-mode arguments matching what the events-query block sends.
	 *
	 * @return array<string, mixed>
	 */
	private function past_filters( int $per_page ): array {
		return [
			'ended_before' => self::CUTOFF,
			'per_page'     => $per_page,
			'max_per_page' => 2000,
			'orderby'      => 'start_datetime',
			'order'        => 'DESC',
		];
	}

	public function test_the_past_branch_carries_the_index_hint(): void {
		$sql = $this->capture_query(
			fn () => $this->index->get_events_in_range( self::WINDOW_START, self::CUTOFF, $this->past_filters( 10 ) )
		);

		$this->assertStringContainsString( 'IGNORE INDEX (idx_start_datetime)', $sql );
	}

	public function test_the_upcoming_branch_does_not_carry_the_hint(): void {
		$sql = $this->capture_query(
			fn () => $this->index->get_events_in_range( self::CUTOFF, self::WINDOW_END, [ 'per_page' => 10 ] )
		);

		$this->assertStringNotContainsString( 'IGNORE INDEX', $sql );
	}

	public function test_the_count_query_does_not_carry_the_hint(): void {
		$sql = $this->capture_query(
			fn () => $this->index->count_events_in_range( self::WINDOW_START, self::CUTOFF, [ 'ended_before' => self::CUTOFF ] )
		);

		$this->assertStringNotContainsString( 'IGNORE INDEX', $sql );
	}

	/**
	 * @dataProvider past_limits
	 */
	public function test_past_listings_do_not_fall_back_to_idx_start_datetime( int $per_page ): void {
		$sql = $this->capture_query(
			fn () => $this->index->get_events_in_range( self::WINDOW_START, self::CUTOFF, $this->past_filters( $per_page ) )
		);

		$this->assertNotSame( '', $sql, 'The query should have been captured.' );

		$plan = $this->explain( $sql );
		$this->assertNotEmpty( $plan );

		$events_row = null;
		foreach ( $plan as $row ) {
			if ( isset( $row->table ) && 'e' === $row->table ) {
				$events_row = $row;
				break;
			}
		}

		$this->assertNotNull( $events_row, 'EXPLAIN should include the events table.' );

		$key   = (string) ( $events_row->key ?? '' );
		$extra = (string) ( $events_row->Extra ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// assertTrue with a hand-built message rather than assertSame: this repo
		// ships a doctrine/instantiator that the container's PHP cannot parse,
		// and PHPUnit loads it while rendering a comparison failure, replacing
		// the real message with a ParseError.
		$detail = sprintf(
			'LIMIT %d chose key=%s rows=%s extra=%s',
			$per_page,
			'' === $key ? 'NULL' : $key,
			$events_row->rows ?? '?',
			'' === $extra ? '-' : $extra
		);

		// The one guarantee that holds at every limit and every table size: the
		// optimizer must not fall back to the index that makes it walk backwards
		// through future occurrences. This is the actual regression guard — if
		// the hint is removed, this fails at every limit.
		$this->assertTrue(
			'idx_start_datetime' !== $key,
			"Past listing fell back to idx_start_datetime despite the hint. {$detail}"
		);

		// Which index the optimizer settles on instead is deliberately not
		// asserted. Both composites are legitimate here — this fixture holds
		// 6,000 rows, small enough that idx_visible_start and idx_visible_past
		// cost almost the same, and the winner flips with the table statistics:
		// running this file alone gives idx_visible_past, running it after the
		// rest of the suite gives idx_visible_start. Pinning either would be
		// asserting the state of the fixture, not a property of the schema.
		//
		// That idx_visible_past is the faster plan at production scale is a
		// benchmark result (200k occurrences, 55ms -> 19ms), not something a
		// 6,000-row table can demonstrate.
	}

	/**
	 * @return array<string, int[]>
	 */
	public function past_limits(): array {
		return [
			'limit 10'   => [ 10 ],
			'limit 100'  => [ 100 ],
			'limit 500'  => [ 500 ],
			'limit 2000' => [ 2000 ],
		];
	}

	/**
	 * Run a query shape twice — once as the index layer builds it, once with the
	 * index hint stripped — and return both ID lists.
	 *
	 * An index hint must never change a result set. It only changes how the rows
	 * are found, so this is the assertion that matters most: everything else in
	 * this file is about speed, and speed is not worth a wrong answer.
	 *
	 * @return array{0: int[], 1: int[]}
	 */
	private function ids_with_and_without_hint( string $start, string $end, array $filters ): array {
		global $wpdb;

		$this->index->flush_cache();
		$rows   = $this->index->get_events_in_range( $start, $end, $filters );
		$hinted = array_map( static fn ( $row ) => (int) $row->id, $rows );

		$this->index->flush_cache();
		$sql = $this->capture_query(
			fn () => $this->index->get_events_in_range( $start, $end, $filters )
		);

		$plain = str_replace( 'IGNORE INDEX (idx_start_datetime)', '', $sql );
		$this->assertNotSame( $sql, $plain, 'This helper is only meaningful on a hinted query.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$unhinted = array_map( static fn ( $row ) => (int) $row->id, (array) $wpdb->get_results( $plain ) );

		return [ $hinted, $unhinted ];
	}

	/**
	 * @dataProvider result_equivalence_cases
	 */
	public function test_the_hint_does_not_change_which_rows_come_back( array $extra ): void {
		[ $hinted, $unhinted ] = $this->ids_with_and_without_hint(
			self::WINDOW_START,
			self::CUTOFF,
			array_merge( $this->past_filters( 200 ), $extra )
		);

		$this->assertNotEmpty( $hinted, 'The fixture should produce rows for this case.' );
		$this->assertSame( $unhinted, $hinted );
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function result_equivalence_cases(): array {
		return [
			'default (hidden excluded)' => [ [] ],
			'hidden included'           => [ [ 'hide_hidden' => false ] ],
			'ongoing excluded'          => [ [ 'ongoing' => false ] ],
			'ascending'                 => [ [ 'order' => 'ASC' ] ],
			'second page'               => [ [ 'page' => 2 ] ],
		];
	}

	public function test_past_and_upcoming_do_not_overlap(): void {
		$this->index->flush_cache();
		$past = $this->index->get_events_in_range( self::WINDOW_START, self::CUTOFF, $this->past_filters( 500 ) );

		$this->index->flush_cache();
		$upcoming = $this->index->get_events_in_range(
			self::CUTOFF,
			self::WINDOW_END,
			[
				'per_page'     => 500,
				'max_per_page' => 2000,
			]
		);

		$past_ids     = array_map( static fn ( $row ) => (int) $row->id, $past );
		$upcoming_ids = array_map( static fn ( $row ) => (int) $row->id, $upcoming );

		$this->assertNotEmpty( $past_ids );
		$this->assertNotEmpty( $upcoming_ids );
		$this->assertSame( [], array_values( array_intersect( $past_ids, $upcoming_ids ) ) );

		// Past mode must exclude ongoing events: their end is a far-future
		// sentinel, so an overlap match would otherwise pull every one of them in.
		foreach ( $past as $row ) {
			$this->assertSame( 0, (int) $row->ongoing );
			$this->assertTrue( $row->end_datetime <= self::CUTOFF );
		}
	}

	public function test_the_count_agrees_with_the_number_of_rows_returned(): void {
		$filters = $this->past_filters( 2000 );

		$this->index->flush_cache();
		$rows = $this->index->get_events_in_range( self::WINDOW_START, self::CUTOFF, $filters );

		$this->index->flush_cache();
		$count = $this->index->count_events_in_range( self::WINDOW_START, self::CUTOFF, $filters );

		// The page query carries the hint and the count does not, so this is the
		// check that the two still describe the same set.
		$this->assertSame( $count, count( $rows ) );
	}

	public function test_upcoming_listings_avoid_a_sort(): void {
		$sql = $this->capture_query(
			fn () => $this->index->get_events_in_range(
				self::CUTOFF,
				self::WINDOW_END,
				[
					'per_page' => 500,
					'orderby'  => 'start_datetime',
					'order'    => 'ASC',
				]
			)
		);

		$plan = $this->explain( $sql );

		$extra = '';
		foreach ( $plan as $row ) {
			if ( isset( $row->table ) && 'e' === $row->table ) {
				$extra = (string) ( $row->Extra ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				break;
			}
		}

		// idx_visible_start fixes hide_from_listings and then reads
		// start_datetime in order, so the ORDER BY needs no sort.
		$this->assertStringNotContainsString( 'Using filesort', $extra );
		$this->assertStringNotContainsString( 'Using temporary', $extra );
	}
}
