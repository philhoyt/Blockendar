<?php
/**
 * Integration coverage for reading a recurrence rule back and saving it again.
 *
 * add_exception() and add_extra_date() load the stored Rule and write it back.
 * That round-trip was lossy: a raw (array) cast produced keys upsert() does not
 * read and array values its sanitizers could not handle, so cancelling a single
 * occurrence silently rewrote the schedule — or threw outright when the rule had
 * an until date. These are the paths behind the cancel and exception REST
 * routes, so the damage happened on an ordinary editorial action.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\Schema;
use Blockendar\Recurrence\Rule;
use Blockendar\Recurrence\RuleRepository;
use WP_UnitTestCase;

class RecurrenceRuleRoundTripTest extends WP_UnitTestCase {

	private RuleRepository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->repo = new RuleRepository();
	}

	/**
	 * Create an event carrying the given recurrence rule.
	 *
	 * @param array $rule Rule data for upsert().
	 * @return int Post ID.
	 */
	private function make_event( array $rule ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => 'Recurring',
			]
		);

		$start = gmdate( 'Y-m-d', strtotime( 'next monday' ) );

		foreach (
			[
				'blockendar_start_date' => $start,
				'blockendar_end_date'   => $start,
				'blockendar_start_time' => '19:00',
				'blockendar_end_time'   => '20:00',
			] as $key => $value
		) {
			update_post_meta( $post_id, $key, $value );
		}

		$this->repo->upsert( $post_id, $rule );

		return $post_id;
	}

	/**
	 * Read the stored rule row.
	 *
	 * @param int $post_id Post ID.
	 */
	private function stored( int $post_id ): array {
		global $wpdb;

		$table = Schema::recurrence_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );
	}

	// -------------------------------------------------------------------------
	// The round-trip preserves the schedule
	// -------------------------------------------------------------------------

	public function test_adding_an_exception_preserves_the_weekdays(): void {
		$post_id = $this->make_event(
			[
				'frequency'    => 'weekly',
				'interval_val' => 1,
				'byday'        => 'MO,WE',
				'count'        => 6,
			]
		);

		$this->repo->add_exception( $post_id, gmdate( 'Y-m-d', strtotime( 'next monday +7 days' ) ) );

		$this->assertSame(
			'MO,WE',
			$this->stored( $post_id )['byday'],
			'Cancelling one occurrence wiped the weekday rule.'
		);
	}

	public function test_adding_an_exception_preserves_the_interval(): void {
		$post_id = $this->make_event(
			[
				'frequency'    => 'weekly',
				'interval_val' => 3,
				'byday'        => 'MO',
				'count'        => 4,
			]
		);

		$this->repo->add_exception( $post_id, gmdate( 'Y-m-d', strtotime( 'next monday +21 days' ) ) );

		$this->assertSame(
			3,
			(int) $this->stored( $post_id )['interval_val'],
			'Every-third-week silently became every week.'
		);
	}

	public function test_adding_an_exception_preserves_bysetpos(): void {
		$post_id = $this->make_event(
			[
				'frequency'    => 'monthly',
				'interval_val' => 1,
				'byday'        => 'TU',
				'bysetpos'     => '2',
				'count'        => 5,
			]
		);

		$this->repo->add_extra_date( $post_id, gmdate( 'Y-m-d', strtotime( '+45 days' ) ) );

		$stored = $this->stored( $post_id );

		$this->assertSame( '2', $stored['bysetpos'] );
		$this->assertSame( 'TU', $stored['byday'] );
	}

	/**
	 * until_date comes back from the database as a DateTimeImmutable, which has
	 * no string cast. Reaching (string) on one threw rather than storing a date.
	 */
	public function test_adding_an_exception_to_a_rule_with_an_until_date_does_not_throw(): void {
		$until = gmdate( 'Y-m-d', strtotime( '+90 days' ) );

		$post_id = $this->make_event(
			[
				'frequency'    => 'weekly',
				'interval_val' => 2,
				'byday'        => 'FR',
				'until_date'   => $until,
			]
		);

		$this->repo->add_exception( $post_id, gmdate( 'Y-m-d', strtotime( 'next friday' ) ) );

		$stored = $this->stored( $post_id );

		$this->assertSame( $until, $stored['until_date'] );
		$this->assertSame( 'FR', $stored['byday'] );
		$this->assertSame( 2, (int) $stored['interval_val'] );
	}

	public function test_the_exception_is_actually_recorded(): void {
		$post_id = $this->make_event(
			[
				'frequency'    => 'weekly',
				'interval_val' => 1,
				'byday'        => 'MO',
				'count'        => 5,
			]
		);

		$date = gmdate( 'Y-m-d', strtotime( 'next monday +7 days' ) );
		$this->repo->add_exception( $post_id, $date );

		$this->assertContains( $date, $this->repo->get( $post_id )->exceptions );
	}

	// -------------------------------------------------------------------------
	// Rule::to_db_array()
	// -------------------------------------------------------------------------

	public function test_to_db_array_round_trips_through_the_rule_constructor(): void {
		$original = new Rule(
			[
				'post_id'      => 42,
				'frequency'    => 'monthly',
				'interval_val' => 4,
				'byday'        => 'MO,TH',
				'bysetpos'     => '1,-1',
				'bymonthday'   => '3,17',
				'until_date'   => '2027-01-31',
				'count'        => 9,
			]
		);

		$rebuilt = new Rule( $original->to_db_array() );

		$this->assertSame( $original->frequency, $rebuilt->frequency );
		$this->assertSame( $original->interval, $rebuilt->interval, 'interval_val key mismatch' );
		$this->assertSame( $original->byday, $rebuilt->byday );
		$this->assertSame( $original->bysetpos, $rebuilt->bysetpos );
		$this->assertSame( $original->bymonthday, $rebuilt->bymonthday );
		$this->assertSame( $original->count, $rebuilt->count );
		$this->assertSame(
			$original->until_date?->format( 'Y-m-d' ),
			$rebuilt->until_date?->format( 'Y-m-d' )
		);
	}

	// -------------------------------------------------------------------------
	// upsert() tolerates the shapes callers actually pass
	// -------------------------------------------------------------------------

	public function test_upsert_accepts_list_fields_as_arrays(): void {
		$post_id = $this->make_event(
			[
				'frequency'    => 'weekly',
				'interval_val' => 1,
				'byday'        => [ 'MO', 'WE' ],
				'bysetpos'     => [ 1, -1 ],
				'count'        => 3,
			]
		);

		$stored = $this->stored( $post_id );

		$this->assertSame( 'MO,WE', $stored['byday'] );
		$this->assertSame( '1,-1', $stored['bysetpos'] );
	}

	public function test_upsert_accepts_a_datetime_until_date(): void {
		$post_id = $this->make_event(
			[
				'frequency'    => 'weekly',
				'interval_val' => 1,
				'byday'        => 'MO',
				'until_date'   => new \DateTimeImmutable( '2027-03-14' ),
			]
		);

		$this->assertSame( '2027-03-14', $this->stored( $post_id )['until_date'] );
	}

	public function test_upsert_accepts_interval_as_well_as_interval_val(): void {
		$post_id = $this->make_event(
			[
				'frequency' => 'weekly',
				'interval'  => 5,
				'byday'     => 'MO',
				'count'     => 3,
			]
		);

		$this->assertSame( 5, (int) $this->stored( $post_id )['interval_val'] );
	}

	public function test_a_junk_weekday_is_still_rejected(): void {
		$post_id = $this->make_event(
			[
				'frequency'    => 'weekly',
				'interval_val' => 1,
				'byday'        => [ 'MO', 'NOPE' ],
				'count'        => 3,
			]
		);

		$this->assertSame( 'MO', $this->stored( $post_id )['byday'] );
	}
}
