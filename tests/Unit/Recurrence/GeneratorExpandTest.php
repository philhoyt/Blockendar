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
		// horizon_days option.
		Monkey\Functions\when( 'get_option' )->justReturn( 365 );
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
		$rule  = $this->rule( [ 'frequency' => 'daily', 'count' => 5, 'interval_val' => 1 ] );
		$pairs = $this->gen->expand( $rule, $this->meta() );

		$this->assertCount( 5, $pairs );
	}

	public function test_weekly_until_date_occurrences_within_range(): void {
		$until = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+30 days' )->format( 'Y-m-d' );
		$rule  = $this->rule( [ 'frequency' => 'weekly', 'until_date' => $until, 'interval_val' => 1 ] );
		$pairs = $this->gen->expand( $rule, $this->meta() );

		// 30 days / 7 days = ~4 occurrences (starting today through until_date).
		$this->assertGreaterThanOrEqual( 4, count( $pairs ) );
		$this->assertLessThanOrEqual( 5, count( $pairs ) );

		// Last occurrence must be ≤ until_date.
		$last = end( $pairs );
		$this->assertLessThanOrEqual( $until, $last['start_date'] );
	}

	public function test_monthly_bymonthday_3_months_correct_count(): void {
		$rule  = $this->rule( [ 'frequency' => 'monthly', 'bymonthday' => '13', 'count' => 3, 'interval_val' => 1 ] );
		$pairs = $this->gen->expand( $rule, $this->meta( [ 'start_date' => '2026-03-13', 'end_date' => '2026-03-13' ] ) );

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
		$rule  = $this->rule( [
			'frequency'    => 'daily',
			'until_date'   => '2031-01-01',
			'interval_val' => 1,
		] );
		$pairs = $this->gen->expand( $rule, $this->meta() );

		// Should not generate 5 years of events — horizon stops at ~365 days.
		$this->assertLessThanOrEqual( 367, count( $pairs ) ); // 365 days + tiny buffer for edge
	}

	// -------------------------------------------------------------------------
	// Exceptions
	// -------------------------------------------------------------------------

	public function test_exception_date_skipped(): void {
		$exception = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+7 days' )->format( 'Y-m-d' );
		$rule       = $this->rule( [
			'frequency'    => 'weekly',
			'count'        => 3,
			'interval_val' => 1,
			'exceptions'   => json_encode( [ $exception ] ),
		] );
		$pairs = $this->gen->expand( $rule, $this->meta() );

		$start_dates = array_column( $pairs, 'start_date' );
		$this->assertNotContains( $exception, $start_dates );
	}

	public function test_dates_around_exception_are_present(): void {
		$exception = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+7 days' )->format( 'Y-m-d' );
		$before    = self::TODAY;
		$after     = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+14 days' )->format( 'Y-m-d' );

		$rule  = $this->rule( [
			'frequency'    => 'weekly',
			'count'        => 3,
			'interval_val' => 1,
			'exceptions'   => json_encode( [ $exception ] ),
		] );
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

		$rule  = $this->rule( [ 'frequency' => 'weekly', 'count' => 2, 'interval_val' => 1 ] );
		$pairs = $this->gen->expand( $rule, $this->meta( [ 'start_date' => $start, 'end_date' => $end ] ) );

		foreach ( $pairs as $pair ) {
			$start_dt    = new \DateTimeImmutable( $pair['start_date'] );
			$end_dt      = new \DateTimeImmutable( $pair['end_date'] );
			$duration    = (int) $start_dt->diff( $end_dt )->days;
			$this->assertSame( 2, $duration );
		}
	}

	// -------------------------------------------------------------------------
	// All-day events
	// -------------------------------------------------------------------------

	public function test_all_day_event_end_stored_as_next_day(): void {
		$rule  = $this->rule( [ 'frequency' => 'daily', 'count' => 1, 'interval_val' => 1 ] );
		$pairs = $this->gen->expand(
			$rule,
			$this->meta( [
				'all_day'  => true,
				'end_date' => self::TODAY,
			] )
		);

		$this->assertCount( 1, $pairs );
		// end_date should be the day after start.
		$expected_end = ( new \DateTimeImmutable( self::TODAY ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$this->assertSame( $expected_end, $pairs[0]['end_date'] );
	}

	// -------------------------------------------------------------------------
	// Edge cases
	// -------------------------------------------------------------------------

	public function test_invalid_start_date_returns_empty(): void {
		$rule  = $this->rule( [ 'frequency' => 'daily', 'count' => 5, 'interval_val' => 1 ] );
		$pairs = $this->gen->expand( $rule, $this->meta( [ 'start_date' => 'not-a-date', 'end_date' => 'not-a-date' ] ) );

		$this->assertSame( [], $pairs );
	}
}
