<?php
/**
 * Demo content definitions.
 *
 * Pure data. Every date is computed relative to the moment these methods are
 * called, which is what keeps the demo from expiring.
 *
 * Titles read as a plausible events site, but the underlying coverage matrix is
 * the one the old bin/generate-test-events.php script established: all four
 * statuses, all-day, multi-day, midnight-crossing, missing venue, missing type,
 * hidden, featured, capacity, registration URL, cost label, cost range, virtual
 * venue, and all five recurrence frequencies. Inline comments mark which case
 * each fixture exists to cover — do not "tidy" a fixture without reading them.
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

namespace Blockendar\Demo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static demo content definitions.
 */
class Fixtures {

	/**
	 * Y-m-d offset by $n days from today.
	 */
	public static function days( int $n ): string {
		return gmdate( 'Y-m-d', strtotime( "{$n} days" ) );
	}

	/**
	 * Event type terms, with inserter colours.
	 *
	 * @return array<int, array{name: string, slug: string, color: string}>
	 */
	public static function event_types(): array {
		return [
			[
				'name'  => 'Music',
				'slug'  => 'music',
				'color' => '#7c3aed',
			],
			[
				'name'  => 'Community',
				'slug'  => 'community',
				'color' => '#059669',
			],
			[
				'name'  => 'Sports',
				'slug'  => 'sports',
				'color' => '#dc2626',
			],
			[
				'name'  => 'Arts & Culture',
				'slug'  => 'arts-culture',
				'color' => '#d97706',
			],
			[
				'name'  => 'Food & Drink',
				'slug'  => 'food-drink',
				'color' => '#db2777',
			],
			[
				'name'  => 'Tech',
				'slug'  => 'tech',
				'color' => '#2563eb',
			],
		];
	}

	/**
	 * Venue terms and their term meta.
	 *
	 * Deliberately uneven: City Hall carries full meta, Rooftop Bar omits
	 * state/country, City Park omits capacity, and Online is virtual with no
	 * map data at all.
	 *
	 * @return array<int, array{name: string, slug: string, meta: array<string, string|int>}>
	 */
	public static function venues(): array {
		return [
			[
				'name' => 'City Hall',
				'slug' => 'city-hall',
				'meta' => [
					'blockendar_venue_address'  => '1 Main Street',
					'blockendar_venue_city'     => 'Springfield',
					'blockendar_venue_state'    => 'IL',
					'blockendar_venue_country'  => 'US',
					'blockendar_venue_lat'      => '39.7817',
					'blockendar_venue_lng'      => '-89.6501',
					'blockendar_venue_capacity' => 500,
				],
			],
			[
				'name' => 'Rooftop Bar',
				'slug' => 'rooftop-bar',
				'meta' => [
					'blockendar_venue_address'  => '55 Sky Ave, Floor 20',
					'blockendar_venue_city'     => 'Springfield',
					'blockendar_venue_lat'      => '39.7900',
					'blockendar_venue_lng'      => '-89.6440',
					'blockendar_venue_capacity' => 120,
				],
			],
			[
				'name' => 'City Park',
				'slug' => 'city-park',
				'meta' => [
					'blockendar_venue_address' => '200 Park Drive',
					'blockendar_venue_city'    => 'Springfield',
					'blockendar_venue_lat'     => '39.7750',
					'blockendar_venue_lng'     => '-89.6580',
				],
			],
			[
				'name' => 'Community Center',
				'slug' => 'community-center',
				'meta' => [
					'blockendar_venue_address'  => '88 Community Lane',
					'blockendar_venue_city'     => 'Springfield',
					'blockendar_venue_lat'      => '39.7830',
					'blockendar_venue_lng'      => '-89.6520',
					'blockendar_venue_capacity' => 200,
				],
			],
			[
				'name' => 'Online / Livestream',
				'slug' => 'online',
				'meta' => [
					'blockendar_venue_virtual' => '1',
				],
			],
		];
	}

