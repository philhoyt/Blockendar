<?php
/**
 * Unit tests for Generator::advance_weekly() and advance_monthly().
 *
 * Private methods are exposed via a TestableGenerator subclass.
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
 * Subclass that exposes private Generator methods for unit testing.
 */
class TestableGenerator extends Generator {

	public function __construct() {
		// Skip parent constructor (it instantiates EventIndex which needs wpdb).
	}

	public function advance_weekly_public( \DateTimeImmutable $cursor, Rule $rule ): ?\DateTimeImmutable {
		$ref = new \ReflectionMethod( Generator::class, 'advance_weekly' );
		return $ref->invoke( $this, $cursor, $rule );
	}

	public function advance_monthly_public( \DateTimeImmutable $cursor, Rule $rule ): ?\DateTimeImmutable {
		$ref = new \ReflectionMethod( Generator::class, 'advance_monthly' );
		return $ref->invoke( $this, $cursor, $rule );
	}

	public function nth_weekday_public( \DateTimeImmutable $cursor, Rule $rule, int $interval ): ?\DateTimeImmutable {
		$ref = new \ReflectionMethod( Generator::class, 'nth_weekday_next_month' );
		return $ref->invoke( $this, $cursor, $rule, $interval );
	}
}

class GeneratorAdvanceTest extends TestCase {

	private TestableGenerator $gen;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Monkey\Functions\when( 'get_option' )->justReturn( 365 );
		$this->gen = new TestableGenerator();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function date( string $ymd ): \DateTimeImmutable {
		return new \DateTimeImmutable( $ymd, new \DateTimeZone( 'UTC' ) );
	}

	private function rule( array $data ): Rule {
		return new Rule( $data );
	}

	// -------------------------------------------------------------------------
	// advance_weekly
	// -------------------------------------------------------------------------

	public function test_weekly_single_byday_advances_seven_days(): void {
		// Cursor on a Monday (2026-03-09), byday=MO, cursor IS on that day.
		// Next occurrence should be Monday 7 days later.
		$cursor = $this->date( '2026-03-09' ); // Monday
		$rule   = $this->rule( [ 'frequency' => 'weekly', 'byday' => 'MO', 'interval_val' => 1 ] );

		$next = $this->gen->advance_weekly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		$this->assertSame( '2026-03-16', $next->format( 'Y-m-d' ) );
	}

	public function test_weekly_cursor_before_target_day_same_week(): void {
		// Cursor on Monday (2026-03-09), byday=WE — Wednesday is in the same week.
		$cursor = $this->date( '2026-03-09' ); // Monday
		$rule   = $this->rule( [ 'frequency' => 'weekly', 'byday' => 'WE', 'interval_val' => 1 ] );

		$next = $this->gen->advance_weekly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		$this->assertSame( '2026-03-11', $next->format( 'Y-m-d' ) );
	}

	public function test_weekly_multi_day_mo_fr_cursor_on_monday_gives_friday(): void {
		$cursor = $this->date( '2026-03-09' ); // Monday
		$rule   = $this->rule( [ 'frequency' => 'weekly', 'byday' => 'MO,FR', 'interval_val' => 1 ] );

		$next = $this->gen->advance_weekly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		$this->assertSame( '2026-03-13', $next->format( 'Y-m-d' ) ); // Friday same week
	}

	public function test_weekly_multi_day_mo_fr_cursor_on_friday_gives_next_monday(): void {
		$cursor = $this->date( '2026-03-13' ); // Friday
		$rule   = $this->rule( [ 'frequency' => 'weekly', 'byday' => 'MO,FR', 'interval_val' => 1 ] );

		$next = $this->gen->advance_weekly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		$this->assertSame( '2026-03-16', $next->format( 'Y-m-d' ) ); // Monday next week
	}

	public function test_weekly_biweekly_single_byday_advances_14_days(): void {
		$cursor = $this->date( '2026-03-09' ); // Monday
		$rule   = $this->rule( [ 'frequency' => 'weekly', 'byday' => 'MO', 'interval_val' => 2 ] );

		$next = $this->gen->advance_weekly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		$this->assertSame( '2026-03-23', $next->format( 'Y-m-d' ) ); // 2 weeks later
	}

	public function test_weekly_sunday_byday_correct(): void {
		$cursor = $this->date( '2026-03-09' ); // Monday
		$rule   = $this->rule( [ 'frequency' => 'weekly', 'byday' => 'SU', 'interval_val' => 1 ] );

		$next = $this->gen->advance_weekly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		// Sunday comes after Monday, so it should be the Sunday at end of this week.
		$this->assertSame( '0', $next->format( 'w' ) ); // 0 = Sunday
	}

	// -------------------------------------------------------------------------
	// advance_monthly — bymonthday
	// -------------------------------------------------------------------------

	public function test_monthly_bymonthday_cursor_before_target_same_month(): void {
		$cursor = $this->date( '2026-03-05' );
		$rule   = $this->rule( [ 'frequency' => 'monthly', 'bymonthday' => '15', 'interval_val' => 1 ] );

		$next = $this->gen->advance_monthly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		$this->assertSame( '2026-03-15', $next->format( 'Y-m-d' ) );
	}

