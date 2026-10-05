<?php
/**
 * Unit tests for Generator date-expansion logic.
 *
 * Uses a StubGenerator that overrides WP-dependent methods and exposes
 * expand_dates() for direct testing.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Unit\Recurrence;

use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\Rule;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Strips WP I/O from Generator so expand_dates() can run in isolation.
 */
class StubGenerator extends Generator {

	/** @var array Captured insert calls [ ['start_date', 'end_date', ...], ... ] */
	public array $inserted = [];

	public function __construct() {
		// Skip parent constructor to avoid instantiating EventIndex (needs wpdb).
	}

	/**
	 * Expose expand_dates() publicly for testing.
	 */
	public function expand( Rule $rule, array $meta ): array {
		$ref = new \ReflectionMethod( Generator::class, 'expand_dates' );
		return $ref->invoke( $this, $rule, $meta );
	}
}

class GeneratorExpandTest extends TestCase {

	private StubGenerator $gen;

	/** Absolute "today" for all tests: 2026-03-13. */
	private const TODAY = '2026-03-13';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Stub WP functions used inside build_date_pair and expand_dates.
		Monkey\Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		/*
		 * Key-aware on purpose. A blanket justReturn() here answers every
		 * option name identically, so a test would pass whether the code read
		 * the right key or a name nothing ever writes — which is exactly how
		 * the horizon setting stayed unwired while these tests stayed green.
		 * Returning the settings array only for its real name means the
		 * SettingsPage::get() path is genuinely exercised.
		 */
		Monkey\Functions\when( 'get_option' )->alias(
			static function ( string $name, $default_value = false ) {
				if ( 'blockendar_settings' === $name ) {
					return [
						'horizon_days'  => 365,
						'max_instances' => 3650,
					];
				}

				return $default_value;
			}
		);
		$this->gen = new StubGenerator();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function rule( array $data ): Rule {
		return new Rule( $data );
	}

	private function meta( array $overrides = [] ): array {
		return array_merge(
			[
				'start_date' => self::TODAY,
				'end_date'   => self::TODAY,
				'start_time' => '09:00',
				'end_time'   => '10:00',
				'all_day'    => false,
				'timezone'   => 'UTC',
				'status'     => 'scheduled',
			],
			$overrides
		);
	}

	// -------------------------------------------------------------------------
	// Basic occurrence counts
	// -------------------------------------------------------------------------

	public function test_daily_count_5_produces_exactly_5_occurrences(): void {
		$rule  = $this->rule(
			[
				'frequency'    => 'daily',
				'count'        => 5,
				'interval_val' => 1,
			]
		);
		$pairs = $this->gen->expand( $rule, $this->meta() );

		$this->assertCount( 5, $pairs );
	}

	public function test_weekly_until_date_occurrences_within_range(): void {
		$until = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+30 days' )->format( 'Y-m-d' );
		$rule  = $this->rule(
			[
				'frequency'    => 'weekly',
				'until_date'   => $until,
				'interval_val' => 1,
			]
		);
		$pairs = $this->gen->expand( $rule, $this->meta() );

		// 30 days / 7 days = ~4 occurrences (starting today through until_date).
		$this->assertGreaterThanOrEqual( 4, count( $pairs ) );
		$this->assertLessThanOrEqual( 5, count( $pairs ) );

		// Last occurrence must be ≤ until_date.
		$last = end( $pairs );
		$this->assertLessThanOrEqual( $until, $last['start_date'] );
	}

	public function test_monthly_bymonthday_3_months_correct_count(): void {
		$rule  = $this->rule(
			[
				'frequency'    => 'monthly',
				'bymonthday'   => '13',
				'count'        => 3,
				'interval_val' => 1,
			]
		);
		$pairs = $this->gen->expand(
			$rule,
			$this->meta(
				[
					'start_date' => '2026-03-13',
					'end_date'   => '2026-03-13',
				]
			)
		);

		$this->assertCount( 3, $pairs );

		// Each occurrence must be on the 13th.
		foreach ( $pairs as $pair ) {
			$this->assertSame( '13', ( new \DateTimeImmutable( $pair['start_date'] ) )->format( 'j' ) );
		}
	}