	/**
	 * Single (non-recurring) events.
	 *
	 * `venue` and `types` hold slugs; Seeder resolves them to term IDs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function events(): array {
		$today = gmdate( 'Y-m-d' );

		return [

			// --- Past: verifies the calendar renders historical events. ---
			[
				'title'      => 'Opening Night Concert',
				'content'    => '<p>The season opener. Standing room only.</p>',
				'start_date' => self::days( -14 ),
				'end_date'   => self::days( -14 ),
				'start_time' => '19:00',
				'end_time'   => '22:00',
				'venue'      => 'city-hall',
				'types'      => [ 'music' ],
				'featured'   => true,
				'cost'       => '$25.00',
				'currency'   => 'USD',
				'image'      => 'music',
			],

			// Past, multi-day AND all-day.
			[
				'title'      => 'Spring Festival',
				'content'    => '<p>Three-day outdoor festival in the park.</p>',
				'start_date' => self::days( -10 ),
				'end_date'   => self::days( -8 ),
				'all_day'    => true,
				'venue'      => 'city-park',
				'types'      => [ 'music', 'community' ],
				'featured'   => true,
				'cost'       => '$15',
				'image'      => 'community',
			],

			// Past, timed, free.
			[
				'title'      => 'Community Breakfast',
				'content'    => '<p>Free breakfast for all residents.</p>',
				'start_date' => self::days( -7 ),
				'end_date'   => self::days( -7 ),
				'start_time' => '08:00',
				'end_time'   => '10:30',
				'venue'      => 'community-center',
				'types'      => [ 'community' ],
				'cost'       => 'Free',
			],

			// --- Today. ---

			// Sold out + featured.
			[
				'title'      => 'Jazz Night',
				'content'    => '<p>Live jazz every Wednesday night on the rooftop.</p>',
				'start_date' => $today,
				'end_date'   => $today,
				'start_time' => '20:00',
				'end_time'   => '23:00',
				'venue'      => 'rooftop-bar',
				'types'      => [ 'music' ],
				'cost'       => '$15.00',
				'featured'   => true,
				'status'     => 'sold_out',
				'image'      => 'music',
			],

			// Currently in progress: started an hour ago, ends in two.
			[
				'title'      => 'Community Forum',
				'content'    => '<p>Happening right now — open Q&amp;A with local leaders.</p>',
				'start_date' => $today,
				'end_date'   => $today,
				'start_time' => gmdate( 'H:i', strtotime( '-1 hour' ) ),
				'end_time'   => gmdate( 'H:i', strtotime( '+2 hours' ) ),
				'venue'      => 'city-hall',
				'types'      => [ 'community' ],
			],

			// --- This week. ---

			// Free, multi-type.
			[
				'title'      => 'Morning Yoga in the Park',
				'content'    => '<p>All levels welcome. Bring your own mat.</p>',
				'start_date' => self::days( 1 ),
				'end_date'   => self::days( 1 ),
				'start_time' => '07:00',
				'end_time'   => '08:00',
				'venue'      => 'city-park',
				'types'      => [ 'sports', 'community' ],
				'cost'       => 'Free',
			],

			// All-day single day, with capacity + registration URL.
			[
				'title'            => 'All-Day Maker Workshop',
				'content'          => '<p>Full-day electronics and coding workshop. Spots are limited.</p>',
				'start_date'       => self::days( 2 ),
				'end_date'         => self::days( 2 ),
				'all_day'          => true,
				'venue'            => 'community-center',
				'types'            => [ 'tech', 'community' ],
				'capacity'         => '30',
				'registration_url' => 'https://example.com/register',
			],

			// No cost field at all.
			[
				'title'      => 'Neighbourhood Clean-Up',
				'content'    => '<p>Volunteers welcome. Gloves and bags provided.</p>',
				'start_date' => self::days( 3 ),
				'end_date'   => self::days( 3 ),
				'start_time' => '09:00',
				'end_time'   => '12:00',
				'venue'      => 'city-park',
				'types'      => [ 'community' ],
			],

			// Hidden from listings — was "Hidden VIP Preview".
			[
				'title'      => 'Members-Only Preview Night',
				'content'    => '<p>Private preview for members. Not listed on the public calendar.</p>',
				'start_date' => self::days( 3 ),
				'end_date'   => self::days( 3 ),
				'start_time' => '12:00',
				'end_time'   => '14:00',
				'hide'       => true,
				'venue'      => 'city-hall',
				'types'      => [ 'arts-culture' ],
				'cost'       => '$200.00',
			],

			// --- Next 31 days. ---

			// Cost label AND cost_min/cost_max together.
			[
				'title'      => 'Acoustic Sessions',
				'content'    => '<p>Intimate acoustic performances. Price varies by act.</p>',
				'start_date' => self::days( 5 ),
				'end_date'   => self::days( 5 ),
				'start_time' => '18:30',
				'end_time'   => '21:00',
				'venue'      => 'rooftop-bar',
				'types'      => [ 'music' ],
				'cost'       => '$10–$25',
				'cost_min'   => '10',
				'cost_max'   => '25',
				'currency'   => 'USD',
			],

			// Explicit "Free" label, no min/max.
			[
				'title'      => 'Free Outdoor Film Screening',
				'content'    => '<p>Free outdoor film. Bring a blanket.</p>',
				'start_date' => self::days( 6 ),
				'end_date'   => self::days( 6 ),
				'start_time' => '21:00',
				'end_time'   => '23:30',
				'venue'      => 'city-park',
				'types'      => [ 'community', 'arts-culture' ],
				'cost'       => 'Free',
			],

			// Virtual venue + registration URL.
			[
				'title'            => 'Online Webinar: Tech Talk',
				'content'          => '<p>Learn about the latest in web development.</p>',
				'start_date'       => self::days( 7 ),
				'end_date'         => self::days( 7 ),
				'start_time'       => '14:00',
				'end_time'         => '15:30',
				'venue'            => 'online',
				'types'            => [ 'tech' ],
				'registration_url' => 'https://example.com/webinar',
				'image'            => 'tech',
			],

			[
				'title'      => 'Town Hall Meeting',
				'content'    => '<p>Quarterly public meeting. All residents welcome.</p>',
				'start_date' => self::days( 8 ),
				'end_date'   => self::days( 8 ),
				'start_time' => '18:00',
				'end_time'   => '20:00',
				'venue'      => 'city-hall',
				'types'      => [ 'community' ],
			],

			// NO event type assigned — exercises the type-colour fallback.
			// Was "No-Type General Meeting".
			[
				'title'      => 'Neighbourhood Assembly',
				'content'    => '<p>Monthly open meeting for residents.</p>',
				'start_date' => self::days( 9 ),
				'end_date'   => self::days( 9 ),
				'start_time' => '11:00',
				'end_time'   => '12:00',
				'venue'      => 'community-center',
			],

			// Cancelled status. Was "Cancelled Workshop".
			[
				'title'      => 'Intro to Robotics Workshop',
				'content'    => '<p>This event was cancelled due to low enrolment.</p>',
				'start_date' => self::days( 10 ),
				'end_date'   => self::days( 10 ),
				'start_time' => '17:00',
				'end_time'   => '19:00',
				'status'     => 'cancelled',
				'venue'      => 'community-center',
				'types'      => [ 'tech' ],
			],

			[
				'title'            => 'Charity 5K Run',
				'content'          => '<p>All proceeds go to the local food bank. Registration required.</p>',
				'start_date'       => self::days( 11 ),
				'end_date'         => self::days( 11 ),
				'start_time'       => '08:00',
				'end_time'         => '12:00',
				'venue'            => 'city-park',
				'types'            => [ 'sports', 'community' ],
				'cost'             => '$20.00',
				'capacity'         => '500',
				'registration_url' => 'https://example.com/5k-run',
				'image'            => 'sports',
			],

			[
				'title'      => 'Art Gallery Opening',
				'content'    => '<p>Local artists exhibit new works. Wine and cheese reception.</p>',
				'start_date' => self::days( 12 ),
				'end_date'   => self::days( 12 ),
				'start_time' => '17:00',
				'end_time'   => '20:00',
				'venue'      => 'city-hall',
				'types'      => [ 'arts-culture' ],
				'cost'       => 'Free',
				'image'      => 'arts',
			],

			[
				'title'      => 'Summer Kick-Off Concert',
				'content'    => '<p>Opening the summer season with a bang. Fireworks to follow.</p>',
				'start_date' => self::days( 14 ),
				'end_date'   => self::days( 14 ),
				'start_time' => '19:00',
				'end_time'   => '23:30',
				'venue'      => 'city-hall',
				'types'      => [ 'music' ],
				'cost'       => '$35.00',
				'currency'   => 'USD',
				'featured'   => true,
				'capacity'   => '200',
				'image'      => 'music',
			],

			// cost_min/cost_max with NO display label — exercises the
			// derived-range path in the cost block. Was
			// "Cost Range — Min $50 / Max $200".
			[
				'title'      => 'Winter Symphony Gala',
				'content'    => '<p>An evening with the city symphony orchestra.</p>',
				'start_date' => self::days( 15 ),
				'end_date'   => self::days( 15 ),
				'start_time' => '18:00',
				'end_time'   => '21:00',
				'venue'      => 'city-hall',
				'types'      => [ 'music' ],
				'cost_min'   => '50',
				'cost_max'   => '200',
				'currency'   => 'USD',
			],

			// Multi-day TIMED (not all-day) — spans 3 days with set hours.
			[
				'title'      => 'Food & Wine Festival',
				'content'    => '<p>Tasting stations, live cooking demos, and wine pairings.</p>',
				'start_date' => self::days( 16 ),
				'end_date'   => self::days( 18 ),
				'start_time' => '11:00',
				'end_time'   => '22:00',
				'venue'      => 'city-park',
				'types'      => [ 'food-drink', 'community' ],
				'cost'       => 'From $45',
				'cost_min'   => '45',
				'cost_max'   => '120',
				'currency'   => 'USD',
				'featured'   => true,
				'image'      => 'food',
			],

			// Multi-day all-day, and NO venue assigned.
			[
				'title'      => 'Riverside Music Festival',
				'content'    => '<p>Four days of non-stop music across three stages.</p>',
				'start_date' => self::days( 20 ),
				'end_date'   => self::days( 23 ),
				'all_day'    => true,
				'types'      => [ 'music' ],
				'featured'   => true,
				'cost'       => 'From $99',
				'cost_min'   => '99',
				'cost_max'   => '299',
				'image'      => 'music',
			],

			// No venue — verifies graceful fallback in the venue block.
			// Was "No-Venue Online Talk".
			[
				'title'      => 'Remote Design Q&amp;A',
				'content'    => '<p>An open question-and-answer session with the design team.</p>',
				'start_date' => self::days( 22 ),
				'end_date'   => self::days( 22 ),
				'start_time' => '10:00',
				'end_time'   => '11:00',
				'types'      => [ 'tech' ],
			],

			// Crosses midnight: 22:00 one day to 03:00 the next.
			[
				'title'      => 'Late Night DJ Set',
				'content'    => '<p>House and techno until 3 AM.</p>',
				'start_date' => self::days( 25 ),
				'end_date'   => self::days( 26 ),
				'start_time' => '22:00',
				'end_time'   => '03:00',
				'venue'      => 'rooftop-bar',
				'types'      => [ 'music' ],
				'cost'       => '$20.00',
			],

			// Postponed status.
			[
				'title'      => 'Postponed Gala Evening',
				'content'    => '<p>Originally scheduled for last month. New date to be confirmed.</p>',
				'start_date' => self::days( 28 ),
				'end_date'   => self::days( 28 ),
				'start_time' => '19:00',
				'end_time'   => '23:00',
				'venue'      => 'city-hall',
				'types'      => [ 'arts-culture', 'community' ],
				'status'     => 'postponed',
				'cost'       => '$60.00',
			],

			// --- Beyond 31 days: month view only, not the default list view. ---

			// Also crosses midnight, far out.
			[
				'title'      => 'New Year\'s Eve Gala',
				'content'    => '<p>Black tie event to ring in the new year. Limited capacity.</p>',
				'start_date' => self::days( 45 ),
				'end_date'   => self::days( 46 ),
				'start_time' => '20:00',
				'end_time'   => '01:00',
				'venue'      => 'city-hall',
				'types'      => [ 'music', 'community' ],
				'cost'       => '$75.00',
				'currency'   => 'USD',
				'featured'   => true,
				'capacity'   => '150',
				'image'      => 'gala',
			],

			[
				'title'      => 'Annual Community Picnic',
				'content'    => '<p>Bring your own food. Games and live music all day.</p>',
				'start_date' => self::days( 60 ),
				'end_date'   => self::days( 60 ),
				'all_day'    => true,
				'venue'      => 'city-park',
				'types'      => [ 'community' ],
				'cost'       => 'Free',
				'image'      => 'market',
			],
		];
	}

	/**
	 * Recurring events: one fixture per supported frequency.
	 *
	 * byday / bymonthday / bysetpos are anchored to today so the series always
	 * produces visible occurrences regardless of when the demo is activated.
	 *
	 * @return array<int, array{event: array<string, mixed>, rule: array<string, mixed>}>
	 */
	public static function recurring(): array {
		$today       = gmdate( 'Y-m-d' );
		$dow_to_rfc  = [
			1 => 'MO',
			2 => 'TU',
			3 => 'WE',
			4 => 'TH',
			5 => 'FR',
			6 => 'SA',
			7 => 'SU',
		];
		$dow_labels  = [
			'MO' => 'Monday',
			'TU' => 'Tuesday',
			'WE' => 'Wednesday',
			'TH' => 'Thursday',
			'FR' => 'Friday',
			'SA' => 'Saturday',
			'SU' => 'Sunday',
		];
		$pos_labels  = [
			1 => '1st',
			2 => '2nd',
			3 => '3rd',
			4 => '4th',
			5 => '5th',
		];
		$today_byday = $dow_to_rfc[ (int) gmdate( 'N' ) ];
		$today_dom   = (int) gmdate( 'j' );
		$today_wpos  = (int) ceil( $today_dom / 7 );
		$pos_title   = ( $pos_labels[ $today_wpos ] ?? "{$today_wpos}th" ) . ' ' . ( $dow_labels[ $today_byday ] ?? $today_byday );

		return [
			// WEEKLY, bounded by until_date.
			[
				'event' => [
					'title'      => 'Weekly Open Mic Night',
					'content'    => '<p>Sign up at the door. All genres welcome.</p>',
					'start_date' => $today,
					'end_date'   => $today,
					'start_time' => '19:00',
					'end_time'   => '22:00',
					'venue'      => 'rooftop-bar',
					'types'      => [ 'music' ],
					'cost'       => '$5.00',
					'image'      => 'music',
				],
				'rule'  => [
					'frequency'    => 'weekly',
					'interval_val' => 1,
					'byday'        => $today_byday,
					'until_date'   => self::days( 90 ),
				],
			],

			// MONTHLY by day-of-month.
			[
				'event' => [
					'title'      => 'Monthly Board Meeting',
					'content'    => '<p>Open to the public. Agenda published one week prior.</p>',
					'start_date' => $today,
					'end_date'   => $today,
					'start_time' => '17:30',
					'end_time'   => '19:00',
					'venue'      => 'city-hall',
					'types'      => [ 'community' ],
				],
				'rule'  => [
					'frequency'    => 'monthly',
					'interval_val' => 1,
					'bymonthday'   => (string) $today_dom,
					'until_date'   => self::days( 180 ),
				],
			],

			// MONTHLY by set position (e.g. 3rd Thursday).
			[
				'event' => [
					'title'      => "Community Support Circle ({$pos_title} of each month)",
					'content'    => '<p>Monthly support group. Confidential and welcoming.</p>',
					'start_date' => $today,
					'end_date'   => $today,
					'start_time' => '18:00',
					'end_time'   => '19:30',
					'venue'      => 'community-center',
					'types'      => [ 'community' ],
				],
				'rule'  => [
					'frequency'    => 'monthly',
					'interval_val' => 1,
					'byday'        => $today_byday,
					'bysetpos'     => (string) $today_wpos,
					'until_date'   => self::days( 180 ),
				],
			],

			// DAILY, bounded by count rather than until_date.
			[
				'event' => [
					'title'      => 'Daily Tech Standup',
					'content'    => '<p>15-minute daily sync for the volunteer team.</p>',
					'start_date' => $today,
					'end_date'   => $today,
					'start_time' => '09:00',
					'end_time'   => '09:15',
					'venue'      => 'online',
					'types'      => [ 'tech' ],
				],
				'rule'  => [
					'frequency'    => 'daily',
					'interval_val' => 1,
					'count'        => 14,
				],
			],

			// YEARLY. Deliberately produces occurrences years out, which is why
			// the "within ±180 days" assertion applies to POSTS, not index rows.
			[
				'event' => [
					'title'      => 'Annual Charity Gala',
					'content'    => '<p>Our flagship annual fundraiser. Black tie optional.</p>',
					'start_date' => $today,
					'end_date'   => $today,
					'start_time' => '18:00',
					'end_time'   => '22:00',
					'venue'      => 'city-hall',
					'types'      => [ 'community', 'arts-culture' ],
					'cost'       => '$100.00',
					'currency'   => 'USD',
					'featured'   => true,
					'image'      => 'gala',
				],
				'rule'  => [
					'frequency'    => 'yearly',
					'interval_val' => 1,
					'count'        => 3,
				],
			],
		];
	}
}
