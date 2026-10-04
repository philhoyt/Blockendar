<?php
/**
 * Global helper functions for Blockendar.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the occurrence to display on a single-event page or in a query loop.
 *
 * Resolution order:
 *  1. $GLOBALS['blockendar_current_occurrence'] — set by events-query render.php
 *     while rendering inner blocks, so each list item shows its own occurrence.
 *  2. ?occurrence_date=YYYY-MM-DD query string param — set by calendar links in
 *     CalendarController so clicking a chip shows that specific occurrence.
 *  3. next_occurrence() fallback for bare permalink visits.
 *
 * @param int|false|null $post_id The event post ID. get_the_ID() returns false
 *                                outside a post context; that resolves to null.
 * @return object|null Index row, or null if no occurrence exists at all.
 */
function blockendar_resolve_occurrence( int|false|null $post_id ): ?object {
	$post_id = (int) $post_id;

	if ( $post_id <= 0 ) {
		return null;
	}

	// Check for occurrence injected by events-query render loop.
	if ( isset( $GLOBALS['blockendar_current_occurrence'] ) &&
		(int) $GLOBALS['blockendar_current_occurrence']->post_id === $post_id ) {
		return $GLOBALS['blockendar_current_occurrence'];
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$raw = isset( $_GET['occurrence_date'] ) ? sanitize_text_field( wp_unslash( $_GET['occurrence_date'] ) ) : '';
	// phpcs:enable

	if ( '' !== $raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
		[ $y, $m, $d ] = explode( '-', $raw );
		if ( checkdate( (int) $m, (int) $d, (int) $y ) ) {
			$occurrence = \Blockendar\DB\EventIndex::get_occurrence_by_date( $post_id, $raw );
			if ( null !== $occurrence ) {
				return $occurrence;
			}
		}
	}

	return \Blockendar\DB\EventIndex::next_occurrence( $post_id );
}

/**
 * Resolve the event a single-event block should render.
 *
 * Blocks take the post from block context (inside an events-query loop or a
 * single event template) and fall back to the global post. Outside any post
 * context — a block dropped on a page, a template part, do_blocks() from
 * WP-CLI — there is nothing to render, and the block must bail rather than
 * hand a false ID to helpers that expect an event.
 *
 * @param WP_Block $block   The block being rendered.
 * @param int      $post_id Optional explicit post ID (e.g. a pinned event).
 * @return int The event post ID, or 0 when there is no event to render.
 */
function blockendar_block_event_id( WP_Block $block, int $post_id = 0 ): int {
	if ( $post_id <= 0 ) {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
	}

	if ( $post_id <= 0 || \Blockendar\CPT\EventPostType::POST_TYPE !== get_post_type( $post_id ) ) {
		return 0;
	}

	return $post_id;
}

/**
 * Append ?occurrence_date= to a CPT permalink while events-query renders inner blocks.
 *
 * Hooked to post_type_link during the render loop and removed immediately after,
 * ensuring core/post-title (and any other link) navigates to the correct occurrence.
 *
 * @param string  $permalink The post permalink.
 * @param WP_Post $post      The post object.
 * @return string Modified permalink.
 */
function blockendar_occurrence_permalink_filter( string $permalink, WP_Post $post ): string {
	if ( isset( $GLOBALS['blockendar_current_occurrence'] ) &&
		(int) $GLOBALS['blockendar_current_occurrence']->post_id === (int) $post->ID ) {
		return add_query_arg( 'occurrence_date', $GLOBALS['blockendar_current_occurrence']->start_date, $permalink );
	}
	return $permalink;
}

/**
 * Resolve the timezone an event's times should be displayed in.
 *
 * Honours the timezone_mode setting:
 *  - 'event' — show the times in the timezone the event was authored in, which
 *    is what a visitor travelling to the venue wants.
 *  - 'site'  — convert every event to the site's timezone, so a listing mixing
 *    events from several timezones can be compared at a glance.
 *
 * @param int $post_id The event post ID.
 * @return array{event: string, display: string} IANA identifiers. Equal when no
 *                                               conversion is needed.
 */
function blockendar_display_timezone( int $post_id ): array {
	$site_tz  = wp_timezone_string();
	$event_tz = (string) get_post_meta( $post_id, 'blockendar_timezone', true );

	if ( '' === $event_tz ) {
		$event_tz = $site_tz;
	}

	$mode = (string) \Blockendar\Admin\SettingsPage::get( 'timezone_mode' );

	return [
		'event'   => $event_tz,
		'display' => 'site' === $mode ? $site_tz : $event_tz,
	];
}

/**
 * Convert a local date and time from one timezone to another.
 *
 * Both timezones may be UTC-offset strings rather than IANA names when the site
 * uses a manual offset, which DateTimeZone accepts. Anything it cannot parse
 * leaves the values untouched — displaying the authored time is a better
 * failure than displaying nothing.
 *
 * @param string $date    Y-m-d in the source timezone.
 * @param string $time    H:i in the source timezone. Empty means date-only.
 * @param string $from_tz Source timezone identifier.
 * @param string $to_tz   Target timezone identifier.
 * @return array{0: string, 1: string} The converted date and time.
 */
function blockendar_convert_datetime( string $date, string $time, string $from_tz, string $to_tz ): array {
	if ( '' === $date || '' === $time || $from_tz === $to_tz ) {
		return [ $date, $time ];
	}

	try {
		$moment = new \DateTimeImmutable(
			$date . ' ' . $time,
			new \DateTimeZone( $from_tz )
		);
		$moment = $moment->setTimezone( new \DateTimeZone( $to_tz ) );
	} catch ( \Exception $e ) {
		return [ $date, $time ];
	}

	return [ $moment->format( 'Y-m-d' ), $moment->format( 'H:i' ) ];
}

/**
 * Warm the post, meta and term caches for a set of index rows.
 *
 * Index rows come from custom SQL, so WordPress has never seen these posts and
 * none of its caches are primed. Every consumer then pays a query per row —
 * get_post() behind get_permalink(), a meta lookup the first time a block asks
 * for a field, and a term query per taxonomy — which is the N+1 that shows up
 * on the query loop, the calendar feed, the events collection and the ICS
 * export alike.
 *
 * One call up front collapses all of that into three queries for the whole set.
 *
 * @param array<object> $rows        Index rows carrying a post_id property.
 * @param bool          $with_terms  Prime the term cache too. Worth it wherever
 *                                   venue or type terms are read per row.
 * @param bool          $with_meta   Prime the post meta cache.
 */
function blockendar_prime_event_caches( array $rows, bool $with_terms = true, bool $with_meta = true ): void {
	if ( empty( $rows ) ) {
		return;
	}

	$post_ids = array_values(
		array_unique(
			array_filter(
				array_map(
					static fn( $row ) => isset( $row->post_id ) ? (int) $row->post_id : 0,
					$rows
				)
			)
		)
	);

	if ( empty( $post_ids ) ) {
		return;
	}

	_prime_post_caches( $post_ids, $with_terms, $with_meta );
}

/**
 * Return the calendar day after a date.
 *
 * For turning an inclusive last day into the exclusive end that iCalendar,
 * FullCalendar and the index's end_datetime all want. Parsed in an explicit
 * zone rather than through strtotime(), which reads a bare date in PHP's
 * default timezone and so depends on nothing else having changed it.
 *
 * @param string $date Y-m-d.
 * @return string Y-m-d, or the input unchanged if it is not a date.
 */
function blockendar_next_day( string $date ): string {
	$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );

	return $day ? $day->modify( '+1 day' )->format( 'Y-m-d' ) : $date;
}