	// -------------------------------------------------------------------------
	// Horizon cutoff
	// -------------------------------------------------------------------------

	public function test_horizon_caps_occurrences_before_until_date(): void {
		// Until date is far in the future (5 years), horizon is 365 days.
		$rule = $this->rule(
			[
				'frequency'    => 'daily',
				'until_date'   => '2031-01-01',
				'interval_val' => 1,
			]
		);
		// Pin the clock to the same day the event starts, so the horizon is exactly
		// 365 days wide regardless of when this test runs.
		$this->gen->set_now( new \DateTimeImmutable( self::TODAY, new \DateTimeZone( 'UTC' ) ) );

		$pairs = $this->gen->expand( $rule, $this->meta() );

		// Should not generate 5 years of events — generation stops at the horizon.
		$this->assertLessThanOrEqual( 367, count( $pairs ) ); // 365 days + buffer for edges.
		$this->assertGreaterThanOrEqual( 365, count( $pairs ) );

		// And the last occurrence must fall on or before the horizon date.
		$horizon = ( new \DateTimeImmutable( self::TODAY, new \DateTimeZone( 'UTC' ) ) )
			->modify( '+365 days' );
		$last    = end( $pairs );
		$this->assertLessThanOrEqual( $horizon->format( 'Y-m-d' ), $last['start_date'] );
	}

	public function test_horizon_is_anchored_to_the_injected_clock(): void {
		$rule = $this->rule(
			[
				'frequency'    => 'daily',
				'until_date'   => '2031-01-01',
				'interval_val' => 1,
			]
		);

		// Advancing the clock 100 days past the event start widens the window by
		// exactly 100 occurrences. This is the drift that previously broke the
		// test above when it relied on the real wall clock.
		$this->gen->set_now(
			( new \DateTimeImmutable( self::TODAY, new \DateTimeZone( 'UTC' ) ) )->modify( '+100 days' )
		);

		$pairs = $this->gen->expand( $rule, $this->meta() );

		$this->assertLessThanOrEqual( 467, count( $pairs ) );
		$this->assertGreaterThanOrEqual( 465, count( $pairs ) );
	}

	// -------------------------------------------------------------------------
	// Exceptions
	// -------------------------------------------------------------------------

	public function test_exception_date_skipped(): void {
		$exception = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+7 days' )->format( 'Y-m-d' );
		$rule      = $this->rule(
			[
				'frequency'    => 'weekly',
				'count'        => 3,
				'interval_val' => 1,
				'exceptions'   => json_encode( [ $exception ] ),
			]
		);
		$pairs     = $this->gen->expand( $rule, $this->meta() );

		$start_dates = array_column( $pairs, 'start_date' );
		$this->assertNotContains( $exception, $start_dates );
	}

	/**
	 * "After N times" is N dates of the rule. One of them skipped leaves N - 1;
	 * it is not replaced by another at the end. (RFC 5545: an excluded date is
	 * removed from the set the count produced.)
	 */
	public function test_a_skipped_date_counts_towards_the_number_of_occurrences(): void {
		$exception = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+7 days' )->format( 'Y-m-d' );
		$rule      = $this->rule(
			[
				'frequency'    => 'weekly',
				'count'        => 3,
				'interval_val' => 1,
				'exceptions'   => json_encode( [ $exception ] ),
			]
		);

		$start_dates = array_column( $this->gen->expand( $rule, $this->meta() ), 'start_date' );

		$this->assertSame(
			[
				self::TODAY,
				( new \DateTimeImmutable( self::TODAY ) )->modify( '+14 days' )->format( 'Y-m-d' ),
			],
			$start_dates
		);
	}

