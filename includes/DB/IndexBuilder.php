<?php
/**
 * Rebuilds the blockendar_events index from CPT post meta.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\CPT\EventPostType;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;

/**
 * Keeps the {prefix}blockendar_events index in sync with post meta.
 *
 * The index is a derived projection — it is never the authoritative source.
 * It can always be rebuilt in full from the CPT data via rebuild_all().
 */
class IndexBuilder {

	private EventIndex $index;

	public function __construct() {
		$this->index = new EventIndex();
	}

	/**
	 * Register hooks for keeping the index in sync on save/delete.
	 */
	public function register(): void {
		add_action( 'save_post_' . EventPostType::POST_TYPE, [ $this, 'on_save' ], 20, 2 );
		// REST API writes meta AFTER save_post fires, so re-index once all meta is persisted.
		add_action( 'rest_after_insert_' . EventPostType::POST_TYPE, [ $this, 'on_rest_insert' ], 10, 1 );
		add_action( 'before_delete_post', [ $this, 'on_delete' ] );
		add_action( 'trashed_post', [ $this, 'on_delete' ] );
		add_action( 'untrashed_post', [ $this, 'on_untrash' ] );
	}

	/**
	 * Rebuild index rows for a single event post on save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function on_save( int $post_id, \WP_Post $post ): void {
		// Skip autosaves, revisions, and non-published posts that have no index rows.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Always delete existing rows first.
		$this->index->delete_by_post_id( $post_id );

		// Only index published posts.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$this->build_for_post( $post_id );
	}

	/**
	 * Re-index after a REST API insert/update — meta is guaranteed to be written by this point.
	 *
	 * @param \WP_Post $post Updated post object.
	 */
	public function on_rest_insert( \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Delete any rows written by the earlier save_post hook (which had stale/empty meta).
		$this->index->delete_by_post_id( $post->ID );
		$this->build_for_post( $post->ID );
	}

	/**
	 * Remove index rows when a post is deleted or trashed.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete( int $post_id ): void {
		if ( EventPostType::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$this->index->delete_by_post_id( $post_id );
	}

	/**
	 * Rebuild index rows when a post is restored from trash.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_untrash( int $post_id ): void {
		if ( EventPostType::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( $post ) {
			$this->build_for_post( $post_id );
		}
	}

	/**
	 * Generate and insert index rows for a single post.
	 *
	 * For non-recurring events this produces one row.
	 * For recurring events the recurrence engine handles materialisation —
	 * IndexBuilder only handles the single-occurrence case here.
	 * The Recurrence\Generator calls index->insert() directly for instances.
	 *
	 * @param int $post_id Post ID.
	 */
	public function build_for_post( int $post_id ): void {
		$meta = $this->get_event_meta( $post_id );

		if ( empty( $meta['start_date'] ) || empty( $meta['end_date'] ) ) {
			return;
		}

		// If this is a recurring event, the recurrence engine owns index generation.
		if ( $this->has_recurrence( $post_id ) ) {
			do_action( 'blockendar_generate_recurrence_index', $post_id );
			return;
		}

		$row = $this->build_row( $post_id, $meta );

		if ( null !== $row ) {
			$this->index->insert( $row );
		}
	}

	/**
	 * Rebuild the entire index for all published events.
	 * Used by WP-CLI and the admin "Rebuild Index" button.
	 *
	 * @return array{ rebuilt: int, skipped: int } Result summary.
	 */
	public function rebuild_all(): array {
		global $wpdb;

		// Truncate both the index and the junction table.
		$events_table     = Schema::events_table();
		$type_terms_table = Schema::type_terms_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$type_terms_table}" );
		$wpdb->query( "TRUNCATE TABLE {$events_table}" );
		// phpcs:enable

		$rebuilt = 0;
		$skipped = 0;

		// Process in batches to avoid memory exhaustion on large sites.
		$batch_size = 100;
		$offset     = 0;

