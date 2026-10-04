<?php
/**
 * Integration coverage for REST permission callbacks.
 *
 * These callbacks are the plugin's entire authorisation surface, and until now
 * nothing exercised them. A regression here silently opens write endpoints, which
 * is exactly the class of bug a unit test with mocked WordPress cannot catch.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\Meta\EventMeta;
use Blockendar\REST\EventsController;
use WP_REST_Request;
use WP_UnitTestCase;

class RestPermissionsTest extends WP_UnitTestCase {

	private EventsController $controller;

	public function set_up(): void {
		parent::set_up();
		$this->controller = new EventsController();
		delete_option( 'blockendar_settings' );

		// WP_UnitTestCase unregisters every meta key in tear_down(), so after the
		// first test in the run the core route has no event meta to serve — and a
		// test that asserts meta is withheld would pass without proving anything.
		( new EventMeta() )->register_meta();
	}

	public function tear_down(): void {
		delete_option( 'blockendar_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Public read
	// -------------------------------------------------------------------------

	public function test_public_read_allowed_by_default(): void {
		wp_set_current_user( 0 );

		$this->assertTrue(
			$this->controller->check_public_read(),
			'With no settings saved the API should default to public.'
		);
	}

	public function test_public_read_allowed_when_rest_public_true(): void {
		update_option( 'blockendar_settings', [ 'rest_public' => true ] );
		wp_set_current_user( 0 );

		$this->assertTrue( $this->controller->check_public_read() );
	}

	public function test_public_read_denied_for_logged_out_when_not_public(): void {
		update_option( 'blockendar_settings', [ 'rest_public' => false ] );
		wp_set_current_user( 0 );

		$this->assertFalse(
			$this->controller->check_public_read(),
			'Disabling rest_public must lock out anonymous readers.'
		);
	}

	public function test_public_read_allowed_for_subscriber_when_not_public(): void {
		update_option( 'blockendar_settings', [ 'rest_public' => false ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertTrue( $this->controller->check_public_read() );
	}

	// -------------------------------------------------------------------------
	// Feed token
	// -------------------------------------------------------------------------

	public function test_feed_token_grants_access_when_not_public(): void {
		update_option(
			'blockendar_settings',
			[
				'rest_public'     => false,
				'rest_feed_token' => 'sekrittokenvalue00',
			]
		);
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'token', 'sekrittokenvalue00' );

		$this->assertTrue( $this->controller->check_feed_read( $request ) );
	}

	public function test_feed_token_rejects_wrong_value(): void {
		update_option(
			'blockendar_settings',
			[
				'rest_public'     => false,
				'rest_feed_token' => 'sekrittokenvalue00',
			]
		);
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'token', 'wrong-token' );

		$this->assertFalse( $this->controller->check_feed_read( $request ) );
	}

	public function test_feed_token_rejects_empty_value(): void {
		update_option(
			'blockendar_settings',
			[
				'rest_public'     => false,
				'rest_feed_token' => 'sekrittokenvalue00',
			]
		);
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'token', '' );

		$this->assertFalse(
			$this->controller->check_feed_read( $request ),
			'An empty token must never match the stored token.'
		);
	}

	public function test_feed_token_not_accepted_when_none_configured(): void {
		update_option(
			'blockendar_settings',
			[
				'rest_public'     => false,
				'rest_feed_token' => '',
			]
		);
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'token', '' );

		$this->assertFalse( $this->controller->check_feed_read( $request ) );
	}

	// -------------------------------------------------------------------------
	// Write and manage capabilities
	// -------------------------------------------------------------------------

	/**
	 * Build a write request naming one specific event.
	 *
	 * @param int $post_id Event the request targets.
	 */
	private function edit_request( int $post_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', "/blockendar/v1/events/{$post_id}/recurrence" );
		$request->set_param( 'id', $post_id );

		return $request;
	}

	public function test_edit_permission_denied_for_anonymous(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );
		wp_set_current_user( 0 );

		$this->assertNotTrue( $this->controller->check_edit_permission( $this->edit_request( $post_id ) ) );
	}

	public function test_edit_permission_denied_for_subscriber(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertNotTrue( $this->controller->check_edit_permission( $this->edit_request( $post_id ) ) );
	}

	public function test_edit_permission_allowed_for_own_event(): void {
		$author_id = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$post_id   = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_author' => $author_id,
				'post_status' => 'draft',
			]
		);

		wp_set_current_user( $author_id );

		$this->assertTrue( $this->controller->check_edit_permission( $this->edit_request( $post_id ) ) );
	}

	/**
	 * The regression this suite exists for: 'edit_posts' is held by every role
	 * down to Contributor, so a permission callback that checks it without an
	 * object ID authorises writes against somebody else's event.
	 */
	public function test_edit_permission_denied_for_another_authors_event(): void {
		$owner_id    = self::factory()->user->create( [ 'role' => 'author' ] );
		$intruder_id = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$post_id     = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_author' => $owner_id,
				'post_status' => 'publish',
			]
		);

		wp_set_current_user( $intruder_id );

		$this->assertTrue(
			user_can( $intruder_id, 'edit_posts' ),
			'Precondition: a Contributor does hold the bare edit_posts capability.'
		);
		$this->assertNotTrue(
			$this->controller->check_edit_permission( $this->edit_request( $post_id ) ),
			'A Contributor must not be authorised to write to an event they do not own.'
		);
	}

	public function test_manage_permission_denied_for_editor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertFalse(
			$this->controller->check_manage_permission(),
			'Index rebuild must stay restricted to manage_options.'
		);
	}

	public function test_manage_permission_allowed_for_administrator(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $this->controller->check_manage_permission() );
	}

	// -------------------------------------------------------------------------
	// Route registration and live authorisation
	// -------------------------------------------------------------------------

	public function test_every_registered_route_declares_a_permission_callback(): void {
		do_action( 'rest_api_init' );

		$routes  = rest_get_server()->get_routes();
		$checked = 0;

		foreach ( $routes as $route => $handlers ) {
			// '/blockendar/v1' itself is the namespace index that WordPress core
			// registers for every namespace. It is core-owned and read-only, so it
			// is not one of the plugin's endpoints to guard.
			if ( ! str_starts_with( $route, '/blockendar/v1/' ) ) {
				continue;
			}

			foreach ( $handlers as $index => $handler ) {
				if ( ! isset( $handler['callback'] ) ) {
					continue;
				}

				++$checked;

				// Compare scalars rather than asserting against $handler directly:
				// the handler array carries closures and full arg schemas, and
				// PHPUnit's exporter cannot render those in a failure message.
				$callback = $handler['permission_callback'] ?? null;
				$label    = "{$route} [{$index}]";

				$this->assertNotNull( $callback, "Route {$label} has no permission_callback." );
				$this->assertNotSame( '__return_true', $callback, "Route {$label} is unguarded." );
				$this->assertTrue(
					is_callable( $callback ),
					"Route {$label} has a permission_callback that is not callable."
				);
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No blockendar routes were registered.' );
	}

	public function test_write_route_rejects_anonymous_request(): void {
		update_option( 'blockendar_settings', [ 'rest_public' => true ] );
		wp_set_current_user( 0 );

		do_action( 'rest_api_init' );

		$post_id  = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );
		$request  = new WP_REST_Request( 'POST', "/blockendar/v1/events/{$post_id}/instances/2026-09-01/cancel" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'Cancelling an instance must not be possible while logged out, even with a public read API.'
		);
	}

	public function test_rebuild_route_rejects_editor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		do_action( 'rest_api_init' );

		$request  = new WP_REST_Request( 'POST', '/blockendar/v1/index/rebuild' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	// -------------------------------------------------------------------------
	// Password-protected events
	// -------------------------------------------------------------------------

	/**
	 * Create a published, password-protected event with meta and one index row.
	 *
	 * The password is set at creation, not via a later wp_update_post(): that
	 * would fire save_post, and IndexBuilder::on_save() would rebuild the row
	 * this fixture inserts.
	 *
	 * @return int Post ID.
	 */
	private function seed_protected_event(): int {
		Schema::create_tables();

		$post_id = self::factory()->post->create(
			[
				'post_type'     => 'blockendar_event',
				'post_status'   => 'publish',
				'post_title'    => 'Members Only Gala',
				'post_password' => 'hunter2',
			]
		);

		update_post_meta( $post_id, 'blockendar_start_date', '2026-09-02' );
		update_post_meta( $post_id, 'blockendar_end_date', '2026-09-02' );
		update_post_meta( $post_id, 'blockendar_start_time', '19:00' );

		( new EventIndex() )->insert(
			[
				'post_id'        => $post_id,
				'start_datetime' => '2026-09-02 19:00:00',
				'end_datetime'   => '2026-09-02 21:00:00',
				'start_date'     => '2026-09-02',
				'end_date'       => '2026-09-02',
				'all_day'        => 0,
				'status'         => 'scheduled',
			]
		);

		return $post_id;
	}

	/**
	 * Dispatch a GET request through the REST server.
	 *
	 * @param string $route  Route to request.
	 * @param array  $params Query parameters.
	 */
	private function dispatch_get( string $route, array $params = [] ): \WP_REST_Response {
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A password-protected event is still 'publish'. The collection route
	 * excludes it in SQL; the single-event route has to make the same call
	 * itself or it hands out the title, dates and venue the password withholds.
	 */
	public function test_single_event_route_hides_a_password_protected_event(): void {
		$post_id = $this->seed_protected_event();
		wp_set_current_user( 0 );

		$response = $this->dispatch_get( "/blockendar/v1/events/{$post_id}" );
		$body     = (string) wp_json_encode( $response->get_data() );

		$this->assertSame( 404, $response->get_status() );
		$this->assertStringNotContainsString( 'Members Only Gala', $body );
		$this->assertStringNotContainsString( '2026-09-02', $body );
	}

	public function test_instances_route_hides_a_password_protected_event(): void {
		$post_id = $this->seed_protected_event();
		wp_set_current_user( 0 );

		$this->assertNotEmpty(
			( new EventIndex() )->get_by_post_id( $post_id ),
			'Precondition: the protected event has an index row to leak.'
		);

		$response = $this->dispatch_get( "/blockendar/v1/events/{$post_id}/instances" );
		$body     = (string) wp_json_encode( $response->get_data() );

		$this->assertSame( 404, $response->get_status() );
		$this->assertStringNotContainsString( '2026-09-02', $body );
	}

	public function test_an_editor_can_still_read_a_password_protected_event(): void {
		$post_id = $this->seed_protected_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 200, $this->dispatch_get( "/blockendar/v1/events/{$post_id}" )->get_status() );
		$this->assertSame( 200, $this->dispatch_get( "/blockendar/v1/events/{$post_id}/instances" )->get_status() );
	}

	/**
	 * Core serves registered post meta for a protected post: it withholds the
	 * content and excerpt, not the meta. With the API public, that would still
	 * publish the dates the plugin's own routes now refuse to give.
	 */
	public function test_core_route_withholds_event_meta_for_a_password_protected_event(): void {
		$post_id = $this->seed_protected_event();
		$venue   = self::factory()->term->create_and_get( [ 'taxonomy' => 'blockendar_event_venue' ] );
		wp_set_object_terms( $post_id, [ $venue->term_id ], 'blockendar_event_venue' );
		wp_set_current_user( 0 );

		$single = $this->dispatch_get( "/wp/v2/blockendar-events/{$post_id}" );
		$list   = $this->dispatch_get( '/wp/v2/blockendar-events' );

		$this->assertSame( 200, $single->get_status(), 'Core still serves the protected post itself.' );
		$this->assertStringNotContainsString( '2026-09-02', (string) wp_json_encode( $single->get_data() ) );
		$this->assertStringNotContainsString( '2026-09-02', (string) wp_json_encode( $list->get_data() ) );

		// The venue assignment leads straight to the venue's address.
		$this->assertArrayNotHasKey( 'event-venues', $single->get_data() );
	}

	/**
	 * Core lists a post's terms to anyone when the post is publicly viewable,
	 * and a password-protected post counts as that. Without this, withholding
	 * the venue from the event's own response is undone by asking the venue
	 * route which venues the event has.
	 */
	public function test_core_term_routes_do_not_list_the_terms_of_a_password_protected_event(): void {
		$post_id = $this->seed_protected_event();
		$venue   = self::factory()->term->create_and_get( [ 'taxonomy' => 'blockendar_event_venue' ] );
		wp_set_object_terms( $post_id, [ $venue->term_id ], 'blockendar_event_venue' );
		wp_set_current_user( 0 );

		$response = $this->dispatch_get( '/wp/v2/event-venues', [ 'post' => $post_id ] );

		$this->assertSame( 401, $response->get_status() );
		$this->assertStringNotContainsString( $venue->name, (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_core_term_routes_still_list_the_terms_of_an_unprotected_event(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
			]
		);
		$venue   = self::factory()->term->create_and_get( [ 'taxonomy' => 'blockendar_event_venue' ] );
		wp_set_object_terms( $post_id, [ $venue->term_id ], 'blockendar_event_venue' );
		wp_set_current_user( 0 );

		$response = $this->dispatch_get( '/wp/v2/event-venues', [ 'post' => $post_id ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ $venue->term_id ], wp_list_pluck( $response->get_data(), 'id' ) );
	}

	public function test_core_term_routes_list_a_protected_events_terms_for_an_editor(): void {
		$post_id = $this->seed_protected_event();
		$venue   = self::factory()->term->create_and_get( [ 'taxonomy' => 'blockendar_event_venue' ] );
		wp_set_object_terms( $post_id, [ $venue->term_id ], 'blockendar_event_venue' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->dispatch_get( '/wp/v2/event-venues', [ 'post' => $post_id ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ $venue->term_id ], wp_list_pluck( $response->get_data(), 'id' ) );
	}

	public function test_core_route_keeps_event_meta_for_an_editor(): void {
		$post_id = $this->seed_protected_event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$data = $this->dispatch_get( "/wp/v2/blockendar-events/{$post_id}" )->get_data();

		$this->assertSame( '2026-09-02', $data['meta']['blockendar_start_date'] );
		$this->assertArrayHasKey( 'event-venues', $data );
	}

	public function test_core_route_keeps_event_meta_for_an_unprotected_event(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $post_id, 'blockendar_start_date', '2026-09-03' );
		wp_set_current_user( 0 );

		$data = $this->dispatch_get( "/wp/v2/blockendar-events/{$post_id}" )->get_data();

		$this->assertSame( '2026-09-03', $data['meta']['blockendar_start_date'] );
	}

	// -------------------------------------------------------------------------
	// rest_public and the core routes
	// -------------------------------------------------------------------------

	/**
	 * The four core routes that serve event data: the post type and its three
	 * taxonomies.
	 *
	 * @return array<string, array{string}>
	 */
	public function core_routes(): array {
		return [
			'events' => [ '/wp/v2/blockendar-events' ],
			'venues' => [ '/wp/v2/event-venues' ],
			'types'  => [ '/wp/v2/event-types' ],
			'tags'   => [ '/wp/v2/event-tags' ],
		];
	}

	/**
	 * @dataProvider core_routes
	 *
	 * @param string $route Core route under test.
	 */
	public function test_core_route_refuses_anonymous_requests_when_not_public( string $route ): void {
		update_option( 'blockendar_settings', [ 'rest_public' => false ] );
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->dispatch_get( $route )->get_status() );
	}

	/**
	 * @dataProvider core_routes
	 *
	 * @param string $route Core route under test.
	 */
	public function test_core_route_allows_a_subscriber_when_not_public( string $route ): void {
		update_option( 'blockendar_settings', [ 'rest_public' => false ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 200, $this->dispatch_get( $route )->get_status() );
	}

	/**
	 * @dataProvider core_routes
	 *
	 * @param string $route Core route under test.
	 */
	public function test_core_route_stays_open_when_public( string $route ): void {
		update_option( 'blockendar_settings', [ 'rest_public' => true ] );
		wp_set_current_user( 0 );

		$this->assertSame( 200, $this->dispatch_get( $route )->get_status() );
	}

	public function test_single_core_item_is_refused_for_anonymous_requests_when_not_public(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
			]
		);
		$term    = self::factory()->term->create_and_get( [ 'taxonomy' => 'blockendar_event_venue' ] );

		update_option( 'blockendar_settings', [ 'rest_public' => false ] );
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->dispatch_get( "/wp/v2/blockendar-events/{$post_id}" )->get_status() );
		$this->assertSame( 401, $this->dispatch_get( "/wp/v2/event-venues/{$term->term_id}" )->get_status() );
	}

	/**
	 * The gate matches whole route segments, so it must leave alone a core
	 * route that merely shares a prefix with one of ours.
	 */
	public function test_unrelated_core_routes_stay_open_when_not_public(): void {
		update_option( 'blockendar_settings', [ 'rest_public' => false ] );
		wp_set_current_user( 0 );

		$this->assertSame( 200, $this->dispatch_get( '/wp/v2/posts' )->get_status() );
		$this->assertSame( 200, $this->dispatch_get( '/wp/v2/categories' )->get_status() );
	}
}
