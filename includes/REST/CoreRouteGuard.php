<?php
/**
 * Guards for the core wp/v2 routes that serve event data.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\CPT\EventPostType;
use Blockendar\ICS\FeedUrl;
use Blockendar\Taxonomy\EventTag;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Applies the plugin's read rules to routes core registers on its behalf.
 *
 * The event post type and its three taxonomies are show_in_rest, which the
 * block editor needs. That also puts every event's dates, cost and venue, and
 * every venue's address, on wp/v2 — outside the blockendar/v1 permission
 * callbacks, so neither the rest_public setting nor a post password reached
 * them.
 */
class CoreRouteGuard {

	const TAXONOMIES = [ Venue::TAXONOMY, EventType::TAXONOMY, EventTag::TAXONOMY ];

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'rest_request_before_callbacks', [ $this, 'require_login_when_not_public' ], 10, 3 );
		add_filter( 'rest_request_before_callbacks', [ $this, 'refuse_terms_of_protected_event' ], 10, 3 );
		add_filter( 'rest_prepare_' . EventPostType::POST_TYPE, [ $this, 'withhold_protected_data' ], 10, 2 );
	}

	/**
	 * Refuse anonymous requests to the core event routes when the API is not public.
	 *
	 * Mirrors AbstractController::check_public_read(): with rest_public off, a
	 * reader needs to be logged in with the 'read' capability.
	 *
	 * @param mixed           $response Result so far; a WP_Error if an earlier filter refused.
	 * @param array           $handler  Route handler matched for the request.
	 * @param WP_REST_Request $request  Current request.
	 * @return mixed
	 */
	public function require_login_when_not_public( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		if ( is_wp_error( $response ) || FeedUrl::is_publicly_readable() || current_user_can( 'read' ) ) {
			return $response;
		}

		if ( ! $this->is_guarded_route( $request->get_route() ) ) {
			return $response;
		}

		return new WP_Error(
			'blockendar_rest_not_public',
			__( 'Sorry, you must be logged in to read event data.', 'blockendar' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * Refuse to list the terms of a password-protected event.
	 *
	 * The term routes accept ?post= and answer for any publicly viewable post,
	 * which a protected post is. Left alone, that names the venue that
	 * withhold_protected_data() removes from the event's own response.
	 *
	 * @param mixed           $response Result so far; a WP_Error if an earlier filter refused.
	 * @param array           $handler  Route handler matched for the request.
	 * @param WP_REST_Request $request  Current request.
	 * @return mixed
	 */
	public function refuse_terms_of_protected_event( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		if ( is_wp_error( $response ) || empty( $request['post'] ) ) {
			return $response;
		}

		$post = get_post( (int) $request['post'] );

		if ( ! $post || EventPostType::POST_TYPE !== $post->post_type || '' === $post->post_password ) {
			return $response;
		}

		if ( current_user_can( 'edit_post', $post->ID ) || ! $this->is_guarded_route( $request->get_route() ) ) {
			return $response;
		}

		return new WP_Error(
			'blockendar_rest_protected_event',
			__( 'Sorry, you are not allowed to view terms for this event.', 'blockendar' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * Remove event data from a password-protected event's core response.
	 *
	 * Core withholds the content and excerpt of a protected post but serves
	 * its registered meta and term assignments, which here are the dates, cost,
	 * registration URL and venue the password exists to withhold.
	 *
	 * @param WP_REST_Response $response Prepared response.
	 * @param WP_Post          $post     Event being served.
	 */
	public function withhold_protected_data( WP_REST_Response $response, WP_Post $post ): WP_REST_Response {
		if ( '' === $post->post_password || current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( array_keys( $data['meta'] ) as $key ) {
				if ( str_starts_with( (string) $key, 'blockendar_' ) ) {
					unset( $data['meta'][ $key ] );
				}
			}
		}

		foreach ( self::TAXONOMIES as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( $object ) {
				unset( $data[ $object->rest_base ?: $object->name ] );
			}
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Whether a route is one of the core routes serving event data.
	 *
	 * Matches whole path segments, so /wp/v2/event-types does not catch another
	 * plugin's /wp/v2/event-types-archive.
	 *
	 * @param string $route Requested route.
	 */
	private function is_guarded_route( string $route ): bool {
		foreach ( $this->guarded_prefixes() as $prefix ) {
			if ( $route === $prefix || str_starts_with( $route, $prefix . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Route prefixes of the event post type and its taxonomies.
	 *
	 * Read from the registered objects rather than hard-coded, so a site that
	 * filters rest_base or rest_namespace stays covered.
	 *
	 * @return string[]
	 */
	private function guarded_prefixes(): array {
		$objects = array_map( 'get_taxonomy', self::TAXONOMIES );

		$objects[] = get_post_type_object( EventPostType::POST_TYPE );

		$prefixes = [];

		foreach ( $objects as $object ) {
			if ( ! $object || ! $object->show_in_rest ) {
				continue;
			}

			$prefixes[] = '/' . $object->rest_namespace . '/' . ( $object->rest_base ?: $object->name );
		}

		return $prefixes;
	}
}
