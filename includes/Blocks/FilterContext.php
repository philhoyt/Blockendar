<?php
/**
 * Centralises URL query-param reading for the filter block suite.
 *
 * All filter blocks and events-query delegate to this class when reading
 * active filter state from $_GET. Keeping the raw superglobal access in
 * one place makes sanitisation and future nonce/token checks easy to add.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves filter param names and reads active filter values from the request.
 */
class FilterContext {

	/**
	 * Return the URL param name for a given filter key and query ID.
	 *
	 * When $query_id is empty the base unprefixed name is returned, which is
	 * correct for single-filter-group pages. When non-empty the query ID is
	 * appended so multiple filter+query groups on the same page are isolated.
	 *
	 * Examples:
	 *   param_name( 'type', '' )         → 'blockendar_type'
	 *   param_name( 'type', 'sidebar' )  → 'blockendar_type_sidebar'
	 *
	 * @param string $key      Filter key: 'type', 'venue', 'date_start', 'date_end', 'page'.
	 * @param string $query_id Query ID from the blockendar/queryId block context.
	 */
	public static function param_name( string $key, string $query_id ): string {
		$base = 'blockendar_' . $key;
		return '' !== $query_id ? $base . '_' . sanitize_key( $query_id ) : $base;
	}

	/**
	 * Read and sanitise all active filter values from $_GET for a given query ID.
	 *
	 * Returns a normalised array. Values that are absent or invalid are null/[].
	 * Callers should treat null as "no filter applied" (pass-through).
	 *
	 * @param string $query_id Query ID from the blockendar/queryId block context.
	 * @return array{
	 *     type_ids: int[],
	 *     venue_id: int|null,
	 *     date_start: string|null,
	 *     date_end: string|null,
	 * }
	 */
	public static function get_active_filters( string $query_id ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$type_raw   = sanitize_text_field( wp_unslash( $_GET[ self::param_name( 'type', $query_id ) ] ?? '' ) );
		$venue_raw  = absint( $_GET[ self::param_name( 'venue', $query_id ) ] ?? 0 );
		$date_start = sanitize_text_field( wp_unslash( $_GET[ self::param_name( 'date_start', $query_id ) ] ?? '' ) );
		$date_end   = sanitize_text_field( wp_unslash( $_GET[ self::param_name( 'date_end', $query_id ) ] ?? '' ) );
		// phpcs:enable

		return [
			'type_ids'   => array_values( array_filter( array_map( 'absint', explode( ',', $type_raw ) ) ) ),
			'venue_id'   => $venue_raw ?: null,
			'date_start' => self::validate_date( $date_start ),
			'date_end'   => self::validate_date( $date_end ),
		];
	}

	/**
	 * Check whether any active filters are set for the given query ID.
	 *
	 * Useful for conditionally adding a "Clear all filters" link.
	 *
	 * @param string $query_id Query ID from the blockendar/queryId block context.
	 */
	public static function has_active_filters( string $query_id ): bool {
		$filters = self::get_active_filters( $query_id );
		return ! empty( $filters['type_ids'] )
			|| null !== $filters['venue_id']
			|| null !== $filters['date_start']
			|| null !== $filters['date_end'];
	}

	/**
	 * Build the URL to clear all filters for a given query ID.
	 *
	 * Removes all blockendar filter params (and resets pagination) while
	 * preserving any other query params on the current page URL.
	 *
	 * @param string $query_id Query ID from the blockendar/queryId block context.
	 */
	public static function clear_filters_url( string $query_id ): string {
		$remove = [
			self::param_name( 'type', $query_id ),
			self::param_name( 'venue', $query_id ),
			self::param_name( 'date_start', $query_id ),
			self::param_name( 'date_end', $query_id ),
			self::param_name( 'page', $query_id ),
		];

		return esc_url( remove_query_arg( $remove ) );
	}

	/**
	 * Validate a Y-m-d date string. Returns null if invalid.
	 *
	 * @param string $date Raw date string from user input.
	 */
	private static function validate_date( string $date ): ?string {
		if ( '' === $date ) {
			return null;
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}

		// Confirm the date is actually valid (e.g. rejects 2026-02-30).
		$parts = explode( '-', $date );
		if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
			return null;
		}

		return $date;
	}
}