/**
 * Format a wall-clock date or datetime exactly as it reads.
 *
 * For values that are already in the timezone they should be shown in: an
 * event's own date, or one blockendar_convert_datetime() has moved to the
 * display zone. The value is read and formatted in the site timezone, so it
 * comes out as written, with month and day names translated.
 *
 * This is what date_i18n( $format, strtotime( $value ) ) was being used for.
 * That reads the value in PHP's default timezone and prints it in the site's,
 * which agree only while the default is UTC.
 *
 * @param string $value  Y-m-d, optionally followed by a time.
 * @param string $format PHP date format.
 * @return string Formatted value, or '' if it is not a date.
 */
function blockendar_format_wall_clock( string $value, string $format ): string {
	if ( '' === $value ) {
		return '';
	}

	$timezone = wp_timezone();
	$moment   = date_create_immutable( $value, $timezone );

	if ( ! $moment ) {
		return '';
	}

	return (string) wp_date( $format, $moment->getTimestamp(), $timezone );
}

/**
 * Convert a "UTC+5.5"-style manual offset into a form DateTimeZone accepts.
 *
 * WordPress and The Events Calendar both write a manual UTC offset as
 * "UTC+5.5". PHP rejects that, but takes "+05:30". Anything that is not in that
 * form is returned unchanged, so a named zone passes straight through.
 *
 * @param string $timezone Timezone as stored by the source.
 * @return string A named zone, "UTC", or an offset as "+HH:MM" / "-HH:MM".
 */
function blockendar_normalize_timezone( string $timezone ): string {
	if ( ! preg_match( '/^UTC([+-])(\d{1,2})(?:\.(\d{1,2}))?$/', $timezone, $parts ) ) {
		return $timezone;
	}

	$hours   = (int) $parts[2];
	$minutes = isset( $parts[3] ) ? (int) round( (float) ( '0.' . $parts[3] ) * 60 ) : 0;

	if ( 0 === $hours && 0 === $minutes ) {
		return 'UTC';
	}

	return sprintf( '%s%02d:%02d', $parts[1], $hours, $minutes );
}

/**
 * Map an ISO 4217 currency code to its display symbol.
 *
 * Mirrors CURRENCY_SYMBOLS in src/blocks/event-cost/edit.jsx. An unknown code
 * is returned as it is.
 *
 * @param string $code ISO 4217 code, e.g. "USD".
 */
function blockendar_currency_symbol( string $code ): string {
	static $map = [
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'CAD' => 'CA$',
		'AUD' => 'A$',
		'JPY' => '¥',
		'CHF' => 'CHF',
		'CNY' => '¥',
		'INR' => '₹',
		'MXN' => 'MX$',
		'BRL' => 'R$',
		'KRW' => '₩',
		'SEK' => 'kr',
		'NOK' => 'kr',
		'DKK' => 'kr',
		'NZD' => 'NZ$',
		'SGD' => 'S$',
		'HKD' => 'HK$',
		'ZAR' => 'R',
	];

	return $map[ $code ] ?? $code;
}
