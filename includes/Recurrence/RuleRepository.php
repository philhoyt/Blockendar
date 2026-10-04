<?php
/**
 * Persist and retrieve recurrence rules from the database.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Recurrence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
			'post_id'       => $post_id,
			'frequency'     => sanitize_text_field( $data['frequency'] ?? 'weekly' ),
			'interval_val'  => max( 1, (int) ( $data['interval_val'] ?? $data['interval'] ?? 1 ) ),
			'byday'         => $this->sanitize_csv( $data['byday'] ?? null, Rule::WEEKDAYS ),
			'bymonthday'    => $this->sanitize_int_csv( $data['bymonthday'] ?? null, -31, 31 ),
			'bysetpos'      => $this->sanitize_int_csv( $data['bysetpos'] ?? null, -366, 366 ),
			'until_date'    => $this->sanitize_date( $data['until_date'] ?? null ),
			'count'         => isset( $data['count'] ) && '' !== $data['count']
				? max( 1, (int) $data['count'] )
				: null,
			'exceptions'    => $this->sanitize_json_dates( $data['exceptions'] ?? null ),
			'additions'     => $this->sanitize_json_dates( $data['additions'] ?? null ),
			'cancellations' => $this->sanitize_json_dates( $data['cancellations'] ?? null ),
		];

		$formats = [
			'post_id'       => '%d',
			'frequency'     => '%s',
			'interval_val'  => '%d',
			'byday'         => '%s',
			'bymonthday'    => '%s',
			'bysetpos'      => '%s',
			'until_date'    => '%s',
			'count'         => '%d',
			'exceptions'    => '%s',
			'additions'     => '%s',
			'cancellations' => '%s',
		];

		$existing = $this->get( $post_id );

		if ( ! $existing ) {
			return false !== $wpdb->insert( $table, $row, array_values( $formats ) );
		}

		/*
		 * An update changes what it was given and leaves the rest. Writing every
		 * column meant a caller that sent only the schedule blanked the skipped
		 * and added dates, which is what the recurrence route and the editor
		 * both do. A column is cleared by naming it with an empty value.
		 */
		unset( $row['post_id'], $formats['post_id'] );

		$given = array_keys( $data );

		if ( in_array( 'interval', $given, true ) ) {
			$given[] = 'interval_val';
		}

		$row = array_intersect_key( $row, array_flip( $given ) );

		if ( empty( $row ) ) {
			return true;
		}

		$result = $wpdb->update(
			$table,
			$row,
			[ 'post_id' => $post_id ],
			array_values( array_intersect_key( $formats, $row ) ),
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Delete every rule whose event no longer exists.
	 *
	 * Versions before 2.1.0 left a rule behind when its event was deleted.
	 *
	 * @return int Rows removed.
	 */
	public function delete_orphans(): int {
		global $wpdb;

		$table = Schema::recurrence_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$removed = $wpdb->query(
			"DELETE r FROM {$table} r
			LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_id
			WHERE p.ID IS NULL"
		);
		// phpcs:enable

		return (int) $removed;
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
				$rule->to_db_array(),
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
				$rule->to_db_array(),
				[
					'additions' => $additions,
				]
			)
		);
	}

	/**
	 * Record that the occurrence on a date is cancelled.
	 *
	 * Kept on the rule because the index rows are rebuilt from it on every
	 * save; a cancellation written only to a row lasted until the next one.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $date    Date in Y-m-d format.
	 * @return bool
	 */
	public function add_cancellation( int $post_id, string $date ): bool {
		$rule = $this->get( $post_id );

		if ( null === $rule ) {
			return false;
		}

		$cancellations = $rule->cancellations;

		if ( ! in_array( $date, $cancellations, true ) ) {
			$cancellations[] = $date;
		}

		// upsert() leaves the columns it is not given, so this is the only one written.
		return $this->upsert( $post_id, [ 'cancellations' => $cancellations ] );
	}

	/**
	 * Copy cancellations that exist only as index rows onto their rules.
	 *
	 * Before cancellations were kept on the rule, cancelling an occurrence set
	 * the status of its index row and nothing else. The upgrade that adds the
	 * column rebuilds the index from the rules, which would put every such
	 * occurrence back as scheduled. Run once, before that rebuild.
	 *
	 * A series whose own status is cancelled has every row cancelled and no
	 * single occurrence called off; it is left alone.
	 *
	 * @return int Occurrences whose cancellation was copied.
	 */
	public function adopt_index_cancellations(): int {
		global $wpdb;

		$events_table = Schema::events_table();
		$rules_table  = Schema::recurrence_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT e.post_id, e.start_date FROM {$events_table} e
			INNER JOIN {$rules_table} r ON r.post_id = e.post_id
			WHERE e.status = 'cancelled'
			ORDER BY e.post_id, e.start_date"
		);
		// phpcs:enable

		$by_post = [];

		foreach ( (array) $rows as $row ) {
			$by_post[ (int) $row->post_id ][] = (string) $row->start_date;
		}

		$adopted = 0;

		foreach ( $by_post as $post_id => $dates ) {
			if ( 'cancelled' === get_post_meta( $post_id, 'blockendar_status', true ) ) {
				continue;
			}

			$rule = $this->get( $post_id );

			if ( null === $rule ) {
				continue;
			}

			$new = array_values( array_diff( array_unique( $dates ), $rule->cancellations ) );

			if ( ! empty( $new ) && $this->upsert( $post_id, [ 'cancellations' => array_merge( $rule->cancellations, $new ) ] ) ) {
				$adopted += count( $new );
			}
		}

		return $adopted;
	}

	// -------------------------------------------------------------------------
	// Sanitizers
	// -------------------------------------------------------------------------

	/**
	 * Coerce a list-ish value into a comma-separated string.
	 *
	 * upsert() is public and its list fields are stored as CSV, but callers
	 * reasonably hand over arrays — Rule exposes them that way. A bare
	 * (string) cast on an array yields the literal 'Array', which then fails
	 * every allowlist and silently erased the stored rule.
	 *
	 * @param mixed $value Raw value.
	 */
	private function to_csv( mixed $value ): ?string {
		if ( is_array( $value ) ) {
			return implode( ',', array_map( 'strval', $value ) );
		}

		if ( null === $value || ! is_scalar( $value ) ) {
			return null;
		}

		return (string) $value;
	}

	private function sanitize_csv( mixed $value, array $allowlist ): ?string {
		$value = $this->to_csv( $value );

		if ( null === $value || '' === $value ) {
			return null;
		}

		$parts   = array_map( 'trim', explode( ',', $value ) );
		$allowed = array_filter( $parts, fn( $v ) => in_array( $v, $allowlist, true ) );

		return ! empty( $allowed ) ? implode( ',', $allowed ) : null;
	}

	private function sanitize_int_csv( mixed $value, int $min, int $max ): ?string {
		$value = $this->to_csv( $value );

		if ( null === $value || '' === $value ) {
			return null;
		}

		$parts   = array_map( 'intval', explode( ',', $value ) );
		$allowed = array_filter( $parts, fn( $v ) => $v >= $min && $v <= $max && 0 !== $v );

		return ! empty( $allowed ) ? implode( ',', $allowed ) : null;
	}

	private function sanitize_date( mixed $value ): ?string {
		// Rule exposes until_date as a DateTimeImmutable, which has no string
		// cast; reaching (string) on one throws rather than storing a date.
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d' );
		}

		if ( null === $value || '' === $value || ! is_scalar( $value ) ) {
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
