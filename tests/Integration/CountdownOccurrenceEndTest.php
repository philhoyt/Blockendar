<?php
/**
 * Integration coverage for the countdown's end target on a recurring event.
 *
 * The countdown took the start from the resolved occurrence but the end from
 * the series meta, which holds the first occurrence's end. The ticker checks
 * the end first, so every later occurrence read "This event has passed" as
 * soon as the first one was over.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\RuleRepository;
use WP_UnitTestCase;

class CountdownOccurrenceEndTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		( new EventIndex() )->flush_cache();
	}

	/**
	 * A weekly series whose first occurrence was two weeks ago.
	 *
	 * @return int Post ID.
	 */
	private function make_series(): int {
		$first = gmdate( 'Y-m-d', strtotime( '-14 days' ) );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => [
					'blockendar_start_date' => $first,
					'blockendar_end_date'   => $first,
					'blockendar_start_time' => '10:00',
					'blockendar_end_time'   => '11:00',
					'blockendar_timezone'   => 'UTC',
				],
			]
		);

		( new RuleRepository() )->upsert(
			$post_id,
			[
				'frequency' => 'weekly',
				'interval'  => 1,
				'count'     => 6,
			]
		);
		( new Generator() )->generate_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		return $post_id;
	}

	/**
	 * Read one data attribute from rendered countdown markup.
	 */
	private function attr( string $html, string $name ): string {
		preg_match( '/' . preg_quote( $name, '/' ) . '="([^"]*)"/', $html, $match );

		return $match[1] ?? '';
	}

	/**
	 * @dataProvider modes
	 */
	public function test_end_target_belongs_to_the_occurrence_being_counted_down( bool $pinned ): void {
		$post_id    = $this->make_series();
		$occurrence = EventIndex::next_occurrence( $post_id );

		$this->assertNotNull( $occurrence, 'Precondition: the series has an upcoming occurrence.' );

		if ( $pinned ) {
			$html = do_blocks( "<!-- wp:blockendar/event-countdown {\"pinnedPostId\":{$post_id}} /-->" );
		} else {
			$html = ( new \WP_Block(
				[
					'blockName' => 'blockendar/event-countdown',
					'attrs'     => [],
				],
				[ 'postId' => $post_id ]
			) )->render();
		}

		$start = strtotime( $this->attr( $html, 'data-target' ) );
		$end   = strtotime( $this->attr( $html, 'data-end-target' ) );

		$this->assertSame( strtotime( $occurrence->start_date . ' 10:00:00 UTC' ), $start );
		$this->assertSame( strtotime( $occurrence->end_date . ' 11:00:00 UTC' ), $end );
		$this->assertGreaterThan( time(), $end, 'An upcoming occurrence must not already be over.' );
	}

	public function modes(): array {
		return [
			'pinned'  => [ true ],
			'context' => [ false ],
		];
	}
}
