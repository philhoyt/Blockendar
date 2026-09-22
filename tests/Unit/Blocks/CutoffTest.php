<?php
/**
 * Unit tests for the upcoming/past cutoff rules.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Unit\Blocks;

use Blockendar\Blocks\Cutoff;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class CutoffTest extends TestCase {

	private \DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// A site in New York, at 22:30 local on 21 September: 02:30 UTC on the 22nd.
		Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/New_York' ) );
		$this->now = new \DateTimeImmutable( '2026-09-22 02:30:00', new \DateTimeZone( 'UTC' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_end_rule_is_now(): void {
		$this->assertSame( '2026-09-22 02:30:00', Cutoff::for_rule( 'end', 3, $this->now ) );
	}

	public function test_day_rule_is_the_start_of_the_site_day_in_utc(): void {
		// Midnight on 21 September in New York (UTC-4 in September) is 04:00 UTC.
		$this->assertSame( '2026-09-21 04:00:00', Cutoff::for_rule( 'day', 3, $this->now ) );
		$this->assertSame( '2026-09-21 04:00:00', Cutoff::start_of_today( $this->now ) );
	}

	public function test_hours_rule_subtracts_the_hours(): void {
		$this->assertSame( '2026-09-21 23:30:00', Cutoff::for_rule( 'hours', 3, $this->now ) );
		$this->assertSame( '2026-09-22 01:30:00', Cutoff::for_rule( 'hours', 1, $this->now ) );
	}

	public function test_hours_are_clamped_to_the_editor_range(): void {
		$this->assertSame( Cutoff::for_rule( 'hours', 1, $this->now ), Cutoff::for_rule( 'hours', 0, $this->now ) );
		$this->assertSame( Cutoff::for_rule( 'hours', 1, $this->now ), Cutoff::for_rule( 'hours', -5, $this->now ) );
		$this->assertSame( Cutoff::for_rule( 'hours', 72, $this->now ), Cutoff::for_rule( 'hours', 500, $this->now ) );
	}

	public function test_an_unknown_rule_is_the_default(): void {
		$this->assertSame( Cutoff::for_rule( 'day', 3, $this->now ), Cutoff::for_rule( 'yesterday', 3, $this->now ) );
		$this->assertSame( Cutoff::for_rule( 'day', 3, $this->now ), Cutoff::for_rule( '', 3, $this->now ) );
	}

	public function test_a_non_utc_now_is_normalised(): void {
		$local = $this->now->setTimezone( new \DateTimeZone( 'Asia/Tokyo' ) );

		$this->assertSame( Cutoff::for_rule( 'end', 3, $this->now ), Cutoff::for_rule( 'end', 3, $local ) );
		$this->assertSame( Cutoff::for_rule( 'day', 3, $this->now ), Cutoff::for_rule( 'day', 3, $local ) );
	}

	public function test_defaults_produce_the_format(): void {
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', Cutoff::for_rule( 'day' ) );
	}

	/**
	 * @dataProvider validity_provider
	 */
	public function test_is_valid_accepts_only_exact_utc_datetimes( mixed $value, bool $expected ): void {
		$this->assertSame( $expected, Cutoff::is_valid( $value ) );
	}

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public function validity_provider(): array {
		return [
			'exact'           => [ '2026-09-21 04:00:00', true ],
			'date only'       => [ '2026-09-21', false ],
			'iso with T'      => [ '2026-09-21T04:00:00', false ],
			'with offset'     => [ '2026-09-21 04:00:00+02:00', false ],
			'impossible date' => [ '2026-02-30 04:00:00', false ],
			'trailing text'   => [ '2026-09-21 04:00:00 UTC', false ],
			'empty'           => [ '', false ],
			'not a string'    => [ 1758427200, false ],
			'null'            => [ null, false ],
		];
	}
}
