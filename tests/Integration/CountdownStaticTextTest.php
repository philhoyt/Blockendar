<?php
/**
 * Integration coverage for the sentence the countdown block prints beside its ticker.
 *
 * "Starts on … at …" is what a screen reader hears and what anyone gets with
 * JavaScript off. It was formatted from a UTC timestamp as if that were local
 * time, so it gave the event's start in UTC.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use WP_UnitTestCase;

class CountdownStaticTextTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		( new EventIndex() )->flush_cache();

		delete_option( 'blockendar_settings' );
	}

	public function tear_down(): void {
		delete_option( 'timezone_string' );
		delete_option( 'blockendar_settings' );
		parent::tear_down();
	}

	/**
	 * The static sentence for a 19:00 Chicago event on 10 November 2030.
	 *
	 * @param array $meta Meta overrides, without the blockendar_ prefix.
	 */
	private function sentence( array $meta = [] ): string {
		$meta = array_merge(
			[
				'start_date' => '2030-11-10',
				'end_date'   => '2030-11-10',
				'start_time' => '19:00',
				'end_time'   => '21:00',
				'timezone'   => 'America/Chicago',
			],
			$meta
		);

		$meta_input = [];

		foreach ( $meta as $key => $value ) {
			$meta_input[ "blockendar_{$key}" ] = $value;
		}

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => $meta_input,
			]
		);

		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		$html = ( new \WP_Block(
			[
				'blockName' => 'blockendar/event-countdown',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		) )->render();

		preg_match( '/blockendar-event-countdown__static">\s*(.*?)\s*<\/span>/s', $html, $match );

		return $match[1] ?? '';
	}

	public function test_the_sentence_gives_the_events_own_time_on_a_site_in_its_timezone(): void {
		update_option( 'timezone_string', 'America/Chicago' );

		$this->assertSame( 'Starts on November 10, 2030 at 7:00 pm.', $this->sentence() );
	}

	/**
	 * Under the default mode times are shown in the site's timezone, and
	 * 11:30 pm in Chicago is the next day in New York.
	 */
	public function test_site_mode_shows_the_sites_time_and_date(): void {
		update_option( 'timezone_string', 'America/New_York' );

		$this->assertSame(
			'Starts on November 11, 2030 at 12:30 am.',
			$this->sentence( [ 'start_time' => '23:30' ] )
		);
	}

	public function test_event_mode_shows_the_events_own_time(): void {
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'blockendar_settings', [ 'timezone_mode' => 'event' ] );

		$this->assertSame( 'Starts on November 10, 2030 at 7:00 pm.', $this->sentence() );
	}

	/**
	 * The ticker's target is an instant and was always right. It must not move.
	 */
	public function test_the_ticker_still_counts_down_to_the_right_instant(): void {
		update_option( 'timezone_string', 'America/Chicago' );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => [
					'blockendar_start_date' => '2030-11-10',
					'blockendar_end_date'   => '2030-11-10',
					'blockendar_start_time' => '19:00',
					'blockendar_end_time'   => '21:00',
					'blockendar_timezone'   => 'America/Chicago',
				],
			]
		);

		( new IndexBuilder() )->build_for_post( $post_id );

		$html = ( new \WP_Block(
			[
				'blockName' => 'blockendar/event-countdown',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		) )->render();

		$this->assertStringContainsString( 'data-target="2030-11-11T01:00:00+00:00"', $html );
	}
}
