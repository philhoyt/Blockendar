<?php
/**
 * Blockendar — comprehensive test event seeder.
 *
 * Run from the WordPress root:
 *   wp eval-file wp-content/plugins/blockendar/bin/generate-test-events.php
 *
 * Featured images (optional):
 *   Set the UNSPLASH_ACCESS_KEY environment variable to fetch real photos from
 *   the Unsplash API (free key at https://unsplash.com/developers).
 *   Without a key, picsum.photos is used instead — no account required.
 *
 * Creates:
 *  - 6 event types with colours (Music, Community, Sports, Arts, Food, Tech)
 *  - 5 venues (City Hall, Rooftop Bar, City Park, Community Center, Online)
 *  - ~30 events covering every status, time pattern, cost variant, and flag
 *  - 5 recurring rules (weekly, monthly/day, monthly/position, daily, yearly)
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Run via: wp eval-file path/to/generate-test-events.php' );
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

// Unsplash API access key (optional). Falls back to picsum.photos if empty.
define( 'BLOCKENDAR_UNSPLASH_KEY', (string) ( getenv( 'UNSPLASH_ACCESS_KEY' ) ?: '' ) );

WP_CLI::log( '=== Blockendar comprehensive test event seeder ===' );
WP_CLI::log( '  Image source: ' . ( BLOCKENDAR_UNSPLASH_KEY ? 'Unsplash API' : 'picsum.photos (set UNSPLASH_ACCESS_KEY env var for Unsplash)' ) );

// ---------------------------------------------------------------------------
// 1. Event Types
// ---------------------------------------------------------------------------

$type_defs = [
	[ 'name' => 'Music',         'slug' => 'music',        'color' => '#7c3aed' ],
	[ 'name' => 'Community',     'slug' => 'community',    'color' => '#059669' ],
	[ 'name' => 'Sports',        'slug' => 'sports',       'color' => '#dc2626' ],
	[ 'name' => 'Arts & Culture','slug' => 'arts-culture', 'color' => '#d97706' ],
	[ 'name' => 'Food & Drink',  'slug' => 'food-drink',   'color' => '#db2777' ],
	[ 'name' => 'Tech',          'slug' => 'tech',         'color' => '#2563eb' ],
];

$type_ids = [];
foreach ( $type_defs as $def ) {
	$term = wp_insert_term( $def['name'], 'event_type', [ 'slug' => $def['slug'] ] );
	if ( is_wp_error( $term ) && isset( $term->error_data['term_exists'] ) ) {
		$term = [ 'term_id' => $term->error_data['term_exists'] ];
	}
	if ( ! is_wp_error( $term ) ) {
		update_term_meta( $term['term_id'], 'blockendar_type_color', $def['color'] );
		$type_ids[ $def['slug'] ] = $term['term_id'];
	}
}

WP_CLI::log( '  Created ' . count( $type_ids ) . ' event types.' );

// ---------------------------------------------------------------------------
// 2. Venues
// ---------------------------------------------------------------------------

// City Hall — physical, full meta.
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

// Rooftop Bar — physical, no state/country.
$venue_rooftop = wp_insert_term( 'Rooftop Bar', 'event_venue', [ 'slug' => 'rooftop-bar' ] );
if ( is_wp_error( $venue_rooftop ) && isset( $venue_rooftop->error_data['term_exists'] ) ) {
	$venue_rooftop = [ 'term_id' => $venue_rooftop->error_data['term_exists'] ];
}
update_term_meta( $venue_rooftop['term_id'], 'blockendar_venue_address',  '55 Sky Ave, Floor 20' );
update_term_meta( $venue_rooftop['term_id'], 'blockendar_venue_city',     'Springfield' );
update_term_meta( $venue_rooftop['term_id'], 'blockendar_venue_lat',      '39.7900' );
update_term_meta( $venue_rooftop['term_id'], 'blockendar_venue_lng',      '-89.6440' );
update_term_meta( $venue_rooftop['term_id'], 'blockendar_venue_capacity', 120 );

// City Park — physical, outdoor, no capacity.
$venue_park = wp_insert_term( 'City Park', 'event_venue', [ 'slug' => 'city-park' ] );
if ( is_wp_error( $venue_park ) && isset( $venue_park->error_data['term_exists'] ) ) {
	$venue_park = [ 'term_id' => $venue_park->error_data['term_exists'] ];
}
update_term_meta( $venue_park['term_id'], 'blockendar_venue_address', '200 Park Drive' );
update_term_meta( $venue_park['term_id'], 'blockendar_venue_city',    'Springfield' );
update_term_meta( $venue_park['term_id'], 'blockendar_venue_lat',     '39.7750' );
update_term_meta( $venue_park['term_id'], 'blockendar_venue_lng',     '-89.6580' );

// Community Center — physical, with capacity.
$venue_center = wp_insert_term( 'Community Center', 'event_venue', [ 'slug' => 'community-center' ] );
if ( is_wp_error( $venue_center ) && isset( $venue_center->error_data['term_exists'] ) ) {
	$venue_center = [ 'term_id' => $venue_center->error_data['term_exists'] ];
}
update_term_meta( $venue_center['term_id'], 'blockendar_venue_address',  '88 Community Lane' );
update_term_meta( $venue_center['term_id'], 'blockendar_venue_city',     'Springfield' );
update_term_meta( $venue_center['term_id'], 'blockendar_venue_lat',      '39.7830' );
update_term_meta( $venue_center['term_id'], 'blockendar_venue_lng',      '-89.6520' );
update_term_meta( $venue_center['term_id'], 'blockendar_venue_capacity', 200 );

// Online — virtual venue, no map data.
$venue_online = wp_insert_term( 'Online / Livestream', 'event_venue', [ 'slug' => 'online' ] );
if ( is_wp_error( $venue_online ) && isset( $venue_online->error_data['term_exists'] ) ) {
	$venue_online = [ 'term_id' => $venue_online->error_data['term_exists'] ];
}
update_term_meta( $venue_online['term_id'], 'blockendar_venue_virtual', '1' );

WP_CLI::log( '  Created 5 venues.' );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Return a Y-m-d date offset by $n days from today.
 */
