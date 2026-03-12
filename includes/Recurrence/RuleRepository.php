<?php
/**
 * Persist and retrieve recurrence rules from the database.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Recurrence;

use Blockendar\DB\Schema;

/**
 * Handles CRUD for the {prefix}blockendar_recurrence table.
 */
class RuleRepository {

	/**
	 * Fetch the recurrence rule for a post. Returns null if none exists.
	 *
	 * @param int $post_id Post ID.
	 */
	public function get( int $post_id ): ?Rule {
		global $wpdb;

		$table = Schema::recurrence_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id )
		);
		// phpcs:enable

		return $row ? Rule::from_db_row( $row ) : null;
	}

	/**
	 * Insert or update the recurrence rule for a post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Rule fields (same keys as the DB columns).
	 * @return bool True on success.
	 */
	public function upsert( int $post_id, array $data ): bool {
		global $wpdb;

		$table = Schema::recurrence_table();

		$row = [
			'post_id'      => $post_id,
			'frequency'    => sanitize_text_field( $data['frequency'] ?? 'weekly' ),
			'interval_val' => max( 1, (int) ( $data['interval_val'] ?? 1 ) ),
			'byday'        => $this->sanitize_csv( $data['byday'] ?? null, Rule::WEEKDAYS ),
			'bymonthday'   => $this->sanitize_int_csv( $data['bymonthday'] ?? null, -31, 31 ),
			'bysetpos'     => $this->sanitize_int_csv( $data['bysetpos'] ?? null, -366, 366 ),
			'until_date'   => $this->sanitize_date( $data['until_date'] ?? null ),
			'count'        => isset( $data['count'] ) && '' !== $data['count']
				? max( 1, (int) $data['count'] )
				: null,
			'exceptions'   => $this->sanitize_json_dates( $data['exceptions'] ?? null ),
			'additions'    => $this->sanitize_json_dates( $data['additions'] ?? null ),
		];

		$formats = [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ];

		// Use INSERT … ON DUPLICATE KEY UPDATE (post_id has a UNIQUE KEY).
		$existing = $this->get( $post_id );

		if ( $existing ) {
			unset( $row['post_id'] );
			$result = $wpdb->update( $table, $row, [ 'post_id' => $post_id ], array_slice( $formats, 1 ), [ '%d' ] );
		} else {
			$result = $wpdb->insert( $table, $row, $formats );
		}

		return false !== $result;
	}

	/**
	 * Delete the recurrence rule for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if a row was deleted.
	 */
	public function delete( int $post_id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			Schema::recurrence_table(),
			[ 'post_id' => $post_id ],
			[ '%d' ]
		);

		return (bool) $result;
	}

	/**
	 * Add an exception date to an existing rule.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $date    Exception date in Y-m-d format.
	 * @return bool
	 */
	public function add_exception( int $post_id, string $date ): bool {
		$rule = $this->get( $post_id );

		if ( null === $rule ) {
			return false;
		}

		$exceptions = $rule->exceptions;

		if ( ! in_array( $date, $exceptions, true ) ) {
			$exceptions[] = $date;
		}

		return $this->upsert(
			$post_id,
			array_merge(
				(array) $rule,
				[
					'exceptions' => $exceptions,
				]
			)
		);
	}

	/**
	 * Add an extra date to a rule's additions list.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $date    Date in Y-m-d format.
	 * @return bool
	 */
	public function add_extra_date( int $post_id, string $date ): bool {
		$rule = $this->get( $post_id );

		if ( null === $rule ) {
			return false;
		}

		$additions = $rule->additions;

		if ( ! in_array( $date, $additions, true ) ) {
			$additions[] = $date;
		}

		return $this->upsert(
			$post_id,
			array_merge(
				(array) $rule,
				[
					'additions' => $additions,
				]
			)
		);
	}

	// -------------------------------------------------------------------------
	// Sanitizers
	// -------------------------------------------------------------------------

	private function sanitize_csv( mixed $value, array $allowlist ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$parts   = array_map( 'trim', explode( ',', (string) $value ) );
		$allowed = array_filter( $parts, fn( $v ) => in_array( $v, $allowlist, true ) );

		return ! empty( $allowed ) ? implode( ',', $allowed ) : null;
	}

	private function sanitize_int_csv( mixed $value, int $min, int $max ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$parts   = array_map( 'intval', explode( ',', (string) $value ) );
		$allowed = array_filter( $parts, fn( $v ) => $v >= $min && $v <= $max && 0 !== $v );

		return ! empty( $allowed ) ? implode( ',', $allowed ) : null;
	}

	private function sanitize_date( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $value );

		return ( $dt && $dt->format( 'Y-m-d' ) === (string) $value ) ? (string) $value : null;
	}

	private function sanitize_json_dates( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$dates = array_values(
			array_filter(
				$value,
				fn( $d ) => is_string( $d ) && (bool) \DateTimeImmutable::createFromFormat( 'Y-m-d', $d )
			)
		);

		return ! empty( $dates ) ? wp_json_encode( $dates ) : null;
	}
}
