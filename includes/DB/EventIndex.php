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
	 * Sentinel end_datetime / end_date written for ongoing events (no end date).
	 *
	 * Far enough in the future that every overlap query treats the event as still
	 * running. Consumers must branch on the `ongoing` column, never on this value.
	 */
	public const ONGOING_END      = '9999-12-31 00:00:00';
	public const ONGOING_END_DATE = '9999-12-31';

	/**
	 * Default upper bound on rows returned by a single range query.
	 *
	 * Guards against a caller asking for an unbounded result set. Callers that
	 * genuinely need a larger batch raise it per query via the `max_per_page`
	 * filter rather than this ceiling being lifted for everyone.
	 */
	public const DEFAULT_MAX_PER_PAGE = 500;

	/**
	 * Object cache group for index reads.
	 *
	 * Invalidation is incremental: every cache key embeds the group's
	 * "last changed" timestamp, so bumping that timestamp on write orphans every
	 * previously cached key at once. This is the same approach core uses for its
	 * own term and meta caches, and it avoids needing wp_cache_delete_group(),
	 * which not every persistent backend implements.
	 *
	 * Without a persistent object cache these entries live for a single request,
	 * which still collapses the repeated reads a calendar render performs.
	 */
	private const CACHE_GROUP = 'blockendar_events';

	/**
	 * Build a cache key scoped to the current state of the index.
	 *
	 * @param string $method Logical read being cached.
	 * @param array  $args   Arguments that fully determine the result.
	 */
	private function cache_key( string $method, array $args ): string {
		$last_changed = wp_cache_get_last_changed( self::CACHE_GROUP );

		return $method . ':' . md5( (string) wp_json_encode( $args ) ) . ':' . $last_changed;
	}

	/**
	 * Invalidate every cached read for this index.
	 *
	 * Cheap enough to call per row during a bulk rebuild: it writes a single
	 * cache entry and issues no database query.
	 */
	public function flush_cache(): void {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

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
	 *     @type bool|null $ongoing        true = only ongoing events, false = exclude them, null = no filter.
	 *     @type string    $ended_before   UTC datetime. When set, "past" semantics replace the overlap
	 *                                     match: the event must have ended at or before this cutoff
	 *                                     (and within the window), and ongoing events are excluded.
	 *     @type int|int[] $exclude_type_term_id Exclude events carrying any of these event type terms.
	 *     @type int       $per_page       Results per page (default 100).
	 *     @type int       $max_per_page   Upper bound on per_page (default 500). Raised only by
	 *                                     callers that genuinely need a bigger single batch, such
	 *                                     as the iCalendar feed, which cannot paginate.
	 *     @type int       $page           1-based page number (default 1).
	 *     @type string    $orderby        start_datetime|end_datetime|post_title (default: start_datetime).
	 *     @type string    $order          ASC|DESC (default: ASC).
	 * }
	 * @return array<object> Rows from the index joined with wp_posts.
	 */
	public function get_events_in_range( string $start, string $end, array $filters = [] ): array {
		global $wpdb;

		$cache_key = $this->cache_key( 'range', [ $start, $end, $filters ] );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$events_table = Schema::events_table();
		$posts_table  = $wpdb->posts;

		$defaults = [
			'venue_term_id'        => null,
			'type_term_id'         => null,
			'exclude_type_term_id' => null,
			'status'               => null,
			'featured'             => null,
			'hide_hidden'          => true,
			'ongoing'              => null,
			'ended_before'         => null,
			'per_page'             => 100,
			'max_per_page'         => self::DEFAULT_MAX_PER_PAGE,
			'page'                 => 1,
			'orderby'              => 'start_datetime',
			'order'                => 'ASC',
		];

		$filters    = wp_parse_args( $filters, $defaults );
		$where      = [];
		$params     = [];
		$index_hint = '';

		if ( null !== $filters['ended_before'] ) {
			// Past mode — the event has finished, and finished inside the window.
			// A narrower $end tightens the cutoff; $start bounds how far back to look.
			$where[]  = 'e.end_datetime <= %s';
			$params[] = min( $end, (string) $filters['ended_before'] );
			$where[]  = 'e.end_datetime > %s';
			$params[] = $start;
			$where[]  = 'e.ongoing = 0';

			// Steer the optimizer off idx_start_datetime on this branch.
			//
			// Past listings default to ORDER BY start_datetime DESC, so MariaDB
			// likes idx_start_datetime: it can walk the index in sort order and
			// stop at the LIMIT. But it walks from the newest start_datetime
			// backwards, and on a calendar most of those rows are in the future
			// or ongoing, so it scans deep before finding rows that pass
			// `end_datetime <= cutoff`. Measured on 200k occurrences (50%
			// ongoing, 25% hidden): 55ms at LIMIT 10, barely better than a scan.
			//
			// Excluding it lets idx_visible_past (hide_from_listings, ongoing,
			// start_datetime) take over, which fixes both flags and then reads
			// start_datetime in order: 19ms at the same limits.
			//
			// IGNORE rather than FORCE INDEX on purpose. This is a hint, not a
			// mandate — the optimizer still chooses among the rest, so a site
			// whose distribution makes another index better is not pinned to a
			// bad plan. Forcing was measurably worse on a benign distribution
			// where few rows are ongoing and early termination really is right.
			$index_hint = 'IGNORE INDEX (idx_start_datetime)';
		} else {
			// Date range — events that overlap the requested window.
			$where[]  = 'e.start_datetime < %s';
			$params[] = $end;
			$where[]  = 'e.end_datetime > %s';
			$params[] = $start;
		}

		// Only published, unprotected posts. post_password is checked because
		// a password-protected event is still post_status = 'publish', and
		// these rows feed the public REST, calendar and ICS responses — which
		// expose title, dates and the venue's street address.
		$where[] = "p.post_status = 'publish'";
		$where[] = "p.post_password = ''";

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

		// Event type filter — junction table subquery (replaces JSON_CONTAINS).
		if ( null !== $filters['type_term_id'] ) {
			$type_ids = array_map( 'absint', (array) $filters['type_term_id'] );
			$type_ids = array_filter( $type_ids );

			if ( ! empty( $type_ids ) ) {
				$type_terms_table = Schema::type_terms_table();
				$placeholders     = implode( ', ', array_fill( 0, count( $type_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where[] = "e.id IN (SELECT event_index_id FROM {$type_terms_table} WHERE type_term_id IN ({$placeholders}))";
				$params  = array_merge( $params, $type_ids );
			}
		}

		// Event type exclusion — same junction subquery, negated.
		if ( null !== $filters['exclude_type_term_id'] ) {
			$exclude_ids = array_filter( array_map( 'absint', (array) $filters['exclude_type_term_id'] ) );

			if ( ! empty( $exclude_ids ) ) {
				$type_terms_table = Schema::type_terms_table();
				$placeholders     = implode( ', ', array_fill( 0, count( $exclude_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where[] = "e.id NOT IN (SELECT event_index_id FROM {$type_terms_table} WHERE type_term_id IN ({$placeholders}))";
				$params  = array_merge( $params, $exclude_ids );
			}
		}

		// Featured filter — denormalised column.
		if ( true === $filters['featured'] ) {
			$where[] = 'e.featured = 1';
		}

		// Hide hidden events — denormalised column.
		if ( $filters['hide_hidden'] ) {
			$where[] = 'e.hide_from_listings = 0';
		}

		// Ongoing filter — unlike `featured`, false is meaningful here.
		if ( null !== $filters['ongoing'] ) {
			$where[] = $filters['ongoing'] ? 'e.ongoing = 1' : 'e.ongoing = 0';
		}

		// ORDER BY — whitelist columns to prevent injection.
		$allowed_orderby = [ 'start_datetime', 'end_datetime', 'post_title' ];
		$orderby         = in_array( $filters['orderby'], $allowed_orderby, true )
			? $filters['orderby']
			: 'start_datetime';

		$order   = 'DESC' === strtoupper( $filters['order'] ) ? 'DESC' : 'ASC';
		$orderby = 'post_title' === $orderby ? "p.post_title $order" : "e.$orderby $order";

		// Pagination.
		$ceiling  = max( 1, (int) $filters['max_per_page'] );
		$per_page = max( 1, min( $ceiling, (int) $filters['per_page'] ) );
		$page     = max( 1, (int) $filters['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $wpdb->prepare(
			"SELECT e.id, e.post_id, e.start_datetime, e.end_datetime, e.start_date,
			        e.end_date, e.all_day, e.recurrence_id, e.status,
			        e.venue_term_id, e.type_term_ids, e.featured, e.hide_from_listings,
			        e.ongoing, p.post_title, p.post_name, p.guid,
			        p.post_date_gmt, p.post_modified_gmt
			FROM   {$events_table} e {$index_hint}
			JOIN   {$posts_table} p ON p.ID = e.post_id
			{$where_sql}
			ORDER  BY {$orderby}
			LIMIT  %d OFFSET %d",
			array_merge( $params, [ $per_page, $offset ] )
		);

		$results = $wpdb->get_results( $query );
		// phpcs:enable

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP );

		return $results;
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

		$cache_key = $this->cache_key( 'range_count', [ $start, $end, $filters ] );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$events_table = Schema::events_table();
		$posts_table  = $wpdb->posts;

		$defaults = [
			'venue_term_id'        => null,
			'type_term_id'         => null,
			'exclude_type_term_id' => null,
			'status'               => null,
			'featured'             => null,
			'hide_hidden'          => true,
			'ongoing'              => null,
			'ended_before'         => null,
		];

		$filters = wp_parse_args( $filters, $defaults );
		$where   = [];
		$params  = [];

		if ( null !== $filters['ended_before'] ) {
			$where[]  = 'e.end_datetime <= %s';
			$params[] = min( $end, (string) $filters['ended_before'] );
			$where[]  = 'e.end_datetime > %s';
			$params[] = $start;
			$where[]  = 'e.ongoing = 0';

			// Deliberately no IGNORE INDEX here, unlike get_events_in_range().
			//
			// That hint pays off because the page query has an ORDER BY and a
			// LIMIT, which is what tempts the optimizer onto idx_start_datetime.
			// A COUNT(*) has neither: it has to visit every matching row either
			// way, and already declines to use idx_start_datetime. Measured on
			// the same 200k rows, the hint changed nothing (25.9ms vs 26.1ms),
			// so it is left off rather than carried over for symmetry.
		} else {
			$where[]  = 'e.start_datetime < %s';
			$params[] = $end;
			$where[]  = 'e.end_datetime > %s';
			$params[] = $start;
		}
		// Must mirror get_events_in_range() exactly, or the count and the page
		// of results disagree.
		$where[] = "p.post_status = 'publish'";
		$where[] = "p.post_password = ''";

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
				$type_terms_table = Schema::type_terms_table();
				$placeholders     = implode( ', ', array_fill( 0, count( $type_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where[] = "e.id IN (SELECT event_index_id FROM {$type_terms_table} WHERE type_term_id IN ({$placeholders}))";
				$params  = array_merge( $params, $type_ids );
			}
		}

		if ( null !== $filters['exclude_type_term_id'] ) {
			$exclude_ids = array_filter( array_map( 'absint', (array) $filters['exclude_type_term_id'] ) );
			if ( ! empty( $exclude_ids ) ) {
				$type_terms_table = Schema::type_terms_table();
				$placeholders     = implode( ', ', array_fill( 0, count( $exclude_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where[] = "e.id NOT IN (SELECT event_index_id FROM {$type_terms_table} WHERE type_term_id IN ({$placeholders}))";
				$params  = array_merge( $params, $exclude_ids );
			}
		}

		if ( true === $filters['featured'] ) {
			$where[] = 'e.featured = 1';
		}

		if ( $filters['hide_hidden'] ) {
			$where[] = 'e.hide_from_listings = 0';
		}

		if ( null !== $filters['ongoing'] ) {
			$where[] = $filters['ongoing'] ? 'e.ongoing = 1' : 'e.ongoing = 0';
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// $where_sql is assembled from literal fragments above; every user value is a
		// %s/%d placeholder in $params, so the placeholder count is only knowable at runtime.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events_table} e
				JOIN {$posts_table} p ON p.ID = e.post_id
				{$where_sql}",
				$params
			)
		);
		// phpcs:enable

		wp_cache_set( $cache_key, (int) $count, self::CACHE_GROUP );

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

		$cache_key = $this->cache_key( 'by_post', [ $post_id ] );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$events_table = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$events_table} WHERE post_id = %d ORDER BY start_datetime ASC",
				$post_id
			)
		);
		// phpcs:enable

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP );

		return $results;
	}

	/**
	 * Get upcoming instances for a specific post, starting from now.
	 *
	 * @param int $post_id   The event post ID.
	 * @param int $limit     Maximum number of instances to return.
	 * @return array<object>
	 */
	/**
	 * Deliberately not cached: the WHERE clause pivots on the current second, so a
	 * cache key derived from it would miss on every call while filling the cache
	 * with single-use entries. The query is a covered lookup on an indexed column
	 * for one post, so it is cheap to run directly.
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
	 * Term IDs that actually have an occurrence from a given moment onwards.
	 *
	 * Filter blocks use this instead of the taxonomy's own counts. A term count
	 * includes every published event ever assigned to it, so a venue whose last
	 * event was three years ago still offers itself as a filter that can only
	 * return nothing. This answers the question the visitor is really asking:
	 * which venues and types have something coming up.
	 *
	 * @param string      $taxonomy 'venue' or 'type'.
	 * @param string|null $from     UTC datetime to look forward from. Defaults to now.
	 * @return int[] Term IDs, unsorted.
	 */
	public function get_term_ids_with_events( string $taxonomy, ?string $from = null ): array {
		global $wpdb;

		$from = $from ?? gmdate( 'Y-m-d H:i:s' );

		$cache_key = $this->cache_key( 'terms_with_events', [ $taxonomy, $from ] );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$events_table = Schema::events_table();
		$posts_table  = $wpdb->posts;

		if ( 'venue' === $taxonomy ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT e.venue_term_id
					FROM   {$events_table} e
					JOIN   {$posts_table} p ON p.ID = e.post_id
					WHERE  e.venue_term_id IS NOT NULL
					  AND  p.post_status = 'publish'
					  AND  p.post_password = ''
					  AND  e.hide_from_listings = 0
					  AND  e.end_datetime >= %s",
					$from
				)
			);
			// phpcs:enable
		} else {
			$type_terms_table = Schema::type_terms_table();

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT t.type_term_id
					FROM   {$type_terms_table} t
					JOIN   {$events_table} e ON e.id = t.event_index_id
					JOIN   {$posts_table} p ON p.ID = e.post_id
					WHERE  p.post_status = 'publish'
					  AND  p.post_password = ''
					  AND  e.hide_from_listings = 0
					  AND  e.end_datetime >= %s",
					$from
				)
			);
			// phpcs:enable
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP );

		return $ids;
	}

	/**
	 * Delete all index rows for a given post ID, including junction table rows.
	 *
	 * @param int $post_id The event post ID.
	 * @return int Number of rows deleted from the events table.
	 */
	public function delete_by_post_id( int $post_id ): int {
		global $wpdb;

		$events_table     = Schema::events_table();
		$type_terms_table = Schema::type_terms_table();

		// Collect index IDs so we can cascade-delete from the junction table.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$events_table} WHERE post_id = %d",
				$post_id
			)
		);
		// phpcs:enable

		if ( ! empty( $index_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $index_ids ), '%d' ) );
			// $placeholders is a runtime-built list of %d tokens, one per index ID, so the
			// count is not statically analysable. Every value is still bound via prepare().
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$type_terms_table} WHERE event_index_id IN ({$placeholders})",
					$index_ids
				)
			);
			// phpcs:enable
		}

		$result = $wpdb->delete(
			$events_table,
			[ 'post_id' => $post_id ],
			[ '%d' ]
		);

		$this->flush_cache();

		return (int) $result;
	}

	/**
	 * Insert a single occurrence row into the index, and populate the type-terms
	 * junction table.
	 *
	 * @param array $data {
	 *     @type int    $post_id            Required.
	 *     @type string $start_datetime     UTC datetime Y-m-d H:i:s.
	 *     @type string $end_datetime       UTC datetime Y-m-d H:i:s.
	 *     @type string $start_date         Local date Y-m-d.
	 *     @type string $end_date           Local date Y-m-d.
	 *     @type int    $all_day            0 or 1.
	 *     @type int    $recurrence_id      Optional recurrence rule ID.
	 *     @type string $status             Event status string.
	 *     @type int    $venue_term_id      Optional venue term ID.
	 *     @type array  $type_term_ids      Optional array of event type term IDs.
	 *     @type int    $featured           1 if event is featured, 0 otherwise.
	 *     @type int    $hide_from_listings 1 if event should be hidden from listings.
	 *     @type int    $ongoing            1 if the event has no end date (sentinel end).
	 * }
	 * @param bool  $flush Invalidate the read cache afterwards. Pass false when
	 *                     inserting in a loop and call flush_cache() once at the
	 *                     end: without a persistent object cache the flush is a
	 *                     single in-process write, but with Redis or Memcached it
	 *                     is a network round-trip per row, and a horizon roll can
	 *                     insert tens of thousands.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public function insert( array $data, bool $flush = true ): int|false {
		global $wpdb;

		$type_term_ids = isset( $data['type_term_ids'] )
			? array_map( 'intval', (array) $data['type_term_ids'] )
			: [];

		$row = [
			'post_id'            => (int) $data['post_id'],
			'start_datetime'     => $data['start_datetime'],
			'end_datetime'       => $data['end_datetime'],
			'start_date'         => $data['start_date'],
			'end_date'           => $data['end_date'],
			'all_day'            => isset( $data['all_day'] ) ? (int) $data['all_day'] : 0,
			'recurrence_id'      => isset( $data['recurrence_id'] ) ? (int) $data['recurrence_id'] : null,
			'status'             => $data['status'] ?? 'scheduled',
			'venue_term_id'      => isset( $data['venue_term_id'] ) ? (int) $data['venue_term_id'] : null,
			'type_term_ids'      => ! empty( $type_term_ids )
				? wp_json_encode( $type_term_ids )
				: null,
			'featured'           => isset( $data['featured'] ) ? (int) $data['featured'] : 0,
			'hide_from_listings' => isset( $data['hide_from_listings'] ) ? (int) $data['hide_from_listings'] : 0,
			'ongoing'            => isset( $data['ongoing'] ) ? (int) $data['ongoing'] : 0,
		];

		$formats = [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%d' ];

		$result = $wpdb->insert( Schema::events_table(), $row, $formats );

		if ( false === $result ) {
			return false;
		}

		$index_id = $wpdb->insert_id;

		// Populate junction table for fast type-term filtering.
		if ( ! empty( $type_term_ids ) ) {
			$type_terms_table = Schema::type_terms_table();
			foreach ( $type_term_ids as $type_term_id ) {
				$wpdb->insert(
					$type_terms_table,
					[
						'event_index_id' => $index_id,
						'type_term_id'   => $type_term_id,
					],
					[ '%d', '%d' ]
				);
			}
		}

		if ( $flush ) {
			$this->flush_cache();
		}

		return $index_id;
	}

	/**
	 * The latest start_datetime currently indexed for one post.
	 *
	 * Used by the nightly horizon roll to work out where its last run stopped,
	 * so it can append the occurrences that have since come into range instead
	 * of deleting and rewriting every row the event has.
	 *
	 * @param int $post_id Event post ID.
	 * @return string|null UTC datetime, or null when the post has no rows.
	 */
	public function max_start_datetime( int $post_id ): ?string {
		global $wpdb;

		$events_table = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(start_datetime) FROM {$events_table} WHERE post_id = %d",
				$post_id
			)
		);
		// phpcs:enable

		return null === $value ? null : (string) $value;
	}

	/**
	 * Get the total number of rows in the index (for stats display).
	 */
	public function get_total_row_count(): int {
		global $wpdb;

		$cache_key = $this->cache_key( 'total_rows', [] );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$events_table = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" );
		// phpcs:enable

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP );

		return $count;
	}
}
