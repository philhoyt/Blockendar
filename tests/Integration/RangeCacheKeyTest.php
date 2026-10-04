<?php
/**
 * Integration coverage for what the range queries' cache keys are made of.
 *
 * The keys were a hash of the filters as the caller happened to write them.
 * The same question asked two ways — a default left out or spelled out, term
 * IDs in another order, the array built in another order — was computed twice,
 * and the total for a listing was computed again for every page and every sort
 * order, none of which can change it.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use WP_UnitTestCase;

class RangeCacheKeyTest extends WP_UnitTestCase {

	private const START = '2027-03-01 00:00:00';
	private const END   = '2027-04-01 00:00:00';

	private EventIndex $index;

	private int $venue;

	private int $type;

	private int $other_type;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		Schema::create_tables();

		$this->index = new EventIndex();

		foreach ( [ Schema::events_table(), Schema::type_terms_table() ] as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		$this->venue      = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_venue' ] );
		$this->type       = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_type' ] );
		$this->other_type = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_type' ] );

		/*
		 * Eight events, each one the only one of its kind, so that every
		 * filter changes the number counted. A fixture where two filters gave
		 * the same total could not tell a shared cache entry from a right one.
		 */
		$this->seed( '2027-03-03' );
		$this->seed( '2027-03-05', [ 'venue_term_id' => $this->venue ] );
		$this->seed( '2027-03-12', [ 'type_term_ids' => [ $this->type ] ] );
		$this->seed( '2027-03-15', [ 'featured' => 1 ] );
		$this->seed( '2027-03-04', [ 'hide_from_listings' => 1 ] );
		$this->seed( '2027-03-20', [ 'status' => 'cancelled' ] );
		$this->seed( '2027-03-25', [ 'type_term_ids' => [ $this->other_type ] ] );
		$this->seed(
			'2027-03-06',
			[
				'ongoing'      => 1,
				'end_datetime' => EventIndex::ONGOING_END,
				'end_date'     => EventIndex::ONGOING_END_DATE,
			]
		);

		$this->index->flush_cache();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Index a published event on one day.
	 *
	 * @param string $date Y-m-d.
	 * @param array  $row  Row fields to override.
	 * @return int Post ID.
	 */
	private function seed( string $date, array $row = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => 'Event on ' . $date,
			]
		);

		$this->index->insert(
			array_merge(
				[
					'post_id'        => $post_id,
					'start_datetime' => $date . ' 19:00:00',
					'end_datetime'   => $date . ' 21:00:00',
					'start_date'     => $date,
					'end_date'       => $date,
				],
				$row
			)
		);

		return $post_id;
	}

	/**
	 * Count the SELECTs that reach the events table while a callback runs.
	 *
	 * @param callable $callback What to run.
	 */
	private function queries( callable $callback ): int {
		$count   = 0;
		$table   = Schema::events_table();
		$counter = static function ( $query ) use ( &$count, $table ) {
			if ( preg_match( '/^\s*SELECT\b.*\bFROM\s+`?' . preg_quote( $table, '/' ) . '`?\s/is', (string) $query ) ) {
				++$count;
			}

			return $query;
		};

		add_filter( 'query', $counter );
		$callback();
		remove_filter( 'query', $counter );

		return $count;
	}

	/**
	 * The total for March under some filters.
	 *
	 * @param array $filters Filters.
	 */
	private function total( array $filters = [] ): int {
		return $this->index->count_events_in_range( self::START, self::END, $filters );
	}

	/**
	 * The rows for March under some filters, as post IDs.
	 *
	 * @param array $filters Filters.
	 * @return int[]
	 */
	private function ids( array $filters = [] ): array {
		return array_map( 'intval', array_column( $this->index->get_events_in_range( self::START, self::END, $filters ), 'post_id' ) );
	}

	// -------------------------------------------------------------------------
	// The total does not depend on how the page is presented
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{array}>
	 */
	public function presentation(): array {
		return [
			'sort column' => [ [ 'orderby' => 'post_title' ] ],
			'sort order'  => [ [ 'order' => 'DESC' ] ],
			'page size'   => [ [ 'per_page' => 3 ] ],
			'size limit'  => [ [ 'max_per_page' => 2000 ] ],
			'page number' => [ [ 'page' => 3 ] ],
			'all of them' => [
				[
					'page'     => 2,
					'order'    => 'DESC',
					'orderby'  => 'end_datetime',
					'per_page' => 5,
				],
			],
		];
	}

	/**
	 * @dataProvider presentation
	 *
	 * @param array $presentation Filters that shape a page and not its total.
	 */
	public function test_the_total_is_counted_once_however_the_page_is_presented( array $presentation ): void {
		$total = $this->total();

		$this->assertSame( 7, $total, 'Precondition: everything but the hidden event.' );

		$queries = $this->queries(
			function () use ( $presentation, $total ) {
				$this->assertSame( $total, $this->total( $presentation ) );
			}
		);

		$this->assertSame( 0, $queries );
	}

	// -------------------------------------------------------------------------
	// The same question, written another way
	// -------------------------------------------------------------------------

	/**
	 * Pairs of filter sets that compile to the same WHERE clause.
	 *
	 * @return array<string, array{callable}>
	 */
	public function equivalents(): array {
		return [
			'keys in another order'       => [
				fn( $t ) => [
					[
						'status'        => 'scheduled',
						'venue_term_id' => $t->venue,
					],
					[
						'venue_term_id' => $t->venue,
						'status'        => 'scheduled',
					],
				],
			],
			'term IDs in another order'   => [
				fn( $t ) => [
					[ 'type_term_id' => [ $t->type, $t->other_type ] ],
					[ 'type_term_id' => [ $t->other_type, $t->type ] ],
				],
			],
			'a term ID given twice'       => [
				fn( $t ) => [
					[ 'type_term_id' => [ $t->type ] ],
					[ 'type_term_id' => [ $t->type, $t->type ] ],
				],
			],
			'a term ID padded with zero'  => [
				fn( $t ) => [
					[ 'venue_term_id' => [ $t->venue ] ],
					[ 'venue_term_id' => [ 0, $t->venue ] ],
				],
			],
			'one term as a number'        => [
				fn( $t ) => [
					[ 'venue_term_id' => [ $t->venue ] ],
					[ 'venue_term_id' => (string) $t->venue ],
				],
			],
			'no terms and no filter'      => [
				fn() => [
					[],
					[ 'exclude_type_term_id' => [] ],
				],
			],
			'not featured and unfiltered' => [
				fn() => [
					[ 'featured' => null ],
					[ 'featured' => false ],
				],
			],
			'a default spelled out'       => [
				fn() => [
					[],
					[
						'hide_hidden' => true,
						'ongoing'     => null,
						'status'      => null,
					],
				],
			],
		];
	}

	/**
	 * @dataProvider equivalents
	 *
	 * @param callable $pair Returns the two filter sets, given the test.
	 */
	public function test_the_same_filters_written_two_ways_are_counted_once( callable $pair ): void {
		[ $first, $second ] = $pair( $this );

		$total   = $this->total( $first );
		$queries = $this->queries(
			function () use ( $second, $total ) {
				$this->assertSame( $total, $this->total( $second ) );
			}
		);

		$this->assertSame( 0, $queries );
	}

	/**
	 * @dataProvider equivalents
	 *
	 * @param callable $pair Returns the two filter sets, given the test.
	 */
	public function test_the_same_filters_written_two_ways_are_fetched_once( callable $pair ): void {
		[ $first, $second ] = $pair( $this );

		$rows    = $this->ids( $first );
		$queries = $this->queries(
			function () use ( $second, $rows ) {
				$this->assertSame( $rows, $this->ids( $second ) );
			}
		);

		$this->assertSame( 0, $queries );
	}

	public function test_a_page_size_over_the_limit_is_the_limit(): void {
		$rows    = $this->ids( [ 'per_page' => 500 ] );
		$queries = $this->queries(
			function () use ( $rows ) {
				$this->assertSame( $rows, $this->ids( [ 'per_page' => 9000 ] ) );
			}
		);

		$this->assertSame( 0, $queries );
	}

	// -------------------------------------------------------------------------
	// Different questions keep different answers
	// -------------------------------------------------------------------------

	/**
	 * Every filter the WHERE clause reads, with the total it gives here.
	 *
	 * This is the guard on the shared cache: a filter that changed the query
	 * and not the key would be handed another filter's total. Each case is
	 * asked after the unfiltered total has been cached, and has to differ.
	 *
	 * @return array<string, array{callable, int}>
	 */
	public function distinguishing(): array {
		return [
			'hidden events included' => [ fn() => [ 'hide_hidden' => false ], 8 ],
			'only ongoing'           => [ fn() => [ 'ongoing' => true ], 1 ],
			'not ongoing'            => [ fn() => [ 'ongoing' => false ], 6 ],
			'featured'               => [ fn() => [ 'featured' => true ], 1 ],
			'cancelled'              => [ fn() => [ 'status' => 'cancelled' ], 1 ],
			'scheduled'              => [ fn() => [ 'status' => 'scheduled' ], 6 ],
			'at a venue'             => [ fn( $t ) => [ 'venue_term_id' => $t->venue ], 1 ],
			'of a type'              => [ fn( $t ) => [ 'type_term_id' => $t->type ], 1 ],
			'of either type'         => [ fn( $t ) => [ 'type_term_id' => [ $t->type, $t->other_type ] ], 2 ],
			'not of a type'          => [ fn( $t ) => [ 'exclude_type_term_id' => $t->type ], 6 ],
			'ended by the tenth'     => [ fn() => [ 'ended_before' => '2027-03-10 00:00:00' ], 2 ],
		];
	}

	/**
	 * @dataProvider distinguishing
	 *
	 * @param callable $filters  Returns the filters, given the test.
	 * @param int      $expected The total they give.
	 */
	public function test_each_filter_keeps_its_own_total( callable $filters, int $expected ): void {
		$this->assertSame( 7, $this->total(), 'The unfiltered total, now cached.' );

		$this->assertSame( $expected, $this->total( $filters( $this ) ) );
	}

	/**
	 * @dataProvider distinguishing
	 *
	 * @param callable $filters  Returns the filters, given the test.
	 * @param int      $expected The total they give.
	 */
	public function test_each_filter_keeps_its_own_rows( callable $filters, int $expected ): void {
		$this->assertCount( 7, $this->ids(), 'The unfiltered rows, now cached.' );

		$this->assertCount( $expected, $this->ids( $filters( $this ) ) );
	}

	/**
	 * The list above has to cover the filters there are. A filter added to the
	 * queries and not to this test would be unguarded.
	 */
	public function test_every_filter_the_queries_read_is_covered_above(): void {
		$covered = [];

		foreach ( $this->distinguishing() as [ $filters ] ) {
			$covered = array_merge( $covered, array_keys( $filters( $this ) ) );
		}

		$this->assertEqualsCanonicalizing( EventIndex::RESULT_FILTERS, array_unique( $covered ) );
	}

	public function test_the_range_is_part_of_the_question(): void {
		$this->assertSame( 7, $this->total() );

		$this->assertSame( 3, $this->index->count_events_in_range( self::START, '2027-03-11 00:00:00' ) );
		$this->assertSame( 4, $this->index->count_events_in_range( '2027-03-14 00:00:00', self::END ) );
	}

	// -------------------------------------------------------------------------
	// Pages stay pages
	// -------------------------------------------------------------------------

	/**
	 * What the total may ignore, the rows may not.
	 */
	public function test_each_page_and_each_order_has_its_own_rows(): void {
		$first  = $this->ids( [ 'per_page' => 3 ] );
		$second = $this->ids(
			[
				'per_page' => 3,
				'page'     => 2,
			]
		);
		$back   = $this->ids(
			[
				'per_page' => 3,
				'order'    => 'DESC',
			]
		);

		$this->assertCount( 3, $first );
		$this->assertCount( 3, $second );
		$this->assertSame( [], array_intersect( $first, $second ) );
		$this->assertNotSame( $first, $back );
		$this->assertCount( 7, $this->ids( [ 'per_page' => 50 ] ) );
	}

	// -------------------------------------------------------------------------
	// A write still clears it
	// -------------------------------------------------------------------------

	public function test_a_new_row_changes_the_cached_total(): void {
		$this->assertSame( 7, $this->total() );

		$this->seed( '2027-03-28' );

		$this->assertSame( 8, $this->total() );
		$this->assertCount( 8, $this->ids() );
	}
}
