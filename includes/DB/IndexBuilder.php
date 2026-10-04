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

use Blockendar\Admin\SettingsPage;
use Blockendar\CPT\EventPostType;
use Blockendar\Recurrence\RuleRepository;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;

/**
 * Keeps the {prefix}blockendar_events index in sync with post meta.
 *
 * The index is a derived projection — it is never the authoritative source.
 * It can always be rebuilt in full from the CPT data via rebuild_all().
 */
class IndexBuilder {

	/**
	 * Cron hook used when generation_strategy is 'cron'.
	 *
	 * Carries the post ID, so a deferred rebuild affects only the event that
	 * was saved rather than the whole index.
	 */
	const DEFERRED_HOOK = 'blockendar_deferred_index_build';

	/**
	 * Cron hook that runs a full rebuild in the background.
	 *
	 * Named for where it began: Schema and Upgrader queue it after an upgrade.
	 * A rebuild that does not finish in one run queues it again.
	 */
	const REBUILD_HOOK = 'blockendar_index_rebuild_after_upgrade';

	/**
	 * Where a full rebuild has got to: the last post ID done and the running
	 * totals. Present only while a rebuild is under way.
	 */
	const CURSOR_OPTION = 'blockendar_rebuild_cursor';

	/**
	 * Seconds one pass of a full rebuild may run before it stops and leaves
	 * the rest for the next. Well inside the usual 30-second request limit,
	 * with room for the event in hand to finish.
	 */
	const REBUILD_BUDGET = 15.0;

	/** Posts fetched per query during a full rebuild. */
	const REBUILD_BATCH = 100;

	/**
	 * The post meta an index row is built from.
	 *
	 * A change to any of these changes the row. The event's other meta — its
	 * cost, its registration link — does not, and is not watched.
	 */
	const INDEX_META_KEYS = [
		'blockendar_start_date',
		'blockendar_end_date',
		'blockendar_start_time',
		'blockendar_end_time',
		'blockendar_all_day',
		'blockendar_timezone',
		'blockendar_status',
		'blockendar_featured',
		'blockendar_hide_from_listings',
		'blockendar_ongoing',
	];

	/**
	 * Events changed in this request whose rows have not been rebuilt yet.
	 *
	 * Shared by every instance: the hooks that fill it belong to the one the
	 * plugin registers, and a build by any other has to be able to clear it.
	 *
	 * @var array<int, true> Keyed by post ID.
	 */
	private static array $dirty = [];

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
		add_action( 'before_delete_post', [ $this, 'on_permanent_delete' ] );
		add_action( 'trashed_post', [ $this, 'on_delete' ] );
		add_action( 'untrashed_post', [ $this, 'on_untrash' ] );
		add_action( self::DEFERRED_HOOK, [ $this, 'build_for_post' ] );
		add_action( self::REBUILD_HOOK, [ $this, 'run_scheduled_rebuild' ], 10, 0 );

