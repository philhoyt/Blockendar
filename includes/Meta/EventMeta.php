<?php
/**
 * Event post meta registration.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\CPT\EventPostType;

/**
 * Registers all event-specific post meta fields via register_post_meta().
 * These fields are the authoritative source — the blockendar_events index is derived from them.
 */
class EventMeta {

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'rest_after_insert_' . EventPostType::POST_TYPE, [ $this, 'clear_stale_reason' ] );
	}

	/**
	 * Drop the status reason from an event saved as scheduled.
	 *
	 * The editor hides the field once the event is back on, and the text would
	 * stay in the post's meta, which WordPress's own REST route gives to
	 * anyone who can read the event. A reason for a postponement that is over
	 * is nobody's business.
	 *
	 * @param \WP_Post $post The event that was saved.
	 */
	public function clear_stale_reason( \WP_Post $post ): void {
		$status = (string) get_post_meta( $post->ID, 'blockendar_status', true );

		if ( '' === $status || 'scheduled' === $status ) {
			delete_post_meta( $post->ID, 'blockendar_status_reason' );
		}
	}

	/**
	 * The maximum length of a status value.
	 *
	 * The index stores the status in a varchar(20) column. A longer value
	 * would be truncated there and never match what the post meta says.
	 */
	public const STATUS_MAX_LENGTH = 20;

	/**
	 * The statuses an event can have, as stored value => label.
	 *
	 * Every place that lists, validates or labels a status reads this: the
	 * meta schema, the REST collection argument, the editor's dropdown and the
	 * status block. A status a site adds through the filter is therefore
	 * accepted everywhere at once.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		$defaults = [
			'scheduled' => __( 'Scheduled', 'blockendar' ),
			'cancelled' => __( 'Cancelled', 'blockendar' ),
			'postponed' => __( 'Postponed', 'blockendar' ),
			'sold_out'  => __( 'Sold Out', 'blockendar' ),
		];

		/**
		 * Filters the statuses an event can have.
		 *
		 * Keys are the values stored in post meta and in the index; labels are
		 * what the editor and the status block show. A key is passed through
		 * sanitize_key() and dropped when that leaves it empty or longer than
		 * 20 characters, the width of the index column. `scheduled` cannot be
		 * removed: it is the default, and what an unknown value falls back to.
		 *
		 * The iCalendar feed exports an added status as CONFIRMED and the
		 * schema.org markup as EventScheduled; the status block puts the key in
		 * a class, `blockendar-status--{key}`, for a theme to style.
		 *
		 * @since 2.4.0
		 *
		 * @param array<string, string> $statuses Status value => label.
		 */
		$filtered = apply_filters( 'blockendar_event_statuses', $defaults );
		$statuses = [];

		foreach ( (array) $filtered as $key => $label ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || strlen( $key ) > self::STATUS_MAX_LENGTH || ! is_scalar( $label ) ) {
				continue;
			}

			$statuses[ $key ] = (string) $label;
		}

		if ( ! isset( $statuses['scheduled'] ) ) {
			$statuses = [ 'scheduled' => $defaults['scheduled'] ] + $statuses;
		}

		return $statuses;
	}

	/**
	 * Register all post meta fields.
	 */
	public function register_meta(): void {
		$post_type = EventPostType::POST_TYPE;

		// Date fields.
		register_post_meta(
			$post_type,
			'blockendar_start_date',
			[
				'type'              => 'string',
				'description'       => 'Event start date (Y-m-d).',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ $this, 'sanitize_date' ],
				'show_in_rest'      => true,
			]
		);

		register_post_meta(
			$post_type,
			'blockendar_end_date',
			[
				'type'              => 'string',
				'description'       => 'Event end date (Y-m-d). Same as start for single-day events.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ $this, 'sanitize_date' ],
				'show_in_rest'      => true,
			]
		);

		// Time fields.
		register_post_meta(
			$post_type,
			'blockendar_start_time',
			[
				'type'              => 'string',
				'description'       => 'Event start time (H:i). Null if all-day.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ $this, 'sanitize_time' ],
				'show_in_rest'      => true,
			]
		);

		register_post_meta(
			$post_type,
			'blockendar_end_time',
			[
				'type'              => 'string',
				'description'       => 'Event end time (H:i). Null if all-day.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ $this, 'sanitize_time' ],
				'show_in_rest'      => true,
			]
		);

		// All-day flag.
		register_post_meta(
			$post_type,
			'blockendar_all_day',
			[
				'type'              => 'boolean',
				'description'       => 'Whether the event runs all day. Overrides time fields.',
				'single'            => true,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			]
		);

		// Timezone.
		register_post_meta(
			$post_type,
			'blockendar_timezone',
			[
				'type'              => 'string',
				'description'       => 'IANA timezone identifier (e.g. America/Chicago).',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ $this, 'sanitize_timezone' ],
				'show_in_rest'      => true,
			]
		);

		// Status. The list is read here, at registration, so a status added
		// through the filter has to be added before init.
		$statuses = array_keys( self::statuses() );

		register_post_meta(
			$post_type,
			'blockendar_status',
			[
				'type'              => 'string',
				'description'       => 'Event status: ' . implode( ' | ', $statuses ) . '.',
				'single'            => true,
				'default'           => 'scheduled',
				'sanitize_callback' => [ $this, 'sanitize_status' ],
				'show_in_rest'      => [
					'schema' => [
						'type' => 'string',
						'enum' => $statuses,
					],
				],
			]
		);

		// Why the event has the status it has. Plain text, shown with the
		// status while the event is not simply going ahead.
		register_post_meta(
			$post_type,
			'blockendar_status_reason',
			[
				'type'              => 'string',
				'description'       => 'Why the event is cancelled, postponed or sold out.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ $this, 'sanitize_reason' ],
				'show_in_rest'      => true,
			]
		);

		// Cost fields.
		register_post_meta(
			$post_type,
			'blockendar_cost',
			[
				'type'              => 'string',
				'description'       => 'Display cost string (e.g. "$10–$25" or "Free").',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
			]
		);

		// Registration URL.
		register_post_meta(
			$post_type,
			'blockendar_registration_url',
			[
				'type'              => 'string',
				'description'       => 'External ticket/RSVP link.',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => [
					'schema' => [
						'type'   => 'string',
						'format' => 'uri',
					],
				],
			]
		);

		// Recurrence preset (mirrors the editor dropdown; used to mark the post
		// dirty when recurrence changes so the Update button activates).
		register_post_meta(
			$post_type,
			'blockendar_recurrence_preset',
			[
				'type'              => 'string',
				'description'       => 'Recurrence preset key selected in the editor (none|daily|weekly_day|monthly_weekday|yearly_date).',
				'single'            => true,
				'default'           => 'none',
				'sanitize_callback' => [ $this, 'sanitize_recurrence_preset' ],
				'show_in_rest'      => true,
			]
		);

		// Flags.
		register_post_meta(
			$post_type,
			'blockendar_featured',
			[
				'type'              => 'boolean',
				'description'       => 'Whether this event is featured/promoted.',
				'single'            => true,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			]
		);

		register_post_meta(
			$post_type,
			'blockendar_hide_from_listings',
			[
				'type'              => 'boolean',
				'description'       => 'Exclude from calendar/list displays without trashing.',
				'single'            => true,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			]
		);

		register_post_meta(
			$post_type,
			'blockendar_ongoing',
			[
				'type'              => 'boolean',
				'description'       => 'Event has no end date and stays in listings until one is set.',
				'single'            => true,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			]
		);
	}

	/**
	 * Sanitize a recurrence preset key.
	 */
	public function sanitize_recurrence_preset( mixed $value ): string {
		$allowed = [ 'none', 'daily', 'weekly_day', 'monthly_weekday', 'yearly_date' ];
		$value   = sanitize_text_field( (string) $value );

		return in_array( $value, $allowed, true ) ? $value : 'none';
	}

	/**
	 * Sanitize a date string to Y-m-d or empty string.
	 */
	public function sanitize_date( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value );

		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * Sanitize a time string to H:i or empty string.
	 */
	public function sanitize_time( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$time = \DateTimeImmutable::createFromFormat( 'H:i', $value );

		return ( $time && $time->format( 'H:i' ) === $value ) ? $value : '';
	}

	/**
	 * Sanitize an IANA timezone identifier.
	 */
	public function sanitize_timezone( mixed $value ): string {
		// "UTC+5.5" is how WordPress and other plugins write a manual offset;
		// DateTimeZone only takes it as "+05:30".
		$value = blockendar_normalize_timezone( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		try {
			new \DateTimeZone( $value );
			return $value;
		} catch ( \Exception ) {
			return '';
		}
	}

	/**
	 * Sanitize a status reason: one line of plain text.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_reason( mixed $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Sanitize event status to an allowed value.
	 */
	public function sanitize_status( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );

		return array_key_exists( $value, self::statuses() ) ? $value : 'scheduled';
	}
}
