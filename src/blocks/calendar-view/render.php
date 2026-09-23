<?php
/**
 * blockendar/calendar-view — server-side render callback.
 *
 * Outputs the block wrapper div with data attributes.
 * view.jsx reads these to configure and mount FullCalendar.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

/*
 * defaultView and firstDay carry no block.json default, so an attribute that
 * is absent means "use the site setting" rather than "the author chose the
 * same value as the default". Settings > Blockendar sets the site-wide
 * default; a block that overrides it keeps its own choice.
 */
$enabled_views = $attributes['enabledViews'] ?? [ 'dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listNextMonth' ];
$default_view  = $attributes['defaultView'] ?? \Blockendar\Admin\SettingsPage::get( 'calendar_default_view' );
$first_day     = (int) ( $attributes['firstDay'] ?? \Blockendar\Admin\SettingsPage::get( 'calendar_first_day' ) );

// Applies to the time-grid views only; FullCalendar ignores it elsewhere.
$slot_duration = (string) \Blockendar\Admin\SettingsPage::get( 'calendar_slot_duration' );
$venue_ids     = array_map( 'intval', (array) ( $attributes['venueIds'] ?? [] ) );
$type_ids      = array_map( 'intval', (array) ( $attributes['typeIds'] ?? [] ) );
$featured_only = ! empty( $attributes['featuredOnly'] ) ? 'true' : 'false';

// On an event_type taxonomy archive, auto-filter to the queried term.
if ( is_tax( 'event_type' ) ) {
	$queried = get_queried_object();
	if ( $queried instanceof \WP_Term ) {
		$type_ids = array_values( array_unique( array_merge( $type_ids, [ $queried->term_id ] ) ) );
	}
}

// On an event_venue taxonomy archive, auto-filter to the queried venue.
if ( is_tax( 'event_venue' ) ) {
	$queried = get_queried_object();
	if ( $queried instanceof \WP_Term ) {
		$venue_ids = array_values( array_unique( array_merge( $venue_ids, [ $queried->term_id ] ) ) );
	}
}

$rest_url = esc_url_raw( rest_url( 'blockendar/v1' ) );

// Resolve the site's IANA timezone identifier for FullCalendar.
// FullCalendar requires either 'local', 'UTC', or a valid IANA timezone name.
// wp_timezone_string() can return a UTC-offset string (e.g. '-4:00') when
// the site uses a manual offset — fall back to 'local' in that case so the
// calendar uses the visitor's browser timezone rather than UTC.
// Note: timezone_mode='event' cannot be honoured in the calendar view because
// FullCalendar is single-timezone-per-view; per-event timezone display is
// handled by the individual single-event blocks instead.
$raw_tz        = wp_timezone_string();
$site_timezone = preg_match( '/^[A-Za-z]/', $raw_tz ) ? $raw_tz : 'local';

$data_attrs = [
	'data-rest-url'      => $rest_url,
	'data-default-view'  => $default_view,
	'data-first-day'     => (string) $first_day,
	'data-slot-duration' => $slot_duration,
	'data-enabled-views' => wp_json_encode( $enabled_views ),
	'data-featured-only' => $featured_only,
	'data-venue-ids'     => wp_json_encode( array_values( $venue_ids ) ),
	'data-type-ids'      => wp_json_encode( array_values( $type_ids ) ),
	'data-timezone'      => $site_timezone,
];

$data_attr_str = '';
foreach ( $data_attrs as $key => $value ) {
	$data_attr_str .= ' ' . $key . '="' . esc_attr( $value ) . '"';
}

/*
 * Subscribe link. Built from the filters resolved above, so a calendar scoped
 * to a taxonomy archive hands out a feed scoped the same way.
 *
 * Suppressed entirely unless the feed is publicly readable: a private feed
 * would need its token in this href, and that token is a bearer credential
 * that must never reach public markup.
 */
$subscribe_ical   = ! empty( $attributes['subscribeIcal'] ?? true );
$subscribe_google = ! empty( $attributes['subscribeGoogle'] ?? true );

$show_subscribe = ! empty( $attributes['showSubscribe'] )
	&& ( $subscribe_ical || $subscribe_google )
	&& \Blockendar\ICS\FeedUrl::is_publicly_readable();

$subscribe_filters = [
	'venue_ids' => $venue_ids,
	'type_ids'  => $type_ids,
	'featured'  => ! empty( $attributes['featuredOnly'] ),
];

/*
 * The two services want opposite things. Apple and Outlook follow webcal://,
 * which hands the link to a calendar app. Google rejects a webcal link in its
 * own UI but requires one inside the cid parameter of its subscribe URL.
 */
$ical_url   = $show_subscribe ? \Blockendar\ICS\FeedUrl::build( $subscribe_filters, true ) : '';
$google_url = $show_subscribe ? \Blockendar\ICS\FeedUrl::google_subscribe_url( $subscribe_filters ) : '';

$subscribe_label = trim( (string) ( $attributes['subscribeLabel'] ?? '' ) );
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $data_attr_str; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>>
</div>
<?php if ( $show_subscribe ) : ?>
	<div class="blockendar-calendar-subscribe">
		<?php if ( '' !== $subscribe_label ) : ?>
			<span class="blockendar-calendar-subscribe__label">
				<?php echo esc_html( $subscribe_label ); ?>
			</span>
		<?php endif; ?>

		<?php if ( $subscribe_ical ) : ?>
			<a
				class="blockendar-calendar-subscribe__link"
				href="<?php echo esc_url( $ical_url, [ 'webcal', 'http', 'https' ] ); ?>">
				<?php esc_html_e( 'iCalendar', 'blockendar' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( $subscribe_google ) : ?>
			<a
				class="blockendar-calendar-subscribe__link"
				href="<?php echo esc_url( $google_url ); ?>"
				target="_blank"
				rel="noopener noreferrer">
				<?php esc_html_e( 'Google Calendar', 'blockendar' ); ?>
			</a>
		<?php endif; ?>
	</div>
<?php endif; ?>