function blockendar_days( int $n ): string {
	return gmdate( 'Y-m-d', strtotime( "$n days" ) );
}

/**
 * Download an image and attach it as the post's featured image.
 *
 * If BLOCKENDAR_UNSPLASH_KEY is set, fetches from the Unsplash API using
 * the supplied $query string.  Otherwise falls back to picsum.photos using
 * a deterministic seed derived from $slug.
 *
 * @param int    $post_id  Target post.
 * @param string $query    Unsplash search term (e.g. "concert stage").
 * @param string $slug     Slug used as the seed for picsum and the filename.
 */
function blockendar_seed_image( int $post_id, string $query, string $slug ): void {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	if ( BLOCKENDAR_UNSPLASH_KEY ) {
		$api_url  = add_query_arg(
			[
				'query'       => $query,
				'orientation' => 'landscape',
				'client_id'   => BLOCKENDAR_UNSPLASH_KEY,
			],
			'https://api.unsplash.com/photos/random'
		);
		$response = wp_remote_get( $api_url, [ 'timeout' => 15 ] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			WP_CLI::warning( "    Could not fetch Unsplash image for '{$query}'" );
			return;
		}

		$data      = json_decode( wp_remote_retrieve_body( $response ), true );
		$image_url = $data['urls']['regular'] ?? '';
	} else {
		// picsum.photos: seed-based URL yields a consistent image per slug.
		$seed      = abs( crc32( $slug ) ) % 1000;
		$image_url = "https://picsum.photos/seed/{$seed}/1200/800";
	}

	if ( empty( $image_url ) ) {
		return;
	}

	$tmp = download_url( $image_url );

	if ( is_wp_error( $tmp ) ) {
		WP_CLI::warning( "    Could not download image for post {$post_id}" );
		return;
	}

	$file_array = [
		'name'     => sanitize_title( $slug ) . '.jpg',
		'tmp_name' => $tmp,
	];

	$attachment_id = media_handle_sideload( $file_array, $post_id );

	if ( is_wp_error( $attachment_id ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $tmp );
		WP_CLI::warning( "    Sideload failed for post {$post_id}: " . $attachment_id->get_error_message() );
		return;
	}

	set_post_thumbnail( $post_id, $attachment_id );
}

