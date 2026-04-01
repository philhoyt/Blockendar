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

		// Status.
		register_post_meta(
			$post_type,
			'blockendar_status',
			[
				'type'              => 'string',
				'description'       => 'Event status: scheduled | cancelled | postponed | sold_out.',
				'single'            => true,
				'default'           => 'scheduled',
				'sanitize_callback' => [ $this, 'sanitize_status' ],
				'show_in_rest'      => [
					'schema' => [
						'type' => 'string',
						'enum' => [ 'scheduled', 'cancelled', 'postponed', 'sold_out' ],
					],
				],
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
		$value = sanitize_text_field( (string) $value );

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
	 * Sanitize event status to an allowed value.
	 */
	public function sanitize_status( mixed $value ): string {
		$allowed = [ 'scheduled', 'cancelled', 'postponed', 'sold_out' ];
		$value   = sanitize_text_field( (string) $value );

		return in_array( $value, $allowed, true ) ? $value : 'scheduled';
	}
}
