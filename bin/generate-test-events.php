<?php
/**
 * Blockendar — test event seeder.
 *
 * Run from the WordPress root:
 *   wp eval-file wp-content/plugins/blockendar/bin/generate-test-events.php
 *
 * Creates:
 *  - 2 event types (Music, Community) with colours
 *  - 3 venues (City Hall, Rooftop Bar, Online)
 *  - ~20 events spread across past / this week / next 31 days / far future
 *    covering: timed, all-day, multi-day, featured, recurring, cancelled
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Run via: wp eval-file path/to/generate-test-events.php' );
}

WP_CLI::log( '=== Blockendar test event seeder ===' );

// ---------------------------------------------------------------------------
// 1. Event Types
// ---------------------------------------------------------------------------

$type_music = wp_insert_term( 'Music', 'event_type', [ 'slug' => 'music' ] );
if ( is_wp_error( $type_music ) && isset( $type_music->error_data['term_exists'] ) ) {
	$type_music = [ 'term_id' => $type_music->error_data['term_exists'] ];
}
update_term_meta( $type_music['term_id'], 'blockendar_type_color', '#7c3aed' );

$type_community = wp_insert_term( 'Community', 'event_type', [ 'slug' => 'community' ] );
if ( is_wp_error( $type_community ) && isset( $type_community->error_data['term_exists'] ) ) {
	$type_community = [ 'term_id' => $type_community->error_data['term_exists'] ];
}
update_term_meta( $type_community['term_id'], 'blockendar_type_color', '#059669' );

WP_CLI::log( "  Created event types: Music ({$type_music['term_id']}), Community ({$type_community['term_id']})" );

// ---------------------------------------------------------------------------
// 2. Venues
// ---------------------------------------------------------------------------

$venue_hall = wp_insert_term( 'City Hall', 'event_venue', [ 'slug' => 'city-hall' ] );
if ( is_wp_error( $venue_hall ) && isset( $venue_hall->error_data['term_exists'] ) ) {
	$venue_hall = [ 'term_id' => $venue_hall->error_data['term_exists'] ];
}
update_term_meta( $venue_hall['term_id'], 'blockendar_venue_address',  '1 Main Street' );
update_term_meta( $venue_hall['term_id'], 'blockendar_venue_city',     'Springfield' );
update_term_meta( $venue_hall['term_id'], 'blockendar_venue_state',    'IL' );
update_term_meta( $venue_hall['term_id'], 'blockendar_venue_country',  'US' );
update_term_meta( $venue_hall['term_id'], 'blockendar_venue_lat',      '39.7817' );
update_term_meta( $venue_hall['term_id'], 'blockendar_venue_lng',      '-89.6501' );
update_term_meta( $venue_hall['term_id'], 'blockendar_venue_capacity', 500 );

$venue_rooftop = wp_insert_term( 'Rooftop Bar', 'event_venue', [ 'slug' => 'rooftop-bar' ] );
if ( is_wp_error( $venue_rooftop ) && isset( $venue_rooftop->error_data['term_exists'] ) ) {
	$venue_rooftop = [ 'term_id' => $venue_rooftop->error_data['term_exists'] ];
}
update_term_meta( $venue_rooftop['term_id'], 'blockendar_venue_address', '55 Sky Ave, Floor 20' );
update_term_meta( $venue_rooftop['term_id'], 'blockendar_venue_city',    'Springfield' );
update_term_meta( $venue_rooftop['term_id'], 'blockendar_venue_lat',     '39.7900' );
update_term_meta( $venue_rooftop['term_id'], 'blockendar_venue_lng',     '-89.6440' );

$venue_online = wp_insert_term( 'Online / Livestream', 'event_venue', [ 'slug' => 'online' ] );
if ( is_wp_error( $venue_online ) && isset( $venue_online->error_data['term_exists'] ) ) {
	$venue_online = [ 'term_id' => $venue_online->error_data['term_exists'] ];
}
update_term_meta( $venue_online['term_id'], 'blockendar_venue_virtual', '1' );

WP_CLI::log( "  Created venues: City Hall, Rooftop Bar, Online" );

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

/**
 * Create a single blockendar_event post with meta + taxonomy terms,
 * then trigger index building.
 */
