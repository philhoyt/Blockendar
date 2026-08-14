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

use Blockendar\REST\EventsController;
use WP_REST_Request;
use WP_UnitTestCase;

class RestPermissionsTest extends WP_UnitTestCase {

	private EventsController $controller;

	public function set_up(): void {
		parent::set_up();
		$this->controller = new EventsController();
		delete_option( 'blockendar_settings' );
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
				'rest_feed_token' => 'sekrit-token-value',
			]
		);
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'token', 'sekrit-token-value' );

		$this->assertTrue( $this->controller->check_feed_read( $request ) );
	}

	public function test_feed_token_rejects_wrong_value(): void {
		update_option(
			'blockendar_settings',
			[
				'rest_public'     => false,
				'rest_feed_token' => 'sekrit-token-value',
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
				'rest_feed_token' => 'sekrit-token-value',
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

	public function test_edit_permission_denied_for_anonymous(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( $this->controller->check_edit_permission() );
	}

	public function test_edit_permission_denied_for_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertFalse( $this->controller->check_edit_permission() );
	}

	public function test_edit_permission_allowed_for_contributor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$this->assertTrue( $this->controller->check_edit_permission() );
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
}
