<?php
/**
 * Integration coverage for the colours the calendar is told to use.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

class CalendarChipColourTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		Schema::create_tables();

		foreach ( [ Schema::events_table(), Schema::type_terms_table() ] as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( 'blockendar_settings' );
		IndexBuilder::forget_dirty();
		( new EventIndex() )->flush_cache();

		do_action( 'rest_api_init' );
	}

	/**
	 * Create and index an event on 9 March 2027, optionally with a type of a colour.
	 *
	 * @param string      $title  Post title.
	 * @param string|null $colour Colour of its event type, or null for no type.
	 */
	private function make_event( string $title, ?string $colour ): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => [
					'blockendar_start_date' => '2027-03-09',
					'blockendar_end_date'   => '2027-03-09',
					'blockendar_start_time' => '19:00',
					'blockendar_end_time'   => '21:00',
					'blockendar_timezone'   => 'UTC',
				],
			]
		);

		if ( null !== $colour ) {
			$type = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_type' ] );

			update_term_meta( $type, 'blockendar_type_color', $colour );
			wp_set_object_terms( $post_id, [ $type ], 'blockendar_event_type' );
		}

		( new IndexBuilder() )->build_for_post( $post_id );
	}

	/**
	 * The calendar payload for March 2027, keyed by event title.
	 *
	 * @return array<string, array>
	 */
	private function payload(): array {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_query_params(
			[
				'start' => '2027-03-01T00:00:00',
				'end'   => '2027-04-01T00:00:00',
			]
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		return array_column( $response->get_data(), null, 'title' );
	}

	/**
	 * The text on a chip was white whatever the type's colour.
	 */
	public function test_an_event_is_given_a_text_colour_to_suit_its_type_colour(): void {
		$this->make_event( 'On Yellow', '#ffeb3b' );
		$this->make_event( 'On Navy', '#1e3a8a' );

		$events = $this->payload();

		// The type's colour is stored in upper case; the calendar takes either.
		$this->assertSame( '#ffeb3b', strtolower( $events['On Yellow']['color'] ) );
		$this->assertSame( '#000000', $events['On Yellow']['textColor'] );
		$this->assertSame( '#ffffff', $events['On Navy']['textColor'] );
	}

	/**
	 * An event with no type colour is left to the calendar's stylesheet, which
	 * a site may have themed. A colour sent here would be applied inline and
	 * override it.
	 */
	public function test_an_event_with_no_type_colour_is_sent_no_colours(): void {
		$this->make_event( 'Plain', null );

		$event = $this->payload()['Plain'];

		$this->assertSame( '', $event['color'] );
		$this->assertArrayNotHasKey( 'textColor', $event );
	}

	public function test_a_type_colour_that_is_not_a_hex_colour_gets_no_text_colour(): void {
		$this->make_event( 'Named', 'rebeccapurple' );

		$this->assertArrayNotHasKey( 'textColor', $this->payload()['Named'] );
	}
}
