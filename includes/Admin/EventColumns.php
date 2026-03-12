<?php
/**
 * Custom columns for the blockendar_event list table.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Admin;

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
				'SELECT start_date, start_datetime, end_date, end_datetime, all_day FROM %i WHERE post_id = %d ORDER BY start_datetime ASC LIMIT 1',
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
		$this->join_index_table( $order );
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

		$this->join_index_table( 'DESC' );
	}

	/**
	 * Add a LEFT JOIN to the index table and sort by MIN(start_datetime).
	 * Uses posts_orderby to inject the ORDER BY directly so the SQL alias
	 * is resolved correctly regardless of WP_Query's internal sanitization.
	 *
	 * @param string $order 'ASC' or 'DESC'.
	 */
	private function join_index_table( string $order = 'ASC' ): void {
		global $wpdb;
		$table = Schema::events_table();

		add_filter(
			'posts_join',
			function ( string $join ) use ( $wpdb, $table ): string {
				$join .= " LEFT JOIN {$table} AS be ON be.post_id = {$wpdb->posts}.ID";
				return $join;
			}
		);

		add_filter(
			'posts_fields',
			function ( string $fields ): string {
				$fields .= ', MIN(be.start_datetime) AS blockendar_start_datetime';
				return $fields;
			}
		);

		add_filter(
			'posts_groupby',
			function () use ( $wpdb ): string {
				return "{$wpdb->posts}.ID";
			}
		);

		add_filter(
			'posts_orderby',
			function () use ( $order ): string {
				return "blockendar_start_datetime {$order}";
			}
		);
	}
}