/**
 * Create a blockendar_event post with meta + taxonomy, then build the index.
 *
 * Accepted keys: title, content, start_date, end_date, start_time, end_time,
 * all_day, timezone, status, cost, cost_min, cost_max, currency,
 * registration_url, capacity, featured, hide, venue_term_id, type_term_ids,
 * image_query (Unsplash search term or picsum seed key).
 */
function blockendar_seed_event( array $args ): int {
	$defaults = [
		'title'            => 'Test Event',
		'content'          => '',
		'start_date'       => gmdate( 'Y-m-d' ),
		'end_date'         => gmdate( 'Y-m-d' ),
		'start_time'       => '09:00',
		'end_time'         => '10:00',
		'all_day'          => false,
		'timezone'         => wp_timezone_string() ?: 'UTC',
		'status'           => 'scheduled',
		'cost'             => '',
		'cost_min'         => '',
		'cost_max'         => '',
		'currency'         => 'USD',
		'registration_url' => '',
		'capacity'         => '',
		'featured'         => false,
		'hide'             => false,
		'venue_term_id'    => null,
		'type_term_ids'    => [],
		'image_query'      => '',
	];

	$a = wp_parse_args( $args, $defaults );

	$post_id = wp_insert_post(
		[
			'post_type'    => 'blockendar_event',
			'post_title'   => $a['title'],
			'post_content' => $a['content'],
			'post_status'  => 'publish',
		],
		true
	);

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( "  Failed to create '{$a['title']}': " . $post_id->get_error_message() );
		return 0;
	}

	$meta_map = [
		'blockendar_start_date'         => $a['start_date'],
		'blockendar_end_date'           => $a['end_date'],
		'blockendar_start_time'         => $a['start_time'],
		'blockendar_end_time'           => $a['end_time'],
		'blockendar_all_day'            => $a['all_day'] ? '1' : '0',
		'blockendar_timezone'           => $a['timezone'],
		'blockendar_status'             => $a['status'],
		'blockendar_cost'               => $a['cost'],
		'blockendar_cost_min'           => $a['cost_min'],
		'blockendar_cost_max'           => $a['cost_max'],
		'blockendar_currency'           => $a['currency'],
		'blockendar_registration_url'   => $a['registration_url'],
		'blockendar_capacity'           => $a['capacity'],
		'blockendar_featured'           => $a['featured'] ? '1' : '0',
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

	if ( ! empty( $a['image_query'] ) ) {
		blockendar_seed_image( $post_id, $a['image_query'], sanitize_title( $a['title'] ) );
	}

	// Build index row (single occurrence — recurrence overrides this via blockendar_seed_recurrence).
	$builder = new Blockendar\DB\IndexBuilder();
	$builder->build_for_post( $post_id );

	return $post_id;
}

/**
 * Insert a recurrence rule for an event post and expand the index.
 *
 * Deletes any single-occurrence index row created by blockendar_seed_event,
 * then fires the recurrence generator to write the full set of occurrences.
 *
 * Rule fields (all optional except where noted):
 *   frequency    string  Required. One of: daily, weekly, monthly, yearly.
 *   interval_val int     Repeat interval (default 1).
 *   byday        string  RFC 5545 weekday CSV, e.g. "MO,WE,FR" or "TH".
 *   bymonthday   string  Day-of-month CSV, e.g. "15" or "1,15".
 *   bysetpos     string  Set-position CSV, e.g. "3" (3rd) or "-1" (last).
 *   until_date   string  Y-m-d end date (exclusive of count).
 *   count        int     Fixed number of occurrences (exclusive of until_date).
 *
 * @param int   $post_id Post ID.
 * @param array $rule    Rule fields — see above.
 */
function blockendar_seed_recurrence( int $post_id, array $rule ): void {
	global $wpdb;

	$recurrence_table = $wpdb->prefix . 'blockendar_recurrence';

	// Remove any existing rule (UNIQUE KEY on post_id).
	$wpdb->delete( $recurrence_table, [ 'post_id' => $post_id ] );

	$wpdb->insert(
		$recurrence_table,
		array_merge(
			[
				'frequency'    => 'weekly',
				'interval_val' => 1,
				'byday'        => null,
				'bymonthday'   => null,
				'bysetpos'     => null,
				'until_date'   => null,
				'count'        => null,
			],
			$rule,
			// Enforce post_id last so it cannot be overridden by $rule.
			[ 'post_id' => $post_id ]
		)
	);

	// Clear the single-occurrence index row written by blockendar_seed_event,
	// then let the recurrence generator write the full occurrence set.
	$wpdb->delete( $wpdb->prefix . 'blockendar_events', [ 'post_id' => $post_id ] );
	do_action( 'blockendar_generate_recurrence_index', $post_id );
}

