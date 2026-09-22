<?php
/**
 * Builds subscription URLs for the calendar feed.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\ICS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces the https:// and webcal:// forms of the calendar feed URL.
 *
 * webcal:// is not a transport. Calendar clients rewrite it to http(s) before
 * fetching; its only job is to make the operating system hand the link to a
 * calendar app instead of a browser.
 */
class FeedUrl {

	/**
	 * Build a feed URL.
	 *
	 * @param array $filters    Optional venue_ids / type_ids / featured filters.
	 * @param bool  $webcal     Return the webcal:// form rather than https://.
	 * @param bool  $with_token Append the feed token.
	 *                          Admin screens only — see the warning below.
	 * @return string The feed URL.
	 */
	public static function build( array $filters = [], bool $webcal = false, bool $with_token = false ): string {
		$args = [ 'format' => 'ics' ];

		// parse_id_list() in CalendarController splits on commas, so IDs go in
		// as one comma-joined value. Passing arrays here would produce
		// venue[0]=… and silently match nothing. Negatives are dropped rather
		// than made positive, matching how parse_id_list() reads them back —
		// absint() would turn -4 into a request for term 4.
		$venue_ids = self::clean_ids( $filters['venue_ids'] ?? [] );
		$type_ids  = self::clean_ids( $filters['type_ids'] ?? [] );

		if ( ! empty( $venue_ids ) ) {
			$args['venue'] = implode( ',', $venue_ids );
		}

		if ( ! empty( $type_ids ) ) {
			$args['type'] = implode( ',', $type_ids );
		}

		if ( ! empty( $filters['featured'] ) ) {
			$args['featured'] = '1';
		}

		/*
		 * The token is a bearer credential: anyone holding the URL holds the
		 * calendar. It must never be rendered into a public page, which is why
		 * callers have to ask for it explicitly rather than getting it by
		 * default.
		 */
		if ( $with_token ) {
			$settings = get_option( 'blockendar_settings', [] );
			$token    = (string) ( $settings['rest_feed_token'] ?? '' );

			if ( '' !== $token ) {
				$args['token'] = $token;
			}
		}

		$url = add_query_arg( $args, rest_url( 'blockendar/v1/calendar' ) );

		if ( $webcal ) {
			$url = (string) preg_replace( '#^https?://#i', 'webcal://', $url );
		}

		return $url;
	}

	/**
	 * Reduce a mixed list of term IDs to positive integers.
	 *
	 * @param mixed $ids Raw IDs from block attributes or a caller.
	 * @return int[] Positive IDs, re-indexed.
	 */
	private static function clean_ids( $ids ): array {
		$ids = array_map( 'intval', (array) $ids );

		return array_values( array_filter( $ids, fn( $id ) => $id > 0 ) );
	}

	/**
	 * Whether the feed can be read by an anonymous visitor.
	 *
	 * The single source of truth for whether a subscribe link may be rendered
	 * on the front end. When this is false the feed needs a token or a login,
	 * and neither belongs in public markup.
	 */
	public static function is_publicly_readable(): bool {
		$settings = get_option( 'blockendar_settings', [] );

		return ! isset( $settings['rest_public'] ) || (bool) $settings['rest_public'];
	}
}
