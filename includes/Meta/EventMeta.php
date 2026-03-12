<?php
/**
 * Event post meta registration.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Meta;

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

		register_post_meta(
			$post_type,
			'blockendar_cost_min',
			[
				'type'              => 'number',
				'description'       => 'Numeric minimum cost for filtering/sorting.',
				'single'            => true,
				'default'           => 0.0,
				'sanitize_callback' => [ $this, 'sanitize_float' ],
				'show_in_rest'      => true,
			]
		);

		register_post_meta(
			$post_type,
			'blockendar_cost_max',
			[
				'type'              => 'number',
				'description'       => 'Numeric maximum cost for filtering/sorting.',
				'single'            => true,
				'default'           => 0.0,
				'sanitize_callback' => [ $this, 'sanitize_float' ],
				'show_in_rest'      => true,
			]
		);

		register_post_meta(
			$post_type,
			'blockendar_currency',
			[
				'type'              => 'string',
				'description'       => 'ISO 4217 currency code (e.g. USD).',
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ $this, 'sanitize_currency' ],
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

		// Capacity.
		register_post_meta(
			$post_type,
			'blockendar_capacity',
			[
				'type'              => 'integer',
				'description'       => 'Maximum attendees. 0 = unlimited.',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
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

	/**
	 * Sanitize a float value.
	 */
	public function sanitize_float( mixed $value ): float {
		return max( 0.0, (float) $value );
	}

	/**
	 * Sanitize an ISO 4217 currency code (3 uppercase letters).
	 */
	public function sanitize_currency( mixed $value ): string {
		$value = strtoupper( sanitize_text_field( (string) $value ) );

		return preg_match( '/^[A-Z]{3}$/', $value ) ? $value : '';
	}
}
