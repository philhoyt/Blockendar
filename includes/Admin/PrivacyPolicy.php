<?php
/**
 * Suggested privacy policy text.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Blockendar's section in Settings > Privacy > Policy Guide.
 *
 * The plugin stores no personal data about visitors — no names, emails, IPs or
 * cookies — so it registers no personal-data exporter or eraser: core's
 * contract for those is keyed on an email address and there is no field here
 * that can be joined to a person.
 *
 * It does make two third-party requests though, and those are disclosable:
 * a visitor's IP reaches OpenStreetMap whenever a map renders, and a venue
 * address is sent to Nominatim when an editor asks for coordinates. That is
 * documented in the readme, but readme prose is not what a site owner sees
 * when drafting a policy — Settings > Privacy is, and until now Blockendar
 * had nothing to say there.
 */
class PrivacyPolicy {

	/**
	 * Attach hooks.
	 *
	 * admin_init specifically: calling wp_add_privacy_policy_content() earlier
	 * triggers a _doing_it_wrong() notice from core.
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
	}

	/**
	 * Add the suggested policy text.
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">'
			. esc_html__(
				'Blockendar does not collect or store personal data about your visitors. It does load resources from OpenStreetMap, which means a visitor\'s IP address reaches a third party whenever a map is shown. Suggested text follows.',
				'blockendar'
			)
			. '</p>';

		$content .= '<p><strong>' . esc_html__( 'Events and calendars', 'blockendar' ) . '</strong></p>';

		$content .= '<p>' . esc_html__(
			'Event and venue information on this site is published by us, not collected from you. Viewing an event page, browsing the calendar or subscribing to the calendar feed does not create a record about you, and Blockendar sets no cookies.',
			'blockendar'
		) . '</p>';

		$content .= '<p>' . esc_html__(
			'Where an event page shows a map, the map images are loaded directly from OpenStreetMap. Your browser contacts openstreetmap.org to fetch them, which means your IP address is visible to that service. This happens only on pages that display a map.',
			'blockendar'
		) . '</p>';

		$content .= '<p>' . sprintf(
			/* translators: %s: link to the OpenStreetMap Foundation privacy policy. */
			esc_html__( 'OpenStreetMap is operated by the OpenStreetMap Foundation. Their privacy policy is available at %s.', 'blockendar' ),
			'<a href="https://osmfoundation.org/wiki/Privacy_Policy">https://osmfoundation.org/wiki/Privacy_Policy</a>'
		) . '</p>';

		$content .= '<p>' . esc_html__(
			'When a site editor uses the "Look up coordinates" button while adding a venue, the venue address is sent to OpenStreetMap\'s Nominatim service to find its map location. This affects site editors only, never visitors, and only when the button is pressed.',
			'blockendar'
		) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Blockendar', 'blockendar' ),
			wp_kses_post( $content )
		);
	}
}
