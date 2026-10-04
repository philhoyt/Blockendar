<?php
/**
 * The moment before which an event counts as past.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes the UTC datetime that separates upcoming events from past ones.
 *
 * The index stores every occurrence with a UTC end, and a listing decides
 * "upcoming" as "ends after the cutoff" and "past" as "ended by the cutoff".
 * Using the current moment as that cutoff drops an event the instant it ends
 * — and an event with no end time is indexed with end = start, so it vanished
 * at showtime. The rules here let a listing keep events for a while longer,
 * and because upcoming and past both derive from one value, an event is never
 * in both lists at once.
 */
class Cutoff {

	public const RULE_END   = 'end';
	public const RULE_DAY   = 'day';
	public const RULE_HOURS = 'hours';

	public const DEFAULT_RULE  = self::RULE_DAY;
	public const DEFAULT_HOURS = 3;
	public const MIN_HOURS     = 1;
	public const MAX_HOURS     = 72;

	public const FORMAT = 'Y-m-d H:i:s';

	/**
	 * A moment to use in place of the clock. For tests.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private static ?\DateTimeImmutable $frozen = null;

	/**
	 * Stop the clock at a moment, or start it again with null. For tests.
	 *
	 * @param \DateTimeImmutable|null $now The moment to report as now.
	 */
	public static function freeze( ?\DateTimeImmutable $now ): void {
		self::$frozen = $now;
	}

	/**
	 * The current moment as a range bound, in UTC, to the minute.
	 *
	 * A bound goes into the cache key of the query it is used in. To the
	 * second, the same listing asked a different question every second, and
	 * no request ever found another's answer. Rounded down, never up: an event
	 * with no end time is indexed as ending when it starts, and a bound past
	 * the present would drop it before it began.
	 *
	 * @param \DateTimeImmutable|null $now The current moment; defaults to now. For tests.
	 */
	public static function now( ?\DateTimeImmutable $now = null ): string {
		return self::minute( self::utc( $now ) )->format( self::FORMAT );
	}

	/**
	 * The far end of a listing's range, in UTC: the start of the day that is
	 * some way ahead.
	 *
	 * Nothing depends on where exactly a listing stops a year or three out, so
	 * it is given the coarsest value that will do and changes once a day.
	 *
	 * @param string                  $interval How far, as an ISO 8601 duration: "P1Y" is a year.
	 * @param \DateTimeImmutable|null $now      The current moment; defaults to now. For tests.
	 */
	public static function ahead( string $interval, ?\DateTimeImmutable $now = null ): string {
		return self::utc( $now )->add( new \DateInterval( $interval ) )->setTime( 0, 0, 0 )->format( self::FORMAT );
	}

	/**
	 * A moment with its seconds dropped.
	 *
	 * @param \DateTimeImmutable $moment The moment.
	 */
	private static function minute( \DateTimeImmutable $moment ): \DateTimeImmutable {
		return $moment->setTime( (int) $moment->format( 'G' ), (int) $moment->format( 'i' ), 0 );
	}

	/**
	 * The rules a listing may choose from.
	 *
	 * @return string[]
	 */
	public static function rules(): array {
		return [ self::RULE_END, self::RULE_DAY, self::RULE_HOURS ];
	}

	/**
	 * Cutoff for a rule, as a UTC datetime string.
	 *
	 * - end:   now, to the minute. The event leaves within a minute of ending.
	 * - day:   the start of the current day in the site timezone, so anything
	 *          that ended earlier today is still listed until midnight.
	 * - hours: now minus $hours, so the event stays that long after it ends.
	 *
	 * An unknown rule is treated as the default, and the hours are clamped to
	 * the range the editor offers.
	 *
	 * @param string                  $rule  One of rules().
	 * @param int                     $hours Hours for the hours rule.
	 * @param \DateTimeImmutable|null $now   The current moment; defaults to now. For tests.
	 */
	public static function for_rule( string $rule, int $hours = self::DEFAULT_HOURS, ?\DateTimeImmutable $now = null ): string {
		$now = self::utc( $now );

		if ( ! in_array( $rule, self::rules(), true ) ) {
			$rule = self::DEFAULT_RULE;
		}

		switch ( $rule ) {
			case self::RULE_END:
				return self::minute( $now )->format( self::FORMAT );

			case self::RULE_HOURS:
				$hours = max( self::MIN_HOURS, min( self::MAX_HOURS, $hours ) );
				return self::minute( $now )->sub( new \DateInterval( "PT{$hours}H" ) )->format( self::FORMAT );

			case self::RULE_DAY:
			default:
				return self::start_of_today( $now );
		}
	}

	/**
	 * Midnight at the start of the current day in the site timezone, in UTC.
	 *
	 * @param \DateTimeImmutable|null $now The current moment; defaults to now. For tests.
	 */
	public static function start_of_today( ?\DateTimeImmutable $now = null ): string {
		return self::utc( $now )
			->setTimezone( wp_timezone() )
			->setTime( 0, 0, 0 )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( self::FORMAT );
	}

	/**
	 * Accept a cutoff only when it is exactly a UTC datetime in FORMAT.
	 *
	 * The value reaches the index as a query parameter. Placeholders keep it
	 * out of the SQL, but a value in another format or timezone would compare
	 * as text against UTC columns and silently mean something else.
	 *
	 * @param mixed $value Candidate cutoff, typically from a filter.
	 * @return bool
	 */
	public static function is_valid( mixed $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!' . self::FORMAT, $value, new \DateTimeZone( 'UTC' ) );

		return $parsed instanceof \DateTimeImmutable && $parsed->format( self::FORMAT ) === $value;
	}

	/**
	 * The given moment, or now, as UTC.
	 *
	 * @param \DateTimeImmutable|null $now The current moment.
	 */
	private static function utc( ?\DateTimeImmutable $now ): \DateTimeImmutable {
		$utc = new \DateTimeZone( 'UTC' );

		$now ??= self::$frozen;

		return null === $now ? new \DateTimeImmutable( 'now', $utc ) : $now->setTimezone( $utc );
	}
}
