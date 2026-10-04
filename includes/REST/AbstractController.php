<?php
/**
 * Base REST controller with shared helpers.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Shared utilities for all Blockendar REST controllers.
 */
abstract class AbstractController {

	const NAMESPACE = 'blockendar/v1';

	/**
	 * Register routes. Implemented by each controller.
	 */
	abstract public function register_routes(): void;

	/**
	 * Attach the register_routes hook.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Return a success response.
	 *
	 * @param mixed $data    Response data.
	 * @param int   $status  HTTP status code.
	 * @param array $headers Additional headers.
	 */
	protected function respond( mixed $data, int $status = 200, array $headers = [] ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );

		foreach ( $headers as $key => $value ) {
			$response->header( $key, $value );
		}

		return $response;
	}

	/**
	 * Turn a start or end param into the UTC datetime the index is queried with.
	 *
	 * Three forms are accepted:
	 *
	 * - Y-m-d — a calendar day in the site's timezone. As a start it is that
	 *   day's local midnight; as an end it is the next local midnight, so the
	 *   named day is included. The same date as both bounds is that whole day.
	 * - Y-m-d H:i:s — a UTC datetime, used as it is.
	 * - ISO 8601 — honoured with its offset, and read as UTC when it has none.
	 *
	 * @param string $value    Raw param value.
	 * @param string $fallback Value to use when the param is empty; already UTC.
	 * @param string $bound    'start' or 'end': which edge of the range this is.
	 * @return string|WP_Error Y-m-d H:i:s in UTC, or an error for a value that is not a date.
	 */
	protected function parse_datetime_param( string $value, string $fallback = '', string $bound = 'start' ): string|WP_Error {
		if ( '' === $value ) {
			return $fallback;
		}

		$utc = new \DateTimeZone( 'UTC' );

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			// The pattern alone lets 2026-02-30 through.
			if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				return $this->invalid_datetime_error();
			}

			$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

			if ( 'end' === $bound ) {
				$day = $day->modify( '+1 day' );
			}

			return $day->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		}

		// Accept Y-m-d H:i:s.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}

		// Accept ISO 8601 with T separator and optional timezone.
		// Always normalize to UTC so comparisons against the UTC index table are correct.
		try {
			$dt = new \DateTimeImmutable( $value, $utc );
			return $dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception ) {
			return $this->invalid_datetime_error();
		}
	}

	/**
	 * The error for a start or end param that is not a date.
	 */
	private function invalid_datetime_error(): WP_Error {
		return new WP_Error(
			'blockendar_invalid_datetime',
			__( 'Invalid datetime parameter. Expected Y-m-d or ISO 8601 format.', 'blockendar' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Check if the current user can edit one specific post.
	 *
	 * Write endpoints that take a post ID in the route MUST use this rather
	 * than a bare 'edit_posts' check: 'edit_posts' is held by every role down
	 * to Contributor, so checking it alone authorises the caller to act on any
	 * event, including ones they do not own.
	 *
	 * @param int $post_id Post the caller wants to modify.
	 */
	public function can_edit_post( int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Whether the current user may read one event through a public read route.
	 *
	 * Every route that returns a single event's data MUST pass through this. A
	 * password-protected event is still 'publish', so a status check alone hands
	 * out the title, dates and venue the password exists to withhold. The
	 * collection routes make the same decision in SQL, in EventIndex.
	 *
	 * @param \WP_Post $post Event the caller wants to read.
	 */
	public function can_read_event( \WP_Post $post ): bool {
		if ( 'publish' === $post->post_status && '' === $post->post_password ) {
			return true;
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Check if the current user can manage options (for admin endpoints).
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for public-read endpoints.
	 *
	 * Returns true when the REST API is set to public (the default).
	 * When rest_public is disabled, requires the user to be logged in with
	 * at least the 'read' capability.
	 */
	public function check_public_read(): bool {
		$settings = get_option( 'blockendar_settings', [] );

		if ( ! isset( $settings['rest_public'] ) || (bool) $settings['rest_public'] ) {
			return true;
		}

		return current_user_can( 'read' );
	}

	/**
	 * Permission callback for the calendar/ICS feed endpoints.
	 *
	 * Same as check_public_read() but also accepts a ?token= query parameter
	 * matching the rest_feed_token setting, allowing tokenised embed access
	 * without a WordPress login.
	 *
	 * @param WP_REST_Request $request The current REST request.
	 */
	public function check_feed_read( WP_REST_Request $request ): bool {
		$settings = get_option( 'blockendar_settings', [] );

		if ( ! isset( $settings['rest_public'] ) || (bool) $settings['rest_public'] ) {
			return true;
		}

		// Allow token-based access even when the API is not public.
		$stored_token = $settings['rest_feed_token'] ?? '';
		if ( '' !== $stored_token ) {
			$provided_token = (string) ( $request->get_param( 'token' ) ?? '' );
			if ( '' !== $provided_token && hash_equals( $stored_token, $provided_token ) ) {
				return true;
			}
		}

		return current_user_can( 'read' );
	}

	/**
	 * Build pagination headers from total count and request params.
	 *
	 * @param int             $total    Total matching items.
	 * @param int             $per_page Items per page.
	 * @param int             $page     Current page.
	 * @param WP_REST_Request $request  The REST request.
	 * @return array Header key => value pairs.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $page and $request reserved for future Link header support.
	protected function pagination_headers( int $total, int $per_page, int $page, WP_REST_Request $request ): array {
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		return [
			'X-WP-Total'      => (string) $total,
			'X-WP-TotalPages' => (string) $total_pages,
		];
	}
}
