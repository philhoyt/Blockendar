<?php
/**
 * All read queries against the blockendar_events index table.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query layer for the {prefix}blockendar_events table.
 * No WP_Query, no wp_postmeta joins — indexed datetime columns only.
 */
class EventIndex {

	/**
	 * Query events within a datetime range.
	 *
	 * @param string $start      UTC datetime string (Y-m-d H:i:s).
	 * @param string $end        UTC datetime string (Y-m-d H:i:s).
	 * @param array  $filters {
	 *     Optional filters.
	 *     @type int|int[] $venue_term_id  Venue term ID(s).
	 *     @type int|int[] $type_term_id   Event type term ID(s).
	 *     @type string    $status         Event status (default: scheduled).
	 *     @type bool      $featured       Filter by featured flag.
	 *     @type bool      $hide_hidden    Exclude hide_from_listings events (default true).
	 *     @type int       $per_page       Results per page (default 100).
	 *     @type int       $page           1-based page number (default 1).
	 *     @type string    $orderby        start_datetime|end_datetime|post_title (default: start_datetime).
	 *     @type string    $order          ASC|DESC (default: ASC).
	 * }
	 * @return array<object> Rows from the index joined with wp_posts.
	 */
	public function get_events_in_range( string $start, string $end, array $filters = [] ): array {
		global $wpdb;

		$events_table = Schema::events_table();
		$posts_table  = $wpdb->posts;

		$defaults = [
			'venue_term_id' => null,
			'type_term_id'  => null,
			'status'        => null,
			'featured'      => null,
			'hide_hidden'   => true,
			'per_page'      => 100,
			'page'          => 1,
			'orderby'       => 'start_datetime',
			'order'         => 'ASC',
		];

		$filters = wp_parse_args( $filters, $defaults );
		$where   = [];
		$params  = [];

		// Date range — events that overlap the requested window.
		$where[]  = 'e.start_datetime < %s';
		$params[] = $end;
		$where[]  = 'e.end_datetime > %s';
		$params[] = $start;

		// Only published posts.
		$where[] = "p.post_status = 'publish'";

		// Status filter.
		if ( null !== $filters['status'] ) {
			$where[]  = 'e.status = %s';
			$params[] = sanitize_text_field( $filters['status'] );
		}

		// Venue filter.
		if ( null !== $filters['venue_term_id'] ) {
			$venue_ids = array_map( 'absint', (array) $filters['venue_term_id'] );
			$venue_ids = array_filter( $venue_ids );

			if ( ! empty( $venue_ids ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $venue_ids ), '%d' ) );
				$where[]      = "e.venue_term_id IN ($placeholders)";
				$params       = array_merge( $params, $venue_ids );
			}
		}

		// Event type filter — JSON_CONTAINS check on denormalised type_term_ids.
		if ( null !== $filters['type_term_id'] ) {
			$type_ids = array_map( 'absint', (array) $filters['type_term_id'] );
			$type_ids = array_filter( $type_ids );

			if ( ! empty( $type_ids ) ) {
				$type_clauses = [];
				foreach ( $type_ids as $type_id ) {
					$type_clauses[] = 'JSON_CONTAINS(e.type_term_ids, %s, \'$\')';
					$params[]       = (string) $type_id;
				}
				$where[] = '(' . implode( ' OR ', $type_clauses ) . ')';
			}
		}

		// Featured filter.
		if ( true === $filters['featured'] ) {
			$where[] = "p.ID IN (
				SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = 'blockendar_featured' AND meta_value = '1'
			)";
		}

		// Hide hidden events.
		if ( $filters['hide_hidden'] ) {
			$where[] = "p.ID NOT IN (
				SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = 'blockendar_hide_from_listings' AND meta_value = '1'
			)";
		}

		// ORDER BY — whitelist columns to prevent injection.
		$allowed_orderby = [ 'start_datetime', 'end_datetime', 'post_title' ];
		$orderby         = in_array( $filters['orderby'], $allowed_orderby, true )
			? $filters['orderby']
			: 'start_datetime';

		$order   = 'DESC' === strtoupper( $filters['order'] ) ? 'DESC' : 'ASC';
		$orderby = 'post_title' === $orderby ? "p.post_title $order" : "e.$orderby $order";

		// Pagination.
		$per_page = max( 1, min( 500, (int) $filters['per_page'] ) );
		$page     = max( 1, (int) $filters['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $wpdb->prepare(
			"SELECT e.id, e.post_id, e.start_datetime, e.end_datetime, e.start_date,
			        e.end_date, e.all_day, e.recurrence_id, e.status,
			        e.venue_term_id, e.type_term_ids,
			        p.post_title, p.post_name, p.guid
			FROM   {$events_table} e
			JOIN   {$posts_table} p ON p.ID = e.post_id
			{$where_sql}
			ORDER  BY {$orderby}
			LIMIT  %d OFFSET %d",
			array_merge( $params, [ $per_page, $offset ] )
		);

		return $wpdb->get_results( $query );
		// phpcs:enable
	}

	/**
	 * Count events in a range (for pagination totals).
	 *
	 * Accepts the same filters as get_events_in_range().
	 */
	public function count_events_in_range( string $start, string $end, array $filters = [] ): int {
		global $wpdb;

		// Reuse the same WHERE logic by fetching IDs only.
		$filters['per_page'] = 1;
		$filters['page']     = 1;

		$events_table = Schema::events_table();
		$posts_table  = $wpdb->posts;

		$defaults = [
			'venue_term_id' => null,
			'type_term_id'  => null,
			'status'        => null,
			'featured'      => null,
			'hide_hidden'   => true,
		];

		$filters = wp_parse_args( $filters, $defaults );
		$where   = [];
		$params  = [];

		$where[]  = 'e.start_datetime < %s';
		$params[] = $end;
		$where[]  = 'e.end_datetime > %s';
		$params[] = $start;
		$where[]  = "p.post_status = 'publish'";

		if ( null !== $filters['status'] ) {
			$where[]  = 'e.status = %s';
			$params[] = sanitize_text_field( $filters['status'] );
		}

		if ( null !== $filters['venue_term_id'] ) {
			$venue_ids = array_filter( array_map( 'absint', (array) $filters['venue_term_id'] ) );
			if ( ! empty( $venue_ids ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $venue_ids ), '%d' ) );
				$where[]      = "e.venue_term_id IN ($placeholders)";
				$params       = array_merge( $params, $venue_ids );
			}
		}

		if ( null !== $filters['type_term_id'] ) {
			$type_ids = array_filter( array_map( 'absint', (array) $filters['type_term_id'] ) );
			if ( ! empty( $type_ids ) ) {
				$type_clauses = [];
				foreach ( $type_ids as $type_id ) {
					$type_clauses[] = 'JSON_CONTAINS(e.type_term_ids, %s, \'$\')';
					$params[]       = (string) $type_id;
				}
				$where[] = '(' . implode( ' OR ', $type_clauses ) . ')';
			}
		}

		if ( true === $filters['featured'] ) {
			$where[] = "p.ID IN (
				SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = 'blockendar_featured' AND meta_value = '1'
			)";
		}

		if ( $filters['hide_hidden'] ) {
			$where[] = "p.ID NOT IN (
				SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = 'blockendar_hide_from_listings' AND meta_value = '1'
			)";
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events_table} e
				JOIN {$posts_table} p ON p.ID = e.post_id
				{$where_sql}",
				$params
			)
		);
		// phpcs:enable

		return (int) $count;
	}

	/**
	 * Get all index rows for a single post (includes all recurrence instances).
	 *
	 * @param int $post_id The event post ID.
	 * @return array<object>
	 */
	public function get_by_post_id( int $post_id ): array {
		global $wpdb;

		$events_table = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$events_table} WHERE post_id = %d ORDER BY start_datetime ASC",
				$post_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Get upcoming instances for a specific post, starting from now.
	 *
	 * @param int $post_id   The event post ID.
	 * @param int $limit     Maximum number of instances to return.
	 * @return array<object>
	 */
	public function get_upcoming_instances( int $post_id, int $limit = 10 ): array {
		global $wpdb;

		$events_table = Schema::events_table();
		$now          = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$events_table}
				WHERE post_id = %d AND end_datetime >= %s
				ORDER BY start_datetime ASC
				LIMIT %d",
				$post_id,
				$now,
				$limit
			)
		);
		// phpcs:enable
	}

	/**
	 * Get the next upcoming occurrence for a post from the index.
	 *
	 * Returns the first index row whose end_datetime is in the future,
	 * or null if no upcoming occurrence exists (all occurrences are past).
	 * Use the returned start_date / end_date / all_day fields for display;
	 * time and timezone should still be read from post meta (they are
	 * consistent across all occurrences of a recurring event).
	 *
	 * @param int $post_id Post ID.
	 * @return object|null
	 */
	public static function next_occurrence( int $post_id ): ?object {
		global $wpdb;

		$table = Schema::events_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d AND end_datetime >= %s ORDER BY start_datetime ASC LIMIT 1",
				$post_id,
				$now
			)
		);
		// phpcs:enable

		return $row ?: null;
	}

	/**
	 * Get the first occurrence for a post matching a specific start date.
	 *
	 * Used by blockendar_resolve_occurrence() to honour ?occurrence_date= links
	 * generated by CalendarController.
	 *
	 * @param int    $post_id The event post ID.
	 * @param string $date    Local date string (Y-m-d).
	 * @return object|null Index row, or null if no match found.
	 */
	public static function get_occurrence_by_date( int $post_id, string $date ): ?object {
		global $wpdb;
		$table = Schema::events_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d AND start_date = %s ORDER BY start_datetime ASC LIMIT 1",
				$post_id,
				$date
			)
		);
		// phpcs:enable
		return $row ?: null;
	}

	/**
	 * Delete all index rows for a given post ID.
	 *
	 * @param int $post_id The event post ID.
	 * @return int Number of rows deleted.
	 */
	public function delete_by_post_id( int $post_id ): int {
		global $wpdb;

		$result = $wpdb->delete(
			Schema::events_table(),
			[ 'post_id' => $post_id ],
			[ '%d' ]
		);

		return (int) $result;
	}

	/**
	 * Insert a single occurrence row into the index.
	 *
	 * @param array $data {
	 *     @type int    $post_id        Required.
	 *     @type string $start_datetime UTC datetime Y-m-d H:i:s.
	 *     @type string $end_datetime   UTC datetime Y-m-d H:i:s.
	 *     @type string $start_date     Local date Y-m-d.
	 *     @type string $end_date       Local date Y-m-d.
	 *     @type int    $all_day        0 or 1.
	 *     @type int    $recurrence_id  Optional recurrence rule ID.
	 *     @type string $status         Event status string.
	 *     @type int    $venue_term_id  Optional venue term ID.
	 *     @type array  $type_term_ids  Optional array of event type term IDs.
	 * }
	 * @return int|false Inserted row ID or false on failure.
	 */
	public function insert( array $data ): int|false {
		global $wpdb;

		$row = [
			'post_id'        => (int) $data['post_id'],
			'start_datetime' => $data['start_datetime'],
			'end_datetime'   => $data['end_datetime'],
			'start_date'     => $data['start_date'],
			'end_date'       => $data['end_date'],
			'all_day'        => isset( $data['all_day'] ) ? (int) $data['all_day'] : 0,
			'recurrence_id'  => isset( $data['recurrence_id'] ) ? (int) $data['recurrence_id'] : null,
			'status'         => $data['status'] ?? 'scheduled',
			'venue_term_id'  => isset( $data['venue_term_id'] ) ? (int) $data['venue_term_id'] : null,
			'type_term_ids'  => isset( $data['type_term_ids'] )
				? wp_json_encode( array_map( 'intval', (array) $data['type_term_ids'] ) )
				: null,
		];

		$formats = [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ];

		$result = $wpdb->insert( Schema::events_table(), $row, $formats );

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get the total number of rows in the index (for stats display).
	 */
	public function get_total_row_count(): int {
		global $wpdb;

		$events_table = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" );
		// phpcs:enable
	}
}
