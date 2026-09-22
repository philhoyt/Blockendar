<?php
/**
 * Serialized block markup for the guided tour pages.
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

namespace Blockendar\Demo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the block markup for each tour page.
 *
 * Every single-event block (event-datetime, event-venue, event-cost, …) resolves
 * its event through blockendar_block_event_id(), which returns 0 unless the
 * context post is a blockendar_event. On a plain page that means they render
 * nothing. So the pages that show those blocks wrap them in
 * blockendar/events-query > blockendar/event-template, which sets the global
 * post per row before rendering inner blocks.
 */
class Content {

	/**
	 * The tour pages, in nav order. Slug => title.
	 */
	public const PAGES = [
		'blockendar-demo'     => 'Blockendar Demo',
		'calendar'            => 'Calendar',
		'find-an-event'       => 'Find an Event',
		'anatomy-of-an-event' => 'Anatomy of an Event',
		'venues-and-maps'     => 'Venues & Maps',
		'subscribe'           => 'Subscribe',
	];

	/**
	 * Nav labels, keyed by slug. Shorter than the page titles.
	 */
	private const NAV_LABELS = [
		'blockendar-demo'     => 'Start',
		'calendar'            => 'Calendar',
		'find-an-event'       => 'Find an Event',
		'anatomy-of-an-event' => 'Event Anatomy',
		'venues-and-maps'     => 'Venues & Maps',
		'subscribe'           => 'Subscribe',
	];

	/**
	 * Build a page's content.
	 *
	 * @param string                $slug Page slug.
	 * @param array<string, string> $nav  Slug => permalink for every tour page.
	 */
	public function for_slug( string $slug, array $nav ): string {
		$body = match ( $slug ) {
			'blockendar-demo'     => $this->landing(),
			'calendar'            => $this->calendar(),
			'find-an-event'       => $this->find_an_event(),
			'anatomy-of-an-event' => $this->anatomy(),
			'venues-and-maps'     => $this->venues(),
			'subscribe'           => $this->subscribe( $nav ),
			default               => '',
		};

		return $this->tour_nav( $nav, $slug ) . "\n\n" . $body;
	}

	/**
	 * A row of buttons linking every tour page except the current one.
	 *
	 * This is deliberately in-page markup rather than a wp_navigation post wired
	 * into the theme's header: the header template part belongs to whichever
	 * theme is active, and core/navigation's no-menu fallback is not documented.
	 * A button row renders the same under any block theme.
	 *
	 * @param array<string, string> $nav     Slug => permalink.
	 * @param string                $current Slug to render as plain text.
	 */
	private function tour_nav( array $nav, string $current ): string {
		$buttons = '';

		foreach ( self::NAV_LABELS as $slug => $label ) {
			if ( $slug === $current || empty( $nav[ $slug ] ) ) {
				continue;
			}

			$buttons .= sprintf(
				'<!-- wp:button {"className":"is-style-outline"} -->' . "\n"
					. '<div class="wp-block-button is-style-outline">'
					. '<a class="wp-block-button__link wp-element-button" href="%s">%s</a></div>' . "\n"
					. '<!-- /wp:button -->' . "\n\n",
				esc_url( $nav[ $slug ] ),
				esc_html( $label )
			);
		}

		if ( '' === $buttons ) {
			return '';
		}

		return '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->' . "\n"
			. '<div class="wp-block-buttons">' . "\n" . rtrim( $buttons ) . '</div>' . "\n"
			. '<!-- /wp:buttons -->';
	}

	/**
	 * A heading + paragraph intro block pair.
	 */
	private function intro( string $heading, string $text ): string {
		return sprintf(
			'<!-- wp:heading {"level":2} -->' . "\n" . '<h2 class="wp-block-heading">%s</h2>' . "\n" . '<!-- /wp:heading -->'
				. "\n\n" . '<!-- wp:paragraph -->' . "\n" . '<p>%s</p>' . "\n" . '<!-- /wp:paragraph -->',
			esc_html( $heading ),
			wp_kses_post( $text )
		);
	}

