<?php
/**
 * Integration coverage for how ongoing events leave the plugin: REST, the
 * FullCalendar feed and iCal.
 *
 * The index stores a far-future sentinel end for ongoing events. None of that
 * may reach a consumer — REST returns null ends and an `ongoing` flag,
 * FullCalendar gets no `end`, and every VEVENT omits DTEND.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\ICS\Exporter;
use Blockendar\REST\CalendarController;
use Blockendar\REST\EventsController;
use WP_REST_Request;
use WP_UnitTestCase;

class OngoingEventOutputTest extends WP_UnitTestCase {

	private int $ongoing_id;

	private int $normal_id;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		( new EventIndex() )->flush_cache();

		global $wpdb;
		$events = Schema::events_table();
		$wpdb->query( "DELETE FROM {$events}" ); // phpcs:ignore WordPress.DB

		delete_option( 'blockendar_settings' );

		$this->ongoing_id = $this->create_event(
			[
				'blockendar_start_date' => '2025-09-13',
				'blockendar_start_time' => '10:00',
				'blockendar_ongoing'    => true,
			]
		);

		$this->normal_id = $this->create_event(
			[
				'blockendar_start_date' => '2025-09-14',
				'blockendar_start_time' => '10:00',
				'blockendar_end_date'   => '2025-09-14',
				'blockendar_end_time'   => '11:00',
			]
		);
	}

	public function tear_down(): void {
		delete_option( 'blockendar_settings' );
		parent::tear_down();
	}

	private function create_event( array $meta ): int {
		return self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => 'Exhibit',
				'meta_input'  => array_merge( [ 'blockendar_timezone' => 'UTC' ], $meta ),
			]
		);
	}

	/**
	 * Find the response item for a post ID in a list keyed by `post_id`.
	 */
	private function item_for( array $items, int $post_id ): array {
		foreach ( $items as $item ) {
			if ( (int) $item['post_id'] === $post_id ) {
				return $item;
			}
		}

		$this->fail( "No item for post {$post_id}." );
	}

	// -------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------

	public function test_events_collection_reports_ongoing_with_null_ends(): void {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/events' );
		$request->set_param( 'start', '2025-09-01 00:00:00' );
		$request->set_param( 'end', '2025-10-01 00:00:00' );

		$response = ( new EventsController() )->get_events( $request );
		$items    = $response->get_data();

		$ongoing = $this->item_for( $items, $this->ongoing_id );
		$this->assertTrue( $ongoing['ongoing'] );
		$this->assertNull( $ongoing['end_datetime'] );
		$this->assertNull( $ongoing['end_date'] );
		$this->assertSame( '2025-09-13 10:00:00', $ongoing['start_datetime'] );

		$normal = $this->item_for( $items, $this->normal_id );
		$this->assertFalse( $normal['ongoing'] );
		$this->assertSame( '2025-09-14 11:00:00', $normal['end_datetime'] );
		$this->assertSame( '2025-09-14', $normal['end_date'] );
	}

	public function test_single_event_and_instances_report_ongoing(): void {
		$controller = new EventsController();

		$request = new WP_REST_Request( 'GET', "/blockendar/v1/events/{$this->ongoing_id}" );
		$request->set_param( 'id', $this->ongoing_id );
		$data = $controller->get_event( $request )->get_data();

		// get_full_meta() strips the prefix and returns the raw stored value.
		$this->assertTrue( (bool) $data['meta']['ongoing'] );
		$this->assertCount( 1, $data['instances'] );
		$this->assertTrue( $data['instances'][0]['ongoing'] );
		$this->assertNull( $data['instances'][0]['end_datetime'] );

		$request = new WP_REST_Request( 'GET', "/blockendar/v1/events/{$this->ongoing_id}/instances" );
		$request->set_param( 'id', $this->ongoing_id );
		$instances = $controller->get_instances( $request )->get_data();

		$this->assertCount( 1, $instances );
		$this->assertTrue( $instances[0]['ongoing'] );
		$this->assertNull( $instances[0]['end_date'] );
	}

	public function test_fullcalendar_feed_omits_end_for_ongoing_events(): void {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'start', '2025-09-01 00:00:00' );
		$request->set_param( 'end', '2025-10-01 00:00:00' );
		$request->set_param( 'format', 'json' );

		$items = ( new CalendarController() )->get_calendar_feed( $request )->get_data();

		$ongoing = $this->item_for( $items, $this->ongoing_id );
		$this->assertArrayNotHasKey( 'end', $ongoing );
		$this->assertTrue( $ongoing['extendedProps']['ongoing'] );
		$this->assertStringStartsWith( '2025-09-13T10:00:00', $ongoing['start'] );

		$normal = $this->item_for( $items, $this->normal_id );
		$this->assertArrayHasKey( 'end', $normal );
		$this->assertFalse( $normal['extendedProps']['ongoing'] );
	}

	// -------------------------------------------------------------------------
	// iCal
	// -------------------------------------------------------------------------

	/**
	 * Split an iCal body into its VEVENT blocks.
	 *
	 * @return string[]
	 */
	private function vevents( string $ics ): array {
		preg_match_all( '/BEGIN:VEVENT.*?END:VEVENT/s', $ics, $matches );

		return $matches[0];
	}

	public function test_ics_feed_has_no_dtend_for_ongoing_events(): void {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'start', '2025-09-01 00:00:00' );
		$request->set_param( 'end', '2025-10-01 00:00:00' );
		$request->set_param( 'format', 'ics' );

		$ics     = ( new CalendarController() )->get_calendar_feed( $request )->get_data();
		$vevents = $this->vevents( $ics );

		$this->assertCount( 2, $vevents );

		$by_uid = [];
		foreach ( $vevents as $vevent ) {
			$post_id            = (int) preg_replace( '/^.*UID:blockendar-(\d+)-.*$/s', '$1', $vevent );
			$by_uid[ $post_id ] = $vevent;
		}

		$this->assertStringContainsString( 'DTSTART:20250913T100000Z', $by_uid[ $this->ongoing_id ] );
		$this->assertStringNotContainsString( 'DTEND', $by_uid[ $this->ongoing_id ] );
		$this->assertStringNotContainsString( '9999', $by_uid[ $this->ongoing_id ], 'The sentinel must never be exported.' );

		$this->assertStringContainsString( 'DTEND:20250914T110000Z', $by_uid[ $this->normal_id ] );
	}

	public function test_single_event_export_has_no_dtend_when_ongoing(): void {
		$exporter = new Exporter();

		$ics = $exporter->generate_single( $this->ongoing_id );
		$this->assertNotNull( $ics );
		$this->assertStringContainsString( 'DTSTART:20250913T100000Z', $ics );
		$this->assertStringNotContainsString( 'DTEND', $ics );
		$this->assertStringNotContainsString( '9999', $ics );

		$ics = $exporter->generate_single( $this->normal_id );
		$this->assertStringContainsString( 'DTEND:20250914T110000Z', $ics );
	}

	public function test_all_day_ongoing_event_exports_a_date_only_dtstart_without_dtend(): void {
		$post_id = $this->create_event(
			[
				'blockendar_start_date' => '2025-09-15',
				'blockendar_all_day'    => true,
				'blockendar_ongoing'    => true,
			]
		);

		$ics = ( new Exporter() )->generate_single( $post_id );

		$this->assertStringContainsString( 'DTSTART;VALUE=DATE:20250915', $ics );
		$this->assertStringNotContainsString( 'DTEND', $ics );
	}
}