		// A row is built from the event's meta and terms, and both can be
		// written without the event being saved: by an importer, a sync, a
		// WP-CLI command, or a term being deleted. Those writes are noted here
		// and the event rebuilt once, when the request ends.
		add_action( 'added_post_meta', [ $this, 'on_meta_change' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'on_meta_change' ], 10, 3 );
		add_action( 'deleted_post_meta', [ $this, 'on_meta_change' ], 10, 3 );
		add_action( 'set_object_terms', [ $this, 'on_terms_change' ], 10, 4 );
		add_action( 'deleted_term_relationships', [ $this, 'on_terms_removed' ], 10, 3 );
		add_action( 'shutdown', [ $this, 'flush_dirty' ] );
	}

	/**
	 * Mark an event dirty when meta its row is built from changes.
	 *
	 * @param int|int[] $meta_id   Meta row ID, or IDs on deletion. Unused.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public function on_meta_change( $meta_id, $object_id, $meta_key ): void {
		if ( ! in_array( $meta_key, self::INDEX_META_KEYS, true ) ) {
			return;
		}

		if ( EventPostType::POST_TYPE === get_post_type( (int) $object_id ) ) {
			$this->mark_dirty( (int) $object_id );
		}
	}

	/**
	 * Mark an event dirty when its venue or event types are set.
	 *
	 * @param int    $object_id Post ID.
	 * @param array  $terms     Terms given. Unused.
	 * @param array  $tt_ids    Term taxonomy IDs now set. Unused.
	 * @param string $taxonomy  Taxonomy.
	 */
	public function on_terms_change( $object_id, $terms, $tt_ids, $taxonomy ): void {
		$this->on_terms_removed( $object_id, $tt_ids, $taxonomy );
	}

	/**
	 * Mark an event dirty when a venue or event type is taken off it, which is
	 * also what deleting the term does to every event that had it.
	 *
	 * @param int    $object_id Post ID.
	 * @param array  $tt_ids    Term taxonomy IDs removed. Unused.
	 * @param string $taxonomy  Taxonomy.
	 */
	public function on_terms_removed( $object_id, $tt_ids, $taxonomy ): void {
		if ( ! in_array( $taxonomy, [ Venue::TAXONOMY, EventType::TAXONOMY ], true ) ) {
			return;
		}

		if ( EventPostType::POST_TYPE === get_post_type( (int) $object_id ) ) {
			$this->mark_dirty( (int) $object_id );
		}
	}

	/**
	 * Rebuild the index for one event, honouring the generation_strategy setting.
	 *
	 * 'on_save' (the default) rebuilds in the current request, so the change is
	 * live the moment the editor saves. 'cron' hands the work to WP-Cron
	 * instead, which keeps the save fast on a site where an event expands into
	 * thousands of occurrences — at the cost of the index trailing the post by
	 * up to a cron cycle.
	 *
	 * @param int $post_id Event post ID.
	 */
	private function schedule_or_build( int $post_id ): void {
		if ( 'cron' !== SettingsPage::get( 'generation_strategy' ) ) {
			$this->build_for_post( $post_id );
			return;
		}

		// The queued build covers whatever marked this event dirty.
		unset( self::$dirty[ $post_id ] );

		// Collapse repeated saves of the same event into one pending job.
		if ( ! wp_next_scheduled( self::DEFERRED_HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::DEFERRED_HOOK, [ $post_id ] );
		}
	}

	/**
	 * Note that an event's rows are out of date, to be rebuilt by flush_dirty().
	 *
	 * @param int $post_id Event post ID.
	 */
	public function mark_dirty( int $post_id ): void {
		self::$dirty[ $post_id ] = true;
	}

	/**
	 * Rebuild every event marked dirty, each one once.
	 *
	 * Runs when the request ends. Code that needs the index current sooner —
	 * a CLI command that reads it back, a test — calls this itself.
	 */
	public function flush_dirty(): void {
		// build_for_post() takes each one off the list, so a second flush, or
		// the one at shutdown after an early one, finds nothing to do.
		foreach ( array_keys( self::$dirty ) as $post_id ) {
			unset( self::$dirty[ $post_id ] );

			// Only published events have rows. One that is not published lost
			// them when it stopped being, and one that is gone has none.
			if ( EventPostType::POST_TYPE !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
				continue;
			}

			$this->schedule_or_build( $post_id );
		}
	}

	/**
	 * IDs of the events waiting for flush_dirty().
	 *
	 * @return int[]
	 */
	public static function dirty(): array {
		return array_keys( self::$dirty );
	}

	/**
	 * Drop the list without rebuilding anything.
	 */
	public static function forget_dirty(): void {
		self::$dirty = [];
	}

	/**
	 * Rebuild index rows for a single event post on save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function on_save( int $post_id, \WP_Post $post ): void {
		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only published posts are indexed; anything else loses its rows.
		if ( 'publish' !== $post->post_status ) {
			$this->index->delete_by_post_id( $post_id );
			return;
		}

		/*
		 * The REST API saves the post and then its meta, terms and fields, so
		 * a build here reads what the event was, and rest_after_insert builds
		 * it again from what it is. One build is enough. The mark is the
		 * fallback: if rest_after_insert is never reached, the event is still
		 * rebuilt when the request ends.
		 */
		if ( $this->is_rest_save() ) {
			$this->mark_dirty( $post_id );
			return;
		}

		$this->schedule_or_build( $post_id );
	}

	/**
	 * Whether the save in hand is being made through the REST API.
	 *
	 * The server's own state is asked as well as the request's, so a request
	 * dispatched internally from an ordinary page load counts.
	 */
	private function is_rest_save(): bool {
		global $wp_rest_server;

		if ( $wp_rest_server instanceof \WP_REST_Server && $wp_rest_server->is_dispatching() ) {
			return true;
		}

		return wp_is_serving_rest_request();
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

		// Replaces any rows written by the earlier save_post hook, which ran
		// before the REST request's meta was persisted.
		$this->schedule_or_build( $post->ID );
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
	 * Remove an event's repeat rule when the event is deleted for good.
	 *
	 * Not on trash: an event restored from the trash has to come back as the
	 * series it was. The rule is a row in the plugin's own table and nothing
	 * else removes it, so without this it outlives its event.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_permanent_delete( int $post_id ): void {
		if ( EventPostType::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		( new RuleRepository() )->delete( $post_id );
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

		// Core restores an untrashed post to 'draft', not to its previous
		// status, so indexing unconditionally here would put unpublished rows
		// into a table every public read path treats as published-only.
		if ( $post && 'publish' === $post->post_status ) {
			$this->build_for_post( $post_id );
		}
	}

	/**
	 * Replace the index rows for a single post.
	 *
	 * Idempotent: the post's existing rows are cleared before anything is
	 * written, so calling this any number of times leaves the same rows.
	 * Import scripts and REST handlers can call it directly without deleting
	 * first.
	 *
	 * For non-recurring events this produces one row. For recurring events the
	 * recurrence engine owns materialisation — Recurrence\Generator replaces the
	 * post's rows itself (it is also the cron entry point), so rows are cleared
	 * exactly once on either path.
	 *
	 * @param int $post_id Post ID.
	 */
	public function build_for_post( int $post_id ): void {
		// Whatever marked the event dirty is covered by this build.
		unset( self::$dirty[ $post_id ] );

		// Every public read path joins the index against wp_posts on
		// post_status = 'publish', so an unpublished post must never hold rows
		// here. Guarding at this level rather than per-caller means a new call
		// site cannot reintroduce the leak by forgetting the check.
		if ( 'publish' !== get_post_status( $post_id ) ) {
			$this->index->delete_by_post_id( $post_id );
			return;
		}

		$meta    = $this->get_event_meta( $post_id );
		$ongoing = ! empty( $meta['ongoing'] );

		// Ongoing events are never recurring — any stored rule is ignored so the
		// single sentinel row below is what gets indexed.
		if ( ! $ongoing && $this->has_recurrence( $post_id ) ) {
			do_action( 'blockendar_generate_recurrence_index', $post_id );
			return;
		}

		// Ongoing events have no end date; everything else needs one.
		$dated = ! empty( $meta['start_date'] ) && ( $ongoing || ! empty( $meta['end_date'] ) );
		$row   = $dated ? $this->build_row( $post_id, $meta ) : null;

		// Cleared and written as one step: if the new row cannot be written
		// the event keeps the one it had.
		$this->index->replace_for_post( $post_id, null === $row ? [] : [ $row ] );
	}

	/**
	 * Rebuild the entire index for all published events, start to finish.
	 *
	 * Used by WP-CLI, which has no request limit to stay inside. Anything
	 * running in a request takes one pass at a time with rebuild_step().
	 *
	 * @param callable|null $on_post Called after each event, for a progress bar.
	 * @return array{ rebuilt: int, skipped: int } Result summary.
	 */
	public function rebuild_all( ?callable $on_post = null ): array {
		// From the beginning, so the totals cover every event.
		delete_option( self::CURSOR_OPTION );

		do {
			$result = $this->rebuild_step( self::REBUILD_BUDGET, $on_post, 10 );
		} while ( ! $result['done'] );

		return [
			'rebuilt' => $result['rebuilt'],
			'skipped' => $result['skipped'],
		];
	}

	/**
	 * Run one pass of a full rebuild and stop when the time is up.
	 *
	 * The index is never emptied. Each published event has its rows replaced in
	 * turn, in ID order, so an event the rebuild has not reached yet is still
	 * served from the rows it had, and a rebuild that dies partway leaves a
	 * complete index behind. Where it got to is stored, and the next pass
	 * carries on from there.
	 *
	 * Every event goes through build_for_post(), including one with no start
	 * date: that is the call that clears the rows it may have had.
	 *
	 * @param float         $budget    Seconds to work for. At least one event is
	 *                                 always done, so a pass cannot stall.
	 * @param callable|null $on_post   Called after each event.
	 * @param int           $lock_wait Seconds to wait for another pass to finish.
	 * @return array{ rebuilt: int, skipped: int, done: bool } Totals so far for
	 *         the whole rebuild, and whether it has finished.
	 */
	public function rebuild_step( float $budget = self::REBUILD_BUDGET, ?callable $on_post = null, int $lock_wait = 0 ): array {
		global $wpdb;

		// Two passes at once would each clear and refill the same event, and
		// could leave it with its rows twice.
		if ( ! $this->acquire_rebuild_lock( $lock_wait ) ) {
			$state = $this->rebuild_state();

			return [
				'rebuilt' => $state['rebuilt'],
				'skipped' => $state['skipped'],
				'done'    => false,
			];
		}

		$deadline    = microtime( true ) + $budget;
		$state       = $this->rebuild_state();
		$out_of_time = false;

		do {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_type = %s AND post_status = 'publish' AND ID > %d
					ORDER BY ID ASC
					LIMIT %d",
					EventPostType::POST_TYPE,
					$state['last_id'],
					self::REBUILD_BATCH
				)
			);

			foreach ( $post_ids as $post_id ) {
				$post_id = (int) $post_id;

				$this->build_for_post( $post_id );

				if ( $this->index->has_rows( $post_id ) ) {
					++$state['rebuilt'];
				} else {
					++$state['skipped'];
				}

				$state['last_id'] = $post_id;

				if ( null !== $on_post ) {
					$on_post( $post_id );
				}

				if ( microtime( true ) >= $deadline ) {
					$out_of_time = true;
					break;
				}
			}

			// Stored after every batch, not only at the end, so a pass that is
			// killed loses one batch of progress at most.
			update_option( self::CURSOR_OPTION, $state, false );

			$more = count( $post_ids ) === self::REBUILD_BATCH;
		} while ( ! $out_of_time && $more );

		if ( ! $out_of_time ) {
			$this->finish_rebuild();
		}

		$this->release_rebuild_lock();

		return [
			'rebuilt' => $state['rebuilt'],
			'skipped' => $state['skipped'],
			'done'    => ! $out_of_time,
		];
	}

	/**
	 * Cron entry point: run one pass and queue another if there is more to do.
	 *
	 * @param float $budget Seconds to work for.
	 */
	public function run_scheduled_rebuild( float $budget = self::REBUILD_BUDGET ): void {
		$result = $this->rebuild_step( $budget );

		if ( ! $result['done'] ) {
			$this->queue_rebuild();
		}
	}

	/**
	 * Queue a background pass, unless one is queued already.
	 *
	 * @param int $delay Seconds from now.
	 */
	public function queue_rebuild( int $delay = 0 ): void {
		if ( ! wp_next_scheduled( self::REBUILD_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::REBUILD_HOOK );
		}
	}

	/**
	 * Queue a full rebuild from the beginning.
	 *
	 * For an upgrade that changes what a row holds: a rebuild already partway
	 * through wrote its first rows the old way, so its place is thrown away.
	 */
	public function queue_full_rebuild(): void {
		delete_option( self::CURSOR_OPTION );
		$this->queue_rebuild();
	}

	/**
	 * Whether a full rebuild has started and not finished.
	 */
	public function is_rebuilding(): bool {
		return false !== get_option( self::CURSOR_OPTION, false );
	}

	/**
	 * Whether a full rebuild is under way or waiting for its first run.
	 */
	public function is_rebuild_pending(): bool {
		return $this->is_rebuilding() || false !== wp_next_scheduled( self::REBUILD_HOOK );
	}

	/**
	 * Where the rebuild in progress has got to, or the start of a new one.
	 *
	 * @return array{ last_id: int, rebuilt: int, skipped: int }
	 */
	private function rebuild_state(): array {
		$stored = get_option( self::CURSOR_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'last_id' => (int) ( $stored['last_id'] ?? 0 ),
			'rebuilt' => (int) ( $stored['rebuilt'] ?? 0 ),
			'skipped' => (int) ( $stored['skipped'] ?? 0 ),
		];
	}

	/**
	 * Close a rebuild that has been through every published event.
	 *
	 * What is left to remove is what no event's own rebuild would: rows for a
	 * post that is gone or no longer published. Emptying the table used to take
	 * them with everything else.
	 */
	private function finish_rebuild(): void {
		global $wpdb;

		$events_table     = Schema::events_table();
		$type_terms_table = Schema::type_terms_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE e FROM {$events_table} e
				LEFT JOIN {$wpdb->posts} p
					ON p.ID = e.post_id AND p.post_type = %s AND p.post_status = 'publish'
				WHERE p.ID IS NULL",
				EventPostType::POST_TYPE
			)
		);

		$wpdb->query(
			"DELETE t FROM {$type_terms_table} t
			LEFT JOIN {$events_table} e ON e.id = t.event_index_id
			WHERE e.id IS NULL"
		);
		// phpcs:enable

		// Both deletes bypass EventIndex, so invalidate the read cache here.
		$this->index->flush_cache();

		update_option( 'blockendar_last_index_rebuild', gmdate( 'Y-m-d H:i:s' ) );
		delete_option( self::CURSOR_OPTION );

		// A pass still queued would find no cursor and start all over again.
		wp_clear_scheduled_hook( self::REBUILD_HOOK );
	}

	/**
	 * Take the database lock that keeps rebuild passes from overlapping.
	 *
	 * A named lock belongs to the connection, so it is released by the server
	 * if the request dies; nothing is left to expire or clean up.
	 *
	 * @param int $wait Seconds to wait for it.
	 */
	private function acquire_rebuild_lock( int $wait ): bool {
		global $wpdb;

		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $this->rebuild_lock_name(), $wait ) );

		return '1' === (string) $got;
	}

	/**
	 * Release the lock taken by acquire_rebuild_lock().
	 */
	private function release_rebuild_lock(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $this->rebuild_lock_name() ) );
	}

	/**
	 * Lock name, per site: locks are shared by the whole database server.
	 */
	private function rebuild_lock_name(): string {
		global $wpdb;

		return substr( 'blockendar_rebuild_' . $wpdb->prefix, 0, 64 );
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
		$ongoing    = ! empty( $meta['ongoing'] );
		$start_time = $all_day ? '00:00' : ( $meta['start_time'] ?: '00:00' );
		$end_time   = $all_day ? '00:00' : ( $meta['end_time'] ?: $start_time );

		$start_local_str = "{$meta['start_date']} {$start_time}:00";

		try {
			$start_dt = new \DateTimeImmutable( $start_local_str, $tz );
		} catch ( \Exception ) {
			return null;
		}

		if ( $ongoing ) {
			// No end date: index a far-future sentinel so overlap queries keep
			// matching, and flag the row so consumers never display the sentinel.
			$end_datetime = EventIndex::ONGOING_END;
			$end_date     = EventIndex::ONGOING_END_DATE;
		} else {
			if ( $all_day ) {
				$end_local_str = blockendar_next_day( $meta['end_date'] ) . ' 00:00:00';
			} else {
				$end_local_str = "{$meta['end_date']} {$end_time}:00";
			}

			try {
				$end_dt = new \DateTimeImmutable( $end_local_str, $tz );
			} catch ( \Exception ) {
				return null;
			}

			$end_date = $meta['end_date'];

			// Nothing stops an end being entered before the start, and every
			// range query assumes a row ends no earlier than it begins. Such an
			// event is indexed at its shortest: over when it starts or, for an
			// all-day event, the one day.
			if ( $end_dt < $start_dt ) {
				$end_date = $meta['start_date'];
				$end_dt   = $all_day
					? new \DateTimeImmutable( blockendar_next_day( $end_date ) . ' 00:00:00', $tz )
					: $start_dt;
			}

			$end_datetime = $end_dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		}

		return [
			'post_id'            => $post_id,
			'start_datetime'     => $start_dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end_datetime'       => $end_datetime,
			'start_date'         => $meta['start_date'],
			'end_date'           => $end_date,
			'all_day'            => $all_day ? 1 : 0,
			'recurrence_id'      => null,
			'status'             => $meta['status'] ?? 'scheduled',
			'venue_term_id'      => $this->get_venue_term_id( $post_id ),
			'type_term_ids'      => $this->get_type_term_ids( $post_id ),
			'featured'           => ! empty( $meta['featured'] ) ? 1 : 0,
			'hide_from_listings' => ! empty( $meta['hide_from_listings'] ) ? 1 : 0,
			'ongoing'            => $ongoing ? 1 : 0,
		];
	}

	/**
	 * Read all relevant post meta for an event.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_event_meta( int $post_id ): array {
		// The keys read here are INDEX_META_KEYS; a key added to one belongs in
		// the other, or changes to it will not reach the index.
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
			'ongoing'            => (bool) get_post_meta( $post_id, 'blockendar_ongoing', true ),
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
