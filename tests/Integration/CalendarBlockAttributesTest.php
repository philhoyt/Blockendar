<?php
/**
 * The calendar-view block's own attributes reach its markup, clamped.
 *
 * The editor keeps eventsPerDay between 1 and 10, but a block comment can be
 * written by hand or by an older plugin version, and FullCalendar would
 * honour a 0 or a 500 as given.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\Schema;
use WP_UnitTestCase;

class CalendarBlockAttributesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	/**
	 * Render the calendar block with the given attributes.
	 *
	 * @param array $attrs Block attributes.
	 */
	private function render( array $attrs = [] ): string {
		return (string) render_block(
			[
				'blockName'    => 'blockendar/calendar-view',
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	public function test_events_per_day_defaults_to_three(): void {
		$this->assertStringContainsString( 'data-events-per-day="3"', $this->render() );
	}

	/**
	 * @return array[]
	 */
	public function events_per_day_inputs(): array {
		return [
			'in range' => [ 5, '5' ],
			'too many' => [ 50, '10' ],
			'zero'     => [ 0, '1' ],
			'negative' => [ -4, '1' ],
			'a string' => [ '7', '7' ],
		];
	}

	/**
	 * @dataProvider events_per_day_inputs
	 */
	public function test_events_per_day_is_clamped_to_one_through_ten( mixed $given, string $expected ): void {
		$this->assertStringContainsString(
			'data-events-per-day="' . $expected . '"',
			$this->render( [ 'eventsPerDay' => $given ] )
		);
	}

	public function test_week_numbers_are_off_unless_the_block_says_so(): void {
		$this->assertStringContainsString( 'data-week-numbers="false"', $this->render() );
		$this->assertStringContainsString( 'data-week-numbers="true"', $this->render( [ 'weekNumbers' => true ] ) );
		$this->assertStringContainsString( 'data-week-numbers="false"', $this->render( [ 'weekNumbers' => 'yes please' ] ), 'only a true boolean counts' );
	}
}
