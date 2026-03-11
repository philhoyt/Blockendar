<?php
/**
 * Venue term meta registration.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Meta;

use Blockendar\Taxonomy\Venue;
use Blockendar\Taxonomy\EventType;

/**
 * Registers term meta for the event_venue and event_type taxonomies.
 */
class VenueMeta {

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	/**
	 * Register all term meta fields.
	 */
	public function register_meta(): void {
		$this->register_venue_meta();
		$this->register_event_type_meta();
	}

	/**
	 * Register venue term meta fields.
	 */
	private function register_venue_meta(): void {
		$taxonomy = Venue::TAXONOMY;

		$string_fields = [
			'blockendar_venue_address'  => 'Street address line 1.',
			'blockendar_venue_address2' => 'Suite, floor, etc.',
			'blockendar_venue_city'     => 'City.',
			'blockendar_venue_state'    => 'State / Province.',
			'blockendar_venue_postcode' => 'Postal / ZIP code.',
			'blockendar_venue_country'  => 'ISO 3166-1 alpha-2 country code.',
			'blockendar_venue_phone'    => 'Contact phone number.',
		];

		foreach ( $string_fields as $key => $description ) {
			register_term_meta( $taxonomy, $key, [
				'type'              => 'string',
				'description'       => $description,
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
			] );
		}

		// URL fields.
		register_term_meta( $taxonomy, 'blockendar_venue_url', [
			'type'              => 'string',
			'description'       => 'Venue website URL.',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
			'show_in_rest'      => [
				'schema' => [
					'type'   => 'string',
					'format' => 'uri',
				],
			],
		] );

		register_term_meta( $taxonomy, 'blockendar_venue_stream_url', [
			'type'              => 'string',
			'description'       => 'Stream link for virtual events.',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
			'show_in_rest'      => [
				'schema' => [
					'type'   => 'string',
					'format' => 'uri',
				],
			],
		] );

		// Coordinate fields.
		register_term_meta( $taxonomy, 'blockendar_venue_lat', [
			'type'              => 'number',
			'description'       => 'Latitude (decimal degrees).',
			'single'            => true,
			'default'           => 0.0,
			'sanitize_callback' => [ $this, 'sanitize_latitude' ],
			'show_in_rest'      => true,
		] );

		register_term_meta( $taxonomy, 'blockendar_venue_lng', [
			'type'              => 'number',
			'description'       => 'Longitude (decimal degrees).',
			'single'            => true,
			'default'           => 0.0,
			'sanitize_callback' => [ $this, 'sanitize_longitude' ],
			'show_in_rest'      => true,
		] );

		// Integer fields.
		register_term_meta( $taxonomy, 'blockendar_venue_capacity', [
			'type'              => 'integer',
			'description'       => 'Venue maximum capacity.',
			'single'            => true,
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'show_in_rest'      => true,
		] );

		// Boolean fields.
		register_term_meta( $taxonomy, 'blockendar_venue_virtual', [
			'type'              => 'boolean',
			'description'       => 'Whether this is an online/virtual venue.',
			'single'            => true,
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'show_in_rest'      => true,
		] );
	}

	/**
	 * Register event_type term meta (calendar colour).
	 */
	private function register_event_type_meta(): void {
		register_term_meta( EventType::TAXONOMY, 'blockendar_type_color', [
			'type'              => 'string',
			'description'       => 'Hex colour for calendar display (e.g. #3B82F6).',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => [ $this, 'sanitize_hex_color' ],
			'show_in_rest'      => true,
		] );
	}

	/**
	 * Sanitize a latitude value (-90 to 90).
	 */
	public function sanitize_latitude( mixed $value ): float {
		$lat = (float) $value;
		return max( -90.0, min( 90.0, $lat ) );
	}

	/**
	 * Sanitize a longitude value (-180 to 180).
	 */
	public function sanitize_longitude( mixed $value ): float {
		$lng = (float) $value;
		return max( -180.0, min( 180.0, $lng ) );
	}

	/**
	 * Sanitize a hex colour code.
	 */
	public function sanitize_hex_color( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );

		return preg_match( '/^#[0-9A-Fa-f]{6}$/', $value ) ? strtoupper( $value ) : '';
	}
}
