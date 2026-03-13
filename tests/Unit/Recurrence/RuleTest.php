<?php
/**
 * Unit tests for Blockendar\Recurrence\Rule.
 *
 * Rule is pure PHP with no WordPress dependencies — no stubs needed.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Unit\Recurrence;

use Blockendar\Recurrence\Rule;
use PHPUnit\Framework\TestCase;

class RuleTest extends TestCase {

	// -------------------------------------------------------------------------
	// Frequency validation
	// -------------------------------------------------------------------------

	/** @dataProvider valid_frequencies */
	public function test_valid_frequency_stored_as_is( string $freq ): void {
		$rule = new Rule( [ 'frequency' => $freq ] );
		$this->assertSame( $freq, $rule->frequency );
	}

	public static function valid_frequencies(): array {
		return [
			[ 'daily' ],
			[ 'weekly' ],
			[ 'monthly' ],
			[ 'yearly' ],
		];
	}

	public function test_invalid_frequency_defaults_to_weekly(): void {
		$rule = new Rule( [ 'frequency' => 'hourly' ] );
		$this->assertSame( 'weekly', $rule->frequency );
	}

	public function test_empty_frequency_defaults_to_weekly(): void {
		$rule = new Rule( [ 'frequency' => '' ] );
		$this->assertSame( 'weekly', $rule->frequency );
	}

	// -------------------------------------------------------------------------
	// parse_csv_list (byday)
	// -------------------------------------------------------------------------

	public function test_byday_three_days(): void {
		$rule = new Rule( [ 'byday' => 'MO,WE,FR' ] );
		$this->assertSame( [ 'MO', 'WE', 'FR' ], $rule->byday );
	}

	public function test_byday_single_day(): void {
		$rule = new Rule( [ 'byday' => 'TH' ] );
		$this->assertSame( [ 'TH' ], $rule->byday );
	}

	public function test_byday_filters_invalid_codes(): void {
		$rule = new Rule( [ 'byday' => 'MON,BAD' ] );
		$this->assertSame( [], $rule->byday );
	}

	public function test_byday_null_returns_empty(): void {
		$rule = new Rule( [ 'byday' => null ] );
		$this->assertSame( [], $rule->byday );
	}

	public function test_byday_empty_string_returns_empty(): void {
		$rule = new Rule( [ 'byday' => '' ] );
		$this->assertSame( [], $rule->byday );
	}

	// -------------------------------------------------------------------------
	// parse_int_list (bymonthday, bysetpos)
	// -------------------------------------------------------------------------

	public function test_bymonthday_two_days(): void {
		$rule = new Rule( [ 'bymonthday' => '1,15' ] );
		$this->assertSame( [ 1, 15 ], $rule->bymonthday );
	}

	public function test_bymonthday_single_day(): void {
		$rule = new Rule( [ 'bymonthday' => '31' ] );
		$this->assertSame( [ 31 ], $rule->bymonthday );
	}

	public function test_bymonthday_filters_zero(): void {
		$rule = new Rule( [ 'bymonthday' => '0' ] );
		$this->assertSame( [], $rule->bymonthday );
	}

	public function test_bymonthday_negative_one(): void {
		$rule = new Rule( [ 'bymonthday' => '-1' ] );
		$this->assertSame( [ -1 ], $rule->bymonthday );
	}

	public function test_bymonthday_out_of_range_filtered(): void {
		// 32 exceeds max of 31, -32 exceeds min of -31.
		$rule = new Rule( [ 'bymonthday' => '32,-32' ] );
		$this->assertSame( [], $rule->bymonthday );
	}

	public function test_bysetpos_first(): void {
		$rule = new Rule( [ 'bysetpos' => '1' ] );
		$this->assertSame( [ 1 ], $rule->bysetpos );
	}

	public function test_bysetpos_last(): void {
		$rule = new Rule( [ 'bysetpos' => '-1' ] );
		$this->assertSame( [ -1 ], $rule->bysetpos );
	}

	// -------------------------------------------------------------------------
	// parse_until_date
	// -------------------------------------------------------------------------

	public function test_until_date_valid(): void {
		$rule = new Rule( [ 'until_date' => '2026-12-31' ] );
		$this->assertInstanceOf( \DateTimeImmutable::class, $rule->until_date );
		$this->assertSame( '2026-12-31', $rule->until_date->format( 'Y-m-d' ) );
		$this->assertSame( '23:59:59', $rule->until_date->format( 'H:i:s' ) );
		$this->assertSame( 'UTC', $rule->until_date->getTimezone()->getName() );
	}

	public function test_until_date_invalid_format_returns_null(): void {
		$rule = new Rule( [ 'until_date' => '31/12/2026' ] );
		$this->assertNull( $rule->until_date );
	}

	public function test_until_date_null_returns_null(): void {
		$rule = new Rule( [ 'until_date' => null ] );
		$this->assertNull( $rule->until_date );
	}

	public function test_until_date_empty_returns_null(): void {
		$rule = new Rule( [ 'until_date' => '' ] );
		$this->assertNull( $rule->until_date );
	}

	// -------------------------------------------------------------------------
	// parse_json_dates (exceptions, additions)
	// -------------------------------------------------------------------------

	public function test_exceptions_parsed_from_json(): void {
		$rule = new Rule( [ 'exceptions' => '["2026-03-15","2026-04-01"]' ] );
		$this->assertSame( [ '2026-03-15', '2026-04-01' ], $rule->exceptions );
	}

	public function test_exceptions_filters_invalid_dates(): void {
		$rule = new Rule( [ 'exceptions' => '["2026-03-15","not-a-date"]' ] );
		$this->assertSame( [ '2026-03-15' ], $rule->exceptions );
	}

	public function test_exceptions_non_array_json_returns_empty(): void {
		$rule = new Rule( [ 'exceptions' => '"2026-03-15"' ] );
		$this->assertSame( [], $rule->exceptions );
	}

	public function test_exceptions_null_returns_empty(): void {
		$rule = new Rule( [ 'exceptions' => null ] );
		$this->assertSame( [], $rule->exceptions );
	}

	public function test_additions_parsed_from_json(): void {
		$rule = new Rule( [ 'additions' => '["2026-05-20"]' ] );
		$this->assertSame( [ '2026-05-20' ], $rule->additions );
	}

	// -------------------------------------------------------------------------
	// interval clamping
	// -------------------------------------------------------------------------

	public function test_interval_zero_clamped_to_one(): void {
		$rule = new Rule( [ 'interval_val' => 0 ] );
		$this->assertSame( 1, $rule->interval );
	}

	public function test_interval_negative_clamped_to_one(): void {
		$rule = new Rule( [ 'interval_val' => -5 ] );
		$this->assertSame( 1, $rule->interval );
	}

	public function test_interval_positive_stored(): void {
		$rule = new Rule( [ 'interval_val' => 2 ] );
		$this->assertSame( 2, $rule->interval );
	}

	// -------------------------------------------------------------------------
	// has_end()
	// -------------------------------------------------------------------------

	public function test_has_end_true_when_count_set(): void {
		$rule = new Rule( [ 'count' => 5 ] );
		$this->assertTrue( $rule->has_end() );
	}

	public function test_has_end_true_when_until_date_set(): void {
		$rule = new Rule( [ 'until_date' => '2027-01-01' ] );
		$this->assertTrue( $rule->has_end() );
	}

	public function test_has_end_false_when_neither_set(): void {
		$rule = new Rule( [] );
		$this->assertFalse( $rule->has_end() );
	}

	// -------------------------------------------------------------------------
	// is_exception()
	// -------------------------------------------------------------------------

	public function test_is_exception_true_for_listed_date(): void {
		$rule = new Rule( [ 'exceptions' => '["2026-03-15"]' ] );
		$this->assertTrue( $rule->is_exception( '2026-03-15' ) );
	}

	public function test_is_exception_false_for_unlisted_date(): void {
		$rule = new Rule( [ 'exceptions' => '["2026-03-15"]' ] );
		$this->assertFalse( $rule->is_exception( '2026-03-14' ) );
	}
}
