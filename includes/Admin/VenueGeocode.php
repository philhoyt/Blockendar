<?php
/**
 * Enqueues the venue geocoding script on venue taxonomy admin pages.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Taxonomy\Venue;

/**
 * Loads a small JS file on the venue add/edit screens that provides a
 * "Look up coordinates" button wired to Nominatim (OSM).
 */
class VenueGeocode {

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the geocode script only on venue taxonomy screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		$taxonomy = Venue::TAXONOMY;

		// Only fire on the Add Term and Edit Term screens for event_venue.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_venue_screen = in_array( $hook, [ 'edit-tags.php', 'term.php' ], true )
			&& isset( $_GET['taxonomy'] ) && $_GET['taxonomy'] === $taxonomy; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		if ( ! $is_venue_screen ) {
			return;
		}

		wp_enqueue_script(
			'blockendar-venue-geocode',
			plugins_url( 'assets/js/venue-geocode.js', BLOCKENDAR_FILE ),
			[],
			BLOCKENDAR_VERSION,
			true
		);

		wp_localize_script(
			'blockendar-venue-geocode',
			'blockendarGeocode',
			[
				'labelLookup'  => __( 'Look up coordinates', 'blockendar' ),
				'labelLooking' => __( 'Looking up…', 'blockendar' ),
				'msgNotFound'  => __( 'Address not found. Please enter coordinates manually.', 'blockendar' ),
				'msgError'     => __( 'Geocoding request failed. Please try again.', 'blockendar' ),
				'msgNoAddress' => __( 'Please enter at least a city or address first.', 'blockendar' ),
			]
		);
	}
}