// ---------------------------------------------------------------------------
// Day-of-week helpers for recurring rules (RFC 5545 two-letter codes).
// ---------------------------------------------------------------------------

$dow_to_rfc  = [ 1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU' ];
$dow_labels  = [ 'MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday', 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday' ];
$pos_labels  = [ 1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th' ];

$today_byday = $dow_to_rfc[ (int) gmdate( 'N' ) ];
$today_dom   = (int) gmdate( 'j' );
$today_wpos  = (int) ceil( $today_dom / 7 );   // 1–5: which week of the month.

// ---------------------------------------------------------------------------
// 3. Seed single events
// ---------------------------------------------------------------------------

WP_CLI::log( '  Seeding events...' );
$created = 0;

$events = [

	// =========================================================
	// PAST — verifies calendar shows historical events
	// =========================================================

	[
		'title'         => 'Opening Night Concert (Past)',
		'content'       => '<p>The season opener. Standing room only.</p>',
		'start_date'    => blockendar_days( -14 ),
		'end_date'      => blockendar_days( -14 ),
		'start_time'    => '19:00',
		'end_time'      => '22:00',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_ids['music'] ],
		'featured'      => true,
		'cost'          => '$25.00',
		'currency'      => 'USD',
		'image_query'   => 'concert hall stage',
	],

	// Past all-day multi-day event.
	[
		'title'         => 'Spring Festival (Past, Multi-Day All-Day)',
		'content'       => '<p>Three-day outdoor festival in the park.</p>',
		'start_date'    => blockendar_days( -10 ),
		'end_date'      => blockendar_days( -8 ),
		'all_day'       => true,
		'venue_term_id' => $venue_park['term_id'],
		'type_term_ids' => [ $type_ids['music'], $type_ids['community'] ],
		'featured'      => true,
		'cost'          => '$15',
		'image_query'   => 'outdoor festival crowd',
	],

	// Past timed, no cost.
	[
		'title'         => 'Community Breakfast (Past)',
		'content'       => '<p>Free breakfast for all residents.</p>',
		'start_date'    => blockendar_days( -7 ),
		'end_date'      => blockendar_days( -7 ),
		'start_time'    => '08:00',
		'end_time'      => '10:30',
		'venue_term_id' => $venue_center['term_id'],
		'type_term_ids' => [ $type_ids['community'] ],
		'cost'          => 'Free',
	],

	// =========================================================
	// TODAY — tests current-day display
	// =========================================================

	// Timed, sold out, featured.
	[
		'title'         => 'Jazz Night',
		'content'       => '<p>Live jazz every Wednesday night on the rooftop.</p>',
		'start_date'    => gmdate( 'Y-m-d' ),
		'end_date'      => gmdate( 'Y-m-d' ),
		'start_time'    => '20:00',
		'end_time'      => '23:00',
		'venue_term_id' => $venue_rooftop['term_id'],
		'type_term_ids' => [ $type_ids['music'] ],
		'cost'          => '$15.00',
		'featured'      => true,
		'status'        => 'sold_out',
		'image_query'   => 'jazz band rooftop night',
	],

	// In-progress: started 1 hour ago, ends 1 hour from now.
	[
		'title'         => 'In-Progress Community Forum',
		'content'       => '<p>Happening right now — open Q&A with local leaders.</p>',
		'start_date'    => gmdate( 'Y-m-d' ),
		'end_date'      => gmdate( 'Y-m-d' ),
		'start_time'    => gmdate( 'H:i', strtotime( '-1 hour' ) ),
		'end_time'      => gmdate( 'H:i', strtotime( '+2 hours' ) ),
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_ids['community'] ],
	],

	// =========================================================
	// THIS WEEK
	// =========================================================

	// Free, multi-type.
	[
		'title'         => 'Morning Yoga in the Park',
		'content'       => '<p>All levels welcome. Bring your own mat.</p>',
		'start_date'    => blockendar_days( 1 ),
		'end_date'      => blockendar_days( 1 ),
		'start_time'    => '07:00',
		'end_time'      => '08:00',
		'venue_term_id' => $venue_park['term_id'],
		'type_term_ids' => [ $type_ids['sports'], $type_ids['community'] ],
		'cost'          => 'Free',
	],

	// All-day single day, with capacity and registration.
	[
		'title'         => 'All-Day Maker Workshop',
		'content'       => '<p>Full-day electronics and coding workshop. Spots are limited.</p>',
		'start_date'    => blockendar_days( 2 ),
		'end_date'      => blockendar_days( 2 ),
		'all_day'       => true,
		'venue_term_id' => $venue_center['term_id'],
		'type_term_ids' => [ $type_ids['tech'], $type_ids['community'] ],
		'capacity'      => '30',
		'registration_url' => 'https://example.com/register',
	],

	// Timed, volunteer, no cost.
	[
		'title'         => 'Neighbourhood Clean-Up',
		'content'       => '<p>Volunteers welcome. Gloves and bags provided.</p>',
		'start_date'    => blockendar_days( 3 ),
		'end_date'      => blockendar_days( 3 ),
		'start_time'    => '09:00',
		'end_time'      => '12:00',
		'venue_term_id' => $venue_park['term_id'],
		'type_term_ids' => [ $type_ids['community'] ],
	],

	// =========================================================
	// NEXT 31 DAYS
	// =========================================================

	// Cost range (tests cost_min + cost_max meta).
	[
		'title'         => 'Acoustic Sessions',
		'content'       => '<p>Intimate acoustic performances. Price varies by act.</p>',
		'start_date'    => blockendar_days( 5 ),
		'end_date'      => blockendar_days( 5 ),
		'start_time'    => '18:30',
		'end_time'      => '21:00',
		'venue_term_id' => $venue_rooftop['term_id'],
		'type_term_ids' => [ $type_ids['music'] ],
		'cost'          => '$10–$25',
		'cost_min'      => '10',
		'cost_max'      => '25',
		'currency'      => 'USD',
	],

	// Online / virtual venue, registration URL.
	[
		'title'         => 'Online Webinar: Tech Talk',
		'content'       => '<p>Learn about the latest in web development.</p>',
		'start_date'    => blockendar_days( 7 ),
		'end_date'      => blockendar_days( 7 ),
		'start_time'    => '14:00',
		'end_time'      => '15:30',
		'venue_term_id' => $venue_online['term_id'],
		'type_term_ids' => [ $type_ids['tech'] ],
		'registration_url' => 'https://example.com/webinar',
	],

	// Community meeting, no cost.
	[
		'title'         => 'Town Hall Meeting',
		'content'       => '<p>Quarterly public meeting. All residents welcome.</p>',
		'start_date'    => blockendar_days( 8 ),
		'end_date'      => blockendar_days( 8 ),
		'start_time'    => '18:00',
		'end_time'      => '20:00',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_ids['community'] ],
	],

	// Sports, with capacity and registration.
	[
		'title'         => 'Charity 5K Run',
		'content'       => '<p>All proceeds go to the local food bank. Registration required.</p>',
		'start_date'    => blockendar_days( 11 ),
		'end_date'      => blockendar_days( 11 ),
		'start_time'    => '08:00',
		'end_time'      => '12:00',
		'venue_term_id' => $venue_park['term_id'],
		'type_term_ids' => [ $type_ids['sports'], $type_ids['community'] ],
		'cost'          => '$20.00',
		'capacity'      => '500',
		'registration_url' => 'https://example.com/5k-run',
		'image_query'   => 'charity run 5k race starting line',
	],

	// Arts & Culture, free admission.
	[
		'title'         => 'Art Gallery Opening',
		'content'       => '<p>Local artists exhibit new works. Wine and cheese reception.</p>',
		'start_date'    => blockendar_days( 12 ),
		'end_date'      => blockendar_days( 12 ),
		'start_time'    => '17:00',
		'end_time'      => '20:00',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_ids['arts-culture'] ],
		'cost'          => 'Free',
		'image_query'   => 'art gallery opening reception',
	],

	// Featured, sold-out, high capacity.
	[
		'title'         => 'Summer Kick-Off Concert',
		'content'       => '<p>Opening the summer season with a bang. Fireworks to follow.</p>',
		'start_date'    => blockendar_days( 14 ),
		'end_date'      => blockendar_days( 14 ),
		'start_time'    => '19:00',
		'end_time'      => '23:30',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_ids['music'] ],
		'cost'          => '$35.00',
		'currency'      => 'USD',
		'featured'      => true,
		'capacity'      => '200',
		'image_query'   => 'outdoor summer concert crowd',
	],

	// Multi-day timed (not all-day) — spans 3 calendar days with set hours.
	[
		'title'         => 'Food & Wine Festival',
		'content'       => '<p>Tasting stations, live cooking demos, and wine pairings.</p>',
		'start_date'    => blockendar_days( 16 ),
		'end_date'      => blockendar_days( 18 ),
		'start_time'    => '11:00',
		'end_time'      => '22:00',
		'venue_term_id' => $venue_park['term_id'],
		'type_term_ids' => [ $type_ids['food-drink'], $type_ids['community'] ],
		'cost'          => 'From $45',
		'cost_min'      => '45',
		'cost_max'      => '120',
		'currency'      => 'USD',
		'featured'      => true,
		'image_query'   => 'food festival wine tasting',
	],

	// Multi-day all-day, with cost range.
	[
		'title'         => 'Multi-Day Music Festival',
		'content'       => '<p>Four days of non-stop music across three stages.</p>',
		'start_date'    => blockendar_days( 20 ),
		'end_date'      => blockendar_days( 23 ),
		'all_day'       => true,
		'type_term_ids' => [ $type_ids['music'] ],
		'featured'      => true,
		'cost'          => 'From $99',
		'cost_min'      => '99',
		'cost_max'      => '299',
		'image_query'   => 'music festival stage crowd night',
	],

	// Timed, no venue assigned — verifies graceful fallback.
	[
		'title'         => 'No-Venue Online Talk',
		'content'       => '<p>No venue assigned — should display gracefully.</p>',
		'start_date'    => blockendar_days( 22 ),
		'end_date'      => blockendar_days( 22 ),
		'start_time'    => '10:00',
		'end_time'      => '11:00',
		'type_term_ids' => [ $type_ids['tech'] ],
	],

	// Midnight-crossing: start today (22:00), end tomorrow (03:00).
	[
		'title'         => 'Late Night DJ Set',
		'content'       => '<p>House and techno until 3 AM. Crosses midnight.</p>',
		'start_date'    => blockendar_days( 25 ),
		'end_date'      => blockendar_days( 26 ),
		'start_time'    => '22:00',
		'end_time'      => '03:00',
		'venue_term_id' => $venue_rooftop['term_id'],
		'type_term_ids' => [ $type_ids['music'] ],
		'cost'          => '$20.00',
	],

	// Postponed status.
	[
		'title'         => 'Postponed Gala Evening',
		'content'       => '<p>Originally scheduled for last month. New date TBC.</p>',
		'start_date'    => blockendar_days( 28 ),
		'end_date'      => blockendar_days( 28 ),
		'start_time'    => '19:00',
		'end_time'      => '23:00',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_ids['arts-culture'], $type_ids['community'] ],
		'status'        => 'postponed',
		'cost'          => '$60.00',
	],

	// =========================================================
	// BEYOND 31 DAYS — appears in month view, not list view
	// =========================================================

	[
		'title'         => "New Year's Eve Gala",
		'content'       => '<p>Black tie event to ring in the new year. Limited capacity.</p>',
		'start_date'    => blockendar_days( 45 ),
		'end_date'      => blockendar_days( 46 ),   // starts 20:00, ends 01:00 next day.
		'start_time'    => '20:00',
		'end_time'      => '01:00',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_ids['music'], $type_ids['community'] ],
		'cost'          => '$75.00',
		'currency'      => 'USD',
		'featured'      => true,
		'capacity'      => '150',
		'image_query'   => "new year's eve party celebration",
	],

	// Far-future all-day single day.
	[
		'title'         => 'Annual Community Picnic',
		'content'       => '<p>Bring your own food. Games and live music all day.</p>',
		'start_date'    => blockendar_days( 60 ),
		'end_date'      => blockendar_days( 60 ),
		'all_day'       => true,
		'venue_term_id' => $venue_park['term_id'],
		'type_term_ids' => [ $type_ids['community'] ],
		'cost'          => 'Free',
		'image_query'   => 'community picnic park summer',
	],

	// =========================================================
	// EDGE CASES
	// =========================================================

	// Cancelled status.
	[
		'title'         => 'Cancelled Workshop',
		'content'       => '<p>This event was cancelled due to low enrolment.</p>',
		'start_date'    => blockendar_days( 10 ),
		'end_date'      => blockendar_days( 10 ),
		'start_time'    => '17:00',
		'end_time'      => '19:00',
		'status'        => 'cancelled',
		'venue_term_id' => $venue_center['term_id'],
		'type_term_ids' => [ $type_ids['tech'] ],
	],

	// Hidden from public listings.
	[
		'title'         => 'Hidden VIP Preview',
		'content'       => '<p>Private preview — excluded from public calendar.</p>',
		'start_date'    => blockendar_days( 3 ),
		'end_date'      => blockendar_days( 3 ),
		'start_time'    => '12:00',
		'end_time'      => '14:00',
		'hide'          => true,
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_ids['arts-culture'] ],
		'cost'          => '$200.00',
	],

	// Explicit free admission (cost field = 'Free', no cost_min/max).
	[
		'title'         => 'Free Outdoor Film Screening',
		'content'       => '<p>Free outdoor film. Bring a blanket. BYOB.</p>',
		'start_date'    => blockendar_days( 6 ),
		'end_date'      => blockendar_days( 6 ),
		'start_time'    => '21:00',
		'end_time'      => '23:30',
		'venue_term_id' => $venue_park['term_id'],
		'type_term_ids' => [ $type_ids['community'], $type_ids['arts-culture'] ],
		'cost'          => 'Free',
	],

	// No event type assigned — verifies graceful fallback in type-colour logic.
	[
		'title'         => 'No-Type General Meeting',
		'content'       => '<p>No event type assigned — colour fallback test.</p>',
		'start_date'    => blockendar_days( 9 ),
		'end_date'      => blockendar_days( 9 ),
		'start_time'    => '11:00',
		'end_time'      => '12:00',
		'venue_term_id' => $venue_center['term_id'],
	],

	// Cost range only (no display label set) — tests $50–$200 min/max meta.
	[
		'title'         => 'Cost Range — Min $50 / Max $200',
		'content'       => '<p>Tests cost_min + cost_max fields without a display label.</p>',
		'start_date'    => blockendar_days( 15 ),
		'end_date'      => blockendar_days( 15 ),
		'start_time'    => '18:00',
		'end_time'      => '21:00',
		'venue_term_id' => $venue_hall['term_id'],
		'type_term_ids' => [ $type_ids['music'] ],
		'cost_min'      => '50',
		'cost_max'      => '200',
		'currency'      => 'USD',
	],
];

