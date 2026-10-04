<?php
/**
 * REST endpoint that serves a single event as an .ics file.
 *
 * GET /wp-json/blockendar/v1/events/{id}/ical
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\CPT\EventPostType;
use Blockendar\ICS\Exporter;

/**
 * Serves .ics (iCalendar) files for individual events.
 */
class IcsEndpoint {

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	/**
	 * Register the REST route.
	 */
	public function register_route(): void {
		register_rest_route(
			'blockendar/v1',
			'/events/(?P<id>\d+)/ical',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'serve_ics' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id'              => [
						'validate_callback' => fn( $v ) => is_numeric( $v ),
						'sanitize_callback' => 'absint',
					],
					'occurrence_date' => [
						'description'       => __( 'Start date (Y-m-d) of the occurrence of a recurring event to export. Defaults to the next one.', 'blockendar' ),
						'type'              => 'string',
						'validate_callback' => [ $this, 'is_date' ],
					],
				],
			]
		);
	}

	/**
	 * Whether a value is a real calendar date written as Y-m-d.
	 *
	 * @param mixed $value Raw param value.
	 */
	public function is_date( mixed $value ): bool {
		return is_string( $value )
			&& 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts )
			&& checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * Permission check: public when rest_public is enabled (default), otherwise
	 * requires a valid feed token or a logged-in user with 'read' capability.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function check_permission( \WP_REST_Request $request ): bool {
		$settings = get_option( 'blockendar_settings', [] );

		if ( ! isset( $settings['rest_public'] ) || (bool) $settings['rest_public'] ) {
			return true;
		}

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
	 * Output the .ics file and exit.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function serve_ics( \WP_REST_Request $request ): void {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || $post->post_type !== EventPostType::POST_TYPE || 'publish' !== $post->post_status ) {
			wp_die( esc_html__( 'Event not found.', 'blockendar' ), 404 );
		}

		// A password-protected event is still 'publish'. This route does not go
		// through EventIndex, so it needs its own check or it would hand out the
		// title, dates and venue address the password exists to withhold.
		if ( '' !== $post->post_password && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Event not found.', 'blockendar' ), 404 );
		}

		/*
		 * Built by the same Exporter the feed uses. It previously assembled its
		 * own VEVENT, which gave the same event a different UID here than in the
		 * feed — a client holding both saw two unrelated events — and skipped
		 * line folding, venue, status and revision properties.
		 */
		$occurrence_date = $request->get_param( 'occurrence_date' );
		$occurrence_date = is_string( $occurrence_date ) && '' !== $occurrence_date ? $occurrence_date : null;

		$ics = ( new Exporter() )->generate_single( $post_id, $occurrence_date );

		if ( null === $ics ) {
			wp_die(
				null === $occurrence_date
					? esc_html__( 'Event has no date.', 'blockendar' )
					: esc_html__( 'Occurrence not found.', 'blockendar' ),
				404
			);
		}

		// One file per occurrence, so two downloads from a series do not share a name.
		$filename = get_post_field( 'post_name', $post_id ) . ( null === $occurrence_date ? '' : '-' . $occurrence_date );

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '.ics"' );
		header( 'Cache-Control: no-cache, must-revalidate' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar body, escaped by Exporter per RFC 5545.
		echo $ics;
		exit;
	}
}
