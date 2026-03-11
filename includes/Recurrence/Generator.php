<?php
/**
 * Materialises recurrence rule instances into the blockendar_events index.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Recurrence;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;

/**
 * Generates occurrence rows for a recurring event and writes them to the index.
 *
 * Design contract:
 * - The Generator owns all index rows for recurring events (recurrence_id IS NOT NULL).
 * - IndexBuilder defers to Generator via the blockendar_generate_recurrence_index action.
 * - Instances are generated up to a configurable horizon (default: 365 days from today).
 */
class Generator {

	/** Default lookahead horizon in days. */
	const DEFAULT_HORIZON_DAYS = 365;

	/** Absolute safety cap — never generate more instances than this per event. */
	const MAX_INSTANCES = 3650;

	private EventIndex $index;

	public function __construct() {
		$this->index = new EventIndex();
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'blockendar_generate_recurrence_index', [ $this, 'generate_for_post' ] );
	}

	/**
	 * Generate (or regenerate) all index rows for a recurring event.
	 * Clears existing rows first, then materialises up to the horizon.
	 *
	 * @param int $post_id Event post ID.
	 */
	public function generate_for_post( int $post_id ): void {
		$rule = $this->get_rule( $post_id );

		if ( null === $rule ) {
			return;
		}

		// Clear existing recurrence rows for this post.
		$this->index->delete_by_post_id( $post_id );

		$meta      = $this->get_event_meta( $post_id );
		$dates     = $this->expand_dates( $rule, $meta );
		$shared    = $this->get_shared_row_data( $post_id, $rule->id, $meta );

		foreach ( $dates as $date_pair ) {
			$row = array_merge( $shared, [
				'start_datetime' => $date_pair['start_utc'],
				'end_datetime'   => $date_pair['end_utc'],
				'start_date'     => $date_pair['start_date'],
				'end_date'       => $date_pair['end_date'],
			] );

			$this->index->insert( $row );
		}

		// Insert manually added extra dates.
		foreach ( $rule->additions as $extra_date ) {
			$date_pair = $this->build_date_pair( $extra_date, $extra_date, $meta );

			if ( null === $date_pair ) {
				continue;
			}

			$row = array_merge( $shared, [
				'start_datetime' => $date_pair['start_utc'],
				'end_datetime'   => $date_pair['end_utc'],
				'start_date'     => $date_pair['start_date'],
				'end_date'       => $date_pair['end_date'],
			] );

			$this->index->insert( $row );
		}
	}

	/**
	 * Roll the recurrence horizon forward for all recurring events.
	 * Called daily by the cron job — generates new instances that have entered the window.
	 */
	public function roll_horizon(): void {
		global $wpdb;

		$recurrence_table = Schema::recurrence_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col( "SELECT post_id FROM {$recurrence_table}" );
		// phpcs:enable

		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );

			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$this->generate_for_post( (int) $post_id );
		}
	}

	// -------------------------------------------------------------------------
	// Date expansion
	// -------------------------------------------------------------------------

	/**
	 * Expand a recurrence rule into an array of start/end date pairs.
	 *
	 * @param Rule  $rule Recurrence rule.
	 * @param array $meta Event meta (start_date, end_date, duration etc.).
	 * @return array[] Each element: ['start_date', 'end_date', 'start_utc', 'end_utc'].
	 */
	private function expand_dates( Rule $rule, array $meta ): array {
		$horizon_days = (int) get_option( 'blockendar_horizon_days', self::DEFAULT_HORIZON_DAYS );
		$horizon      = new \DateTimeImmutable( 'today', new \DateTimeZone( 'UTC' ) );
		$horizon      = $horizon->modify( "+{$horizon_days} days" )->setTime( 23, 59, 59 );

		$event_start = \DateTimeImmutable::createFromFormat( 'Y-m-d', $meta['start_date'] );
		$event_end   = \DateTimeImmutable::createFromFormat( 'Y-m-d', $meta['end_date'] );

		if ( ! $event_start || ! $event_end ) {
			return [];
		}

		// Duration of the event in days (for multi-day events).
		$duration_days = (int) $event_start->diff( $event_end )->days;

		$occurrences = [];
		$count       = 0;
		$cursor      = $event_start;

		while ( $count < self::MAX_INSTANCES ) {
			$date_str = $cursor->format( 'Y-m-d' );

			// Stop if past the until_date.
			if ( null !== $rule->until_date && $cursor > $rule->until_date ) {
				break;
			}

			// Stop if past the generation horizon.
			if ( $cursor > $horizon ) {
				break;
			}

			// Stop if count limit reached.
			if ( null !== $rule->count && $count >= $rule->count ) {
				break;
			}

			// Skip exceptions.
			if ( ! $rule->is_exception( $date_str ) ) {
				$end_date  = $cursor->modify( "+{$duration_days} days" );
				$date_pair = $this->build_date_pair( $date_str, $end_date->format( 'Y-m-d' ), $meta );

				if ( null !== $date_pair ) {
					$occurrences[] = $date_pair;
					$count++;
				}
			}

			$next = $this->advance( $cursor, $rule );

			if ( null === $next || $next <= $cursor ) {
				break;
			}

			$cursor = $next;
		}

		return $occurrences;
	}

	/**
	 * Advance the cursor to the next occurrence date.
	 *
	 * @param \DateTimeImmutable $cursor Current date.
	 * @param Rule               $rule   Recurrence rule.
	 * @return \DateTimeImmutable|null Next occurrence, or null if exhausted.
	 */
	private function advance( \DateTimeImmutable $cursor, Rule $rule ): ?\DateTimeImmutable {
		$interval = $rule->interval;

		switch ( $rule->frequency ) {
			case Rule::FREQ_DAILY:
				return $cursor->modify( "+{$interval} days" );

			case Rule::FREQ_WEEKLY:
				return $this->advance_weekly( $cursor, $rule );

			case Rule::FREQ_MONTHLY:
				return $this->advance_monthly( $cursor, $rule );

			case Rule::FREQ_YEARLY:
				return $cursor->modify( "+{$interval} years" );

			default:
				return null;
		}
	}

	/**
	 * Weekly advancement: respects BYDAY day-of-week selections.
	 */
	private function advance_weekly( \DateTimeImmutable $cursor, Rule $rule ): ?\DateTimeImmutable {
		if ( empty( $rule->byday ) ) {
			return $cursor->modify( "+{$rule->interval} weeks" );
		}

		// Map RFC 5545 codes to PHP day numbers (0=Sun…6=Sat).
		$day_map = [ 'SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6 ];

		$selected_days = array_map( fn( $d ) => $day_map[ $d ] ?? -1, $rule->byday );
		$selected_days = array_filter( $selected_days, fn( $d ) => $d >= 0 );
		sort( $selected_days );

		$current_dow = (int) $cursor->format( 'w' ); // 0=Sun

		// Find the next selected day after today within the same week.
		foreach ( $selected_days as $day ) {
			if ( $day > $current_dow ) {
				$diff = $day - $current_dow;
				return $cursor->modify( "+{$diff} days" );
			}
		}

		// No more days this week — jump to first selected day next interval-weeks.
		$first_day = reset( $selected_days );
		$days_to_monday = ( 7 - $current_dow + 1 ) % 7 ?: 7; // days to next Monday
		$days_to_week_start = $days_to_monday + ( ( $rule->interval - 1 ) * 7 );
		$days_to_first = $days_to_week_start + ( $first_day - 1 ); // Mon=1, so offset

		// Simpler: jump to start of next occurrence week then find first matching day.
		$next_week_start = $cursor->modify( 'Monday next week' );

		if ( $rule->interval > 1 ) {
			$extra_weeks = $rule->interval - 1;
			$next_week_start = $next_week_start->modify( "+{$extra_weeks} weeks" );
		}

		foreach ( $selected_days as $day ) {
			$diff = ( $day - 1 + 7 ) % 7; // Mon=0 in this week
			// $day is 0=Sun through 6=Sat; next_week_start is Monday.
			// Calculate day offset from Monday.
			$offset = ( $day === 0 ) ? 6 : $day - 1;
			return $next_week_start->modify( "+{$offset} days" );
		}

		return null;
	}

	/**
	 * Monthly advancement: supports bymonthday and BYDAY+BYSETPOS (Nth weekday).
	 */
	private function advance_monthly( \DateTimeImmutable $cursor, Rule $rule ): ?\DateTimeImmutable {
		$interval = $rule->interval;

		if ( ! empty( $rule->bysetpos ) && ! empty( $rule->byday ) ) {
			// Nth weekday pattern (e.g. second Tuesday = BYDAY=TU;BYSETPOS=2).
			return $this->nth_weekday_next_month( $cursor, $rule, $interval );
		}

		if ( ! empty( $rule->bymonthday ) ) {
			// Specific day(s) of month — find next after cursor.
			$day_of_month = (int) $cursor->format( 'j' );

			foreach ( $rule->bymonthday as $target_day ) {
				if ( $target_day > $day_of_month ) {
					try {
						return new \DateTimeImmutable(
							$cursor->format( 'Y-m-' ) . sprintf( '%02d', $target_day )
						);
					} catch ( \Exception ) {
						continue;
					}
				}
			}

			// No more target days this month — advance to next interval month.
			$next_month = $cursor->modify( "+{$interval} months" );
			$first_day  = reset( $rule->bymonthday );

			try {
				return new \DateTimeImmutable(
					$next_month->format( 'Y-m-' ) . sprintf( '%02d', $first_day )
				);
			} catch ( \Exception ) {
				return null;
			}
		}

		// Default: same day next N months.
		return $cursor->modify( "+{$interval} months" );
	}

	/**
	 * Compute the Nth weekday of a given month.
	 * E.g. "second Tuesday" = BYDAY=TU, BYSETPOS=2.
	 */
	private function nth_weekday_next_month(
		\DateTimeImmutable $cursor,
		Rule $rule,
		int $interval
	): ?\DateTimeImmutable {
		$day_map     = [ 'SU' => 'Sunday', 'MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday',
		                 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday' ];
		$weekday_str = $day_map[ $rule->byday[0] ] ?? null;
		$setpos      = $rule->bysetpos[0] ?? 1;

		if ( null === $weekday_str ) {
			return null;
		}

		$next_month = $cursor->modify( "+{$interval} months" )->modify( 'first day of this month' );

		if ( $setpos > 0 ) {
			$ordinals = [ 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth' ];
			$ordinal  = $ordinals[ $setpos ] ?? 'first';
			try {
				return new \DateTimeImmutable( "{$ordinal} {$weekday_str} of " . $next_month->format( 'F Y' ) );
			} catch ( \Exception ) {
				return null;
			}
		}

		// Negative setpos — e.g. last Tuesday.
		if ( $setpos === -1 ) {
			try {
				return new \DateTimeImmutable( "last {$weekday_str} of " . $next_month->format( 'F Y' ) );
			} catch ( \Exception ) {
				return null;
			}
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a UTC date pair array from local date strings and event meta.
	 *
	 * @param string $start_date Local start date (Y-m-d).
	 * @param string $end_date   Local end date (Y-m-d).
	 * @param array  $meta       Event meta.
	 * @return array|null
	 */
	private function build_date_pair( string $start_date, string $end_date, array $meta ): ?array {
		$timezone_str = ! empty( $meta['timezone'] ) ? $meta['timezone'] : wp_timezone_string();

		try {
			$tz = new \DateTimeZone( $timezone_str );
		} catch ( \Exception ) {
			$tz = wp_timezone();
		}

		$utc     = new \DateTimeZone( 'UTC' );
		$all_day = ! empty( $meta['all_day'] );

		$start_time = $all_day ? '00:00' : ( $meta['start_time'] ?: '00:00' );
		$end_time   = $all_day ? '23:59' : ( $meta['end_time'] ?: $start_time );

		try {
			$start_dt = new \DateTimeImmutable( "{$start_date} {$start_time}:00", $tz );
			$end_dt   = new \DateTimeImmutable( "{$end_date} {$end_time}:00", $tz );
		} catch ( \Exception ) {
			return null;
		}

		return [
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'start_utc'  => $start_dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end_utc'    => $end_dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Data shared by all instances of a recurring event.
	 *
	 * @param int   $post_id      Post ID.
	 * @param int   $recurrence_id Recurrence rule DB ID.
	 * @param array $meta         Event meta.
	 * @return array
	 */
	private function get_shared_row_data( int $post_id, int $recurrence_id, array $meta ): array {
		return [
			'post_id'       => $post_id,
			'recurrence_id' => $recurrence_id,
			'all_day'       => ! empty( $meta['all_day'] ) ? 1 : 0,
			'status'        => $meta['status'] ?? 'scheduled',
			'venue_term_id' => $this->get_venue_term_id( $post_id ),
			'type_term_ids' => $this->get_type_term_ids( $post_id ),
		];
	}

	/**
	 * Fetch a recurrence Rule for a post. Returns null if none exists.
	 *
	 * @param int $post_id Post ID.
	 */
	private function get_rule( int $post_id ): ?Rule {
		global $wpdb;

		$recurrence_table = Schema::recurrence_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$recurrence_table} WHERE post_id = %d",
				$post_id
			)
		);
		// phpcs:enable

		if ( null === $row ) {
			return null;
		}

		return Rule::from_db_row( $row );
	}

	/**
	 * Read event meta needed for instance generation.
	 *
	 * @param int $post_id Post ID.
	 */
	private function get_event_meta( int $post_id ): array {
		return [
			'start_date' => get_post_meta( $post_id, 'blockendar_start_date', true ),
			'end_date'   => get_post_meta( $post_id, 'blockendar_end_date', true ),
			'start_time' => get_post_meta( $post_id, 'blockendar_start_time', true ),
			'end_time'   => get_post_meta( $post_id, 'blockendar_end_time', true ),
			'all_day'    => get_post_meta( $post_id, 'blockendar_all_day', true ),
			'timezone'   => get_post_meta( $post_id, 'blockendar_timezone', true ),
			'status'     => get_post_meta( $post_id, 'blockendar_status', true ) ?: 'scheduled',
		];
	}

	/**
	 * Get the venue term ID for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function get_venue_term_id( int $post_id ): ?int {
		$terms = get_the_terms( $post_id, Venue::TAXONOMY );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return (int) $terms[0]->term_id;
	}

	/**
	 * Get event type term IDs for a post.
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