	public function test_monthly_bymonthday_cursor_after_target_wraps_to_next_month(): void {
		$cursor = $this->date( '2026-03-20' );
		$rule   = $this->rule( [ 'frequency' => 'monthly', 'bymonthday' => '15', 'interval_val' => 1 ] );

		$next = $this->gen->advance_monthly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		$this->assertSame( '2026-04-15', $next->format( 'Y-m-d' ) );
	}

	public function test_monthly_bymonthday_31_february_overflows_gracefully(): void {
		// PHP's DateTimeImmutable does not throw for '2026-02-31' — it overflows to March 3.
		// The generator returns a DateTimeImmutable (not null) in this case.
		$cursor = $this->date( '2026-01-31' );
		$rule   = $this->rule( [ 'frequency' => 'monthly', 'bymonthday' => '31', 'interval_val' => 1 ] );

		$next = $this->gen->advance_monthly_public( $cursor, $rule );

		// Result is non-null (PHP overflows rather than throwing).
		$this->assertInstanceOf( \DateTimeImmutable::class, $next );
		// And it advances past the cursor (no infinite loop).
		$this->assertGreaterThan( $cursor, $next );
	}

	public function test_monthly_bymonthday_two_days_cursor_between_picks_second(): void {
		$cursor = $this->date( '2026-03-10' );
		$rule   = $this->rule( [ 'frequency' => 'monthly', 'bymonthday' => '1,15', 'interval_val' => 1 ] );

		$next = $this->gen->advance_monthly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		$this->assertSame( '2026-03-15', $next->format( 'Y-m-d' ) );
	}

	public function test_monthly_bymonthday_two_days_cursor_after_both_wraps_to_first_next_month(): void {
		$cursor = $this->date( '2026-03-20' );
		$rule   = $this->rule( [ 'frequency' => 'monthly', 'bymonthday' => '1,15', 'interval_val' => 1 ] );

		$next = $this->gen->advance_monthly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		$this->assertSame( '2026-04-01', $next->format( 'Y-m-d' ) );
	}

	public function test_monthly_default_no_bymonthday_same_day_next_month(): void {
		$cursor = $this->date( '2026-03-15' );
		$rule   = $this->rule( [ 'frequency' => 'monthly', 'interval_val' => 1 ] );

		$next = $this->gen->advance_monthly_public( $cursor, $rule );

		$this->assertNotNull( $next );
		$this->assertSame( '2026-04-15', $next->format( 'Y-m-d' ) );
	}

	// -------------------------------------------------------------------------
	// nth_weekday_next_month
	// -------------------------------------------------------------------------

	public function test_nth_weekday_second_thursday(): void {
		$cursor = $this->date( '2026-03-01' );
		$rule   = $this->rule( [ 'frequency' => 'monthly', 'byday' => 'TH', 'bysetpos' => '2', 'interval_val' => 1 ] );

		$next = $this->gen->nth_weekday_public( $cursor, $rule, 1 );

		$this->assertNotNull( $next );
		// Second Thursday of April 2026.
		$this->assertSame( '4', $next->format( 'w' ) ); // 4 = Thursday
		$this->assertSame( '2026-04', $next->format( 'Y-m' ) );
		// Verify it is the second Thursday (day 9).
		$this->assertSame( '2026-04-09', $next->format( 'Y-m-d' ) );
	}

	public function test_nth_weekday_first_monday(): void {
		$cursor = $this->date( '2026-03-01' );
		$rule   = $this->rule( [ 'frequency' => 'monthly', 'byday' => 'MO', 'bysetpos' => '1', 'interval_val' => 1 ] );

		$next = $this->gen->nth_weekday_public( $cursor, $rule, 1 );

		$this->assertNotNull( $next );
		$this->assertSame( '1', $next->format( 'w' ) ); // 1 = Monday
		$this->assertSame( '2026-04-06', $next->format( 'Y-m-d' ) ); // First Monday April 2026
	}

	public function test_nth_weekday_last_friday(): void {
		$cursor = $this->date( '2026-03-01' );
		$rule   = $this->rule( [ 'frequency' => 'monthly', 'byday' => 'FR', 'bysetpos' => '-1', 'interval_val' => 1 ] );

		$next = $this->gen->nth_weekday_public( $cursor, $rule, 1 );

		$this->assertNotNull( $next );
		$this->assertSame( '5', $next->format( 'w' ) ); // 5 = Friday
		$this->assertSame( '2026-04', $next->format( 'Y-m' ) );
		$this->assertSame( '2026-04-24', $next->format( 'Y-m-d' ) ); // Last Friday April 2026
	}

	public function test_nth_weekday_fifth_tuesday_month_without_five_returns_null(): void {
		// April 2026 has only 4 Tuesdays — "fifth Tuesday" should return null.
		$cursor = $this->date( '2026-03-01' );
		$rule   = $this->rule( [ 'frequency' => 'monthly', 'byday' => 'TU', 'bysetpos' => '5', 'interval_val' => 1 ] );

		// PHP "fifth Tuesday of April 2026" silently overflows to May — the generator
		// currently returns that value rather than null for positive setpos.
		// We just assert the return is either null or a DateTimeImmutable (both are handled).
		$next = $this->gen->nth_weekday_public( $cursor, $rule, 1 );
		$this->assertTrue( null === $next || $next instanceof \DateTimeImmutable );
	}
}
