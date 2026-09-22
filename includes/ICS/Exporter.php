<?php
/**
 * iCalendar (RFC 5545) file generator.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\ICS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\DB\EventIndex;

/**
 * Generates iCal (.ics) content from event index rows.
 *
 * Used by:
 * - CalendarController (?format=ics feed endpoint)
 * - Single event .ics download (registered as a rewrite endpoint)
 */
class Exporter {

	/**
	 * Maximum octets in a single physical content line (RFC 5545 §3.1).
	 */
	private const MAX_OCTETS = 75;

	/**
	 * Generate an iCal feed string from an array of index rows.
	 *
	 * @param object[] $rows  Index rows joined with wp_posts.
	 * @param string   $title Optional calendar title.
	 * @return string Full VCALENDAR iCal content.
	 */
	public function generate_feed( array $rows, string $title = '' ): string {
		if ( '' === $title ) {
			$title = get_bloginfo( 'name' ) . ' Events';
		}

		$lines   = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//Blockendar//Blockendar Events//EN';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'METHOD:PUBLISH';
		$lines[] = 'X-WR-CALNAME:' . $this->escape_text( $title );
		$lines[] = 'X-WR-TIMEZONE:UTC';

		foreach ( $rows as $row ) {
			$lines = array_merge( $lines, $this->build_vevent( $row ) );
		}

		$lines[] = 'END:VCALENDAR';

		$lines = array_map( [ $this, 'fold_line' ], $lines );

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Fold a single logical content line to RFC 5545 §3.1 limits.
	 *
	 * Physical lines are capped at 75 octets. Continuation lines are introduced
	 * by CRLF plus one space, and that space counts toward the limit, so they
	 * carry one octet less content than the first line.
	 *
	 * Public so it can be unit tested directly.
	 *
	 * @param string $line One logical content line, unfolded.
	 * @return string The same line, folded where necessary.
	 */
	public function fold_line( string $line ): string {
		if ( strlen( $line ) <= self::MAX_OCTETS ) {
			return $line;
		}

		$folded = '';
		$pos    = 0;
		$len    = strlen( $line );
		$limit  = self::MAX_OCTETS;

		while ( $pos < $len ) {
			$take = min( $limit, $len - $pos );

			if ( $pos + $take < $len ) {
				$take = $this->safe_split_length( $line, $pos, $take );
			}

			$folded .= ( '' === $folded ? '' : "\r\n " ) . substr( $line, $pos, $take );
			$pos    += $take;

			// Continuation lines spend one octet on the leading space.
			$limit = self::MAX_OCTETS - 1;
		}

		return $folded;
	}

	/**
	 * Pull a proposed fold point back to a safe boundary.
	 *
	 * Backs off while the split would land inside a UTF-8 multi-octet sequence
	 * or between a backslash and the character it escapes. Unfolding restores
	 * the octet stream either way, but some consumers parse folded lines
	 * individually, so neither is worth risking.
	 *
	 * @param string $line Full logical line.
	 * @param int    $pos  Offset of the current chunk.
	 * @param int    $take Proposed chunk length in octets.
	 * @return int Adjusted chunk length, always at least 1.
	 */
	private function safe_split_length( string $line, int $pos, int $take ): int {
		while ( $take > 1 ) {
			$splits_utf8 = ( ord( $line[ $pos + $take ] ) & 0xC0 ) === 0x80;

			if ( ! $splits_utf8 && ! $this->ends_with_open_escape( substr( $line, $pos, $take ) ) ) {
				break;
			}

			--$take;
		}

		return $take;
	}

	/**
	 * Whether a chunk ends on a backslash that still needs its escaped char.
	 *
	 * An odd number of trailing backslashes means the last one opens an escape
	 * sequence whose target sits in the next chunk.
	 *
	 * @param string $chunk Candidate chunk.
	 */
	private function ends_with_open_escape( string $chunk ): bool {
		$trailing = strlen( $chunk ) - strlen( rtrim( $chunk, '\\' ) );

		return 1 === $trailing % 2;
	}

	/**
	 * Generate a single-event .ics file string.
	 *
	 * @param int $post_id Event post ID.
	 * @return string|null iCal content, or null if post not found.
	 */
	public function generate_single( int $post_id ): ?string {
		$post = get_post( $post_id );

		if ( ! $post || 'blockendar_event' !== $post->post_type ) {
			return null;
		}

		// Build a synthetic row from post meta.
		$row = $this->build_row_from_meta( $post );

		if ( null === $row ) {
			return null;
		}

		return $this->generate_feed( [ $row ], get_the_title( $post ) );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a VEVENT block from an index row.
	 *
	 * @param object $row Index row joined with wp_posts.
	 * @return string[]
	 */
	private function build_vevent( object $row ): array {
		$post_id     = (int) $row->post_id;
		$all_day     = (bool) $row->all_day;
		$ongoing     = ! empty( $row->ongoing );
		$uid         = "blockendar-{$post_id}-{$row->start_date}@" . wp_parse_url( home_url(), PHP_URL_HOST );
		$url         = get_permalink( $post_id );
		$summary     = $this->escape_text( $row->post_title );
		$description = $this->escape_text( wp_strip_all_tags( get_the_excerpt( $post_id ) ) );
		$location    = $this->get_location_string( $row->venue_term_id ? (int) $row->venue_term_id : null );

		$lines   = [];
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $uid;
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );

		// RFC 5545 permits a VEVENT with DTSTART only; ongoing events have no end
		// date, so emit none rather than the index's sentinel.
		if ( $all_day ) {
			$lines[] = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $row->start_date );
			if ( ! $ongoing ) {
				// iCal all-day end is exclusive, so add one day.
				$end_exclusive = gmdate( 'Ymd', strtotime( $row->end_date . ' +1 day' ) );
				$lines[]       = 'DTEND;VALUE=DATE:' . $end_exclusive;
			}
		} else {
			$lines[] = 'DTSTART:' . $this->utc_to_ical( $row->start_datetime );
			if ( ! $ongoing ) {
				$lines[] = 'DTEND:' . $this->utc_to_ical( $row->end_datetime );
			}
		}

		$lines[] = 'SUMMARY:' . $summary;

		if ( '' !== $description ) {
			$lines[] = 'DESCRIPTION:' . $description;
		}

		if ( '' !== $url ) {
			$lines[] = 'URL:' . $url;
		}

		if ( '' !== $location ) {
			$lines[] = 'LOCATION:' . $this->escape_text( $location );
		}

		// Status mapping.
		$status_map  = [
			'scheduled' => 'CONFIRMED',
			'cancelled' => 'CANCELLED',
			'postponed' => 'TENTATIVE',
			'sold_out'  => 'CONFIRMED',
		];
		$ical_status = $status_map[ $row->status ] ?? 'CONFIRMED';
		$lines[]     = 'STATUS:' . $ical_status;

		$lines[] = 'END:VEVENT';

		return $lines;
	}

