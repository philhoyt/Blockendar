<?php
/**
 * schema.org Event markup for single event pages.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Admin\SettingsPage;
use Blockendar\CPT\EventPostType;
use Blockendar\DB\EventIndex;
use Blockendar\Taxonomy\Venue;

/**
 * Prints one schema.org `Event` in the head of a single event page.
 *
 * This is what lets a search engine show an event with its date and place.
 * The object describes the occurrence the page is showing, and is built only
 * from what the event really has: there is no organiser or performer, because
 * the plugin stores neither, and nothing is filled in to satisfy a validator.
 *
 * Google's Event guidelines (developers.google.com/search/docs/appearance/
 * structured-data/event, as of September 2026) require a name, a start date
 * and a physical place with an address, and say events with no real-world
 * location are not supported. An event that lacks those is given no markup
 * by default, since all it could earn is an "invalid item" in Search Console.
 * The `blockendar_json_ld_enabled` filter decides otherwise.
 */
class JsonLd {

	private const SCHEMA = 'https://schema.org/';

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'wp_head', [ $this, 'print_markup' ] );
	}

	/**
	 * Print the markup on a single event page.
	 */
	public function print_markup(): void {
		if ( ! is_singular( EventPostType::POST_TYPE ) ) {
			return;
		}

		$post = get_queried_object();
		$data = $post instanceof \WP_Post ? $this->for_post( $post ) : null;

		if ( null === $data ) {
			return;
		}

		/*
		 * JSON_HEX_TAG writes < and > as unicode escapes, so nothing in the
		 * text can end the script element. Tags are stripped from the text
		 * before this, but entities are decoded after that, so a title
		 * containing "&lt;/script&gt;" arrives here as the real thing.
		 * Nothing else is escaped: this is JSON in a script element, not HTML
		 * and not a JavaScript string, and esc_js() or esc_html() would
		 * corrupt it.
		 *
		 * A float goes into JSON with as many digits as serialize_precision
		 * says. PHP's default writes 19.99 as 19.99; a host that sets 17
		 * writes 19.989999999999998, and a latitude of 39.781700000000001.
		 * -1 asks for the shortest form that reads back as the same number.
		 * If the host forbids changing it, the output is still valid JSON.
		 */
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Scoped to one encode and restored below.
		$precision = ini_set( 'serialize_precision', '-1' );

		$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false !== $precision ) {
			ini_set( 'serialize_precision', $precision ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		if ( false === $json ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, encoded above with JSON_HEX_TAG.
		echo '<script type="application/ld+json">' . $json . "</script>\n";
	}

	/**
	 * The Event object for a post, or null when none should be printed.
	 *
	 * @param \WP_Post $post Event post.
	 * @return array|null
	 */
	public function for_post( \WP_Post $post ): ?array {
		// A password-protected event is published, and its date and place are
		// what the password is there to keep back.
		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return null;
		}

		$occurrence = blockendar_resolve_occurrence( $post->ID ) ?? $this->past_occurrence( $post->ID );

		if ( null === $occurrence ) {
			return null;
		}

		$data = $this->event( $post, $occurrence );

		/**
		 * Filter whether a single event page carries schema.org Event markup.
		 *
		 * The default is true when the event has what Google's Event
		 * guidelines require (a physical venue with an address) and false
		 * when it does not: an online event, or one with no venue.
		 *
		 * @param bool   $enabled    Whether to print the markup.
		 * @param int    $post_id    Event post ID.
		 * @param object $occurrence Index row for the occurrence the page shows.
		 */
		if ( ! apply_filters( 'blockendar_json_ld_enabled', $this->has_a_place_with_an_address( $data ), $post->ID, $occurrence ) ) {
			return null;
		}

		/**
		 * Filter the schema.org Event printed on a single event page.
		 *
		 * Return an empty array to print nothing.
		 *
		 * @param array  $data       The Event, as an array ready for JSON.
		 * @param int    $post_id    Event post ID.
		 * @param object $occurrence Index row for the occurrence the page shows.
		 */
		$data = apply_filters( 'blockendar_json_ld_event', $data, $post->ID, $occurrence );

		return is_array( $data ) && ! empty( $data ) ? $data : null;
	}

	// -------------------------------------------------------------------------
	// The Event and its parts
	// -------------------------------------------------------------------------

	/**
	 * Build the Event.
	 *
	 * @param \WP_Post $post       Event post.
	 * @param object   $occurrence Index row.
	 * @return array
	 */
	private function event( \WP_Post $post, object $occurrence ): array {
		$url  = $this->url( $post, $occurrence );
		$data = [
			'@context' => 'https://schema.org',
			'@type'    => 'Event',
			'name'     => $this->plain( get_the_title( $post ) ),
			'url'      => $url,
		];

		// With no excerpt written, WordPress cuts one from the content and ends
		// it with "[…]". The brackets are page furniture; the ellipsis stays.
		$description = (string) preg_replace( '/\s*\[(?:…|\.\.\.)\]$/u', '…', $this->plain( get_the_excerpt( $post ) ) );

		if ( '' !== $description ) {
			$data['description'] = $description;
		}

		$image = get_the_post_thumbnail_url( $post, 'full' );

		if ( $image ) {
			$data['image'] = [ $image ];
		}

		$data += $this->dates( $post, $occurrence );

		// The occurrence's status, not the event's: one date of a series can
		// be cancelled on its own. "Sold out" is about tickets, and is said in
		// the offer; the event itself is going ahead.
		$statuses            = [
			'cancelled' => 'EventCancelled',
			'postponed' => 'EventPostponed',
		];
		$data['eventStatus'] = self::SCHEMA . ( $statuses[ $occurrence->status ?? '' ] ?? 'EventScheduled' );

		$venue_id = $this->venue_id( $post, $occurrence );
		$virtual  = $venue_id && get_term_meta( $venue_id, 'blockendar_venue_virtual', true );

		if ( $venue_id ) {
			$data['eventAttendanceMode'] = self::SCHEMA . ( $virtual ? 'OnlineEventAttendanceMode' : 'OfflineEventAttendanceMode' );

			$location = $virtual ? $this->virtual_location( $venue_id ) : $this->place( $venue_id );

			if ( null !== $location ) {
				$data['location'] = $location;
			}
		}

		$offer = $this->offer( $post, $occurrence, $url );

		if ( null !== $offer ) {
			$data['offers'] = $offer;
		}

		return $data;
	}

	/**
	 * The page's address: the event's, naming the occurrence when the request did.
	 *
	 * @param \WP_Post $post       Event post.
	 * @param object   $occurrence Index row.
	 */
	private function url( \WP_Post $post, object $occurrence ): string {
		$url = (string) get_permalink( $post );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, and compared with a date from the index.
		$asked = isset( $_GET['occurrence_date'] ) ? sanitize_text_field( wp_unslash( $_GET['occurrence_date'] ) ) : '';

		return $asked === $occurrence->start_date ? add_query_arg( 'occurrence_date', $occurrence->start_date, $url ) : $url;
	}

	/**
	 * startDate and endDate.
	 *
	 * An all-day event gives dates with no time, as Google asks. A timed one
	 * gives the local time with its offset, in the timezone the event was
	 * entered in. An ongoing event has no end, and neither does one whose end
	 * is its start, which is how an event with no end time is indexed.
	 *
	 * @param \WP_Post $post       Event post.
	 * @param object   $occurrence Index row.
	 * @return array<string, string>
	 */
	private function dates( \WP_Post $post, object $occurrence ): array {
		$ongoing = ! empty( $occurrence->ongoing );

		if ( ! empty( $occurrence->all_day ) ) {
			$dates = [ 'startDate' => (string) $occurrence->start_date ];

			if ( ! $ongoing ) {
				// Inclusive: the last day of the event, not the day after.
				$dates['endDate'] = (string) $occurrence->end_date;
			}

			return $dates;
		}

		$timezone = $this->timezone( $post->ID );
		$dates    = [ 'startDate' => $this->local( (string) $occurrence->start_datetime, $timezone ) ];

		if ( ! $ongoing && $occurrence->end_datetime > $occurrence->start_datetime ) {
			$dates['endDate'] = $this->local( (string) $occurrence->end_datetime, $timezone );
		}

		return $dates;
	}

	/**
	 * A physical venue as a Place.
	 *
	 * @param int $venue_id Venue term ID.
	 * @return array|null
	 */
	private function place( int $venue_id ): ?array {
		$term = get_term( $venue_id, Venue::TAXONOMY );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$place = [
			'@type' => 'Place',
			'name'  => $this->plain( $term->name ),
		];

		$address = array_filter(
			[
				'streetAddress'   => $this->meta( $venue_id, 'address' ),
				'addressLocality' => $this->meta( $venue_id, 'city' ),
				'addressRegion'   => $this->meta( $venue_id, 'state' ),
				'postalCode'      => $this->meta( $venue_id, 'postal_code' ),
				'addressCountry'  => $this->meta( $venue_id, 'country' ),
			],
			static fn( string $part ): bool => '' !== $part
		);

			if ( ! empty( $address ) ) {
				$place['address'] = [ '@type' => 'PostalAddress' ] + $address;
			}

			$lat = (float) get_term_meta( $venue_id, 'blockendar_venue_lat', true );
			$lng = (float) get_term_meta( $venue_id, 'blockendar_venue_lng', true );

			// 0, 0 is what an unset pair of coordinates reads as.
			if ( $lat && $lng ) {
				$place['geo'] = [
					'@type'     => 'GeoCoordinates',
					'latitude'  => $lat,
					'longitude' => $lng,
				];
			}

			return $place;
	}

	/**
	 * An online venue as a VirtualLocation, when it has a stream to point to.
	 *
	 * @param int $venue_id Venue term ID.
	 * @return array|null
	 */
	private function virtual_location( int $venue_id ): ?array {
		$stream = (string) get_term_meta( $venue_id, 'blockendar_venue_stream_url', true );

		if ( '' === $stream ) {
			return null;
		}

		return [
			'@type' => 'VirtualLocation',
			'url'   => $stream,
		];
	}

	/**
	 * The price as an Offer, when the cost is a number.
	 *
	 * "Donation" or "$10–$25" is not a price a machine can use, and is left
	 * out. A cost of 0 is a price: the event is free.
	 *
	 * @param \WP_Post $post       Event post.
	 * @param object   $occurrence Index row.
	 * @param string   $event_url  The event's own address.
	 * @return array|null
	 */
	private function offer( \WP_Post $post, object $occurrence, string $event_url ): ?array {
		$cost = trim( (string) get_post_meta( $post->ID, 'blockendar_cost', true ) );

		// Not a truthiness test: "0" is a cost.
		if ( '' === $cost || ! is_numeric( $cost ) ) {
			return null;
		}

		$registration = (string) get_post_meta( $post->ID, 'blockendar_registration_url', true );

		return [
			'@type'         => 'Offer',
			'price'         => $cost + 0,
			'priceCurrency' => (string) SettingsPage::get( 'default_currency' ),
			'availability'  => self::SCHEMA . ( 'sold_out' === ( $occurrence->status ?? '' ) ? 'SoldOut' : 'InStock' ),
			'url'           => '' !== $registration ? $registration : $event_url,
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * The occurrence to describe when an event has none still to come.
	 *
	 * The page of an event that is over goes on showing the date the event
	 * was entered with, so that is the occurrence described; failing that,
	 * the last one there was.
	 *
	 * @param int $post_id Event post ID.
	 * @return object|null Index row, or null for an event with no rows at all.
	 */
	private function past_occurrence( int $post_id ): ?object {
		$entered = (string) get_post_meta( $post_id, 'blockendar_start_date', true );
		$first   = '' !== $entered ? EventIndex::get_occurrence_by_date( $post_id, $entered ) : null;

		return $first ?? EventIndex::last_occurrence( $post_id );
	}

	/**
	 * Whether the Event has the place Google requires: a Place with an address.
	 *
	 * @param array $data The Event.
	 */
	private function has_a_place_with_an_address( array $data ): bool {
		return 'Place' === ( $data['location']['@type'] ?? '' ) && isset( $data['location']['address'] );
	}

	/**
	 * The event's venue: the one on the occurrence, or its first venue term.
	 *
	 * @param \WP_Post $post       Event post.
	 * @param object   $occurrence Index row.
	 */
	private function venue_id( \WP_Post $post, object $occurrence ): int {
		if ( ! empty( $occurrence->venue_term_id ) ) {
			return (int) $occurrence->venue_term_id;
		}

		$terms = get_the_terms( $post->ID, Venue::TAXONOMY );

		return is_array( $terms ) && ! empty( $terms ) ? (int) $terms[0]->term_id : 0;
	}

	/**
	 * The timezone an event's times are given in: its own, or the site's.
	 *
	 * @param int $post_id Event post ID.
	 */
	private function timezone( int $post_id ): \DateTimeZone {
		$own = (string) get_post_meta( $post_id, 'blockendar_timezone', true );

		if ( '' !== $own ) {
			try {
				return new \DateTimeZone( $own );
			} catch ( \Exception ) {
				return wp_timezone();
			}
		}

		return wp_timezone();
	}

	/**
	 * A UTC datetime from the index as local time with its offset.
	 *
	 * @param string        $utc      Y-m-d H:i:s in UTC.
	 * @param \DateTimeZone $timezone Timezone to give it in.
	 */
	private function local( string $utc, \DateTimeZone $timezone ): string {
		try {
			return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone )->format( 'Y-m-d\TH:i:sP' );
		} catch ( \Exception ) {
			return str_replace( ' ', 'T', $utc ) . '+00:00';
		}
	}

	/**
	 * One venue field, trimmed.
	 *
	 * @param int    $venue_id Venue term ID.
	 * @param string $field    Field name without the prefix.
	 */
	private function meta( int $venue_id, string $field ): string {
		return trim( (string) get_term_meta( $venue_id, "blockendar_venue_{$field}", true ) );
	}

	/**
	 * Text with no markup and no entities, on one line.
	 *
	 * @param string $value Text that may hold tags or entities.
	 */
	private function plain( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	}
}
