<?php
/**
 * REST controller for the FullCalendar feed and iCal export.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\REST;

use Blockendar\DB\EventIndex;
use Blockendar\ICS\Exporter;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Handles GET /blockendar/v1/calendar
 *
 * Returns FullCalendar-compatible event objects, or an iCal feed when
 * ?format=ics is passed.
 */
class CalendarController extends AbstractController {

	private EventIndex $index;

	public function __construct() {
		$this->index = new EventIndex();
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/calendar',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_calendar_feed' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'start'    => [
						'type'    => 'string',
						'default' => '',
					],
					'end'      => [
						'type'    => 'string',
						'default' => '',
					],
					'venue'    => [
						'type'    => 'string',
						'default' => '',
					],
					'type'     => [
						'type'    => 'string',
						'default' => '',
					],
					'featured' => [ 'type' => 'boolean' ],
					'format'   => [
						'type'    => 'string',
						'default' => 'json',
						'enum'    => [ 'json', 'ics' ],
					],
				],
			]
		);
	}

	/**
	 * GET /blockendar/v1/calendar
	 */
	public function get_calendar_feed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Default window: current month ± some buffer. FullCalendar always sends start+end.
		$start = $this->parse_datetime_param(
			(string) ( $request->get_param( 'start' ) ?? '' ),
			gmdate( 'Y-m-01 00:00:00' )
		);

		$end = $this->parse_datetime_param(
			(string) ( $request->get_param( 'end' ) ?? '' ),
			gmdate( 'Y-m-d 23:59:59', strtotime( 'last day of +1 month' ) )
		);

		if ( is_wp_error( $start ) ) {
			return $start;
		}

		if ( is_wp_error( $end ) ) {
			return $end;
		}

		$filters = [
			'venue_term_id' => $this->parse_id_list( $request->get_param( 'venue' ) ),
			'type_term_id'  => $this->parse_id_list( $request->get_param( 'type' ) ),
			'featured'      => $request->get_param( 'featured' ) ? rest_sanitize_boolean( $request->get_param( 'featured' ) ) : null,
			'per_page'      => 500,
			'page'          => 1,
		];

		$rows = $this->index->get_events_in_range( $start, $end, $filters );

		if ( 'ics' === $request->get_param( 'format' ) ) {
			return $this->serve_ics( $rows );
		}

		$events = array_map( [ $this, 'format_for_fullcalendar' ], $rows );

		return $this->respond( $events );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Parse a comma-separated ID string into an array of positive integers.
	 * Returns null when the string is empty so the filter is skipped entirely.
	 *
	 * @param string|null $value Raw param value e.g. "1,2,3".
	 * @return int[]|null
	 */
	private function parse_id_list( ?string $value ): ?array {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$ids = array_filter(
			array_map( 'intval', explode( ',', $value ) ),
			fn( $id ) => $id > 0
		);

		return ! empty( $ids ) ? array_values( $ids ) : null;
	}

	// -------------------------------------------------------------------------
	// Formatters
	// -------------------------------------------------------------------------

	/**
	 * Format an index row into the FullCalendar event object shape.
	 *
	 * @param object $row Index row joined with wp_posts.
	 */
	private function format_for_fullcalendar( object $row ): array {
		$post_id  = (int) $row->post_id;
		$type_ids = $row->type_term_ids ? json_decode( $row->type_term_ids, true ) : [];
		$color    = $this->resolve_color( $type_ids );
		$venue    = $this->get_venue_summary( $row->venue_term_id ? (int) $row->venue_term_id : null );
		$types    = $this->get_type_summaries( $type_ids );
		$cost     = get_post_meta( $post_id, 'blockendar_cost', true );
		$featured = (bool) get_post_meta( $post_id, 'blockendar_featured', true );

		// FullCalendar expects ISO 8601. The index stores UTC — append Z.
		$start = $this->to_iso8601( $row->start_datetime, (bool) $row->all_day, $row->start_date );
		$end   = $this->to_iso8601( $row->end_datetime, (bool) $row->all_day, $row->end_date );

		return [
			'id'            => "blockendar_{$post_id}_{$row->start_date}",
			'post_id'       => $post_id,
			'title'         => $row->post_title,
			'start'         => $start,
			'end'           => $end,
			'allDay'        => (bool) $row->all_day,
			'url'           => get_permalink( $post_id ),
			'color'         => $color,
			'status'        => $row->status,
			'extendedProps' => [
				'venue'    => $venue,
				'types'    => $types,
				'cost'     => $cost,
				'featured' => $featured,
			],
		];
	}

	/**
	 * Return a date string for FullCalendar.
	 * All-day events use date-only strings; timed events use UTC ISO 8601.
	 */
	private function to_iso8601( string $utc_datetime, bool $all_day, string $date ): string {
		if ( $all_day ) {
			return $date;
		}

		return str_replace( ' ', 'T', $utc_datetime ) . 'Z';
	}

	/**
	 * Resolve the display colour from event type terms (first term with a colour wins).
	 *
	 * @param int[] $type_ids Event type term IDs.
	 */
	private function resolve_color( array $type_ids ): string {
		foreach ( $type_ids as $type_id ) {
			$color = get_term_meta( (int) $type_id, 'blockendar_type_color', true );

			if ( '' !== $color ) {
				return $color;
			}
		}

		return '';
	}

	/**
	 * Get a minimal venue summary from a term ID.
	 *
	 * @param int|null $venue_term_id Venue term ID.
	 */
	private function get_venue_summary( ?int $venue_term_id ): ?array {
		if ( null === $venue_term_id ) {
			return null;
		}

		$term = get_term( $venue_term_id, 'event_venue' );

		if ( is_wp_error( $term ) || null === $term ) {
			return null;
		}

		return [
			'id'   => $term->term_id,
			'name' => $term->name,
			'city' => get_term_meta( $term->term_id, 'blockendar_venue_city', true ),
		];
	}

	/**
	 * Get minimal summaries for each event type.
	 *
	 * @param int[] $type_ids Event type term IDs.
	 * @return array[]
	 */
	private function get_type_summaries( array $type_ids ): array {
		$types = [];

		foreach ( $type_ids as $type_id ) {
			$term = get_term( (int) $type_id, 'event_type' );

			if ( is_wp_error( $term ) || null === $term ) {
				continue;
			}

			$types[] = [
				'id'   => $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
			];
		}

		return $types;
	}

	// -------------------------------------------------------------------------
	// iCal output
	// -------------------------------------------------------------------------

	/**
	 * Stream the event rows as an iCal (.ics) feed.
	 *
	 * @param object[] $rows Index rows.
	 */
	private function serve_ics( array $rows ): WP_REST_Response {
		$exporter = new Exporter();
		$ics      = $exporter->generate_feed( $rows );

		$response = new WP_REST_Response( $ics );
		$response->header( 'Content-Type', 'text/calendar; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="blockendar-events.ics"' );

		return $response;
	}
}
