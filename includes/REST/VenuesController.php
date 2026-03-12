<?php
/**
 * REST controller for venue data.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\REST;

use Blockendar\DB\EventIndex;
use Blockendar\Taxonomy\Venue;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Handles:
 *   GET /blockendar/v1/venues
 *   GET /blockendar/v1/venues/{id}
 */
class VenuesController extends AbstractController {

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
			'/venues',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_venues' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'per_page' => [
						'type'    => 'integer',
						'default' => 100,
						'minimum' => 1,
						'maximum' => 500,
					],
					'page'     => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'search'   => [
						'type'    => 'string',
						'default' => '',
					],
					'virtual'  => [ 'type' => 'boolean' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/venues/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_venue' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'type'    => 'integer',
						'minimum' => 1,
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /blockendar/v1/venues
	 */
	public function get_venues( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$per_page = min( 500, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 100 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$search   = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?? '' ) );
		$virtual  = $request->get_param( 'virtual' );

		$args = [
			'taxonomy'   => Venue::TAXONOMY,
			'hide_empty' => false,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'orderby'    => 'name',
			'order'      => 'ASC',
		];

		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		// Filter by virtual flag via meta query.
		if ( null !== $virtual ) {
			$args['meta_query'] = [
				[
					'key'     => 'blockendar_venue_virtual',
					'value'   => rest_sanitize_boolean( $virtual ) ? '1' : '0',
					'compare' => '=',
				],
			];
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$total     = wp_count_terms(
			[
				'taxonomy'   => Venue::TAXONOMY,
				'hide_empty' => false,
			]
		);
		$total_int = is_wp_error( $total ) ? 0 : (int) $total;

		$data = array_map( [ $this, 'format_venue' ], $terms );

		return $this->respond(
			$data,
			200,
			$this->pagination_headers( $total_int, $per_page, $page, $request )
		);
	}

	/**
	 * GET /blockendar/v1/venues/{id}
	 */
	public function get_venue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$term_id = (int) $request->get_param( 'id' );
		$term    = get_term( $term_id, Venue::TAXONOMY );

		if ( is_wp_error( $term ) || null === $term ) {
			return new WP_Error(
				'blockendar_venue_not_found',
				__( 'Venue not found.', 'blockendar' ),
				[ 'status' => 404 ]
			);
		}

		$venue = $this->format_venue( $term );

		// Attach upcoming events at this venue.
		$now     = gmdate( 'Y-m-d H:i:s' );
		$horizon = gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) );
		$rows    = $this->index->get_events_in_range(
			$now,
			$horizon,
			[
				'venue_term_id' => $term_id,
				'per_page'      => 20,
			]
		);

		$venue['upcoming_events'] = array_map(
			fn( $row ) => [
				'post_id'        => (int) $row->post_id,
				'title'          => $row->post_title,
				'url'            => get_permalink( (int) $row->post_id ),
				'start_datetime' => $row->start_datetime,
				'end_datetime'   => $row->end_datetime,
				'all_day'        => (bool) $row->all_day,
				'status'         => $row->status,
			],
			$rows
		);

		return $this->respond( $venue );
	}

	// -------------------------------------------------------------------------
	// Formatter
	// -------------------------------------------------------------------------

	/**
	 * Format a venue WP_Term object into a full API response shape.
	 *
	 * @param \WP_Term $term Venue taxonomy term.
	 */
	private function format_venue( \WP_Term $term ): array {
		$id = $term->term_id;

		return [
			'id'          => $id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'url'         => get_term_link( $term ),
			'address'     => [
				'line1'    => get_term_meta( $id, 'blockendar_venue_address', true ),
				'line2'    => get_term_meta( $id, 'blockendar_venue_address2', true ),
				'city'     => get_term_meta( $id, 'blockendar_venue_city', true ),
				'state'    => get_term_meta( $id, 'blockendar_venue_state', true ),
				'postcode' => get_term_meta( $id, 'blockendar_venue_postcode', true ),
				'country'  => get_term_meta( $id, 'blockendar_venue_country', true ),
			],
			'coordinates' => [
				'lat' => (float) get_term_meta( $id, 'blockendar_venue_lat', true ),
				'lng' => (float) get_term_meta( $id, 'blockendar_venue_lng', true ),
			],
			'phone'       => get_term_meta( $id, 'blockendar_venue_phone', true ),
			'website'     => get_term_meta( $id, 'blockendar_venue_url', true ),
			'capacity'    => (int) get_term_meta( $id, 'blockendar_venue_capacity', true ),
			'virtual'     => (bool) get_term_meta( $id, 'blockendar_venue_virtual', true ),
			'stream_url'  => get_term_meta( $id, 'blockendar_venue_stream_url', true ),
		];
	}
}