function blockendar_seed_event( array $args ): int {
	$defaults = [
		'title'            => 'Test Event',
		'content'          => '',
		'start_date'       => date( 'Y-m-d' ),
		'end_date'         => date( 'Y-m-d' ),
		'start_time'       => '09:00',
		'end_time'         => '10:00',
		'all_day'          => false,
		'timezone'         => 'America/Chicago',
		'status'           => 'scheduled',
		'cost'             => '',
		'currency'         => 'USD',
		'registration_url' => '',
		'capacity'         => '',
		'featured'         => false,
		'hide'             => false,
		'venue_term_id'    => null,
		'type_term_ids'    => [],
	];

	$a = wp_parse_args( $args, $defaults );

	$post_id = wp_insert_post( [
		'post_type'    => 'blockendar_event',
		'post_title'   => $a['title'],
		'post_content' => $a['content'],
		'post_status'  => 'publish',
	], true );

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( "  Failed to create '{$a['title']}': " . $post_id->get_error_message() );
		return 0;
	}

	$meta_map = [
		'blockendar_start_date'       => $a['start_date'],
		'blockendar_end_date'         => $a['end_date'],
		'blockendar_start_time'       => $a['start_time'],
		'blockendar_end_time'         => $a['end_time'],
		'blockendar_all_day'          => $a['all_day'] ? '1' : '0',
		'blockendar_timezone'         => $a['timezone'],
		'blockendar_status'           => $a['status'],
		'blockendar_cost'             => $a['cost'],
		'blockendar_currency'         => $a['currency'],
		'blockendar_registration_url' => $a['registration_url'],
		'blockendar_capacity'         => $a['capacity'],
		'blockendar_featured'         => $a['featured'] ? '1' : '0',
		'blockendar_hide_from_listings' => $a['hide'] ? '1' : '0',
	];

	foreach ( $meta_map as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	if ( $a['venue_term_id'] ) {
		wp_set_object_terms( $post_id, (int) $a['venue_term_id'], 'event_venue' );
	}

	if ( ! empty( $a['type_term_ids'] ) ) {
		wp_set_object_terms( $post_id, array_map( 'intval', $a['type_term_ids'] ), 'event_type' );
	}

	// Trigger index build now that meta is written.
	$builder = new Blockendar\DB\IndexBuilder();
	$builder->build_for_post( $post_id );

	return $post_id;
}

// ---------------------------------------------------------------------------
// Shorthand date helpers
// ---------------------------------------------------------------------------

$today = date( 'Y-m-d' );

function days( int $n ): string {
	return date( 'Y-m-d', strtotime( "$n days" ) );
}

// ---------------------------------------------------------------------------
// 3. Seed events
// ---------------------------------------------------------------------------

WP_CLI::log( '  Seeding events...' );