		do {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_type = %s AND post_status = 'publish'
					ORDER BY ID ASC
					LIMIT %d OFFSET %d",
					EventPostType::POST_TYPE,
					$batch_size,
					$offset
				)
			);

			foreach ( $post_ids as $post_id ) {
				$post_id = (int) $post_id;
				$meta    = $this->get_event_meta( $post_id );

				if ( empty( $meta['start_date'] ) ) {
					++$skipped;
					continue;
				}

				$this->build_for_post( $post_id );
				++$rebuilt;
			}

			$fetched_count = count( $post_ids );
			$offset       += $batch_size;
		} while ( $fetched_count === $batch_size );

		update_option( 'blockendar_last_index_rebuild', gmdate( 'Y-m-d H:i:s' ) );

		return [
			'rebuilt' => $rebuilt,
			'skipped' => $skipped,
		];
	}

	/**
	 * Build a single index row array from event meta.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $meta    Event meta from get_event_meta().
	 * @return array|null Row data array, or null if required fields are missing.
	 */
	private function build_row( int $post_id, array $meta ): ?array {
		$timezone_str = ! empty( $meta['timezone'] ) ? $meta['timezone'] : wp_timezone_string();

		try {
			$tz = new \DateTimeZone( $timezone_str );
		} catch ( \Exception ) {
			$tz = wp_timezone();
		}

		$utc = new \DateTimeZone( 'UTC' );

		$all_day    = ! empty( $meta['all_day'] );
		$start_time = $all_day ? '00:00' : ( $meta['start_time'] ?: '00:00' );
		$end_time   = $all_day ? '00:00' : ( $meta['end_time'] ?: $start_time );

		$start_local_str = "{$meta['start_date']} {$start_time}:00";

		if ( $all_day ) {
			$end_date_exclusive = gmdate( 'Y-m-d', strtotime( '+1 day', strtotime( $meta['end_date'] ) ) );
			$end_local_str      = "{$end_date_exclusive} 00:00:00";
		} else {
			$end_local_str = "{$meta['end_date']} {$end_time}:00";
		}

		try {
			$start_dt = new \DateTimeImmutable( $start_local_str, $tz );
			$end_dt   = new \DateTimeImmutable( $end_local_str, $tz );
		} catch ( \Exception ) {
			return null;
		}

		return [
			'post_id'            => $post_id,
			'start_datetime'     => $start_dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end_datetime'       => $end_dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'start_date'         => $meta['start_date'],
			'end_date'           => $meta['end_date'],
			'all_day'            => $all_day ? 1 : 0,
			'recurrence_id'      => null,
			'status'             => $meta['status'] ?? 'scheduled',
			'venue_term_id'      => $this->get_venue_term_id( $post_id ),
			'type_term_ids'      => $this->get_type_term_ids( $post_id ),
			'featured'           => ! empty( $meta['featured'] ) ? 1 : 0,
			'hide_from_listings' => ! empty( $meta['hide_from_listings'] ) ? 1 : 0,
		];
	}

	/**
	 * Read all relevant post meta for an event.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_event_meta( int $post_id ): array {
		return [
			'start_date'         => get_post_meta( $post_id, 'blockendar_start_date', true ),
			'end_date'           => get_post_meta( $post_id, 'blockendar_end_date', true ),
			'start_time'         => get_post_meta( $post_id, 'blockendar_start_time', true ),
			'end_time'           => get_post_meta( $post_id, 'blockendar_end_time', true ),
			'all_day'            => get_post_meta( $post_id, 'blockendar_all_day', true ),
			'timezone'           => get_post_meta( $post_id, 'blockendar_timezone', true ),
			'status'             => get_post_meta( $post_id, 'blockendar_status', true ) ?: 'scheduled',
			'featured'           => (bool) get_post_meta( $post_id, 'blockendar_featured', true ),
			'hide_from_listings' => (bool) get_post_meta( $post_id, 'blockendar_hide_from_listings', true ),
		];
	}

	/**
	 * Check if this post has an active recurrence rule.
	 *
	 * @param int $post_id Post ID.
	 */
	private function has_recurrence( int $post_id ): bool {
		global $wpdb;

		$recurrence_table = Schema::recurrence_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$recurrence_table} WHERE post_id = %d",
				$post_id
			)
		);
		// phpcs:enable

		return (int) $count > 0;
	}

	/**
	 * Get the venue term ID assigned to a post (first term only).
	 *
	 * @param int $post_id Post ID.
	 * @return int|null
	 */
	private function get_venue_term_id( int $post_id ): ?int {
		$terms = get_the_terms( $post_id, Venue::TAXONOMY );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return (int) $terms[0]->term_id;
	}

	/**
	 * Get an array of event type term IDs assigned to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private function get_type_term_ids( int $post_id ): array {
		$terms = get_the_terms( $post_id, EventType::TAXONOMY );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		return array_map( fn( $t ) => (int) $t->term_id, $terms );
	}
}