	/**
	 * The cap on rows is a different limit from the rule's count, and skipped
	 * dates do not use it up: it bounds what is written, and they are not.
	 */
	public function test_skipped_dates_do_not_count_towards_the_row_cap(): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static function ( string $name, $default_value = false ) {
				return 'blockendar_settings' === $name
					? [
						'horizon_days'  => 365,
						'max_instances' => 3,
					]
					: $default_value;
			}
		);

		$exception = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$rule      = $this->rule(
			[
				'frequency'    => 'daily',
				'interval_val' => 1,
				'exceptions'   => json_encode( [ $exception ] ),
			]
		);

		$this->assertCount( 3, $this->gen->expand( $rule, $this->meta() ) );
	}

	public function test_dates_around_exception_are_present(): void {
		$exception = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+7 days' )->format( 'Y-m-d' );
		$before    = self::TODAY;
		$after     = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+14 days' )->format( 'Y-m-d' );

		$rule  = $this->rule(
			[
				'frequency'    => 'weekly',
				'count'        => 3,
				'interval_val' => 1,
				'exceptions'   => json_encode( [ $exception ] ),
			]
		);
		$pairs = $this->gen->expand( $rule, $this->meta() );

		$start_dates = array_column( $pairs, 'start_date' );
		$this->assertContains( $before, $start_dates );
		$this->assertContains( $after, $start_dates );
	}

	// -------------------------------------------------------------------------
	// Multi-day events
	// -------------------------------------------------------------------------

	public function test_multi_day_event_each_instance_spans_correct_duration(): void {
		$start = self::TODAY;
		$end   = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+2 days' )->format( 'Y-m-d' );

		$rule  = $this->rule(
			[
				'frequency'    => 'weekly',
				'count'        => 2,
				'interval_val' => 1,
			]
		);
		$pairs = $this->gen->expand(
			$rule,
			$this->meta(
				[
					'start_date' => $start,
					'end_date'   => $end,
				]
			)
		);

		foreach ( $pairs as $pair ) {
			$start_dt = new \DateTimeImmutable( $pair['start_date'] );
			$end_dt   = new \DateTimeImmutable( $pair['end_date'] );
			$duration = (int) $start_dt->diff( $end_dt )->days;
			$this->assertSame( 2, $duration );
		}
	}

	// -------------------------------------------------------------------------
	// All-day events
	// -------------------------------------------------------------------------

	/**
	 * end_date is the event's last day, as it is for a single event; the
	 * instant it stops, midnight after that day, is end_utc. This used to
	 * assert that end_date was the day after — the generator stored it that
	 * way, and every reader of the column then ran the event a day long.
	 */
	public function test_all_day_event_ends_on_its_last_day_and_stops_at_the_next_midnight(): void {
		$rule  = $this->rule(
			[
				'frequency'    => 'daily',
				'count'        => 1,
				'interval_val' => 1,
			]
		);
		$pairs = $this->gen->expand(
			$rule,
			$this->meta(
				[
					'all_day'  => true,
					'end_date' => self::TODAY,
				]
			)
		);

		$this->assertCount( 1, $pairs );
		$this->assertSame( self::TODAY, $pairs[0]['start_date'] );
		$this->assertSame( self::TODAY, $pairs[0]['end_date'] );

		$next_day = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$this->assertSame( self::TODAY . ' 00:00:00', $pairs[0]['start_utc'] );
		$this->assertSame( $next_day . ' 00:00:00', $pairs[0]['end_utc'] );
	}

	// -------------------------------------------------------------------------
	// Edge cases
	// -------------------------------------------------------------------------

	public function test_invalid_start_date_returns_empty(): void {
		$rule  = $this->rule(
			[
				'frequency'    => 'daily',
				'count'        => 5,
				'interval_val' => 1,
			]
		);
		$pairs = $this->gen->expand(
			$rule,
			$this->meta(
				[
					'start_date' => 'not-a-date',
					'end_date'   => 'not-a-date',
				]
			)
		);

		$this->assertSame( [], $pairs );
	}
}