foreach ( $events as $event ) {
	$id = blockendar_seed_event( $event );
	if ( $id ) {
		$img_flag = ! empty( $event['image_query'] ) ? ' [img]' : '';
		WP_CLI::log( "  + [{$id}] {$event['title']}{$img_flag}" );
		++$created;
	}
}

// ---------------------------------------------------------------------------
// 4. Recurring events
// ---------------------------------------------------------------------------

WP_CLI::log( '  Seeding recurring events...' );

// 4a. WEEKLY — every current day of week, until 90 days.
$open_mic_id = blockendar_seed_event( [
	'title'         => 'Weekly Open Mic Night',
	'content'       => '<p>Sign up at the door. All genres welcome.</p>',
	'start_date'    => gmdate( 'Y-m-d' ),
	'end_date'      => gmdate( 'Y-m-d' ),
	'start_time'    => '19:00',
	'end_time'      => '22:00',
	'venue_term_id' => $venue_rooftop['term_id'],
	'type_term_ids' => [ $type_ids['music'] ],
	'cost'          => '$5.00',
	'image_query'   => 'open mic night singer microphone',
] );

if ( $open_mic_id ) {
	blockendar_seed_recurrence( $open_mic_id, [
		'frequency'    => 'weekly',
		'interval_val' => 1,
		'byday'        => $today_byday,
		'until_date'   => blockendar_days( 90 ),
	] );
	WP_CLI::log( "  + [{$open_mic_id}] Weekly Open Mic Night (WEEKLY/{$today_byday}, until +90 days) [img]" );
	++$created;
}