	/**
	 * Landing page: what the plugin is, plus a featured-events strip.
	 */
	private function landing(): string {
		return $this->intro(
			'A block-native events plugin',
			'Blockendar stores every event occurrence in its own indexed table, so calendars and queries stay fast no matter how many recurring series you add. Everything on this demo site was generated when the demo plugin was activated, which is why the dates are always current. Use the buttons above to walk through the plugin\'s blocks.'
		)
			. "\n\n" . $this->intro(
				'Coming up',
				'The list below is a <strong>Events Query</strong> block in its list layout, showing the next few events.'
			)
			. "\n\n" . $this->events_query(
				[
					'perPage'        => 4,
					'showPagination' => false,
				],
				$this->event_card()
			);
	}

	/**
	 * Calendar page: the full calendar-view block.
	 */
	private function calendar(): string {
		return $this->intro(
			'The Event Calendar block',
			'A full calendar with month, week and list views. Click any event to open it. Recurring events are expanded from the index table, so a weekly series shows every occurrence without creating a post per week.'
		)
			. "\n\n" . '<!-- wp:blockendar/calendar-view {"defaultView":"dayGridMonth"} /-->';
	}

	/**
	 * Find an Event: the filter wrapper, view switcher, query and pagination.
	 */
	private function find_an_event(): string {
		$inner = '<!-- wp:blockendar/filter-event-type {"label":"Type"} /-->' . "\n\n"
			. '<!-- wp:blockendar/filter-venue {"label":"Venue"} /-->' . "\n\n"
			. '<!-- wp:blockendar/filter-date-range {"label":"Dates"} /-->' . "\n\n"
			. '<!-- wp:blockendar/query-view-switcher {"showLabels":true} /-->' . "\n\n"
			. $this->events_query(
				[
					'perPage'        => 6,
					'showPagination' => true,
				],
				$this->event_card()
			);

		return $this->intro(
			'Filters, view switching and pagination',
			'The filter blocks live inside an <strong>Events Query Filters</strong> wrapper, which also contains the query itself — that is how the filters know which query to narrow. Filtering happens through URL parameters, so it works with JavaScript disabled.'
		)
			. "\n\n" . '<!-- wp:blockendar/query-filters {"queryId":"tour"} -->' . "\n"
			. '<div class="wp-block-blockendar-query-filters">' . "\n" . $inner . "\n" . '</div>' . "\n"
			. '<!-- /wp:blockendar/query-filters -->';
	}

	/**
	 * Anatomy: every single-event block, for one upcoming event.
	 */
	private function anatomy(): string {
		$template = '<!-- wp:blockendar/event-status /-->' . "\n\n"
			. '<!-- wp:post-title {"level":3,"isLink":true} /-->' . "\n\n"
			. '<!-- wp:blockendar/event-datetime {"showTimezone":true} /-->' . "\n\n"
			. '<!-- wp:blockendar/event-countdown /-->' . "\n\n"
			. '<!-- wp:blockendar/event-venue /-->' . "\n\n"
			. '<!-- wp:blockendar/event-cost /-->' . "\n\n"
			. '<!-- wp:post-excerpt /-->' . "\n\n"
			. '<!-- wp:blockendar/add-to-calendar /-->';

		return $this->intro(
			'The single-event blocks',
			'Status badge, date and time, countdown, venue, cost and an add-to-calendar button. These blocks read their event from block context, so they only render inside an event query or on a single event template — dropping them on a blank page shows nothing.'
		)
			. "\n\n" . $this->events_query(
				[
					'perPage'        => 1,
					'showPagination' => false,
				],
				$template
			);
	}

