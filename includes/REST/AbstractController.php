<?php
/**
 * Base REST controller with shared helpers.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\REST;

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
	 * Parse and validate a UTC datetime param (Y-m-d or Y-m-d H:i:s).
	 * Returns a Y-m-d H:i:s string or a WP_Error.
	 *
	 * @param string $value    Raw param value.
	 * @param string $fallback Fallback value if empty.
	 */
	protected function parse_datetime_param( string $value, string $fallback = '' ): string|WP_Error {
		if ( '' === $value ) {
			return $fallback;
		}

		// Accept Y-m-d (date only — assume start/end of day upstream).
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value . ' 00:00:00';
		}

		// Accept Y-m-d H:i:s.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}

		// Accept ISO 8601 with T separator and optional timezone.
		// Always normalize to UTC so comparisons against the UTC index table are correct.
		try {
			$dt = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
			return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception ) {
			return new WP_Error(
				'blockendar_invalid_datetime',
				__( 'Invalid datetime parameter. Expected Y-m-d or ISO 8601 format.', 'blockendar' ),
				[ 'status' => 400 ]
			);
		}
	}

	/**
	 * Check if the current user can edit posts (for write endpoints).
	 */
	protected function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check if the current user can manage options (for admin endpoints).
	 */
	protected function can_manage(): bool {
		return current_user_can( 'manage_options' );
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
