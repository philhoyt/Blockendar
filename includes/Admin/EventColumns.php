<?php
/**
 * Custom columns for the blockendar_event list table.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\DB\Schema;

/**
 * Adds Start Date and End Date columns to the event list table,
 * with sortable Start Date backed by the custom index table.
 */
class EventColumns {

	/**
	 * Earliest index row of each event looked up this request, by post ID.
	 * Null for an event with no rows.
	 *
	 * @var array<int, object|null>
	 */
	private array $first_occurrences = [];

	/**
	 * Register all hooks.
	 */
	public function register(): void {
		add_filter( 'manage_blockendar_event_posts_columns', [ $this, 'add_columns' ] );
		add_action( 'manage_blockendar_event_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_filter( 'manage_edit-blockendar_event_sortable_columns', [ $this, 'sortable_columns' ] );
		add_action( 'pre_get_posts', [ $this, 'handle_sort' ] );
		add_action( 'pre_get_posts', [ $this, 'default_sort' ] );
	}

	/**
	 * Insert Start Date and End Date columns after the title column.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_columns( array $columns ): array {
		unset( $columns['date'] );

		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['blockendar_start_date'] = __( 'Start Date', 'blockendar' );
				$new['blockendar_end_date']   = __( 'End Date', 'blockendar' );
			}
		}
		return $new;
	}

	/**
	 * Render the Start Date or End Date column value for a given post.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( ! in_array( $column, [ 'blockendar_start_date', 'blockendar_end_date' ], true ) ) {
			return;
		}

		$row = $this->first_occurrence( $post_id );

		if ( ! $row ) {
			echo '&mdash;';
			return;
		}

		if ( 'blockendar_end_date' === $column && ! empty( $row->ongoing ) ) {
			// The index holds a sentinel end for ongoing events; never print it.
			echo esc_html__( 'Ongoing', 'blockendar' );
			return;
		}

		$is_start    = 'blockendar_start_date' === $column;
		$date_format = get_option( 'date_format', 'F j, Y' );

		if ( $row->all_day ) {
			// No clock time to convert: the stored dates are the event's own days.
			echo esc_html( blockendar_format_wall_clock( $is_start ? $row->start_date : $row->end_date, $date_format ) );
			return;
		}

		$local = $this->to_display_timezone( $is_start ? $row->start_datetime : $row->end_datetime, $post_id );

		if ( '' === $local ) {
			echo '&mdash;';
			return;
		}

		$display = esc_html( blockendar_format_wall_clock( $local, $date_format ) )
			. ' <span style="color:#757575">'
			. esc_html( blockendar_format_wall_clock( $local, get_option( 'time_format', 'g:i a' ) ) )
			. '</span>';

		echo wp_kses( $display, [ 'span' => [ 'style' => [] ] ] );
	}

	/**
	 * Move one of the index's UTC datetimes into the timezone the event is shown in.
	 *
	 * The date is taken from the result as well as the time: 11:30 pm in one
	 * zone is the next day in another.
	 *
	 * @param string $utc_datetime Y-m-d H:i:s in UTC.
	 * @param int    $post_id      Event post ID.
	 * @return string Y-m-d H:i in the display timezone, or '' if the value is not a datetime.
	 */
	private function to_display_timezone( string $utc_datetime, int $post_id ): string {
		$moment = date_create_immutable( $utc_datetime, new \DateTimeZone( 'UTC' ) );

		if ( ! $moment ) {
			return '';
		}

		try {
			$timezone = new \DateTimeZone( blockendar_display_timezone( $post_id )['display'] );
		} catch ( \Exception ) {
			$timezone = wp_timezone();
		}

		return $moment->setTimezone( $timezone )->format( 'Y-m-d H:i' );
	}

	/**
	 * The earliest index row for an event.
	 *
	 * The list table calls render_column() once per column per row. The first
	 * call reads the first occurrence of every event on the page in one query;
	 * the rest are answered from that.
	 *
	 * @param int $post_id Event post ID.
	 */
	private function first_occurrence( int $post_id ): ?object {
		if ( ! array_key_exists( $post_id, $this->first_occurrences ) ) {
			$this->load_first_occurrences( array_merge( [ $post_id ], $this->page_post_ids() ) );
		}

		return $this->first_occurrences[ $post_id ] ?? null;
	}

	/**
	 * IDs of the events on the list-table page being rendered.
	 *
	 * @return int[]
	 */
	private function page_post_ids(): array {
		global $wp_query;

		if ( ! $wp_query instanceof \WP_Query || empty( $wp_query->posts ) ) {
			return [];
		}

		return array_map(
			static fn( $post ): int => is_object( $post ) ? (int) $post->ID : (int) $post,
			$wp_query->posts
		);
	}

	/**
	 * Read the earliest index row of each event into the per-request store.
	 *
	 * An event with no rows is stored as null, so it is not looked up again.
	 *
	 * @param int[] $post_ids Event post IDs.
	 */
	private function load_first_occurrences( array $post_ids ): void {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$post_ids = array_values( array_diff( $post_ids, array_keys( $this->first_occurrences ) ) );

		if ( empty( $post_ids ) ) {
			return;
		}

		$this->first_occurrences += array_fill_keys( $post_ids, null );

		$table        = Schema::events_table();
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// Joined to each event's earliest start so every row comes back whole; a
		// bare GROUP BY would mix columns from different occurrences.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a list of %d, one per ID.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.post_id, e.start_date, e.start_datetime, e.end_date, e.end_datetime, e.all_day, e.ongoing
				FROM %i e
				INNER JOIN (
					SELECT post_id, MIN( start_datetime ) AS first_start
					FROM %i
					WHERE post_id IN ( {$placeholders} )
					GROUP BY post_id
				) f ON f.post_id = e.post_id AND f.first_start = e.start_datetime",
				array_merge( [ $table, $table ], $post_ids )
			)
		);
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			// Two rows can share a start; the first one read stands.
			$this->first_occurrences[ (int) $row->post_id ] ??= $row;
		}
	}

	/**
	 * Declare Start Date as a sortable column.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['blockendar_start_date'] = 'blockendar_start_date';
		return $columns;
	}

	/**
	 * Handle explicit sort by Start Date.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public function handle_sort( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'blockendar_event' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( 'blockendar_start_date' !== $query->get( 'orderby' ) ) {
			return;
		}

		$order = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
		$this->join_index_table( $query, $order );
	}

	/**
	 * Apply ascending Start Date as the default sort when no orderby is set.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public function default_sort( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'blockendar_event' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( $query->get( 'orderby' ) ) {
			return;
		}

		$this->join_index_table( $query, 'DESC' );
	}

	/**
	 * Add a LEFT JOIN to the index table and sort by MIN(start_datetime).
	 * Uses posts_orderby to inject the ORDER BY directly so the SQL alias
	 * is resolved correctly regardless of WP_Query's internal sanitization.
	 *
	 * @param \WP_Query $target The one query these filters should affect.
	 * @param string    $order  'ASC' or 'DESC'.
	 */
	private function join_index_table( \WP_Query $target, string $order = 'ASC' ): void {
		global $wpdb;
		$table = Schema::events_table();

		/*
		 * Every one of these filters applies to EVERY WP_Query for the rest of
		 * the request, not just the one that was running when pre_get_posts
		 * fired. Left unguarded they would bolt a LEFT JOIN, a GROUP BY and an
		 * ORDER BY on an alias onto any secondary query another plugin runs
		 * later on this screen — a wasted join at best, a SQL error on the
		 * unknown alias at worst. Binding each closure to the exact WP_Query
		 * instance it was added for makes them inert everywhere else.
		 */
		add_filter(
			'posts_join',
			static function ( string $join, \WP_Query $query ) use ( $wpdb, $table, $target ): string {
				if ( $query !== $target ) {
					return $join;
				}

				return $join . " LEFT JOIN {$table} AS be ON be.post_id = {$wpdb->posts}.ID";
			},
			10,
			2
		);

		add_filter(
			'posts_fields',
			static function ( string $fields, \WP_Query $query ) use ( $target ): string {
				if ( $query !== $target ) {
					return $fields;
				}

				return $fields . ', MIN(be.start_datetime) AS blockendar_start_datetime';
			},
			10,
			2
		);

		add_filter(
			'posts_groupby',
			static function ( string $groupby, \WP_Query $query ) use ( $wpdb, $target ): string {
				if ( $query !== $target ) {
					return $groupby;
				}

				return "{$wpdb->posts}.ID";
			},
			10,
			2
		);

		add_filter(
			'posts_orderby',
			static function ( string $orderby, \WP_Query $query ) use ( $order, $target ): string {
				if ( $query !== $target ) {
					return $orderby;
				}

				return "blockendar_start_datetime {$order}";
			},
			10,
			2
		);
	}
}