	/**
	 * Venues & Maps: venue block plus the Leaflet map.
	 */
	private function venues(): string {
		$template = '<!-- wp:post-title {"level":3,"isLink":true} /-->' . "\n\n"
			. '<!-- wp:blockendar/event-venue /-->' . "\n\n"
			. '<!-- wp:blockendar/event-map /-->';

		return $this->intro(
			'Venues and maps',
			'Venues are a taxonomy, so an event can be assigned one and inherit its address, coordinates and capacity. The map block lazy-loads Leaflet and only renders when the venue has coordinates. The demo also includes a virtual venue with no map data, and events with no venue at all, so you can see the fallbacks.'
		)
			. "\n\n" . $this->events_query(
				[
					'perPage'        => 3,
					'showPagination' => false,
				],
				$template
			);
	}

	/**
	 * Subscribe: iCal feed and the archive templates.
	 *
	 * @param array<string, string> $nav Slug => permalink.
	 */
	private function subscribe( array $nav ): string {
		$events_url = home_url( '/' . ltrim( \Blockendar\Admin\SettingsPage::events_slug(), '/' ) . '/' );
		$feed_url   = rest_url( 'blockendar/v1/calendar?format=ics' );

		return $this->intro(
			'Subscribe and export',
			'Every event carries an <strong>Add to Calendar</strong> block that produces a per-event <code>.ics</code> download plus Google and Outlook links. The whole calendar is also available as a single subscribable feed.'
		)
			. "\n\n" . sprintf(
				'<!-- wp:paragraph -->' . "\n" . '<p>Full iCal feed: <a href="%1$s"><code>%1$s</code></a></p>' . "\n" . '<!-- /wp:paragraph -->',
				esc_url( $feed_url )
			)
			. "\n\n" . $this->intro(
				'Archive templates',
				'The plugin registers block templates for the events archive and for each event type, so the archive works without any theme changes.'
			)
			. "\n\n" . sprintf(
				'<!-- wp:paragraph -->' . "\n" . '<p><a href="%1$s">Browse the events archive</a></p>' . "\n" . '<!-- /wp:paragraph -->',
				esc_url( $events_url )
			)
			. "\n\n" . $this->events_query(
				[
					'perPage'        => 3,
					'showPagination' => false,
				],
				'<!-- wp:post-title {"level":3,"isLink":true} /-->' . "\n\n"
					. '<!-- wp:blockendar/event-datetime /-->' . "\n\n"
					. '<!-- wp:blockendar/add-to-calendar /-->'
			)
			. ( empty( $nav['calendar'] ) ? '' : "\n\n" . sprintf(
				'<!-- wp:paragraph {"align":"center"} -->' . "\n" . '<p class="has-text-align-center"><a href="%s">Back to the calendar</a></p>' . "\n" . '<!-- /wp:paragraph -->',
				esc_url( $nav['calendar'] )
			) );
	}

	/**
	 * The default event card used in most queries.
	 */
	private function event_card(): string {
		return '<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"16/9"} /-->' . "\n\n"
			. '<!-- wp:blockendar/event-status /-->' . "\n\n"
			. '<!-- wp:post-title {"level":3,"isLink":true} /-->' . "\n\n"
			. '<!-- wp:blockendar/event-datetime /-->' . "\n\n"
			. '<!-- wp:blockendar/event-venue /-->' . "\n\n"
			. '<!-- wp:blockendar/event-cost /-->';
	}

	/**
	 * Wrap an event template in an events-query block.
	 *
	 * @param array<string, mixed> $attrs    events-query attributes.
	 * @param string               $template Inner blocks for event-template.
	 */
	private function events_query( array $attrs, string $template ): string {
		$json = wp_json_encode( $attrs );

		return sprintf(
			'<!-- wp:blockendar/events-query %s -->' . "\n"
				. '<!-- wp:blockendar/event-template -->' . "\n%s\n" . '<!-- /wp:blockendar/event-template -->' . "\n"
				. '<!-- /wp:blockendar/events-query -->',
			is_string( $json ) ? $json : '{}',
			$template
		);
	}
}
