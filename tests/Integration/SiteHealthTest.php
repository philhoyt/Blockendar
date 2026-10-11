<?php
/**
 * Integration coverage for the Site Health section and tests.
 *
 * The filter callbacks are called directly, the way WP_Site_Health and
 * WP_Debug_Data call them, and their results read back.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\SiteHealth;
use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Meta\EventMeta;
use Blockendar\Recurrence\RuleRepository;
use WP_UnitTestCase;

class SiteHealthTest extends WP_UnitTestCase {

	private SiteHealth $health;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		Schema::create_tables();

		foreach ( [ Schema::events_table(), Schema::type_terms_table(), Schema::recurrence_table() ] as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		( new EventMeta() )->register_meta();

		delete_option( 'blockendar_settings' );
		IndexBuilder::forget_dirty();
		( new EventIndex() )->flush_cache();

		// A test that asserts on a rebuild being idle must not inherit one.
		_set_cron_array( [] );

		$this->health = new SiteHealth();

		require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
	}

	public function tear_down(): void {
		// A test may have dropped a table or an index. The table has to come
		// back as a real one: the suite's query filter would make it temporary,
		// which SHOW TABLES does not list, and every later test would then see
		// it as missing.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		Schema::create_tables();
		add_filter( 'query', [ $this, '_create_temporary_tables' ] );
		delete_option( 'blockendar_settings' );
		IndexBuilder::forget_dirty();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create and index a published event.
	 *
	 * @param array      $meta   Meta to override, without the prefix.
	 * @param array|null $rule   Repeat rule.
	 * @param array      $post   Post fields to override.
	 * @return int Post ID.
	 */
	private function make_event( array $meta = [], ?array $rule = null, array $post = [] ): int {
		$meta += [
			'start_date' => '2027-03-09',
			'end_date'   => '2027-03-09',
			'start_time' => '19:00',
			'end_time'   => '21:00',
			'timezone'   => 'UTC',
		];

		$post_id = self::factory()->post->create(
			$post + [
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
			]
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, 'blockendar_' . $key, $value );
		}

		if ( null !== $rule ) {
			( new RuleRepository() )->upsert( $post_id, $rule );
		}

		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		return $post_id;
	}

	/**
	 * The Blockendar section of Site Health → Info.
	 */
	private function info(): array {
		return apply_filters( 'debug_information', [] )['blockendar'];
	}

	/**
	 * The tests Blockendar adds, keyed by slug, in a flat list.
	 */
	private function tests(): array {
		$tests = apply_filters( 'site_status_tests', [] );

		return $tests['direct'] + $tests['async'];
	}

	// -------------------------------------------------------------------------
	// Info
	// -------------------------------------------------------------------------

	public function test_the_info_section_has_the_fields_and_no_token(): void {
		update_option(
			'blockendar_settings',
			[
				'rest_feed_token'     => 'sekrit-token-value',
				'generation_strategy' => 'cron',
				'timezone_mode'       => 'event',
				'horizon_days'        => 180,
			]
		);
		update_option( 'blockendar_last_index_rebuild', '2026-10-01 12:00:00' );

		$this->make_event();
		$this->make_event(
			[],
			[
				'frequency' => 'weekly',
				'count'     => 3,
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'draft',
			]
		);

		$section = $this->info();

		$this->assertSame( 'Blockendar', $section['label'] );
		$this->assertSame(
			[
				'version',
				'db_version',
				'events',
				'index_rows',
				'indexed_events',
				'recurrence_rules',
				'last_rebuild',
				'next_roll',
				'strategy',
				'horizon',
				'max_instances',
				'timezone_mode',
				'events_slug',
				'rest_public',
				'feed_token',
				'object_cache',
			],
			array_keys( $section['fields'] )
		);

		$fields = $section['fields'];

		$this->assertSame( BLOCKENDAR_VERSION, $fields['version']['value'] );
		$this->assertSame( Schema::DB_VERSION, $fields['db_version']['value'] );
		$this->assertSame( '2 published, 1 draft', $fields['events']['value'] );
		$this->assertSame( '4', $fields['index_rows']['value'] );
		$this->assertSame( '2', $fields['indexed_events']['value'] );
		$this->assertSame( '1', $fields['recurrence_rules']['value'] );
		$this->assertStringContainsString( '2026', $fields['last_rebuild']['value'] );
		$this->assertSame( 'Not scheduled', $fields['next_roll']['value'], 'The cron array was cleared in set_up().' );
		$this->assertSame( 'Deferred to WP-Cron', $fields['strategy']['value'] );
		$this->assertSame( '180 days', $fields['horizon']['value'] );
		$this->assertSame( "Each event's own timezone", $fields['timezone_mode']['value'] );
		$this->assertSame( 'events', $fields['events_slug']['value'] );
		$this->assertSame( 'Yes', $fields['rest_public']['value'] );
		$this->assertSame( 'Set', $fields['feed_token']['value'] );
		$this->assertTrue( $fields['feed_token']['private'], 'The token field is flagged private in the filter output.' );

		// The copied report (keyed by field slug, with the debug values) leaves
		// the private field out.
		$report = \WP_Debug_Data::format( [ 'blockendar' => $section ], 'debug' );

		$this->assertStringContainsString( 'version: ' . BLOCKENDAR_VERSION, $report );
		$this->assertStringContainsString( 'rest_public: true', $report );
		$this->assertStringNotContainsString( 'feed_token', $report );
		$this->assertStringNotContainsString( 'sekrit', $report );

		// And the token itself is nowhere in the section at all.
		$this->assertStringNotContainsString( 'sekrit', wp_json_encode( $section ) );
	}

	public function test_the_info_section_says_when_the_schema_is_behind(): void {
		update_option( Schema::DB_VERSION_OPTION, '3' );

		$this->assertSame( '3 (this version of the plugin expects ' . Schema::DB_VERSION . ')', $this->info()['fields']['db_version']['value'] );
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public function test_three_tests_are_registered_and_the_route_answers_an_administrator(): void {
		$tests = $this->tests();

		$this->assertSame( [ 'blockendar_tables', 'blockendar_index', 'blockendar_calendar_route' ], array_keys( $tests ) );
		$this->assertTrue( $tests['blockendar_calendar_route']['has_rest'] );
		$this->assertStringContainsString( '/blockendar/v1/site-health/calendar-route', $tests['blockendar_calendar_route']['test'] );
		$this->assertIsCallable( $tests['blockendar_calendar_route']['async_direct_test'] );

		do_action( 'rest_api_init' );

		$request = new \WP_REST_Request( 'GET', '/blockendar/v1/site-health/calendar-route' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status(), 'A visitor cannot run the check.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		add_filter( 'pre_http_request', fn() => $this->http( 200, '[]' ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'good', $response->get_data()['status'] );
		$this->assertSame( 'blockendar_calendar_route', $response->get_data()['test'] );
	}

	// -------------------------------------------------------------------------
	// Test 1: tables
	// -------------------------------------------------------------------------

	public function test_the_tables_test_is_good_on_a_complete_schema(): void {
		$result = $this->health->test_tables();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'blockendar_tables', $result['test'] );
		$this->assertSame( [ 'label', 'color' ], array_keys( $result['badge'] ) );
	}

	public function test_dropping_a_table_is_critical(): void {
		global $wpdb;

		$table = Schema::type_terms_table();

		// The suite rewrites DROP TABLE to DROP TEMPORARY TABLE, which fails on
		// the real table the bootstrap created and leaves it in place.
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB
		add_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		$result = $this->health->test_tables();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( $table, $result['description'] );
		$this->assertSame( [ $table ], Schema::missing_tables() );
	}

	public function test_dropping_a_required_index_is_recommended(): void {
		global $wpdb;

		$table = Schema::events_table();
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX idx_visible_start" ); // phpcs:ignore WordPress.DB

		$this->assertFalse( Schema::has_required_indexes(), 'Precondition.' );
		$this->assertSame( 'recommended', $this->health->test_tables()['status'] );
	}

	// -------------------------------------------------------------------------
	// Test 2: drift
	// -------------------------------------------------------------------------

	/**
	 * Recurring, ongoing and password-protected events are all indexed; an
	 * event without an end date is skipped by the builder on purpose. None of
	 * them is drift.
	 */
	public function test_a_healthy_index_shows_no_drift(): void {
		$this->make_event();
		$this->make_event(
			[],
			[
				'frequency' => 'weekly',
				'count'     => 3,
			]
		);
		$this->make_event(
			[
				'ongoing'  => true,
				'end_date' => '',
			]
		);
		$this->make_event( [], null, [ 'post_password' => 'hunter2' ] );
		$this->make_event( [ 'end_date' => '' ] );

		$this->assertSame( 4, ( new EventIndex() )->get_indexed_post_count(), 'Precondition: four of the five are indexed.' );

		$result = $this->health->test_index();

		$this->assertSame( 'good', $result['status'], $result['description'] );
		$this->assertStringContainsString( '4 published events indexed as 6 occurrences', wp_strip_all_tags( $result['description'] ) );
	}

	public function test_deleting_an_events_rows_is_recommended_until_a_rebuild(): void {
		global $wpdb;

		$post_id = $this->make_event();
		$this->make_event();

		$table = Schema::events_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB
		( new EventIndex() )->flush_cache();

		$result = $this->health->test_index();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '1 published event with a date has no entry in the index', wp_strip_all_tags( $result['description'] ) );
		$this->assertStringContainsString( 'page=blockendar-settings', $result['actions'] );

		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		$this->assertSame( 'good', $this->health->test_index()['status'] );
	}

	public function test_rows_left_behind_by_an_unpublished_event_are_reported(): void {
		global $wpdb;

		$post_id = $this->make_event();

		// Straight to the posts table: the builder's own hooks would clean up.
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'draft' ], [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB
		clean_post_cache( $post_id );

		$result = $this->health->test_index();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '1 event in the index is no longer published', wp_strip_all_tags( $result['description'] ) );
	}

	public function test_drift_is_not_reported_while_a_rebuild_is_queued(): void {
		global $wpdb;

		$post_id = $this->make_event();
		$table   = Schema::events_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB
		( new EventIndex() )->flush_cache();

		( new IndexBuilder() )->queue_rebuild( MINUTE_IN_SECONDS );

		$result = $this->health->test_index();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'being rebuilt', $result['label'] );
	}

	// -------------------------------------------------------------------------
	// Test 3: the calendar route
	// -------------------------------------------------------------------------

	/**
	 * A mocked HTTP response, in the shape wp_remote_get() returns.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 */
	private function http( int $code, string $body ): array {
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	public function test_a_calendar_route_that_answers_is_good(): void {
		$requested = null;

		add_filter(
			'pre_http_request',
			function ( $pre, array $args, string $url ) use ( &$requested ) {
				$requested = [ $url, $args ];

				return $this->http( 200, '[]' );
			},
			10,
			3
		);

		$result = $this->health->test_calendar_route();

		$this->assertSame( 'good', $result['status'] );
		$this->assertNotNull( $requested );
		$this->assertStringContainsString( 'blockendar%2Fv1%2Fcalendar', $requested[0] );
		$this->assertSame( [], $requested[1]['cookies'], 'The request is made the way a visitor makes it: without a login.' );
		$this->assertArrayNotHasKey( 'Authorization', $requested[1]['headers'] );
	}

	public function test_a_calendar_route_forced_to_403_is_critical(): void {
		add_filter( 'pre_http_request', fn() => $this->http( 403, '{"code":"rest_forbidden"}' ) );

		$result = $this->health->test_calendar_route();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'HTTP 403', wp_strip_all_tags( $result['description'] ) );
		$this->assertStringContainsString( 'empty calendar', $result['description'] );
	}

	public function test_a_body_that_is_not_the_calendars_json_is_critical(): void {
		add_filter( 'pre_http_request', fn() => $this->http( 200, '<html>Please enable JavaScript</html>' ) );

		$this->assertSame( 'critical', $this->health->test_calendar_route()['status'] );
	}

	public function test_a_failed_loopback_is_recommended_not_critical(): void {
		add_filter( 'pre_http_request', fn() => new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );

		$result = $this->health->test_calendar_route();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'Failed to connect', $result['description'] );
	}

	public function test_a_private_api_is_not_checked(): void {
		update_option( 'blockendar_settings', [ 'rest_public' => false ] );

		add_filter(
			'pre_http_request',
			function () {
				$this->fail( 'No request should be made when the API is private.' );
			}
		);

		$result = $this->health->test_calendar_route();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'private by setting', $result['label'] );
	}
}