// 4b. MONTHLY by day of month — e.g. every 13th, until 180 days.
$board_id = blockendar_seed_event( [
	'title'         => 'Monthly Board Meeting',
	'content'       => '<p>Open to the public. Agenda published one week prior.</p>',
	'start_date'    => gmdate( 'Y-m-d' ),
	'end_date'      => gmdate( 'Y-m-d' ),
	'start_time'    => '17:30',
	'end_time'      => '19:00',
	'venue_term_id' => $venue_hall['term_id'],
	'type_term_ids' => [ $type_ids['community'] ],
] );

if ( $board_id ) {
	blockendar_seed_recurrence( $board_id, [
		'frequency'    => 'monthly',
		'interval_val' => 1,
		'bymonthday'   => (string) $today_dom,
		'until_date'   => blockendar_days( 180 ),
	] );
	WP_CLI::log( "  + [{$board_id}] Monthly Board Meeting (MONTHLY/day {$today_dom}, until +180 days)" );
	++$created;
}

// 4c. MONTHLY by position — e.g. 3rd Thursday of each month, until 180 days.
$pos_title  = ( $pos_labels[ $today_wpos ] ?? "{$today_wpos}th" ) . ' ' . ( $dow_labels[ $today_byday ] ?? $today_byday );

$support_id = blockendar_seed_event( [
	'title'         => "Community Support Circle ({$pos_title} of each month)",
	'content'       => '<p>Monthly support group. Confidential and welcoming.</p>',
	'start_date'    => gmdate( 'Y-m-d' ),
	'end_date'      => gmdate( 'Y-m-d' ),
	'start_time'    => '18:00',
	'end_time'      => '19:30',
	'venue_term_id' => $venue_center['term_id'],
	'type_term_ids' => [ $type_ids['community'] ],
] );