$events = [

	// --- Past ---

	[
		'title'         => 'Opening Night Concert (Past)',
		'content'       => 'The season opener. Standing room only.',
		'start_date'    => days( -14 ),
		'end_date'      => days( -14 ),
		'start_time'    => '19:00',
		'end_time'      => '22:00',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_music['term_id'] ],
		'featured'      => true,
		'cost'          => '25.00',
	],
	[
		'title'         => 'Community Breakfast (Past)',
		'content'       => 'Free breakfast for all residents.',
		'start_date'    => days( -7 ),
		'end_date'      => days( -7 ),
		'start_time'    => '08:00',
		'end_time'      => '10:30',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_community['term_id'] ],
	],
	[
		'title'      => 'Spring Festival (Past, Multi-day)',
		'content'    => 'Three-day outdoor festival.',
		'start_date' => days( -10 ),
		'end_date'   => days( -8 ),
		'all_day'    => true,
		'type_term_ids' => [ $type_music['term_id'], $type_community['term_id'] ],
		'featured'   => true,
	],

	// --- This week ---

	[
		'title'         => 'Jazz Night',
		'content'       => 'Live jazz every Wednesday.',
		'start_date'    => $today,
		'end_date'      => $today,
		'start_time'    => '20:00',
		'end_time'      => '23:00',
		'venue_term_id' => $venue_rooftop['term_id'],
		'type_term_ids' => [ $type_music['term_id'] ],
		'cost'          => '15.00',
		'featured'      => true,
	],
	[
		'title'         => 'Neighbourhood Clean-Up',
		'content'       => 'Volunteers welcome. Gloves provided.',
		'start_date'    => days( 1 ),
		'end_date'      => days( 1 ),
		'start_time'    => '09:00',
		'end_time'      => '12:00',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_community['term_id'] ],
	],
	[
		'title'      => 'All-Day Workshop',
		'content'    => 'Full-day maker workshop.',
		'start_date' => days( 2 ),
		'end_date'   => days( 2 ),
		'all_day'    => true,
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_community['term_id'] ],
		'capacity'   => '30',
		'registration_url' => 'https://example.com/register',
	],

	// --- Next 31 days ---

	[
		'title'         => 'Acoustic Sessions',
		'content'       => 'Intimate acoustic performances.',
		'start_date'    => days( 5 ),
		'end_date'      => days( 5 ),
		'start_time'    => '18:30',
		'end_time'      => '21:00',
		'venue_term_id' => $venue_rooftop['term_id'],
		'type_term_ids' => [ $type_music['term_id'] ],
		'cost'          => '10.00',
	],
	[
		'title'         => 'Town Hall Meeting',
		'content'       => 'Quarterly public meeting.',
		'start_date'    => days( 8 ),
		'end_date'      => days( 8 ),
		'start_time'    => '18:00',
		'end_time'      => '20:00',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_community['term_id'] ],
	],
	[
		'title'         => 'Online Webinar: Event Planning 101',
		'content'       => 'Learn how to organise community events.',
		'start_date'    => days( 10 ),
		'end_date'      => days( 10 ),
		'start_time'    => '14:00',
		'end_time'      => '15:30',
		'venue_term_id' => $venue_online['term_id'],
		'type_term_ids' => [ $type_community['term_id'] ],
		'registration_url' => 'https://example.com/webinar',
	],
	[
		'title'         => 'Summer Kick-Off Concert',
		'content'       => 'Opening the summer season with a bang.',
		'start_date'    => days( 14 ),
		'end_date'      => days( 14 ),
		'start_time'    => '19:00',
		'end_time'      => '23:30',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_music['term_id'] ],
		'cost'          => '35.00',
		'featured'      => true,
		'capacity'      => '200',
	],
	[
		'title'      => 'Multi-Day Music Festival',
		'content'    => 'Four days of non-stop music.',
		'start_date' => days( 18 ),
		'end_date'   => days( 21 ),
		'all_day'    => true,
		'type_term_ids' => [ $type_music['term_id'] ],
		'featured'   => true,
		'cost'       => '99.00',
	],
	[
		'title'         => 'Volunteer Orientation',
		'content'       => 'Onboarding for new volunteers.',
		'start_date'    => days( 22 ),
		'end_date'      => days( 22 ),
		'start_time'    => '10:00',
		'end_time'      => '11:30',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_community['term_id'] ],
	],
	[
		'title'         => 'Late Night DJ Set',
		'content'       => 'House and techno until 3 AM.',
		'start_date'    => days( 25 ),
		'end_date'      => days( 25 ),
		'start_time'    => '22:00',
		'end_time'      => '03:00',
		'venue_term_id' => $venue_rooftop['term_id'],
		'type_term_ids' => [ $type_music['term_id'] ],
		'cost'          => '20.00',
	],

	// --- Beyond 31 days (should appear in month view but not list view) ---

	[
		'title'         => 'New Year\'s Eve Gala',
		'content'       => 'Black tie event to ring in the new year.',
		'start_date'    => days( 45 ),
		'end_date'      => days( 45 ),
		'start_time'    => '20:00',
		'end_time'      => '01:00',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_music['term_id'], $type_community['term_id'] ],
		'cost'          => '75.00',
		'featured'      => true,
		'capacity'      => '150',
	],
	[
		'title'      => 'Annual Community Picnic',
		'content'    => 'Bring your own food and join us outside.',
		'start_date' => days( 60 ),
		'end_date'   => days( 60 ),
		'all_day'    => true,
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_community['term_id'] ],
	],

	// --- Edge cases ---

	[
		'title'      => 'Cancelled Event',
		'content'    => 'This event was cancelled.',
		'start_date' => days( 12 ),
		'end_date'   => days( 12 ),
		'start_time' => '17:00',
		'end_time'   => '19:00',
		'status'     => 'cancelled',
		'type_term_ids' => [ $type_community['term_id'] ],
	],
	[
		'title'      => 'Hidden Event (not in listings)',
		'content'    => 'This event is hidden from the calendar.',
		'start_date' => days( 3 ),
		'end_date'   => days( 3 ),
		'start_time' => '12:00',
		'end_time'   => '13:00',
		'hide'       => true,
	],
	[
		'title'         => 'Free Event (no cost)',
		'content'       => 'Completely free to attend.',
		'start_date'    => days( 6 ),
		'end_date'      => days( 6 ),
		'start_time'    => '11:00',
		'end_time'      => '13:00',
		'venue_term_id' => $venue_online['term_id'],
		'type_term_ids' => [ $type_community['term_id'] ],
		'cost'          => '0',
	],
];

