<?php
/**
 * REST controller for event queries and instance management.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\Recurrence\RuleRepository;
use Blockendar\Recurrence\Generator;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Handles GET /blockendar/v1/events and related sub-routes.
 *
 * Registered endpoints:
 *   GET  /blockendar/v1/events
 *   GET  /blockendar/v1/events/{id}
 *   GET  /blockendar/v1/events/{id}/instances
 *   POST /blockendar/v1/events/{id}/instances/{date}/cancel
 *   POST /blockendar/v1/events/{id}/instances/{date}/exception
 *   POST /blockendar/v1/index/rebuild
 */
class EventsController extends AbstractController {

	private EventIndex $index;
	private RuleRepository $rules;
	private IndexBuilder $builder;

	public function __construct() {
		$this->index   = new EventIndex();
		$this->rules   = new RuleRepository();
		$this->builder = new IndexBuilder();
	}

	/**
	 * Register all routes.
	 */
	public function register_routes(): void {
		// Collection.
		register_rest_route(
			self::NAMESPACE,
			'/events',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_events' ],
				'permission_callback' => '__return_true',
				'args'                => $this->collection_args(),
			]
		);

		// Single event.
		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_event' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
				],
			]
		);

		// All instances of a recurring event.
		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<id>\d+)/instances',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_instances' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'type'    => 'integer',
						'minimum' => 1,
					],
				],
			]
		);

		// Cancel a single instance.
		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<id>\d+)/instances/(?P<date>\d{4}-\d{2}-\d{2})/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel_instance' ],
				'permission_callback' => [ $this, 'check_edit_permission' ],
				'args'                => $this->instance_action_args(),
			]
		);

		// Add an exception date (removes the instance without creating a cancellation).
		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<id>\d+)/instances/(?P<date>\d{4}-\d{2}-\d{2})/exception',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_exception' ],
				'permission_callback' => [ $this, 'check_edit_permission' ],
				'args'                => $this->instance_action_args(),
			]
		);

		// Admin: trigger full index rebuild.
		register_rest_route(
			self::NAMESPACE,
			'/index/rebuild',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rebuild_index' ],
				'permission_callback' => [ $this, 'check_manage_permission' ],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /blockendar/v1/events
	 */
	public function get_events( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$now = gmdate( 'Y-m-d H:i:s' );

		$start = $this->parse_datetime_param(
			(string) ( $request->get_param( 'start' ) ?? '' ),
			$now
		);

		$end = $this->parse_datetime_param(
			(string) ( $request->get_param( 'end' ) ?? '' ),
			gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) )
		);

		if ( is_wp_error( $start ) ) {
			return $start;
		}

		if ( is_wp_error( $end ) ) {
			return $end;
		}

		$per_page = min( 500, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 20 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );

		$filters = [
			'venue_term_id' => $request->get_param( 'venue' ) ? (int) $request->get_param( 'venue' ) : null,
			'type_term_id'  => $request->get_param( 'type' ) ? (int) $request->get_param( 'type' ) : null,
			'status'        => $request->get_param( 'status' ) ?: null,
			'featured'      => $request->get_param( 'featured' ) ? rest_sanitize_boolean( $request->get_param( 'featured' ) ) : null,
			'per_page'      => $per_page,
			'page'          => $page,
			'orderby'       => $request->get_param( 'orderby' ) ?: 'start_datetime',
			'order'         => strtoupper( (string) ( $request->get_param( 'order' ) ?? 'ASC' ) ),
		];

		$events = $this->index->get_events_in_range( $start, $end, $filters );
		$total  = $this->index->count_events_in_range( $start, $end, $filters );

		$data = array_map( [ $this, 'format_event_row' ], $events );

		return $this->respond(
			$data,
			200,
			$this->pagination_headers( $total, $per_page, $page, $request )
		);
	}

	/**
	 * GET /blockendar/v1/events/{id}
	 */
	public function get_event( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'blockendar_event' !== $post->post_type ) {
			return new WP_Error( 'blockendar_not_found', __( 'Event not found.', 'blockendar' ), [ 'status' => 404 ] );
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'blockendar_forbidden', __( 'You do not have permission to view this event.', 'blockendar' ), [ 'status' => 403 ] );
		}

		$meta      = $this->get_full_meta( $post_id );
		$rule      = $this->rules->get( $post_id );
		$instances = $this->index->get_upcoming_instances( $post_id, 10 );

		$data = [
			'id'          => $post_id,
			'title'       => get_the_title( $post ),
			'slug'        => $post->post_name,
			'url'         => get_permalink( $post ),
			'status'      => $post->post_status,
			'meta'        => $meta,
			'recurrence'  => $rule ? $this->format_rule( $rule ) : null,
			'instances'   => array_map( [ $this, 'format_instance_row' ], $instances ),
			'venue'       => $this->get_venue_data( $post_id ),
			'event_types' => $this->get_type_data( $post_id ),
			'event_tags'  => $this->get_tag_data( $post_id ),
		];

		return $this->respond( $data );
	}

	/**
	 * GET /blockendar/v1/events/{id}/instances
	 */
	public function get_instances( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'blockendar_event' !== $post->post_type ) {
			return new WP_Error( 'blockendar_not_found', __( 'Event not found.', 'blockendar' ), [ 'status' => 404 ] );
		}

		$rows = $this->index->get_by_post_id( $post_id );

		return $this->respond( array_map( [ $this, 'format_instance_row' ], $rows ) );
	}

	/**
	 * POST /blockendar/v1/events/{id}/instances/{date}/cancel
	 * Sets the status of a single instance to 'cancelled' in the index.
	 */
	public function cancel_instance( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$date    = sanitize_text_field( (string) $request->get_param( 'date' ) );

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'blockendar_not_found', __( 'Event not found.', 'blockendar' ), [ 'status' => 404 ] );
		}

		global $wpdb;
		$events_table = \Blockendar\DB\Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$events_table} SET status = 'cancelled' WHERE post_id = %d AND start_date = %s",
				$post_id,
				$date
			)
		);
		// phpcs:enable

		if ( false === $updated ) {
			return new WP_Error( 'blockendar_db_error', __( 'Failed to cancel instance.', 'blockendar' ), [ 'status' => 500 ] );
		}

		return $this->respond(
			[
				'cancelled' => true,
				'post_id'   => $post_id,
				'date'      => $date,
			]
		);
	}

	/**
	 * POST /blockendar/v1/events/{id}/instances/{date}/exception
	 * Adds the date to the rule's exceptions list and removes it from the index.
	 */
	public function add_exception( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$date    = sanitize_text_field( (string) $request->get_param( 'date' ) );

		$rule = $this->rules->get( $post_id );

		if ( null === $rule ) {
			return new WP_Error( 'blockendar_not_recurring', __( 'Event has no recurrence rule.', 'blockendar' ), [ 'status' => 400 ] );
		}

		$this->rules->add_exception( $post_id, $date );

		// Remove the specific instance row from the index.
		global $wpdb;
		$events_table = \Blockendar\DB\Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$events_table} WHERE post_id = %d AND start_date = %s",
				$post_id,
				$date
			)
		);
		// phpcs:enable

		return $this->respond(
			[
				'exception_added' => true,
				'post_id'         => $post_id,
				'date'            => $date,
			]
		);
	}

	/**
	 * POST /blockendar/v1/index/rebuild
	 */
	public function rebuild_index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->builder->rebuild_all();

		return $this->respond(
			[
				'success'    => true,
				'rebuilt'    => $result['rebuilt'],
				'skipped'    => $result['skipped'],
				'rebuilt_at' => get_option( 'blockendar_last_index_rebuild' ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function check_edit_permission(): bool {
		return $this->can_edit();
	}

	public function check_manage_permission(): bool {
		return $this->can_manage();
	}

	// -------------------------------------------------------------------------
	// Formatters
	// -------------------------------------------------------------------------

	/**
	 * Format an index row for the events collection response.
	 *
	 * @param object $row Index table row joined with wp_posts.
	 */
	private function format_event_row( object $row ): array {
		return [
			'id'             => (int) $row->id,
			'post_id'        => (int) $row->post_id,
			'title'          => $row->post_title,
			'url'            => get_permalink( (int) $row->post_id ),
			'start_datetime' => $row->start_datetime,
			'end_datetime'   => $row->end_datetime,
			'start_date'     => $row->start_date,
			'end_date'       => $row->end_date,
			'all_day'        => (bool) $row->all_day,
			'status'         => $row->status,
			'venue_term_id'  => $row->venue_term_id ? (int) $row->venue_term_id : null,
			'type_term_ids'  => $row->type_term_ids ? json_decode( $row->type_term_ids, true ) : [],
		];
	}

	/**
	 * Format a single index row (for the instances endpoint).
	 *
	 * @param object $row Index table row.
	 */
	private function format_instance_row( object $row ): array {
		return [
			'id'             => (int) $row->id,
			'post_id'        => (int) $row->post_id,
			'start_datetime' => $row->start_datetime,
			'end_datetime'   => $row->end_datetime,
			'start_date'     => $row->start_date,
			'end_date'       => $row->end_date,
			'all_day'        => (bool) $row->all_day,
			'status'         => $row->status,
			'recurrence_id'  => $row->recurrence_id ? (int) $row->recurrence_id : null,
		];
	}

	/**
	 * Format a Rule object for JSON output.
	 *
	 * @param \Blockendar\Recurrence\Rule $rule Rule value object.
	 */
	private function format_rule( \Blockendar\Recurrence\Rule $rule ): array {
		return [
			'id'         => $rule->id,
			'frequency'  => $rule->frequency,
			'interval'   => $rule->interval,
			'byday'      => $rule->byday,
			'bymonthday' => $rule->bymonthday,
			'bysetpos'   => $rule->bysetpos,
			'until_date' => $rule->until_date?->format( 'Y-m-d' ),
			'count'      => $rule->count,
			'exceptions' => $rule->exceptions,
			'additions'  => $rule->additions,
		];
	}

	/**
	 * Get all registered post meta for an event.
	 *
	 * @param int $post_id Post ID.
	 */
	private function get_full_meta( int $post_id ): array {
		$keys = [
			'blockendar_start_date',
			'blockendar_end_date',
			'blockendar_start_time',
			'blockendar_end_time',
			'blockendar_all_day',
			'blockendar_timezone',
			'blockendar_status',
			'blockendar_cost',
			'blockendar_cost_min',
			'blockendar_cost_max',
			'blockendar_currency',
			'blockendar_registration_url',
			'blockendar_capacity',
			'blockendar_featured',
			'blockendar_hide_from_listings',
		];

		$meta = [];

		foreach ( $keys as $key ) {
			$meta[ str_replace( 'blockendar_', '', $key ) ] = get_post_meta( $post_id, $key, true );
		}

		return $meta;
	}

	/**
	 * Get venue term data for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function get_venue_data( int $post_id ): ?array {
		$terms = get_the_terms( $post_id, 'event_venue' );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$term = $terms[0];

		return [
			'id'      => $term->term_id,
			'name'    => $term->name,
			'slug'    => $term->slug,
			'city'    => get_term_meta( $term->term_id, 'blockendar_venue_city', true ),
			'state'   => get_term_meta( $term->term_id, 'blockendar_venue_state', true ),
			'country' => get_term_meta( $term->term_id, 'blockendar_venue_country', true ),
			'address' => get_term_meta( $term->term_id, 'blockendar_venue_address', true ),
			'lat'     => get_term_meta( $term->term_id, 'blockendar_venue_lat', true ),
			'lng'     => get_term_meta( $term->term_id, 'blockendar_venue_lng', true ),
			'virtual' => (bool) get_term_meta( $term->term_id, 'blockendar_venue_virtual', true ),
		];
	}

	/**
	 * Get event type term data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	private function get_type_data( int $post_id ): array {
		$terms = get_the_terms( $post_id, 'event_type' );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		return array_map(
			fn( $t ) => [
				'id'    => $t->term_id,
				'name'  => $t->name,
				'slug'  => $t->slug,
				'color' => get_term_meta( $t->term_id, 'blockendar_type_color', true ),
			],
			$terms
		);
	}

	/**
	 * Get event tag term data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	private function get_tag_data( int $post_id ): array {
		$terms = get_the_terms( $post_id, 'event_tag' );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		return array_map(
			fn( $t ) => [
				'id'   => $t->term_id,
				'name' => $t->name,
				'slug' => $t->slug,
			],
			$terms
		);
	}

	// -------------------------------------------------------------------------
	// Route argument definitions
	// -------------------------------------------------------------------------

	private function collection_args(): array {
		return [
			'start'    => [
				'type'    => 'string',
				'default' => '',
			],
			'end'      => [
				'type'    => 'string',
				'default' => '',
			],
			'venue'    => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'type'     => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'status'   => [
				'type' => 'string',
				'enum' => [ 'scheduled', 'cancelled', 'postponed', 'sold_out' ],
			],
			'featured' => [ 'type' => 'boolean' ],
			'per_page' => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 500,
			],
			'page'     => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'orderby'  => [
				'type'    => 'string',
				'default' => 'start_datetime',
				'enum'    => [ 'start_datetime', 'end_datetime', 'post_title' ],
			],
			'order'    => [
				'type'    => 'string',
				'default' => 'ASC',
				'enum'    => [ 'ASC', 'DESC' ],
			],
		];
	}

	private function instance_action_args(): array {
		return [
			'id'   => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'date' => [
				'type'    => 'string',
				'pattern' => '^\d{4}-\d{2}-\d{2}$',
			],
		];
	}
}
