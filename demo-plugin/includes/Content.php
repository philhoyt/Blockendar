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
		$sections = match ( $slug ) {
			'blockendar-demo'     => $this->landing(),
			'calendar'            => $this->calendar(),
			'find-an-event'       => $this->find_an_event(),
			'anatomy-of-an-event' => $this->anatomy(),
			'venues-and-maps'     => $this->venues(),
			'subscribe'           => $this->subscribe( $nav ),
			default               => [],
		};

		$sections = array_filter( $sections, static fn( string $part ): bool => '' !== $part );

		if ( ! $sections ) {
			return $this->tour_nav( $nav, $slug );
		}

		return $this->tour_nav( $nav, $slug ) . "\n\n" . $this->page_shell( $sections );
	}

	/**
	 * The full-width container every page body sits in.
	 *
	 * Full width plus a constrained inner layout is what makes wide alignment
	 * work: the group spans the viewport, then re-constrains its children, so
	 * text lands at the theme's content size while an .alignwide child — the
	 * calendar, a query, the filter wrapper — gets the wide size. Wrapping
	 * those in an ordinary group instead would cap them at the content width
	 * and undo the alignment.
	 *
	 * Only layout and blockGap are set. Both are resolved server-side from the
	 * attributes, so the saved <div> stays exactly what core/group's save()
	 * would produce; margin or padding here would have to be mirrored as an
	 * inline style or the block fails validation when the page is opened.
	 *
	 * @param string[] $sections Top-level block markup, in order.
	 */
	private function page_shell( array $sections ): string {
		return $this->group(
			implode( "\n\n", $sections ),
			[
				'align'  => 'full',
				'layout' => [ 'type' => 'constrained' ],
				'style'  => [ 'spacing' => [ 'blockGap' => '3rem' ] ],
			]
		);
	}

	/**
	 * A row of buttons linking every tour page.
	 *
	 * This is deliberately in-page markup rather than a wp_navigation post wired
	 * into the theme's header: the header template part belongs to whichever
	 * theme is active, and core/navigation's no-menu fallback is not documented.
	 * A button row renders the same under any block theme.
	 *
	 * @param array<string, string> $nav     Slug => permalink.
	 * @param string                $current Slug of the page being built.
	 */
	private function tour_nav( array $nav, string $current ): string {
		$buttons = '';

		foreach ( self::NAV_LABELS as $slug => $label ) {
			if ( empty( $nav[ $slug ] ) ) {
				continue;
			}

			/*
			 * The current page stays in the row rather than being dropped, so
			 * the nav keeps its shape from page to page and the visitor can see
			 * where they are. It is marked by a filled button against outlined
			 * ones, which is a difference in shape rather than colour alone.
			 *
			 * It is not marked with aria-current: core/button has a static
			 * save(), so an extra attribute on the anchor would fail block
			 * validation the first time the page is opened in the editor.
			 */
			$style = ( $slug === $current ) ? 'is-style-fill' : 'is-style-outline';

			$buttons .= sprintf(
				'<!-- wp:button {"className":"%1$s"} -->' . "\n"
					. '<div class="wp-block-button %1$s">'
					. '<a class="wp-block-button__link wp-element-button" href="%2$s">%3$s</a></div>' . "\n"
					. '<!-- /wp:button -->' . "\n\n",
				esc_attr( $style ),
				esc_url( $nav[ $slug ] ),
				esc_html( $label )
			);
		}

		if ( '' === $buttons ) {
			return '';
		}

		return '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center","flexWrap":"wrap"}} -->' . "\n"
			. '<div class="wp-block-buttons">' . "\n" . rtrim( $buttons ) . '</div>' . "\n"
			. '<!-- /wp:buttons -->';
	}

	/**
	 * Wrap markup in a core/group.
	 *
	 * core/group has a static save(), so the markup here has to match what the
	 * editor would have produced or the block fails validation on open. Two
	 * things follow from that: the class list is built here rather than left to
	 * the server (align and className are baked into save output by the block
	 * supports), and layout / spacing live in the attribute comment only, since
	 * the server derives their CSS from the attributes at render time.
	 *
	 * @param string               $inner Inner block markup.
	 * @param array<string, mixed> $attrs Group attributes.
	 */
	private function group( string $inner, array $attrs = [] ): string {
		$classes = [ 'wp-block-group' ];

		if ( ! empty( $attrs['align'] ) ) {
			$classes[] = 'align' . $attrs['align'];
		}

		if ( ! empty( $attrs['className'] ) ) {
			$classes[] = (string) $attrs['className'];
		}

		$json      = $attrs ? wp_json_encode( $attrs ) : '';
		$delimiter = ( is_string( $json ) && '' !== $json ) ? ' ' . $json : '';

		return '<!-- wp:group' . $delimiter . ' -->' . "\n"
			. '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">' . "\n"
			. $inner . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->';
	}

	/**
	 * Flex layout attributes for a group, with a gap and optional justification.
	 *
	 * Gaps are literal rem values rather than var:preset|spacing|NN. A preset
	 * only resolves if the active theme defines that step, and the demo has to
	 * look right under whatever theme it lands on.
	 *
	 * @param string $justify One of the flex justifyContent values, or ''.
	 * @param string $gap     CSS length for blockGap.
	 * @return array<string, mixed>
	 */
	private function flex( string $justify = '', string $gap = '1rem' ): array {
		$layout = [
			'type'              => 'flex',
			'flexWrap'          => 'wrap',
			'verticalAlignment' => 'bottom',
		];

		if ( '' !== $justify ) {
			$layout['justifyContent'] = $justify;
		}

		return [
			'layout' => $layout,
			'style'  => [ 'spacing' => [ 'blockGap' => $gap ] ],
		];
	}

	/**
	 * A core/paragraph.
	 */
	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph -->' . "\n" . '<p>' . wp_kses_post( $text ) . '</p>' . "\n" . '<!-- /wp:paragraph -->';
	}

	/**
	 * A heading, its paragraph, and anything else that belongs with them,
	 * grouped so they hold together as one block of text.
	 *
	 * The group is constrained but not aligned, so it takes the content width
	 * from its parent. Wide blocks must therefore be siblings of this group,
	 * not children of it.
	 *
	 * @param string $heading Section heading.
	 * @param string $text    Lead paragraph.
	 * @param string ...$rest Further blocks to keep in the same group.
	 */
	private function intro( string $heading, string $text, string ...$rest ): string {
		$inner = sprintf(
			'<!-- wp:heading {"level":2} -->' . "\n" . '<h2 class="wp-block-heading">%s</h2>' . "\n" . '<!-- /wp:heading -->',
			esc_html( $heading )
		) . "\n\n" . $this->paragraph( $text );

		foreach ( $rest as $block ) {
			if ( '' !== $block ) {
				$inner .= "\n\n" . $block;
			}
		}

		return $this->group(
			$inner,
			[
				'layout' => [ 'type' => 'constrained' ],
				'style'  => [ 'spacing' => [ 'blockGap' => '0.8rem' ] ],
			]
		);
	}

	/**
	 * Landing page: what the plugin is, plus a featured-events strip.
	 *
	 * @return string[]
	 */
	private function landing(): array {
		return [
			$this->intro(
				'A block-native events plugin',
				'Blockendar stores every event occurrence in its own indexed table, so calendars and queries stay fast no matter how many recurring series you add. Everything on this demo site was generated when the demo plugin was activated, which is why the dates are always current. Use the buttons above to walk through the plugin\'s blocks.'
			),
			$this->intro(
				'Coming up',
				'The list below is an <strong>Events Query</strong> block in its list layout, showing the next few events.'
			),
			$this->events_query(
				[
					'align'          => 'wide',
					'perPage'        => 4,
					'showPagination' => false,
				],
				$this->event_card()
			),
		];
	}

	/**
	 * Calendar page: the full calendar-view block.
	 *
	 * @return string[]
	 */
	private function calendar(): array {
		return [
			$this->intro(
				'The Event Calendar block',
				'A full calendar with month, week and list views. Click any event to open it. Recurring events are expanded from the index table, so a weekly series shows every occurrence without creating a post per week.'
			),
			'<!-- wp:blockendar/calendar-view {"defaultView":"dayGridMonth","align":"wide"} /-->',
		];
	}

	/**
	 * Find an Event: the filter wrapper, view switcher, query and pagination.
	 *
	 * @return string[]
	 */
	private function find_an_event(): array {
		// The three filters in a row of their own, so they stay together when
		// the outer row wraps and the switcher drops to its own line.
		$filters = $this->group(
			'<!-- wp:blockendar/filter-event-type {"label":"Type"} /-->' . "\n\n"
				. '<!-- wp:blockendar/filter-venue {"label":"Venue"} /-->' . "\n\n"
				. '<!-- wp:blockendar/filter-date-range {"label":"Dates"} /-->',
			$this->flex()
		);

		// Filters left, view switcher right.
		$controls = $this->group(
			$filters . "\n\n" . '<!-- wp:blockendar/query-view-switcher {"showLabels":true} /-->',
			$this->flex( 'space-between', '1.5rem' )
		);

		/*
		 * No "align" on this events-query, unlike the standalone ones on other
		 * pages. Alignment is a layout-container rule -- .is-layout-constrained
		 * > .alignwide -- and query-filters renders a plain div with no layout,
		 * so the class would match nothing here. The wide width comes from the
		 * query-filters wrapper, and the query fills it.
		 */
		$inner = $controls . "\n\n"
			. $this->events_query(
				[
					'perPage'        => 6,
					'showPagination' => true,
				],
				$this->event_card()
			);

		return [
			$this->intro(
				'Filters, view switching and pagination',
				'The filter blocks live inside an <strong>Events Query Filters</strong> wrapper, which also contains the query itself — that is how the filters know which query to narrow. Filtering happens through URL parameters, so it works with JavaScript disabled.'
			),

			// query-filters saves <InnerBlocks.Content /> — no wrapper element —
			// and its render.php emits the wrapping div server-side. Adding one
			// here would double-wrap the block and fail validation in the editor.
			'<!-- wp:blockendar/query-filters {"queryId":"tour","align":"wide"} -->' . "\n"
				. $inner . "\n"
				. '<!-- /wp:blockendar/query-filters -->',
		];
	}

	/**
	 * Anatomy: every single-event block, for one upcoming event.
	 *
	 * @return string[]
	 */
	private function anatomy(): array {
		$template = '<!-- wp:blockendar/event-status /-->' . "\n\n"
			. '<!-- wp:post-title {"level":3,"isLink":true} /-->' . "\n\n"
			. '<!-- wp:blockendar/event-datetime {"showTimezone":true,"fontSize":"small"} /-->' . "\n\n"
			. '<!-- wp:blockendar/event-countdown /-->' . "\n\n"
			. '<!-- wp:blockendar/event-venue {"fontSize":"small"} /-->' . "\n\n"
			. '<!-- wp:blockendar/event-cost {"fontSize":"small"} /-->' . "\n\n"
			. '<!-- wp:post-excerpt /-->' . "\n\n"
			. '<!-- wp:blockendar/add-to-calendar /-->';

		return [
			$this->intro(
				'The single-event blocks',
				'Status badge, date and time, countdown, venue, cost and an add-to-calendar button. These blocks read their event from block context, so they only render inside an event query or on a single event template — dropping them on a blank page shows nothing.'
			),
			$this->events_query(
				[
					'align'          => 'wide',
					'perPage'        => 1,
					'showPagination' => false,
				],
				$template
			),
		];
	}

	/**
	 * Venues & Maps: venue block plus the Leaflet map.
	 *
	 * @return string[]
	 */
	private function venues(): array {
		$template = '<!-- wp:post-title {"level":3,"isLink":true} /-->' . "\n\n"
			. '<!-- wp:blockendar/event-venue {"fontSize":"small"} /-->' . "\n\n"
			. '<!-- wp:blockendar/event-map /-->';

		return [
			$this->intro(
				'Venues and maps',
				'Venues are a taxonomy, so an event can be assigned one and inherit its address, coordinates and capacity. The map block lazy-loads Leaflet and only renders when the venue has coordinates. The demo also includes a virtual venue with no map data, and events with no venue at all, so you can see the fallbacks.'
			),
			$this->events_query(
				[
					'align'          => 'wide',
					'perPage'        => 3,
					'showPagination' => false,
				],
				$template
			),
		];
	}

	/**
	 * Subscribe: iCal feed and the archive templates.
	 *
	 * @param array<string, string> $nav Slug => permalink.
	 * @return string[]
	 */
	private function subscribe( array $nav ): array {
		$events_url = home_url( '/' . ltrim( \Blockendar\Admin\SettingsPage::events_slug(), '/' ) . '/' );
		$feed_url   = rest_url( 'blockendar/v1/calendar?format=ics' );

		return [
			$this->intro(
				'Subscribe and export',
				'Every event carries an <strong>Add to Calendar</strong> block that produces a per-event <code>.ics</code> download plus Google and Outlook links. The whole calendar is also available as a single subscribable feed.',
				$this->paragraph(
					sprintf( 'Full iCal feed: <a href="%1$s"><code>%1$s</code></a>', esc_url( $feed_url ) )
				)
			),
			$this->intro(
				'Archive templates',
				'The plugin registers block templates for the events archive and for each event type, so the archive works without any theme changes.',
				$this->paragraph(
					sprintf( '<a href="%s">Browse the events archive</a>', esc_url( $events_url ) )
				)
			),
			$this->events_query(
				[
					'align'          => 'wide',
					'perPage'        => 3,
					'showPagination' => false,
				],
				'<!-- wp:post-title {"level":3,"isLink":true} /-->' . "\n\n"
					. '<!-- wp:blockendar/event-datetime {"fontSize":"small"} /-->' . "\n\n"
					. '<!-- wp:blockendar/add-to-calendar /-->'
			),
			empty( $nav['calendar'] ) ? '' : sprintf(
				'<!-- wp:paragraph {"align":"center"} -->' . "\n" . '<p class="has-text-align-center"><a href="%s">Back to the calendar</a></p>' . "\n" . '<!-- /wp:paragraph -->',
				esc_url( $nav['calendar'] )
			),
		];
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
