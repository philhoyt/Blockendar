<?php
/**
 * Integration coverage for the single-event .ics export.
 *
 * The download and the subscription feed have to agree about what an event is.
 * They did not: the endpoint assembled its own VEVENT with a different UID, so a
 * client holding both a downloaded event and a subscription saw the same event
 * twice with no way to tell they were the same thing.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\IndexBuilder;
use Blockendar\ICS\Exporter;
use WP_REST_Request;
use WP_UnitTestCase;

class SingleEventIcsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'blockendar_settings' );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		delete_option( 'blockendar_settings' );
		parent::tear_down();
	}

	/**
	 * Create a published, indexed event.
	 *
	 * @param string $title Event title.
	 * @param array  $meta  Meta overrides.
	 * @return int Post ID.
	 */
	private function make_event( string $title, array $meta = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'blockendar_event',
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => 'An evening of music.',
			]
		);

		$date = gmdate( 'Y-m-d', strtotime( '+9 days' ) );

		$meta = array_merge(
			[
				'blockendar_start_date' => $date,
				'blockendar_end_date'   => $date,
				'blockendar_start_time' => '19:00',
				'blockendar_end_time'   => '21:00',
			],
			$meta
		);

		foreach ( $meta as $key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $post_id, $key );
				continue;
			}

			update_post_meta( $post_id, $key, $value );
		}

		( new IndexBuilder() )->build_for_post( $post_id );

		return $post_id;
	}

	/**
	 * Return the first content line with the given prefix.
	 *
	 * @param string $ics    iCalendar content.
	 * @param string $prefix Property prefix.
	 */
	private function property( string $ics, string $prefix ): string {
		foreach ( explode( "\r\n", $ics ) as $line ) {
			if ( str_starts_with( $line, $prefix ) ) {
				return $line;
			}
		}

		return '';
	}

	/**
	 * The feed body for the current window.
	 */
	private function feed(): string {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'format', 'ics' );

		return (string) rest_do_request( $request )->get_data();
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	public function test_the_download_and_the_feed_agree_on_the_uid(): void {
		$post_id = $this->make_event( 'Autumn Concert' );

		$download = (string) ( new Exporter() )->generate_single( $post_id );

		$this->assertSame(
			$this->property( $this->feed(), 'UID:' ),
			$this->property( $download, 'UID:' ),
			'A client holding both would show this event twice.'
		);
	}

	public function test_the_download_uid_is_the_prefixed_form(): void {
		$post_id = $this->make_event( 'Prefixed UID' );

		$download = (string) ( new Exporter() )->generate_single( $post_id );

		$this->assertStringStartsWith( "UID:blockendar-{$post_id}@", $this->property( $download, 'UID:' ) );
	}

	// -------------------------------------------------------------------------
	// Spec compliance
	// -------------------------------------------------------------------------

	public function test_download_lines_are_folded(): void {
		$post_id = $this->make_event(
			'Autumn Concert at the Really Quite Long Named Venue Hall Downtown By The River'
		);

		$ics = (string) ( new Exporter() )->generate_single( $post_id );

		foreach ( explode( "\r\n", rtrim( $ics, "\r\n" ) ) as $index => $line ) {
			$this->assertLessThanOrEqual(
				75,
				strlen( $line ),
				"Physical line {$index} exceeds 75 octets."
			);
		}
	}

	public function test_the_download_carries_the_revision_properties(): void {
		$post_id = $this->make_event( 'Full Properties' );

		$ics = (string) ( new Exporter() )->generate_single( $post_id );

		foreach ( [ 'SEQUENCE:', 'LAST-MODIFIED:', 'STATUS:', 'DESCRIPTION:' ] as $property ) {
			$this->assertNotSame( '', $this->property( $ics, $property ), "Missing {$property}" );
		}
	}

	// -------------------------------------------------------------------------
	// A download is not a subscription
	// -------------------------------------------------------------------------

	public function test_a_single_event_file_advertises_no_refresh_or_calendar_name(): void {
		$post_id = $this->make_event( 'One Off' );

		$ics = (string) ( new Exporter() )->generate_single( $post_id );

		// Both would be wrong on a file that is imported once rather than polled,
		// and a calendar name makes some clients offer to create a new calendar.
		$this->assertStringNotContainsString( 'REFRESH-INTERVAL', $ics );
		$this->assertStringNotContainsString( 'X-PUBLISHED-TTL', $ics );
		$this->assertStringNotContainsString( 'X-WR-CALNAME', $ics );

		// The feed still advertises them.
		$this->assertStringContainsString( 'REFRESH-INTERVAL', $this->feed() );
	}

	// -------------------------------------------------------------------------
	// Leniency the endpoint used to provide itself
	// -------------------------------------------------------------------------

	public function test_an_event_without_an_end_date_still_exports(): void {
		$post_id = $this->make_event( 'No End Date', [ 'blockendar_end_date' => null ] );

		$ics = (string) ( new Exporter() )->generate_single( $post_id );

		$this->assertStringContainsString( 'BEGIN:VEVENT', $ics );
		$this->assertNotSame( '', $this->property( $ics, 'DTSTART' ) );
	}

	public function test_an_event_with_no_dates_at_all_is_not_exportable(): void {
		$post_id = $this->make_event(
			'Dateless',
			[
				'blockendar_start_date' => null,
				'blockendar_end_date'   => null,
			]
		);

		$this->assertNull( ( new Exporter() )->generate_single( $post_id ) );
	}

	public function test_a_non_event_post_is_not_exportable(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$this->assertNull( ( new Exporter() )->generate_single( $post_id ) );
	}
}
