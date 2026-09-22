<?php
/**
 * REST controller for the FullCalendar feed and iCal export.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

	/**
	 * Whether the current request is being served as an iCalendar feed.
	 *
	 * @var bool
	 */
	private bool $serving_ics = false;

	public function __construct() {
		$this->index = new EventIndex();
	}

	/**
	 * Attach hooks.
	 *
	 * Extends the base registration with the filter that writes the iCalendar
	 * body directly, bypassing JSON encoding.
	 */
	public function register(): void {
		parent::register();

		add_filter( 'rest_pre_serve_request', [ $this, 'serve_raw_ics' ], 10, 3 );
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
				'permission_callback' => [ $this, 'check_feed_read' ],
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
					'download' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
	}

	/**
	 * GET /blockendar/v1/calendar
	 */
	public function get_calendar_feed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$is_ics = 'ics' === $request->get_param( 'format' );

		// A subscribed client fetches the same URL forever, so the feed's default
		// window has to be relative to the request rather than a fixed span, or
		// the calendar silently stops moving. The JSON path keeps its own
		// defaults: FullCalendar always sends start and end explicitly.
		[ $default_start, $default_end ] = $is_ics
			? $this->subscription_window()
			: [ gmdate( 'Y-m-01 00:00:00' ), gmdate( 'Y-m-d 23:59:59', strtotime( 'last day of +1 month' ) ) ];

		$start = $this->parse_datetime_param(
			(string) ( $request->get_param( 'start' ) ?? '' ),
			$default_start
		);

		$end = $this->parse_datetime_param(
			(string) ( $request->get_param( 'end' ) ?? '' ),
			$default_end
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
			return $this->serve_ics( $rows, $request );
		}

		$events = array_map( [ $this, 'format_for_fullcalendar' ], $rows );

		return $this->respond( $events );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * The rolling window a subscribed feed covers when no range was requested.
	 *
	 * Computed in UTC, matching how the index stores datetimes, and snapped to
	 * whole days so an all-day event sitting on either edge is not clipped by a
	 * mid-day boundary.
	 *
	 * @return string[] Tuple of [ start, end ] as 'Y-m-d H:i:s' UTC strings.
	 */
	private function subscription_window(): array {
		$settings = get_option( 'blockendar_settings', [] );
		$past     = max( 0, min( 3650, (int) ( $settings['subscribe_past_days'] ?? 30 ) ) );
		$future   = max( 1, min( 3650, (int) ( $settings['subscribe_future_days'] ?? 365 ) ) );

		$now    = time();
		$window = [
			gmdate( 'Y-m-d 00:00:00', $now - ( $past * DAY_IN_SECONDS ) ),
			gmdate( 'Y-m-d 23:59:59', $now + ( $future * DAY_IN_SECONDS ) ),
		];

		/**
		 * Filters the rolling window used for a subscribed iCalendar feed.
		 *
		 * @param string[] $window Tuple of [ start, end ], 'Y-m-d H:i:s' in UTC.
		 * @param int      $past   Configured days of history.
		 * @param int      $future Configured days ahead.
		 */
		$filtered = array_values( (array) apply_filters( 'blockendar_ics_window', $window, $past, $future ) );

		// A filter returning something unusable must not take the feed down.
		if ( 2 !== count( $filtered ) ) {
			return $window;
		}

		return [ (string) $filtered[0], (string) $filtered[1] ];
	}

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

		$ongoing = ! empty( $row->ongoing );

		// FullCalendar expects ISO 8601. Convert UTC to the site timezone so startStr is correct.
		$start = $this->to_iso8601( $row->start_datetime, (bool) $row->all_day, $row->start_date );

		$event = [
			'id'            => "blockendar_{$post_id}_{$row->start_date}",
			'post_id'       => $post_id,
			'title'         => html_entity_decode( $row->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'start'         => $start,
			'allDay'        => (bool) $row->all_day,
			'url'           => add_query_arg( 'occurrence_date', $row->start_date, get_permalink( $post_id ) ),
			'color'         => $color,
			'status'        => $row->status,
			'extendedProps' => [
				'venue'    => $venue,
				'types'    => $types,
				'cost'     => $cost,
				'featured' => $featured,
				'ongoing'  => $ongoing,
			],
		];

		// Ongoing events carry a sentinel end in the index; give FullCalendar no
		// end at all so the chip renders on the start day only.
		if ( ! $ongoing ) {
			$event['end'] = $this->to_iso8601( $row->end_datetime, (bool) $row->all_day, $row->end_date );
		}

		return $event;
	}

	/**
	 * Return a date string for FullCalendar.
	 * All-day events use date-only strings; timed events use an offset-aware
	 * ISO 8601 string in the site timezone so FullCalendar's startStr reflects
	 * the local time rather than UTC.
	 */
	private function to_iso8601( string $utc_datetime, bool $all_day, string $date ): string {
		if ( $all_day ) {
			return $date;
		}

		try {
			$dt = new \DateTimeImmutable( $utc_datetime, new \DateTimeZone( 'UTC' ) );
			$dt = $dt->setTimezone( wp_timezone() );
			return $dt->format( 'Y-m-d\TH:i:sP' );
		} catch ( \Exception $e ) {
			// Fallback to UTC string if conversion fails.
			return str_replace( ' ', 'T', $utc_datetime ) . 'Z';
		}
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
	private function serve_ics( array $rows, WP_REST_Request $request ): WP_REST_Response {
		$exporter = new Exporter();
		$ics      = $exporter->generate_feed( $rows );

		$response = new WP_REST_Response( $ics );
		$response->header( 'Content-Type', 'text/calendar; charset=utf-8' );

		// A subscription is fetched by a calendar client, not saved by a person,
		// so the feed is served inline unless a download was explicitly asked for.
		$response->header(
			'Content-Disposition',
			$request->get_param( 'download' )
				? 'attachment; filename="blockendar-events.ics"'
				: 'inline'
		);

		$response->header( 'Cache-Control', $this->feed_cache_control( $request ) );

		// Mark the response so rest_pre_serve_request() knows to emit the body
		// verbatim rather than letting the server JSON-encode it.
		$this->serving_ics = true;

		return $response;
	}

	/**
	 * Cache-Control value for a feed response.
	 *
	 * An open feed is the same for everyone and can sit in a shared cache. Any
	 * response that needed a token or a login is specific to whoever asked for
	 * it — and a token travels in the URL — so those must never be stored by an
	 * intermediary and handed to the next caller.
	 *
	 * @param WP_REST_Request $request Current request.
	 */
	private function feed_cache_control( WP_REST_Request $request ): string {
		$settings  = get_option( 'blockendar_settings', [] );
		$is_public = ! isset( $settings['rest_public'] ) || (bool) $settings['rest_public'];
		$has_token = '' !== (string) ( $request->get_param( 'token' ) ?? '' );

		if ( $is_public && ! $has_token ) {
			return 'public, max-age=3600';
		}

		return 'private, no-store';
	}

	/**
	 * Emit the iCalendar body verbatim.
	 *
	 * A WP_REST_Response holding a raw string is JSON-encoded by the server,
	 * which turns the feed into a quoted string with literal \r\n escapes that
	 * no calendar client can parse. Taking over the write here is the documented
	 * way to bypass that; headers set on the response have already been sent by
	 * the time this filter runs.
	 *
	 * @param bool  $served  Whether the request was already served.
	 * @param mixed $result  Response to send.
	 * @param mixed $request Current request.
	 * @return bool True when this filter wrote the body itself.
	 */
	public function serve_raw_ics( bool $served, $result, $request ): bool {
		if ( $served || ! $this->serving_ics ) {
			return $served;
		}

		if ( ! $result instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $served;
		}

		// Only ever take over our own feed route.
		if ( '/' . self::NAMESPACE . '/calendar' !== $request->get_route() ) {
			return $served;
		}

		if ( 'ics' !== $request->get_param( 'format' ) ) {
			return $served;
		}

		$body = $result->get_data();

		if ( ! is_string( $body ) ) {
			return $served;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar body, escaped by Exporter per RFC 5545.
		echo $body;

		return true;
	}
}
