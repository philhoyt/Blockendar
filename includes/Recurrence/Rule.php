<?php
/**
 * Recurrence rule value object (RFC 5545 RRULE semantics).
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Recurrence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object representing a recurrence rule.
 * Hydrated from a database row or a raw array.
 */
class Rule {

	const FREQ_DAILY   = 'daily';
	const FREQ_WEEKLY  = 'weekly';
	const FREQ_MONTHLY = 'monthly';
	const FREQ_YEARLY  = 'yearly';

	const ALLOWED_FREQUENCIES = [ self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_MONTHLY, self::FREQ_YEARLY ];

	// RFC 5545 weekday codes.
	const WEEKDAYS = [ 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' ];

	public readonly int $id;
	public readonly int $post_id;
	public readonly string $frequency;
	public readonly int $interval;

	/** @var string[] RFC 5545 weekday codes, e.g. ['MO','WE','FR'] */
	public readonly array $byday;

	/** @var int[] Day(s) of month, e.g. [1, 15] */
	public readonly array $bymonthday;

	/** @var int[] Set positions, e.g. [1] = first, [-1] = last */
	public readonly array $bysetpos;

	public readonly ?\DateTimeImmutable $until_date;
	public readonly ?int $count;

	/** @var string[] Excluded dates in Y-m-d format */
	public readonly array $exceptions;

	/** @var string[] Additional dates in Y-m-d format */
	public readonly array $additions;

	/**
	 * @param array $data Raw data array (from DB row or user input).
	 */
	public function __construct( array $data ) {
		$this->id        = (int) ( $data['id'] ?? 0 );
		$this->post_id   = (int) ( $data['post_id'] ?? 0 );
		$this->frequency = self::validate_frequency( (string) ( $data['frequency'] ?? '' ) );
		$this->interval  = max( 1, (int) ( $data['interval_val'] ?? 1 ) );

		$this->byday      = self::parse_csv_list( $data['byday'] ?? null, self::WEEKDAYS );
		$this->bymonthday = self::parse_int_list( $data['bymonthday'] ?? null, -31, 31 );
		$this->bysetpos   = self::parse_int_list( $data['bysetpos'] ?? null, -366, 366 );

		$this->until_date = self::parse_until_date( $data['until_date'] ?? null );
		$this->count      = isset( $data['count'] ) && null !== $data['count']
			? max( 1, (int) $data['count'] )
			: null;

		$this->exceptions = self::parse_json_dates( $data['exceptions'] ?? null );
		$this->additions  = self::parse_json_dates( $data['additions'] ?? null );
	}

	/**
	 * Hydrate from a wpdb row object.
	 */
	public static function from_db_row( object $row ): self {
		return new self( (array) $row );
	}

	/**
	 * Whether a given date (Y-m-d) is in the exceptions list.
	 */
	public function is_exception( string $date ): bool {
		return in_array( $date, $this->exceptions, true );
	}

	/**
	 * Whether the rule has a finite end condition.
	 */
	public function has_end(): bool {
		return null !== $this->until_date || null !== $this->count;
	}

	// -------------------------------------------------------------------------
	// Private parsers
	// -------------------------------------------------------------------------

	private static function validate_frequency( string $freq ): string {
		return in_array( $freq, self::ALLOWED_FREQUENCIES, true ) ? $freq : self::FREQ_WEEKLY;
	}

	/**
	 * Parse a comma-separated string against an allowlist.
	 *
	 * @param string|null $value     Raw value (e.g. "MO,WE,FR").
	 * @param string[]    $allowlist Valid values.
	 * @return string[]
	 */
	private static function parse_csv_list( ?string $value, array $allowlist ): array {
		if ( null === $value || '' === $value ) {
			return [];
		}

		$parts = array_map( 'trim', explode( ',', $value ) );

		return array_values( array_filter( $parts, fn( $v ) => in_array( $v, $allowlist, true ) ) );
	}

	/**
	 * Parse a comma-separated list of integers within a range.
	 *
	 * @param string|null $value Raw value (e.g. "1,15").
	 * @param int         $min   Minimum allowed value.
	 * @param int         $max   Maximum allowed value.
	 * @return int[]
	 */
	private static function parse_int_list( ?string $value, int $min, int $max ): array {
		if ( null === $value || '' === $value ) {
			return [];
		}

		$parts = array_map( 'intval', explode( ',', $value ) );

		return array_values(
			array_filter( $parts, fn( $v ) => $v >= $min && $v <= $max && 0 !== $v )
		);
	}

	/**
	 * Parse an until_date value into a DateTimeImmutable set to midnight UTC.
	 */
	private static function parse_until_date( mixed $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $value, new \DateTimeZone( 'UTC' ) );

		return $dt instanceof \DateTimeImmutable ? $dt->setTime( 23, 59, 59 ) : null;
	}

	/**
	 * Decode a JSON-encoded array of Y-m-d date strings.
	 *
	 * @return string[]
	 */
	private static function parse_json_dates( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return [];
		}

		if ( is_array( $value ) ) {
			$dates = $value;
		} else {
			$dates = json_decode( (string) $value, true );
		}

		if ( ! is_array( $dates ) ) {
			return [];
		}

		return array_values(
			array_filter(
				$dates,
				fn( $d ) => is_string( $d ) && (bool) \DateTimeImmutable::createFromFormat( 'Y-m-d', $d )
			)
		);
	}
}
