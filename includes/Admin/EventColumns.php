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
		global $wpdb;

		if ( ! in_array( $column, [ 'blockendar_start_date', 'blockendar_end_date' ], true ) ) {
			return;
		}

		$table = Schema::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT start_date, start_datetime, end_date, end_datetime, all_day, ongoing FROM %i WHERE post_id = %d ORDER BY start_datetime ASC LIMIT 1',
				$table,
				$post_id
			)
		);

		if ( ! $row ) {
			echo '&mdash;';
			return;
		}

		$all_day     = (bool) $row->all_day;
		$date_format = get_option( 'date_format', 'F j, Y' );
		$time_format = get_option( 'time_format', 'g:i a' );

		if ( 'blockendar_start_date' === $column ) {
			$display = esc_html( date_i18n( $date_format, strtotime( $row->start_date ) ) );
			if ( ! $all_day && $row->start_datetime ) {
				$display .= ' <span style="color:#757575">' . esc_html( date_i18n( $time_format, strtotime( $row->start_datetime ) ) ) . '</span>';
			}
			echo wp_kses( $display, [ 'span' => [ 'style' => [] ] ] );
		} elseif ( ! empty( $row->ongoing ) ) {
			// The index holds a sentinel end for ongoing events; never print it.
			echo esc_html__( 'Ongoing', 'blockendar' );
		} else {
			$display = $row->end_date ? esc_html( date_i18n( $date_format, strtotime( $row->end_date ) ) ) : '&mdash;';
			if ( ! $all_day && $row->end_datetime && $row->end_date ) {
				$display .= ' <span style="color:#757575">' . esc_html( date_i18n( $time_format, strtotime( $row->end_datetime ) ) ) . '</span>';
			}
			echo wp_kses( $display, [ 'span' => [ 'style' => [] ] ] );
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
