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
	 * How long a cached read may be kept, in seconds. See cache_set().
	 */
	private const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * The filters that decide which rows a range query matches.
	 *
	 * The rest of what get_events_in_range() accepts — orderby, order,
	 * per_page, max_per_page, page — arranges those rows into a page and
	 * cannot change how many there are.
	 */
	public const RESULT_FILTERS = [
		'venue_term_id',
		'type_term_id',
		'exclude_type_term_id',
		'status',
		'featured',
		'hide_hidden',
		'ongoing',
		'ended_before',
	];

	/**
	 * Rows per INSERT statement when writing an event's occurrences.
	 */
	private const INSERT_CHUNK = 50;

	/**
	 * How many times to try replacing a post's rows when the database picks
	 * the attempt as a deadlock victim.
	 */
	private const DEADLOCK_ATTEMPTS = 3;

	/**
	 * Build a cache key scoped to the current state of the index.
	 *
	 * @param string $method Logical read being cached.
	 * @param array  $args   Arguments that fully determine the result.
	 */
	private function cache_key( string $method, array $args ): string {
		$last_changed = wp_cache_get_last_changed( self::CACHE_GROUP );

		return $method . ':' . md5( (string) wp_json_encode( self::sort_keys( $args ) ) ) . ':' . $last_changed;
	}

	/**
	 * Sort an array's string keys, at every depth, so the order it was built in
	 * does not show in its hash. Lists are left in the order they are in.
	 *
	 * @param array $value Array to sort.
	 * @return array
	 */
	private static function sort_keys( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::sort_keys( $item );
			}
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		return $value;
	}

	/**
	 * Reduce a caller's filters to the ones that decide which rows match, in
	 * the one form the WHERE clause is built from.
	 *
	 * Both range queries build their SQL and their cache key from what this
	 * returns, and from nothing else in the caller's array. Two filter sets
	 * that ask the same question — a default left out or spelled out, term IDs
	 * reordered, repeated or padded with 0, "not featured" as false or as
	 * null — come out identical, and so share a cache entry. And a filter
	 * cannot reach the query without reaching the key, because the query never
	 * sees the caller's array.
	 *
	 * @param array $filters Filters as get_events_in_range() documents them.
	 * @return array Keyed by RESULT_FILTERS.
	 */
	private function canonical_filters( array $filters ): array {
		$term_ids = static function ( $value ): array {
			$ids = array_unique( array_filter( array_map( 'absint', (array) $value ) ) );
			sort( $ids );

			return $ids;
		};

		return [
			'venue_term_id'        => $term_ids( $filters['venue_term_id'] ?? null ),
			'type_term_id'         => $term_ids( $filters['type_term_id'] ?? null ),
			'exclude_type_term_id' => $term_ids( $filters['exclude_type_term_id'] ?? null ),
			'status'               => isset( $filters['status'] ) ? sanitize_text_field( (string) $filters['status'] ) : null,
			// Only true filters; false and null both mean "any".
			'featured'             => true === ( $filters['featured'] ?? null ),
			// On unless switched off; an explicit null switches it off, as it always has.
			'hide_hidden'          => array_key_exists( 'hide_hidden', $filters ) ? (bool) $filters['hide_hidden'] : true,
			// Unlike `featured`, false is meaningful here.
			'ongoing'              => isset( $filters['ongoing'] ) ? (bool) $filters['ongoing'] : null,
			'ended_before'         => isset( $filters['ended_before'] ) ? (string) $filters['ended_before'] : null,
		];
	}

	/**
	 * The WHERE clause both range queries share, and its values.
	 *
	 * One copy, so the page of rows and the total beside it cannot come to
	 * disagree about what matches.
	 *
	 * @param string $start   UTC datetime string (Y-m-d H:i:s).
	 * @param string $end     UTC datetime string (Y-m-d H:i:s).
	 * @param array  $filters Filters from canonical_filters().
	 * @return array{ 0: string, 1: array } SQL beginning "WHERE", and the values for its placeholders.
	 */
	private function range_where( string $start, string $end, array $filters ): array {
		$where  = [];
		$params = [];

		if ( null !== $filters['ended_before'] ) {
			// Past mode — the event has finished, and finished inside the window.
			// A narrower $end tightens the cutoff; $start bounds how far back to look.
			$where[]  = 'e.end_datetime <= %s';
			$params[] = min( $end, $filters['ended_before'] );
			$where[]  = 'e.end_datetime > %s';
			$params[] = $start;
			$where[]  = 'e.ongoing = 0';
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

		if ( null !== $filters['status'] ) {
			$where[]  = 'e.status = %s';
			$params[] = $filters['status'];
		}

		if ( ! empty( $filters['venue_term_id'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $filters['venue_term_id'] ), '%d' ) );
			$where[]      = "e.venue_term_id IN ($placeholders)";
			$params       = array_merge( $params, $filters['venue_term_id'] );
		}

		// Event type filter — junction table subquery (replaces JSON_CONTAINS).
		if ( ! empty( $filters['type_term_id'] ) ) {
			$type_terms_table = Schema::type_terms_table();
			$placeholders     = implode( ', ', array_fill( 0, count( $filters['type_term_id'] ), '%d' ) );
			$where[]          = "e.id IN (SELECT event_index_id FROM {$type_terms_table} WHERE type_term_id IN ({$placeholders}))";
			$params           = array_merge( $params, $filters['type_term_id'] );
		}

		// Event type exclusion — same junction subquery, negated.
		if ( ! empty( $filters['exclude_type_term_id'] ) ) {
			$type_terms_table = Schema::type_terms_table();
			$placeholders     = implode( ', ', array_fill( 0, count( $filters['exclude_type_term_id'] ), '%d' ) );
			$where[]          = "e.id NOT IN (SELECT event_index_id FROM {$type_terms_table} WHERE type_term_id IN ({$placeholders}))";
			$params           = array_merge( $params, $filters['exclude_type_term_id'] );
		}

		// The three flags below are denormalised columns.
		if ( $filters['featured'] ) {
			$where[] = 'e.featured = 1';
		}

		if ( $filters['hide_hidden'] ) {
			$where[] = 'e.hide_from_listings = 0';
		}

		if ( null !== $filters['ongoing'] ) {
			$where[] = $filters['ongoing'] ? 'e.ongoing = 1' : 'e.ongoing = 0';
		}

		return [ 'WHERE ' . implode( ' AND ', $where ), $params ];
	}

	/**
	 * Store a read in the cache, for a day at most.
	 *
	 * Every key carries the index's last-changed stamp, so a write to the
	 * index orphans all the entries before it; nothing asks for them again.
	 * The in-memory cache drops them with the request. A persistent one kept
	 * them until it ran out of room, which is why they are given a lifetime.
	 * Every cache write in this class goes through here.
	 *
	 * @param string $key   Key from cache_key().
	 * @param mixed  $value Value to store.
	 */
	private function cache_set( string $key, mixed $value ): void {
		wp_cache_set( $key, $value, self::CACHE_GROUP, self::CACHE_TTL );
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

		$events_table = Schema::events_table();
		$posts_table  = $wpdb->posts;

		$matching = $this->canonical_filters( $filters );

		// ORDER BY — whitelist columns to prevent injection.
		$allowed_orderby = [ 'start_datetime', 'end_datetime', 'post_title' ];
		$orderby         = in_array( $filters['orderby'] ?? null, $allowed_orderby, true )
			? $filters['orderby']
			: 'start_datetime';

		$order = 'DESC' === strtoupper( (string) ( $filters['order'] ?? 'ASC' ) ) ? 'DESC' : 'ASC';

		// Pagination.
		$ceiling  = max( 1, (int) ( $filters['max_per_page'] ?? self::DEFAULT_MAX_PER_PAGE ) );
		$per_page = max( 1, min( $ceiling, (int) ( $filters['per_page'] ?? 100 ) ) );
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Keyed on what the query is built from, not on what the caller wrote:
		// the filters as reduced above and the page as clamped here.
		$cache_key = $this->cache_key( 'range', [ $start, $end, $matching, $orderby, $order, $per_page, $offset ] );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		[ $where_sql, $params ] = $this->range_where( $start, $end, $matching );

		$index_hint = '';

		if ( null !== $matching['ended_before'] ) {
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
		}

		$order_sql = 'post_title' === $orderby ? "p.post_title $order" : "e.$orderby $order";

		// $where_sql is assembled from literal fragments in range_where(); every user
		// value is a %s/%d placeholder in $params, so the count is only knowable at runtime.
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
			ORDER  BY {$order_sql}
			LIMIT  %d OFFSET %d",
			array_merge( $params, [ $per_page, $offset ] )
		);

		$results = $wpdb->get_results( $query );
		// phpcs:enable

		$this->cache_set( $cache_key, $results );

		return $results;
	}

	/**
	 * Count events in a range (for pagination totals).
	 *
	 * Accepts the same filters as get_events_in_range().
	 */
	public function count_events_in_range( string $start, string $end, array $filters = [] ): int {
		global $wpdb;

		// Sort order, page size and page number are left out of the key as
		// they are left out of the query: none of them can change a total, and
		// with them in it every page of a listing counted the listing again.
		$matching  = $this->canonical_filters( $filters );
		$cache_key = $this->cache_key( 'range_count', [ $start, $end, $matching ] );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$events_table = Schema::events_table();
		$posts_table  = $wpdb->posts;

		/*
		 * No IGNORE INDEX in past mode, unlike get_events_in_range().
		 *
		 * That hint pays off because the page query has an ORDER BY and a
		 * LIMIT, which is what tempts the optimizer onto idx_start_datetime.
		 * A COUNT(*) has neither: it has to visit every matching row either
		 * way, and already declines to use idx_start_datetime. Measured on
		 * the same 200k rows, the hint changed nothing (25.9ms vs 26.1ms),
		 * so it is left off rather than carried over for symmetry.
		 */
		[ $where_sql, $params ] = $this->range_where( $start, $end, $matching );

		// $where_sql is assembled from literal fragments in range_where(); every user
		// value is a %s/%d placeholder in $params, so the count is only knowable at runtime.
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

		$this->cache_set( $cache_key, (int) $count );

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

		$this->cache_set( $cache_key, $results );

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
	 * Get the last occurrence indexed for a post, whenever it is.
	 *
	 * What a series that is already over falls back to, where
	 * next_occurrence() has nothing to return.
	 *
	 * @param int $post_id The event post ID.
	 * @return object|null Index row, or null if the post has none.
	 */
	public static function last_occurrence( int $post_id ): ?object {
		global $wpdb;

		$table = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d ORDER BY start_datetime DESC LIMIT 1",
				$post_id
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

		$this->cache_set( $cache_key, $ids );

		return $ids;
	}

	/**
	 * Delete all index rows for a given post ID, including junction table rows.
	 *
	 * @param int $post_id The event post ID.
	 * @return int Number of rows deleted from the events table.
	 */
	public function delete_by_post_id( int $post_id ): int {
		$deleted = $this->delete_rows( $post_id );

		$this->flush_cache();

		return (int) $deleted;
	}

	/**
	 * Delete a post's rows and their junction rows, leaving the cache alone.
	 *
	 * @param int $post_id The event post ID.
	 * @return int|false Rows deleted from the events table, or false on failure.
	 */
	private function delete_rows( int $post_id ): int|false {
		global $wpdb;

		$events_table     = Schema::events_table();
		$type_terms_table = Schema::type_terms_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$junction = $wpdb->query(
			$wpdb->prepare(
				"DELETE t FROM {$type_terms_table} t
				INNER JOIN {$events_table} e ON e.id = t.event_index_id
				WHERE e.post_id = %d",
				$post_id
			)
		);
		// phpcs:enable

		if ( false === $junction ) {
			return false;
		}

		return $wpdb->delete( $events_table, [ 'post_id' => $post_id ], [ '%d' ] );
	}

	/**
	 * Replace all of a post's rows with a new set, or leave them as they were.
	 *
	 * The delete and the inserts are one transaction. Apart, a failure between
	 * them left the event with some of its occurrences or none, and a reader
	 * arriving between them saw the same.
	 *
	 * @param int     $post_id The event post ID.
	 * @param array[] $rows    Rows in the shape insert() takes. May be empty.
	 * @return bool False when the rows could not be written; the old ones remain.
	 */
	public function replace_for_post( int $post_id, array $rows ): bool {
		global $wpdb;

		$events_table = Schema::events_table();
		$attempts     = 0;

		do {
			++$attempts;

			// A deadlock is expected now and then, and is answered by trying
			// again; it is not logged unless the last attempt fails too.
			$suppressed  = $wpdb->suppress_errors( true );
			$transaction = $this->begin();
			$written     = false;

			try {
				/*
				 * Take the post's rows before touching them. Two builds of one
				 * event at the same moment then run one after the other, where
				 * otherwise each could hold what the other was waiting for.
				 *
				 * If this fails, nothing else is attempted. A deadlock here
				 * means the server has already undone the transaction, and
				 * anything written after it would be written for good, with
				 * nothing to take it back if a later statement failed.
				 */
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$locked = $wpdb->query(
					$wpdb->prepare( "SELECT id FROM {$events_table} WHERE post_id = %d FOR UPDATE", $post_id )
				);
				// phpcs:enable

				$written = false !== $locked
					&& false !== $this->delete_rows( $post_id )
					&& $this->write_rows( $post_id, $rows, 0 );

				$error = $written ? '' : (string) $wpdb->last_error;

				// On a deadlock the server has already undone the transaction. Only
				// worth repeating when the transaction was ours to begin with.
				$deadlocked = ! $written && 'transaction' === $transaction && $this->last_error_was_deadlock();
			} finally {
				// Reached on an exception as well, so that neither an open
				// transaction nor silenced errors outlive this call.
				$this->end( $transaction, $written );
				$wpdb->suppress_errors( $suppressed );
			}
		} while ( $deadlocked && $attempts < self::DEADLOCK_ATTEMPTS );

		if ( ! $written && '' !== $error ) {
			$wpdb->print_error( $error );
		}

		$this->flush_cache();

		return $written;
	}

	/**
	 * Whether the statement that just failed was chosen as a deadlock victim.
	 */
	private function last_error_was_deadlock(): bool {
		global $wpdb;

		// ER_LOCK_DEADLOCK. The number, because the message is translated.
		return $wpdb->dbh instanceof \mysqli && 1213 === $wpdb->dbh->errno;
	}

	/**
	 * Add rows to the ones a post already has, all of them or none.
	 *
	 * @param int     $post_id The event post ID.
	 * @param array[] $rows    Rows in the shape insert() takes.
	 * @param bool    $flush   Invalidate the read cache afterwards. See insert().
	 * @return bool False when the rows could not be written.
	 */
	public function insert_many( int $post_id, array $rows, bool $flush = true ): bool {
		global $wpdb;

		if ( empty( $rows ) ) {
			return true;
		}

		$events_table = Schema::events_table();
		$transaction  = $this->begin();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE( MAX(id), 0 ) FROM {$events_table} WHERE post_id = %d", $post_id )
		);
		// phpcs:enable

		$written = $this->write_rows( $post_id, $rows, $last_id );

		$this->end( $transaction, $written );

		if ( $flush ) {
			$this->flush_cache();
		}

		return $written;
	}

	/**
	 * Insert rows for one post, several to a statement, then their junction rows.
	 *
	 * One statement per row meant 3,650 of them for a daily event indexed ten
	 * years ahead, and as many again for each event type.
	 *
	 * @param int     $post_id  The event post ID.
	 * @param array[] $rows     Rows in the shape insert() takes.
	 * @param int     $after_id The post's highest row ID before this call; the
	 *                          rows written are the ones above it.
	 */
	private function write_rows( int $post_id, array $rows, int $after_id ): bool {
		global $wpdb;

		$events_table     = Schema::events_table();
		$type_terms_table = Schema::type_terms_table();
		$has_types        = false;

		foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
			$groups = [];
			$values = [];

			foreach ( $chunk as $data ) {
				$row            = $this->normalise_row( $data );
				$row['post_id'] = $post_id;
				$has_types      = $has_types || null !== $row['type_term_ids'];

				// prepare() has no placeholder for NULL, so the three columns
				// that may be empty are written as the keyword when they are.
				$groups[] = sprintf(
					'( %%d, %%s, %%s, %%s, %%s, %%d, %s, %%s, %s, %s, %%d, %%d, %%d )',
					null === $row['recurrence_id'] ? 'NULL' : '%d',
					null === $row['venue_term_id'] ? 'NULL' : '%d',
					null === $row['type_term_ids'] ? 'NULL' : '%s'
				);

				foreach ( $row as $value ) {
					if ( null !== $value ) {
						$values[] = $value;
					}
				}
			}

			$columns = implode( ', ', array_keys( $this->normalise_row( $chunk[0] ) ) );
			$groups  = implode( ', ', $groups );

			// $groups is a runtime-built list of placeholder groups, one per row;
			// every value is still bound through prepare().
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$result = $wpdb->query(
				$wpdb->prepare( "INSERT INTO {$events_table} ( {$columns} ) VALUES {$groups}", $values )
			);
			// phpcs:enable

			if ( false === $result ) {
				return false;
			}
		}

		if ( ! $has_types ) {
			return true;
		}

		/*
		 * The junction rows need the IDs the rows were just given. They are
		 * read back rather than worked out from the first one: MySQL does not
		 * promise consecutive IDs for a multi-row insert in every lock mode.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type_term_ids FROM {$events_table}
				WHERE post_id = %d AND id > %d AND type_term_ids IS NOT NULL",
				$post_id,
				$after_id
			)
		);
		// phpcs:enable

		$pairs = [];

		foreach ( $inserted as $row ) {
			foreach ( (array) json_decode( (string) $row->type_term_ids, true ) as $type_term_id ) {
				$pairs[] = (int) $row->id;
				$pairs[] = (int) $type_term_id;
			}
		}

		// Two values to a pair, so this is INSERT_CHUNK * 10 pairs to a statement.
		foreach ( array_chunk( $pairs, self::INSERT_CHUNK * 20 ) as $chunk ) {
			$groups = implode( ', ', array_fill( 0, count( $chunk ) / 2, '( %d, %d )' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$result = $wpdb->query(
				$wpdb->prepare( "INSERT INTO {$type_terms_table} ( event_index_id, type_term_id ) VALUES {$groups}", $chunk )
			);
			// phpcs:enable

			if ( false === $result ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Open a transaction, or a savepoint inside one that is already open.
	 *
	 * With autocommit off, statements are already in a transaction that
	 * belongs to someone else — the test suite runs every test in one — and
	 * START TRANSACTION would commit it. A savepoint gives the same all-or-
	 * nothing write without ending theirs.
	 *
	 * @return string What was opened: "transaction" or "savepoint".
	 */
	private function begin(): string {
		global $wpdb;

		if ( '0' === (string) $wpdb->get_var( 'SELECT @@autocommit' ) ) {
			$wpdb->query( 'SAVEPOINT blockendar_index_write' );

			return 'savepoint';
		}

		$wpdb->query( 'START TRANSACTION' );

		return 'transaction';
	}

	/**
	 * Keep or undo what was written since begin().
	 *
	 * @param string $opened  What begin() returned.
	 * @param bool   $written Whether every write succeeded.
	 */
	private function end( string $opened, bool $written ): void {
		global $wpdb;

		if ( 'savepoint' === $opened && $written ) {
			$wpdb->query( 'RELEASE SAVEPOINT blockendar_index_write' );
		} elseif ( 'savepoint' === $opened ) {
			$wpdb->query( 'ROLLBACK TO SAVEPOINT blockendar_index_write' );
		} elseif ( $written ) {
			$wpdb->query( 'COMMIT' );
		} else {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	/**
	 * A row as the events table stores it, from the shape insert() takes.
	 *
	 * @param array $data Row data.
	 * @return array Column => value, in column order.
	 */
	private function normalise_row( array $data ): array {
		$type_term_ids = isset( $data['type_term_ids'] )
			? array_values( array_map( 'intval', (array) $data['type_term_ids'] ) )
			: [];

		return [
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

		$row     = $this->normalise_row( $data );
		$formats = [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%d' ];

		$result = $wpdb->insert( Schema::events_table(), $row, $formats );

		if ( false === $result ) {
			return false;
		}

		$index_id = $wpdb->insert_id;

		// Populate junction table for fast type-term filtering.
		$type_terms_table = Schema::type_terms_table();

		foreach ( (array) json_decode( (string) $row['type_term_ids'], true ) as $type_term_id ) {
			$filed = $wpdb->insert(
				$type_terms_table,
				[
					'event_index_id' => $index_id,
					'type_term_id'   => $type_term_id,
				],
				[ '%d', '%d' ]
			);

			// A row with no junction rows is in the index and invisible to
			// every filter by type. Better not there, and reported.
			if ( false === $filed ) {
				$wpdb->delete( $type_terms_table, [ 'event_index_id' => $index_id ], [ '%d' ] );
				$wpdb->delete( Schema::events_table(), [ 'id' => $index_id ], [ '%d' ] );

				return false;
			}
		}

		if ( $flush ) {
			$this->flush_cache();
		}

		return $index_id;
	}

	/**
	 * The start date of every occurrence a post has in the index, Y-m-d.
	 *
	 * Read straight from the table, for the nightly roll to tell which
	 * occurrences are already there. The local date and not the UTC instant:
	 * an event with no timezone of its own takes the site's, and when that
	 * changes every instant moves while every date stays where it was.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public function start_dates( int $post_id ): array {
		global $wpdb;

		$events_table = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT start_date FROM {$events_table} WHERE post_id = %d",
				$post_id
			)
		);
		// phpcs:enable

		return array_map( 'strval', $values );
	}

	/**
	 * Whether a post has any row in the index.
	 *
	 * Read straight from the table: a full rebuild asks this once per event,
	 * about rows it has just written.
	 *
	 * @param int $post_id Post ID.
	 */
	public function has_rows( int $post_id ): bool {
		global $wpdb;

		$events_table = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$events_table} WHERE post_id = %d LIMIT 1",
				$post_id
			)
		);
		// phpcs:enable

		return null !== $found;
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

		$this->cache_set( $cache_key, $count );

		return $count;
	}
}