	/**
	 * Convert a UTC datetime string (Y-m-d H:i:s) to iCal UTC format (Ymd\THis\Z).
	 */
	private function utc_to_ical( string $utc_datetime ): string {
		try {
			$dt = new \DateTimeImmutable( $utc_datetime, new \DateTimeZone( 'UTC' ) );
			return $dt->format( 'Ymd\THis\Z' );
		} catch ( \Exception ) {
			return '';
		}
	}

	/**
	 * Get a formatted location string from a venue term ID.
	 */
	private function get_location_string( ?int $venue_term_id ): string {
		if ( null === $venue_term_id ) {
			return '';
		}

		$term = get_term( $venue_term_id, 'event_venue' );

		if ( is_wp_error( $term ) || null === $term ) {
			return '';
		}

		$parts = array_filter(
			[
				$term->name,
				get_term_meta( $venue_term_id, 'blockendar_venue_address', true ),
				get_term_meta( $venue_term_id, 'blockendar_venue_city', true ),
				get_term_meta( $venue_term_id, 'blockendar_venue_state', true ),
				get_term_meta( $venue_term_id, 'blockendar_venue_country', true ),
			]
		);

		return implode( ', ', $parts );
	}

	/**
	 * Escape text for iCal property values (RFC 5545 §3.3.11).
	 *
	 * Line breaks are normalised to \n escapes first. A raw CR left in a value
	 * would terminate the content line early and let the rest of the string be
	 * read as further iCalendar properties, so CRLF and bare CR are collapsed
	 * before anything else is escaped.
	 */
	private function escape_text( string $value ): string {
		$value = str_replace( [ "\r\n", "\r" ], "\n", $value );
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( ';', '\;', $value );
		$value = str_replace( ',', '\,', $value );
		$value = str_replace( "\n", '\n', $value );
		return $value;
	}

	/**
	 * Build a synthetic row object from post meta (for single-event exports).
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function build_row_from_meta( \WP_Post $post ): ?object {
		$post_id    = $post->ID;
		$start_date = get_post_meta( $post_id, 'blockendar_start_date', true );
		$end_date   = get_post_meta( $post_id, 'blockendar_end_date', true );

		if ( empty( $start_date ) ) {
			return null;
		}

		$all_day    = (bool) get_post_meta( $post_id, 'blockendar_all_day', true );
		$ongoing    = (bool) get_post_meta( $post_id, 'blockendar_ongoing', true );
		$start_time = get_post_meta( $post_id, 'blockendar_start_time', true ) ?: '00:00';
		$end_time   = get_post_meta( $post_id, 'blockendar_end_time', true ) ?: $start_time;
		$tz_str     = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();
		$status     = get_post_meta( $post_id, 'blockendar_status', true ) ?: 'scheduled';

		if ( ! $ongoing && empty( $end_date ) ) {
			return null;
		}

		try {
			$tz       = new \DateTimeZone( $tz_str );
			$utc      = new \DateTimeZone( 'UTC' );
			$start_dt = ( new \DateTimeImmutable( "{$start_date} {$start_time}:00", $tz ) )->setTimezone( $utc );
			// Ongoing events have no end date; build_vevent() never reads the end
			// for them, so mirror the index sentinel rather than parsing an empty date.
			$end_dt = $ongoing
				? new \DateTimeImmutable( EventIndex::ONGOING_END, $utc )
				: ( new \DateTimeImmutable( "{$end_date} {$end_time}:00", $tz ) )->setTimezone( $utc );
		} catch ( \Exception ) {
			return null;
		}

		$terms         = get_the_terms( $post_id, 'event_venue' );
		$venue_term_id = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0]->term_id : null;

		return (object) [
			'post_id'        => $post_id,
			'post_title'     => $post->post_title,
			'start_date'     => $start_date,
			'end_date'       => $ongoing ? EventIndex::ONGOING_END_DATE : $end_date,
			'start_datetime' => $start_dt->format( 'Y-m-d H:i:s' ),
			'end_datetime'   => $end_dt->format( 'Y-m-d H:i:s' ),
			'all_day'        => $all_day ? 1 : 0,
			'ongoing'        => $ongoing ? 1 : 0,
			'status'         => $status,
			'venue_term_id'  => $venue_term_id,
		];
	}
}