if ( $support_id ) {
	blockendar_seed_recurrence( $support_id, [
		'frequency'    => 'monthly',
		'interval_val' => 1,
		'byday'        => $today_byday,
		'bysetpos'     => (string) $today_wpos,
		'until_date'   => blockendar_days( 180 ),
	] );
	WP_CLI::log( "  + [{$support_id}] Community Support Circle (MONTHLY/{$pos_title}, until +180 days)" );
	++$created;
}

// 4d. DAILY — standup, fixed count of 14 occurrences.
$standup_id = blockendar_seed_event( [
	'title'         => 'Daily Tech Standup',
	'content'       => '<p>15-minute daily sync for the volunteer team.</p>',
	'start_date'    => gmdate( 'Y-m-d' ),
	'end_date'      => gmdate( 'Y-m-d' ),
	'start_time'    => '09:00',
	'end_time'      => '09:15',
	'venue_term_id' => $venue_online['term_id'],
	'type_term_ids' => [ $type_ids['tech'] ],
] );

if ( $standup_id ) {
	blockendar_seed_recurrence( $standup_id, [
		'frequency'    => 'daily',
		'interval_val' => 1,
		'count'        => 14,
	] );
	WP_CLI::log( "  + [{$standup_id}] Daily Tech Standup (DAILY, 14 occurrences)" );
	++$created;
}

// 4e. YEARLY — annual gala, 3 occurrences.
$annual_id = blockendar_seed_event( [
	'title'         => 'Annual Charity Gala',
	'content'       => '<p>Our flagship annual fundraiser. Black tie optional.</p>',
	'start_date'    => gmdate( 'Y-m-d' ),
	'end_date'      => gmdate( 'Y-m-d' ),
	'start_time'    => '18:00',
	'end_time'      => '22:00',
	'venue_term_id' => $venue_hall['term_id'],
	'type_term_ids' => [ $type_ids['community'], $type_ids['arts-culture'] ],
	'cost'          => '$100.00',
	'currency'      => 'USD',
	'featured'      => true,
	'image_query'   => 'charity gala dinner elegant ballroom',
] );

if ( $annual_id ) {
	blockendar_seed_recurrence( $annual_id, [
		'frequency'    => 'yearly',
		'interval_val' => 1,
		'count'        => 3,
	] );
	WP_CLI::log( "  + [{$annual_id}] Annual Charity Gala (YEARLY, 3 occurrences) [img]" );
	++$created;
}

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------

WP_CLI::success( "Created {$created} events (including 5 recurring). Visit your calendar block to review them." );