$created = 0;
foreach ( $events as $event ) {
	$id = blockendar_seed_event( $event );
	if ( $id ) {
		WP_CLI::log( "  + [{$id}] {$event['title']}" );
		$created++;
	}
}

// ---------------------------------------------------------------------------
// 4. Weekly recurring event
// ---------------------------------------------------------------------------

WP_CLI::log( '  Seeding recurring event (weekly open mic)...' );

$open_mic_id = blockendar_seed_event( [
	'title'         => 'Weekly Open Mic Night',
	'content'       => 'Sign up at the door. All genres welcome.',
	'start_date'    => $today,
	'end_date'      => $today,
	'start_time'    => '19:00',
	'end_time'      => '22:00',
	'venue_term_id' => $venue_rooftop['term_id'],
	'type_term_ids' => [ $type_music['term_id'] ],
	'cost'          => '5.00',
] );

if ( $open_mic_id ) {
	global $wpdb;
	$recurrence_table = $wpdb->prefix . 'blockendar_recurrence';
	$wpdb->insert( $recurrence_table, [
		'post_id'    => $open_mic_id,
		'frequency'  => 'WEEKLY',
		'interval'   => 1,
		'byday'      => json_encode( [ strtoupper( date( 'D' ) ) ] ),
		'until_date' => days( 90 ),
		'created_at' => current_time( 'mysql', true ),
		'updated_at' => current_time( 'mysql', true ),
	] );

	// Trigger full recurrence expansion.
	do_action( 'blockendar_generate_recurrence_index', $open_mic_id );

	WP_CLI::log( "  + [{$open_mic_id}] Weekly Open Mic Night (recurring, 90 days)" );
	$created++;
}

// ---------------------------------------------------------------------------
// 5. Monthly recurring event
// ---------------------------------------------------------------------------

WP_CLI::log( '  Seeding recurring event (monthly board meeting)...' );

$board_id = blockendar_seed_event( [
	'title'         => 'Monthly Board Meeting',
	'content'       => 'Open to the public.',
	'start_date'    => $today,
	'end_date'      => $today,
	'start_time'    => '17:30',
	'end_time'      => '19:00',
	'venue_term_id' => $venue_hall['term_id'],
	'type_term_ids' => [ $type_community['term_id'] ],
] );

if ( $board_id ) {
	global $wpdb;
	$recurrence_table = $wpdb->prefix . 'blockendar_recurrence';
	$wpdb->insert( $recurrence_table, [
		'post_id'    => $board_id,
		'frequency'  => 'MONTHLY',
		'interval'   => 1,
		'bymonthday' => json_encode( [ (int) date( 'j' ) ] ),
		'until_date' => days( 180 ),
		'created_at' => current_time( 'mysql', true ),
		'updated_at' => current_time( 'mysql', true ),
	] );

	do_action( 'blockendar_generate_recurrence_index', $board_id );

	WP_CLI::log( "  + [{$board_id}] Monthly Board Meeting (recurring, 180 days)" );
	$created++;
}

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------

WP_CLI::success( "Created {$created} events. Visit your calendar block to review them." );
