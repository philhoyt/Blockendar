<?php
/**
 * Site Health integration.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\CPT\EventPostType;
use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\Cron;
use Blockendar\REST\AbstractController;

/**
 * A "Blockendar" section on Site Health → Info, and three tests on Site
 * Health → Status for the failures that leave a calendar silently empty:
 * missing tables, an index out of step with the events, and a calendar route
 * that visitors cannot reach.
 *
 * Nothing here repairs anything. Each result says what is wrong and where the
 * fix is.
 */
class SiteHealth {

	/**
	 * The REST route the asynchronous test is fetched from.
	 */
	public const ROUTE = '/site-health/calendar-route';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'debug_information', [ $this, 'add_debug_information' ] );
		add_filter( 'site_status_tests', [ $this, 'add_tests' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	// -------------------------------------------------------------------------
	// Info
	// -------------------------------------------------------------------------

	/**
	 * Add the Blockendar section to Site Health → Info.
	 *
	 * @param mixed $info Sections, keyed by slug.
	 * @return array
	 */
	public function add_debug_information( mixed $info ): array {
		$info = is_array( $info ) ? $info : [];

		$index    = new EventIndex();
		$builder  = new IndexBuilder();
		$stored   = (string) get_option( Schema::DB_VERSION_OPTION, '' );
		$rebuilt  = (string) get_option( 'blockendar_last_index_rebuild', '' );
		$roll     = wp_next_scheduled( Cron::HOOK );
		$strategy = (string) SettingsPage::get( 'generation_strategy' );
		$token    = (string) SettingsPage::get( 'rest_feed_token' );

		$fields = [
			'version'          => [
				'label' => __( 'Plugin version', 'blockendar' ),
				'value' => BLOCKENDAR_VERSION,
			],
			'db_version'       => [
				'label' => __( 'Database version', 'blockendar' ),
				'value' => $stored === Schema::DB_VERSION
					? $stored
					/* translators: 1: version recorded in the database, 2: version this plugin expects. */
					: sprintf( __( '%1$s (this version of the plugin expects %2$s)', 'blockendar' ), '' === $stored ? __( 'none', 'blockendar' ) : $stored, Schema::DB_VERSION ),
				'debug' => $stored,
			],
			'events'           => $this->events_field(),
			'index_rows'       => [
				'label' => __( 'Indexed occurrences', 'blockendar' ),
				'value' => number_format_i18n( $index->get_total_row_count() ),
				'debug' => $index->get_total_row_count(),
			],
			'indexed_events'   => [
				'label' => __( 'Indexed events', 'blockendar' ),
				'value' => number_format_i18n( $index->get_indexed_post_count() ),
				'debug' => $index->get_indexed_post_count(),
			],
			'recurrence_rules' => [
				'label' => __( 'Recurrence rules', 'blockendar' ),
				'value' => number_format_i18n( $this->rule_count() ),
				'debug' => $this->rule_count(),
			],
			'last_rebuild'     => [
				'label' => __( 'Last full index rebuild', 'blockendar' ),
				'value' => $this->describe_rebuild( $rebuilt, $builder->is_rebuild_pending() ),
				'debug' => '' === $rebuilt ? 'never' : $rebuilt . ' UTC',
			],
			'next_roll'        => [
				'label' => __( 'Next recurrence horizon roll', 'blockendar' ),
				'value' => $roll
					? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $roll )
					: __( 'Not scheduled', 'blockendar' ),
				'debug' => $roll ? gmdate( 'Y-m-d H:i:s', $roll ) . ' UTC' : 'not scheduled',
			],
			'strategy'         => [
				'label' => __( 'Index generation', 'blockendar' ),
				'value' => 'cron' === $strategy ? __( 'Deferred to WP-Cron', 'blockendar' ) : __( 'On save', 'blockendar' ),
				'debug' => $strategy,
			],
			'horizon'          => [
				'label' => __( 'Recurrence horizon', 'blockendar' ),
				/* translators: %s: number of days. */
				'value' => sprintf( _n( '%s day', '%s days', (int) SettingsPage::get( 'horizon_days' ), 'blockendar' ), number_format_i18n( (int) SettingsPage::get( 'horizon_days' ) ) ),
				'debug' => (int) SettingsPage::get( 'horizon_days' ),
			],
			'max_instances'    => [
				'label' => __( 'Maximum occurrences per event', 'blockendar' ),
				'value' => number_format_i18n( (int) SettingsPage::get( 'max_instances' ) ),
				'debug' => (int) SettingsPage::get( 'max_instances' ),
			],
			'timezone_mode'    => [
				'label' => __( 'Timezone display', 'blockendar' ),
				'value' => 'event' === SettingsPage::get( 'timezone_mode' ) ? __( "Each event's own timezone", 'blockendar' ) : __( "The site's timezone", 'blockendar' ),
				'debug' => (string) SettingsPage::get( 'timezone_mode' ),
			],
			'events_slug'      => [
				'label' => __( 'Events slug', 'blockendar' ),
				'value' => SettingsPage::events_slug(),
			],
			'rest_public'      => [
				'label' => __( 'Public REST endpoints', 'blockendar' ),
				'value' => SettingsPage::get( 'rest_public' ) ? __( 'Yes', 'blockendar' ) : __( 'No', 'blockendar' ),
				'debug' => (bool) SettingsPage::get( 'rest_public' ),
			],
			// Whether a token exists, never the token. Private as well, so the
			// field is left out of the copied report altogether.
			'feed_token'       => [
				'label'   => __( 'Feed token', 'blockendar' ),
				'value'   => '' !== $token ? __( 'Set', 'blockendar' ) : __( 'Not set', 'blockendar' ),
				'private' => true,
			],
			'object_cache'     => [
				'label' => __( 'Persistent object cache', 'blockendar' ),
				'value' => wp_using_ext_object_cache() ? __( 'In use', 'blockendar' ) : __( 'Not in use', 'blockendar' ),
				'debug' => wp_using_ext_object_cache(),
			],
		];

		$info['blockendar'] = [
			'label'       => __( 'Blockendar', 'blockendar' ),
			'description' => __( 'The event index and the settings that shape it. Include this section when reporting a problem with the calendar.', 'blockendar' ),
			'fields'      => $fields,
		];

		return $info;
	}

	/**
	 * The events field: how many events there are, by post status.
	 *
	 * @return array{label: string, value: string, debug: string}
	 */
	private function events_field(): array {
		$counts = (array) wp_count_posts( EventPostType::POST_TYPE );
		$labels = [
			'publish' => __( 'published', 'blockendar' ),
			'future'  => __( 'scheduled', 'blockendar' ),
			'draft'   => __( 'draft', 'blockendar' ),
			'pending' => __( 'pending', 'blockendar' ),
			'private' => __( 'private', 'blockendar' ),
			'trash'   => __( 'in the trash', 'blockendar' ),
		];

		$parts = [];
		$debug = [];

		foreach ( $labels as $status => $label ) {
			$count = (int) ( $counts[ $status ] ?? 0 );

			if ( 0 === $count ) {
				continue;
			}

			$parts[] = number_format_i18n( $count ) . ' ' . $label;
			$debug[] = $status . '=' . $count;
		}

		return [
			'label' => __( 'Events', 'blockendar' ),
			'value' => empty( $parts ) ? __( 'None', 'blockendar' ) : implode( ', ', $parts ),
			'debug' => empty( $debug ) ? 'none' : implode( ', ', $debug ),
		];
	}

	/**
	 * When the index was last rebuilt in full, as a sentence.
	 *
	 * @param string $rebuilt  UTC datetime of the last rebuild, or ''.
	 * @param bool   $pending  Whether a rebuild is running or queued.
	 */
	private function describe_rebuild( string $rebuilt, bool $pending ): string {
		if ( $pending ) {
			return __( 'In progress', 'blockendar' );
		}

		if ( '' === $rebuilt ) {
			return __( 'Never', 'blockendar' );
		}

		$timestamp = strtotime( $rebuilt . ' UTC' );

		if ( false === $timestamp ) {
			return $rebuilt;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $timestamp );
	}

	/**
	 * How many recurrence rules are stored.
	 */
	private function rule_count(): int {
		global $wpdb;

		if ( in_array( Schema::recurrence_table(), Schema::missing_tables(), true ) ) {
			return 0;
		}

		$table = Schema::recurrence_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from $wpdb->prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * Add the three tests to Site Health → Status.
	 *
	 * @param mixed $tests Tests, under 'direct' and 'async'.
	 * @return array
	 */
	public function add_tests( mixed $tests ): array {
		$tests = is_array( $tests ) ? $tests : [];

		$tests['direct']['blockendar_tables'] = [
			'label' => __( 'Blockendar database tables', 'blockendar' ),
			'test'  => [ $this, 'test_tables' ],
		];

		$tests['direct']['blockendar_index'] = [
			'label' => __( 'Blockendar event index', 'blockendar' ),
			'test'  => [ $this, 'test_index' ],
		];

		// Asynchronous: it makes an HTTP request, which would hold up the page.
		// The Site Health screen fetches the REST route; the weekly scheduled
		// check calls the callable.
		$tests['async']['blockendar_calendar_route'] = [
			'label'             => __( 'Blockendar calendar route', 'blockendar' ),
			'test'              => rest_url( AbstractController::NAMESPACE . self::ROUTE ),
			'has_rest'          => true,
			'async_direct_test' => [ $this, 'test_calendar_route' ],
		];

		return $tests;
	}

	/**
	 * Register the route the asynchronous test is fetched from.
	 */
	public function register_routes(): void {
		register_rest_route(
			AbstractController::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'test_calendar_route' ],
				// The capability core's own Site Health routes check.
				'permission_callback' => static fn(): bool => current_user_can( 'view_site_health_checks' ),
			]
		);
	}

	/**
	 * The badge every result carries.
	 *
	 * @return array{label: string, color: string}
	 */
	private function badge(): array {
		return [
			'label' => __( 'Blockendar', 'blockendar' ),
			'color' => 'blue',
		];
	}

	/**
	 * Test 1: all three tables exist, and the events table has the indexes
	 * this version of the schema is defined by.
	 *
	 * @return array Site Health result.
	 */
	public function test_tables(): array {
		$result = [
			'label'       => __( "Blockendar's database tables are in place", 'blockendar' ),
			'status'      => 'good',
			'badge'       => $this->badge(),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'Events are listed from three tables of their own, which exist and are shaped as this version expects.', 'blockendar' )
			),
			'actions'     => '',
			'test'        => 'blockendar_tables',
		];

		$missing = Schema::missing_tables();

		if ( ! empty( $missing ) ) {
			$result['status']      = 'critical';
			$result['label']       = __( "Blockendar's database tables are missing", 'blockendar' );
			$result['description'] = sprintf(
				'<p>%s</p><p>%s</p>',
				sprintf(
					/* translators: %s: comma-separated list of table names. */
					esc_html__( 'The calendar, event lists and feeds read from tables that do not exist: %s. They show nothing until the tables are created.', 'blockendar' ),
					'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $missing ) ) . '</code>'
				),
				esc_html__( 'This happens when a site is copied with its database options but without the plugin\'s tables. Deactivating and reactivating Blockendar creates them; a full index rebuild then fills them.', 'blockendar' )
			);

			return $result;
		}

		if ( ! Schema::has_required_indexes() ) {
			$result['status']      = 'recommended';
			$result['label']       = __( "Blockendar's events table is missing an index", 'blockendar' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'The events table lacks an index this version relies on. Listings still work, but more slowly on a large calendar. The plugin retries the schema upgrade on every page load; if this persists, the database user may not be allowed to alter tables.', 'blockendar' )
			);
		}

		return $result;
	}

	/**
	 * Test 2: every published event the builder would index is in the index,
	 * and nothing is indexed that is no longer published.
	 *
	 * @return array Site Health result.
	 */
	public function test_index(): array {
		$result = [
			'label'       => __( 'The event index matches your events', 'blockendar' ),
			'status'      => 'good',
			'badge'       => $this->badge(),
			'description' => '',
			'actions'     => '',
			'test'        => 'blockendar_index',
		];

		if ( ! empty( Schema::missing_tables() ) ) {
			$result['description'] = sprintf( '<p>%s</p>', esc_html__( 'Not checked: the tables are missing. See the result above.', 'blockendar' ) );

			return $result;
		}

		if ( ( new IndexBuilder() )->is_rebuild_pending() ) {
			$result['label']       = __( 'The event index is being rebuilt', 'blockendar' );
			$result['description'] = sprintf( '<p>%s</p>', esc_html__( 'A full rebuild is running or queued, so the index was not compared with the events. Check again when it has finished.', 'blockendar' ) );

			return $result;
		}

		$missing = $this->unindexed_event_count();
		$stale   = $this->stale_indexed_event_count();
		$index   = new EventIndex();

		if ( 0 === $missing && 0 === $stale ) {
			$events      = $index->get_indexed_post_count();
			$occurrences = $index->get_total_row_count();

			$result['description'] = sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: 1: "N published event(s)", 2: "N occurrence(s)". */
					esc_html__( '%1$s indexed as %2$s, which is what the calendar, lists and feeds read.', 'blockendar' ),
					/* translators: %s: number of events. */
					sprintf( _n( '%s published event', '%s published events', $events, 'blockendar' ), number_format_i18n( $events ) ),
					/* translators: %s: number of occurrences. */
					sprintf( _n( '%s occurrence', '%s occurrences', $occurrences, 'blockendar' ), number_format_i18n( $occurrences ) )
				)
			);

			return $result;
		}

		$problems = [];

		if ( $missing > 0 ) {
			$problems[] = sprintf(
				/* translators: %s: number of events. */
				_n( '%s published event with a date has no entry in the index, so it is missing from the calendar, lists and feeds.', '%s published events with dates have no entry in the index, so they are missing from the calendar, lists and feeds.', $missing, 'blockendar' ),
				number_format_i18n( $missing )
			);
		}

		if ( $stale > 0 ) {
			$problems[] = sprintf(
				/* translators: %s: number of events. */
				_n( '%s event in the index is no longer published. Its rows are harmless, since listings join against published posts, but the index is out of date.', '%s events in the index are no longer published. Their rows are harmless, since listings join against published posts, but the index is out of date.', $stale, 'blockendar' ),
				number_format_i18n( $stale )
			);
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'The event index is out of step with your events', 'blockendar' );
		$result['description'] = '<p>' . implode( '</p><p>', array_map( 'esc_html', $problems ) ) . '</p>'
			. sprintf(
				'<p>%s</p>',
				'cron' === SettingsPage::get( 'generation_strategy' )
					? esc_html__( 'Index generation is deferred to WP-Cron, so an event saved a moment ago is indexed on the next cron run. If this persists, rebuild the index.', 'blockendar' )
					: esc_html__( 'A full rebuild writes every published event into the index again.', 'blockendar' )
			);
		$result['actions']     = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'edit.php?post_type=' . EventPostType::POST_TYPE . '&page=' . SettingsPage::MENU_SLUG . '#performance' ) ),
			esc_html__( 'Rebuild the index', 'blockendar' )
		);

		return $result;
	}

	/**
	 * Published events the builder would index that have no row.
	 *
	 * "Would index" is what IndexBuilder::build_for_post() asks: a start date,
	 * and either an end date or the ongoing flag. Anything else is skipped on
	 * purpose and is not drift.
	 */
	private function unindexed_event_count(): int {
		global $wpdb;

		$events = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from $wpdb->prefix.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} sd ON sd.post_id = p.ID AND sd.meta_key = 'blockendar_start_date' AND sd.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} ed ON ed.post_id = p.ID AND ed.meta_key = 'blockendar_end_date' AND ed.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} og ON og.post_id = p.ID AND og.meta_key = 'blockendar_ongoing' AND og.meta_value = '1'
				WHERE p.post_type = %s
				  AND p.post_status = 'publish'
				  AND ( ed.post_id IS NOT NULL OR og.post_id IS NOT NULL )
				  AND NOT EXISTS ( SELECT 1 FROM {$events} e WHERE e.post_id = p.ID )",
				EventPostType::POST_TYPE
			)
		);
		// phpcs:enable
	}

	/**
	 * Events in the index whose post is no longer published, or is gone.
	 */
	private function stale_indexed_event_count(): int {
		global $wpdb;

		$events = Schema::events_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from $wpdb->prefix.
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT e.post_id)
			FROM {$events} e
			LEFT JOIN {$wpdb->posts} p ON p.ID = e.post_id
			WHERE p.ID IS NULL OR p.post_status <> 'publish'"
		);
		// phpcs:enable
	}

	/**
	 * Test 3: when the REST API is public, a visitor's request to the calendar
	 * route succeeds.
	 *
	 * The request is made from the server to itself without cookies, which is
	 * what a logged-out visitor's browser sends. The calendar block fetches its
	 * events from this route, so a security plugin or a server rule that blocks
	 * it blanks the calendar for everyone but the administrator who would
	 * notice.
	 *
	 * @return array Site Health result.
	 */
	public function test_calendar_route(): array {
		$result = [
			'label'       => __( 'The calendar route answers visitors', 'blockendar' ),
			'status'      => 'good',
			'badge'       => $this->badge(),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'The Calendar block loads its events from the REST API. A request made the way a visitor\'s browser makes it was answered.', 'blockendar' )
			),
			'actions'     => '',
			'test'        => 'blockendar_calendar_route',
		];

		if ( ! SettingsPage::get( 'rest_public' ) ) {
			$result['label']       = __( 'The calendar route is private by setting', 'blockendar' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'Public REST endpoints is turned off, so the calendar is served to logged-in users only and there is nothing to check for a visitor.', 'blockendar' )
			);

			return $result;
		}

		$today = gmdate( 'Y-m-d' );
		$url   = add_query_arg(
			[
				'start' => $today,
				'end'   => $today,
			],
			rest_url( AbstractController::NAMESPACE . '/calendar' )
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout'   => 10,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => [ 'Cache-Control' => 'no-cache' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'The calendar route could not be checked', 'blockendar' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %s: error message. */
					esc_html__( 'The site could not make a request to itself: %s. If the "loopback request" test above also fails, the host blocks such requests and the calendar is probably fine for visitors.', 'blockendar' ),
					esc_html( $response->get_error_message() )
				)
			);

			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && is_array( $body ) ) {
			return $result;
		}

		$result['status'] = 'critical';
		$result['label']  = __( 'The calendar route does not answer visitors', 'blockendar' );

		if ( in_array( $code, [ 401, 403 ], true ) ) {
			$reason = esc_html__( 'The request was refused, so visitors who are not logged in see an empty calendar. A security plugin or a server rule is blocking the REST API for anonymous requests; allow the blockendar/v1 routes, or turn off Public REST endpoints and accept a logged-in calendar.', 'blockendar' );
		} elseif ( 404 === $code ) {
			$reason = esc_html__( 'The route was not found. The REST API may be disabled, or its routes rewritten by another plugin.', 'blockendar' );
		} else {
			$reason = esc_html__( 'The response was not the calendar\'s JSON, so the block has nothing to show. The server or another plugin is replacing the response.', 'blockendar' );
		}

		$result['description'] = sprintf(
			'<p>%s</p><p>%s</p>',
			sprintf(
				/* translators: 1: URL, 2: HTTP status code. */
				esc_html__( '%1$s answered with HTTP %2$s.', 'blockendar' ),
				'<code>' . esc_html( $url ) . '</code>',
				(int) $code
			),
			$reason
		);

		return $result;
	}
}
