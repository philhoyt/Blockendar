<?php
/**
 * Builds the "add to calendar" links for Google Calendar and Outlook.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Taxonomy\Venue;

/**
 * Turns one event occurrence into the URLs that open a pre-filled "new event"
 * form in Google Calendar and Outlook on the web.
 *
 * Neither service publishes its link format. The parameters used here are the
 * ones documented, from observation, at
 * https://github.com/InteractionDesignFoundation/add-event-to-calendar-docs
 *
 * Two rules do most of the work:
 *
 * - A timed event is sent as UTC instants. Outlook reads a time with no "Z" in
 *   the viewer's own timezone, so anything else moves the event for a visitor
 *   in another zone.
 * - An all-day event ends, for both services, on the day after its last day.
 */
class CalendarLinks {

	/**
	 * Longest description text sent, in characters, before the permalink.
	 *
	 * A link is a URL, and long ones are cut by browsers, servers and mail
	 * clients. The permalink that follows matters more than the last sentence.
	 */
	const DETAILS_LIMIT = 900;

	/**
	 * Google Calendar's event template URL.
	 *
	 * @param array $event {
	 *     One occurrence.
	 *
	 *     @type string             $title    Plain-text title.
	 *     @type \DateTimeImmutable $start    Start, in the event's timezone. Midnight on the first day when all-day.
	 *     @type \DateTimeImmutable $end      End, in the event's timezone. Midnight on the last day when all-day.
	 *     @type bool               $all_day  Whether the event has no times.
	 *     @type string             $details  Plain-text description.
	 *     @type string             $location Plain-text location.
	 * }
	 */
	public static function google( array $event ): string {
		$args = [
			'action' => 'TEMPLATE',
			'text'   => $event['title'],
		];

		if ( $event['all_day'] ) {
			$args['dates'] = $event['start']->format( 'Ymd' ) . '/' . $event['end']->modify( '+1 day' )->format( 'Ymd' );
		} else {
			$utc = new \DateTimeZone( 'UTC' );

			// Read as a UTC range only when both halves end in "Z".
			$args['dates'] = $event['start']->setTimezone( $utc )->format( 'Ymd\THis\Z' )
				. '/' . $event['end']->setTimezone( $utc )->format( 'Ymd\THis\Z' );

			// The zone the saved event is shown in. Google takes a named zone
			// here, not an offset.
			$zone = $event['start']->getTimezone()->getName();

			if ( preg_match( '/^[A-Za-z]/', $zone ) ) {
				$args['ctz'] = $zone;
			}
		}

		$args['details']  = $event['details'];
		$args['location'] = $event['location'];

		return self::url( 'https://calendar.google.com/calendar/render', $args, [ 'dates' ] );
	}

	/**
	 * Outlook on the web's compose-event URL.
	 *
	 * @param array  $event One occurrence; see google().
	 * @param string $host  'outlook.office.com' for work and school accounts, 'outlook.live.com' for personal ones.
	 */
	public static function outlook( array $event, string $host ): string {
		$args = [
			'path'    => '/calendar/action/compose',
			'rru'     => 'addevent',
			'subject' => $event['title'],
		];

		if ( $event['all_day'] ) {
			$args['startdt'] = $event['start']->format( 'Y-m-d' );
			$args['enddt']   = $event['end']->modify( '+1 day' )->format( 'Y-m-d' );
			$args['allday']  = 'true';
		} else {
			$utc = new \DateTimeZone( 'UTC' );

			$args['startdt'] = $event['start']->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' );
			$args['enddt']   = $event['end']->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' );
		}

		$args['body']     = $event['details'];
		$args['location'] = $event['location'];

		return self::url( "https://{$host}/calendar/0/deeplink/compose", $args, [ 'path' ] );
	}

	/**
	 * A plain-text title for an event.
	 *
	 * Not get_the_title(): that texturizes, turning "&" into an entity, and
	 * prefixes "Protected:" — neither belongs in another service's form field.
	 *
	 * @param int $post_id Event post ID.
	 */
	public static function title( int $post_id ): string {
		return self::plain_text( (string) get_post_field( 'post_title', $post_id, 'raw' ) );
	}

	/**
	 * A short description for an event: its excerpt, then its permalink.
	 *
	 * Uses the manual excerpt when there is one and the opening of the content
	 * otherwise. The content is not run through the_content, so a copy of this
	 * block inside it cannot render itself again. A password-protected event
	 * gives the permalink alone.
	 *
	 * @param int $post_id Event post ID.
	 */
	public static function details( int $post_id ): string {
		$post = get_post( $post_id );
		$url  = (string) get_permalink( $post_id );

		if ( ! $post || post_password_required( $post ) ) {
			return $url;
		}

		$text = '' !== trim( $post->post_excerpt )
			? self::plain_text( $post->post_excerpt )
			: wp_trim_words( self::plain_text( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ) ), 40, '…' );

		if ( mb_strlen( $text ) > self::DETAILS_LIMIT ) {
			$text = rtrim( mb_substr( $text, 0, self::DETAILS_LIMIT ) ) . '…';
		}

		// A space, not a line break: esc_url() removes encoded line breaks.
		return trim( $text . ' ' . $url );
	}

	/**
	 * A one-line location for an event, from its venue.
	 *
	 * A virtual venue gives its stream URL when it has one. The virtual flag
	 * and the URL are separate fields, so one without the other gives the
	 * venue's name.
	 *
	 * @param int $post_id Event post ID.
	 */
	public static function location( int $post_id ): string {
		$terms = get_the_terms( $post_id, Venue::TAXONOMY );

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		$term = $terms[0];
		$name = self::plain_text( $term->name );

		if ( get_term_meta( $term->term_id, 'blockendar_venue_virtual', true ) ) {
			$stream_url = (string) get_term_meta( $term->term_id, 'blockendar_venue_stream_url', true );

			return '' !== $stream_url ? $stream_url : $name;
		}

		$parts = [ $name, self::plain_text( blockendar_venue_address( $term->term_id ) ) ];

		return implode( ', ', array_filter( $parts, static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Strip markup and decode entities, leaving text on one line.
	 *
	 * @param string $value Text that may hold tags, entities or line breaks.
	 */
	private static function plain_text( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Add arguments to a URL, dropping the empty ones.
	 *
	 * @param string   $base    URL without a query string.
	 * @param array    $args    Parameter name => unencoded value.
	 * @param string[] $literal Parameters whose "/" is left as it is, as both services' own examples have it.
	 */
	private static function url( string $base, array $args, array $literal = [] ): string {
		$encoded = [];

		foreach ( $args as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$encoded[ $key ] = rawurlencode( $value );

			if ( in_array( $key, $literal, true ) ) {
				$encoded[ $key ] = str_replace( '%2F', '/', $encoded[ $key ] );
			}
		}

		return add_query_arg( $encoded, $base );
	}
}
