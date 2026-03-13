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
					'id' => [
						'validate_callback' => fn( $v ) => is_numeric( $v ),
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
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
		$post_id = $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || $post->post_type !== EventPostType::POST_TYPE || $post->post_status !== 'publish' ) {
			wp_die( esc_html__( 'Event not found.', 'blockendar' ), 404 );
		}

		$start_date = get_post_meta( $post_id, 'blockendar_start_date', true );
		if ( ! $start_date ) {
			wp_die( esc_html__( 'Event has no date.', 'blockendar' ), 404 );
		}

		$end_date   = get_post_meta( $post_id, 'blockendar_end_date', true ) ?: $start_date;
		$start_time = get_post_meta( $post_id, 'blockendar_start_time', true ) ?: '00:00:00';
		$end_time   = get_post_meta( $post_id, 'blockendar_end_time', true ) ?: $start_time;
		$all_day    = (bool) get_post_meta( $post_id, 'blockendar_all_day', true );
		$tz_str     = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();
		$title      = get_the_title( $post_id );
		$url        = get_permalink( $post_id );
		$uid        = $post_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );

		$fmt = function ( string $date, string $time ) use ( $tz_str, $all_day ): string {
			if ( $all_day ) {
				return str_replace( '-', '', $date );
			}
			try {
				$dt = new \DateTimeImmutable( "$date $time", new \DateTimeZone( $tz_str ) );
				return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
			} catch ( \Exception ) {
				return str_replace( '-', '', $date );
			}
		};

		$dtstart = $fmt( $start_date, $start_time );
		$dtend   = $fmt( $end_date, $end_time );
		$now     = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Ymd\THis\Z' );
		$slug    = get_post_field( 'post_name', $post_id );

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Blockendar//Blockendar//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			"UID:$uid",
			"DTSTAMP:$now",
			$all_day ? "DTSTART;VALUE=DATE:$dtstart" : "DTSTART:$dtstart",
			$all_day ? "DTEND;VALUE=DATE:$dtend" : "DTEND:$dtend",
			'SUMMARY:' . $this->escape_ical( $title ),
			'URL:' . $this->escape_ical( $url ),
			'END:VEVENT',
			'END:VCALENDAR',
		];

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $slug ) . '.ics"' );
		header( 'Cache-Control: no-cache, must-revalidate' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( "\r\n", $lines );
		exit;
	}

	/**
	 * Escape special iCalendar characters.
	 */
	private function escape_ical( string $value ): string {
		return str_replace(
			[ '\\', ',', ';', "\n" ],
			[ '\\\\', '\\,', '\\;', '\\n' ],
			$value
		);
	}
}
