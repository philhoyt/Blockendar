# Blockendar

[![CI](https://github.com/philhoyt/Blockendar/actions/workflows/ci.yml/badge.svg)](https://github.com/philhoyt/Blockendar/actions/workflows/ci.yml)
[![Playground Demo](https://img.shields.io/badge/Playground_Demo-blue?logo=wordpress&logoColor=%23fff&labelColor=%233858e9&color=%23386be9)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/philhoyt/Blockendar/main/_playground/blueprint.json)

A block-native WordPress events plugin.

## Features

- **Block-based event editor** — Date & time, recurrence, venue, cost, registration, and status managed through dedicated block editor sidebar panels
- **Recurring events** — Full recurrence rule support (daily, weekly, monthly, yearly) with exceptions, custom additions, and a rolling horizon cron job
- **Calendar View block** — Interactive FullCalendar-powered calendar with day, week, and month views; exposes a valid iCal feed and an opt-in subscribe button
- **Calendar subscriptions** — A live `webcal://` feed people can subscribe to in Apple Calendar, Google Calendar, or Outlook; it rolls forward with the date rather than going stale
- **Events Query block** — Flexible query block for custom event displays; shows individual occurrences of recurring events with correct dates and occurrence-aware links
- **7 single-event blocks** — Date/time, venue, cost, status, countdown, map, add-to-calendar
- **Custom database layer** — All date range queries run against a dedicated indexed table (`{prefix}blockendar_events`)
- **REST API** — Full read/write API under `blockendar/v1`, including iCal feed and index rebuild endpoints
- **Venues** — Taxonomy with rich meta (address, coordinates, capacity, website) and inline venue creation in the editor
- **Admin settings** — Date/time formats, timezone mode, calendar defaults, map provider, currency, recurrence horizon, and more
- **GitHub-based updates** — Automatic update notifications in wp-admin via tagged releases

## Requirements

| | Minimum | Tested up to |
|---|---|---|
| WordPress | 6.8 | 7.1 |
| PHP | 8.1 | 8.1 |

## Installation

1. Go to the [Releases page](https://github.com/philhoyt/Blockendar/releases) and download the latest `blockendar.zip` asset.
2. In your WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the downloaded `.zip` file and click **Install Now**.
4. Click **Activate Plugin**.

## Try the demo

The [Playground demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/philhoyt/Blockendar/main/_playground/blueprint.json)
runs the plugin in your browser with no install.

To preview the working tree instead — no Docker needed, it runs on php-wasm:

```bash
npm run playground   # http://127.0.0.1:9400
```

To get the same content on a site of your own, download `blockendar_demo.zip`
from the [Releases page](https://github.com/philhoyt/Blockendar/releases) and
install it alongside Blockendar. Activating it creates 31 events, 5 venues and a
six-page guided tour. Every date is generated when you activate it, so the demo
is always current.

Manage it from **Tools → Blockendar Demo**, or with `wp blockendar-demo seed`
and `wp blockendar-demo reset`. Reset removes only what the demo created and
restores your previous front page setting — your own content is never touched.

## Migrating from The Events Calendar

Export your events on the source site (**Tools → Export**, choosing *Events*),
then import them with WP-CLI:

```bash
wp blockendar import-tribe ./tribe-events.xml --dry-run   # report only
wp blockendar import-tribe ./tribe-events.xml
```

There is no upload screen. A one-off migration does not need a permanent HTTP
endpoint, and a real export runs to thousands of events — more than a single
web request can parse and insert before it times out.

## Development

```bash
npm run start          # Watch mode
npm run build          # Production build
npm run lint:js        # JS lint
npm run lint:css       # CSS lint
npm run lint:php       # PHP lint (WPCS)
npm run lint:php:fix   # Auto-fix PHP lint issues
npm run plugin-zip     # Build distributable zip
npm run plugin-zip:demo # Build the demo companion zip
```

The demo companion plugin lives in [`demo-plugin/`](demo-plugin/) and ships as
its own zip.

## Architecture

- **Namespace:** `Blockendar\` → `includes/` (PSR-4)
- **REST namespace:** `blockendar/v1`
- **CPT:** `blockendar_event` (authoring only — never queried by date via `WP_Query`)
- **Taxonomies:** `blockendar_event_type` (hierarchical), `blockendar_event_tag` (flat), `blockendar_event_venue` (hierarchical)
- **DB tables:** `{prefix}blockendar_events` (occurrence index), `{prefix}blockendar_recurrence` (RRULE storage)

### Calendar subscriptions

The calendar feed is served from `GET /wp-json/blockendar/v1/calendar?format=ics`.
The same URL with a `webcal://` scheme is what calendar apps expect; both forms
are shown under **Events → Settings → REST API**.

Requested without `start` and `end`, the feed returns a window relative to the
moment of the request — 30 days back and 365 days ahead by default — so a
subscription keeps moving instead of freezing on the range it was first fetched
with. Both bounds are configurable in settings. The feed cannot show events
further ahead than the recurrence horizon has generated.

The Calendar View block can show two subscribe buttons, each toggleable:
**iCalendar** for Apple Calendar, Outlook and most other apps, and **Google
Calendar** for a one-click add. Both inherit the block's venue, type and
featured filters, so a filtered calendar hands out a matching feed. Both are
hidden whenever **Public REST endpoints** is turned off, because linking a
private feed would mean publishing its token.

The two services want opposite things, which is why the settings screen offers
both URL forms:

| Destination | Scheme | Notes |
|-------------|--------|-------|
| Apple Calendar, Outlook | `webcal://` | Handed to the OS, which opens a calendar app |
| Google, pasted into **From URL** | `https://` | Google rejects a `webcal://` link here |
| Google, one-click button | `https://www.google.com/calendar/render?cid=` + encoded `webcal://` URL | Google rejects an `https://` cid |

Google fetches the feed from its own servers, so a Google subscription needs the
site reachable over HTTPS, and needs `robots.txt` not to block the feed path.
Google also polls on its own schedule, often every 8-24 hours, regardless of the
`REFRESH-INTERVAL` the feed advertises.

Four filters are available:

| Filter | Purpose |
|--------|---------|
| `blockendar_ics_window` | The `[ start, end ]` window a subscribed feed covers |
| `blockendar_ics_max_events` | Ceiling on events in one feed (default 2000) |
| `blockendar_ics_refresh_interval` | Refresh hint as an iCalendar duration (default `PT1H`) |
| `blockendar_ics_calendar_name` | The calendar name shown in a subscriber app |

iCalendar has no pagination, so events past the ceiling are simply absent. A feed
that hits it says so in `X-WR-CALDESC` and raises a notice on the settings screen.

## Privacy

Blockendar stores no personal data about site visitors — no names, email
addresses, or IP addresses, and no cookies.

Two third-party requests to OpenStreetMap are possible:

- **Geocoding (admin, on demand).** The "Look up coordinates" button on the venue
  edit screen sends the venue address to
  [Nominatim](https://nominatim.openstreetmap.org) to resolve it to latitude and
  longitude. It runs from the browser only when clicked; entering coordinates
  manually sends nothing.
- **Map tiles (front end).** The Event Map block loads tiles from
  `tile.openstreetmap.org` in the visitor's browser when a map is displayed.

See the [OpenStreetMap privacy policy](https://osmfoundation.org/wiki/Privacy_Policy).

## Changelog

### 2.0.0
- Changed: the three taxonomies are renamed from `event_type`, `event_tag` and `event_venue` to `blockendar_event_type`, `blockendar_event_tag` and `blockendar_event_venue`, so they can no longer collide with another events plugin that uses the same names. Existing sites are migrated the first time a page loads after the update: event types, tags and venues with their assignments and settings, classic menu items that point at them, Site Editor template customisations, and the core blocks that name a taxonomy (Post Terms, Categories List, Tag Cloud, Post Navigation Link, Navigation links, Query Loop filters) in pages and block widgets are all moved. Permalinks, REST routes and calendar feed URLs are unchanged.
- Added: `wp blockendar migrate-taxonomies` with `--status`, `--dry-run`, `--run`, `--rollback` and `--clear-backups`. Under WP-CLI the migration runs only when asked, so a site can be inspected before its first page load.
- Changed: when another plugin already has data under the old names, or anything exists under the new ones, the migration refuses to run, shows administrators a notice saying why, and retries hourly. Nothing is moved until the conflict is resolved.
- Not migrated, and needing a manual update where present: theme or snippet code that names the old taxonomies or hooks into `created_event_venue`, `event_type_edit_form_fields` and similar; custom CSS targeting core's `taxonomy-event_type` or `tax-event_type` classes; SEO and translation plugin settings stored per taxonomy name; hidden or reordered editor metaboxes, which reset; and restoring a revision saved before 2.0.0, which brings the old names back into that post.

### 1.8.2
- Fixed: the Event Venue block always showed its sample venue, "The Grand Ballroom", in the editor instead of the venue assigned to the event. It was reading the wrong field from the REST API. Visitors were never affected.
- Fixed: the Event Date & Time block showed the wrong date and time in the editor for anyone whose browser timezone differed from the site's. The values were shifted by the difference, and from a timezone ahead of the site the date moved back a day. Visitors were never affected.
- Changed: the automated test suites now fail on any PHP notice, warning, deprecation or database error, which used to be logged and ignored, and the editor previews of the Calendar View, Events Query and Event Date & Time blocks are covered by browser tests.

### 1.8.1
- Changed: listings of past events are faster on sites with a large archive. Two database indexes are added on update, which happens once and does not rebuild your events.
- Fixed: the plugin rewrote the columns of all three of its database tables on every page load. The table definitions were laid out in aligned columns, which WordPress reads as a changed column type, so it issued a rewrite each time it checked.

### 1.8.0
- Security: an event's recurrence rule and individual occurrences could be changed by any contributor, including on events they do not own. Those four endpoints now check permission against the specific event rather than the general ability to edit posts.
- Security: password-protected events no longer appear in the REST API, the calendar feed or the iCalendar export. Their title, dates and venue address were readable by anyone despite the password.
- Security: the calendar feed token is generated with the browser's cryptographic random source, and a token shorter than 16 characters is refused rather than stored.
- Fixed: the Recurring Events settings had no effect. The generation horizon stayed at 365 days and the instance limit at its built-in ceiling, whatever was saved.
- Fixed: the Calendar and General settings had no effect. Default view, first day of week, slot duration and timezone display now apply, and a calendar block can still override the first two.
- Fixed: sorting the events list by start date left its database changes in place for the rest of the page, which could disturb other queries running on that screen.
- Fixed: deleting the plugin left tables and settings behind on every site of a multisite network except the one it was deleted from, along with a stored notice and two scheduled jobs.
- Fixed: the date filter could not be used with a keyboard. Opening the panel left no way to reach or type a date, only to clear or apply.
- Fixed: the calendar showed nothing when JavaScript was unavailable or slow to load. It now lists upcoming events from the server, and the events archive and event type archive are readable without scripts.
- Fixed: the event map showed nothing when JavaScript was unavailable. It now prints the venue name and address, which the map replaces once it loads.
- Fixed: screen reader and keyboard problems across the filter, countdown, status and time controls: unlabelled time fields in the editor, filter lists that misreported their grouping, a countdown that changed under the cursor once a second, and a view switch that gave no indication anything had happened.
- Fixed: translations of text in the block editor and the settings screen were never loaded, so those strings stayed in English however complete the translation.
- Changed: the Venue and Type filters apply when the Apply button is pressed, rather than the moment a choice is made. Changing a choice with the keyboard used to navigate away before the intended option was reached.
- Changed: the nightly recurrence job adds the occurrences that have come into range instead of rebuilding every occurrence of every recurring event, which could exceed the time limit on a busy site and quietly stop.
- Changed: event listings, the calendar feed and the iCalendar export load the data they need in bulk rather than once per event.
- Changed: the events archive and the event type archive draw their calendar at wide width, so a month grid has room for its day cells. A site that has customised either template in the Site Editor keeps its own copy and sees no change.
- Changed: the date filter's closed label shows numeric dates, so a selected range stays readable in a narrow column.
- Changed: both date format fields say they take a PHP date format.
- Added: suggested privacy policy text under Settings > Privacy, covering the map tiles shown to visitors and the address lookup used when adding a venue.
- Removed: the Import screen for The Events Calendar. Importing now runs from the command line with `wp blockendar import-tribe <file>`, which suits an export of any size; the screen could not finish a large one.

### 1.7.0
- Added: colour, typography, spacing and border controls on the Event Venue block, bringing it in line with the other single-event blocks.
- Changed: the filter blocks can sit at any depth inside the Events Query Filters block. Wrapping Type, Venue and Dates in a Group to lay them out in a row now works; before this they had to be direct children.
- Changed: the events archive and the event type archive draw their calendar at wide width, so a month grid has room for its day cells. A site that has customised either template in the Site Editor keeps its own copy and sees no change.

### 1.6.0
- Added: calendar subscriptions. The Event Calendar block can show **iCalendar** and **Google Calendar** buttons that let visitors subscribe to your events, so their calendar keeps up with yours instead of taking a one-time copy. Both buttons carry whatever venue, type and featured filters the block is set to, and both are hidden while public REST access is turned off, because a private feed cannot be linked without publishing its access token. Settings > REST API shows both feed URLs with copy buttons.
- Added: settings for how much of the calendar a subscription covers, 30 days back and 365 days ahead by default. The window moves with the date, so a subscription that worked in January still shows this month's events in June.
- Fixed: the calendar feed at `/blockendar/v1/calendar?format=ics` returned its contents as JSON rather than iCalendar, so no calendar app could read it. The feed had never worked; it now returns a valid calendar.
- Fixed: downloading a single event produced a different identifier than the same event in the feed, so anyone who subscribed and also downloaded an event saw it twice. Single-event downloads now also include the venue and status, and no longer produce over-long lines that stricter calendar apps reject.
- Fixed: cancelling one occurrence of a repeating event could rewrite the rest of the series. A weekly Monday and Wednesday class lost its days, an every-third-week event became weekly, and an event with an end date failed outright. Rules already damaged this way are not repaired automatically and need checking by hand.
- Fixed: the calendar feed URLs on the settings screen were cut off partway through, so the address could not be read or copied in full.
- Security: a carriage return in an event title, description or venue field could add entries to an exported calendar file. Line breaks in those fields are now escaped.

### 1.5.1
- Fixed: updating from 1.5.0 could install the companion demo plugin over Blockendar. The update checker was not told which release asset to use, so it took the first one GitHub listed, and `blockendar-demo.zip` sorted ahead of `blockendar.zip`. The update checker now matches `blockendar.zip` exactly, and the demo asset is named `blockendar_demo.zip` so it sorts last. If you were affected, reinstall Blockendar from the Releases page; your events and settings are untouched.

### 1.5.0
- Added: a companion demo plugin, released as `blockendar-demo.zip`. Activating it fills a site with 31 events, 6 event types, 5 venues and a six-page guided tour of the plugin's blocks. Dates are generated at activation, so the demo does not go stale.
- Added: `wp blockendar-demo seed` and `wp blockendar-demo reset`, plus a Tools > Blockendar Demo screen for the same two actions.
- Fixed: the Playground demo booted an empty site. It imported a placeholder WXR file that carried no content, and its landing page pointed at a page that was never created.
- Removed: `bin/generate-test-events.php`. Its fixtures now live in the demo plugin and are available through the seeder.

### 1.4.0
- Added: a "Hide events" setting on the Events Query block that chooses when an event leaves the upcoming list — when it ends, at the end of its day, or a number of hours after it ends. The same moment decides when an event counts as past, so an event is never in both an upcoming and a past list at once. Developers can override the cutoff with the `blockendar_events_query_cutoff` filter.
- Changed: events now stay in upcoming lists until the end of their day by default, and enter past lists the following day. Before, an event dropped out the moment it ended, and an event with no end time dropped out at its start time.
- Changed: the venue and type filters offer a venue or type whose only event ended earlier today, matching what the query lists.
- Fixed: the date range filter treated the chosen dates as UTC, so on sites in other timezones a filter for today could miss events at the start or end of the day.

### 1.3.3
- Fixed: an open filter dropdown could be painted underneath content further down the page when a page builder wraps blocks in groups with their own stacking context (Advanced Columns and similar). The open panel now uses the browser's top layer, so nothing on the page can cover or clip it.
- Fixed: a filter's dropdown was only as wide as its button, so a venue such as "Online / Livestream" wrapped at the first word. The panel now widens to fit its options, at least as wide as the button and never wider than the screen.

### 1.3.2
- Fixed: after switching the Events Query to grid with the View Switcher, clicking a pagination link loaded the next page as a list. Pagination links, filter forms and filter "clear" links now follow the chosen view.
- Fixed: submitting the date range filter or the dropdown type filter dropped a chosen grid view even on a fresh page load, and every filter did with JavaScript off. The view now travels with every filter submission.
- Fixed: in the type filter's list style, a date range already in the URL was lost when a type was ticked.
- Fixed: the filter dropdowns rendered open and then snapped shut as the page loaded. A one-line inline script now marks the page as JavaScript-capable ahead of the first filter, so the closed state is painted from the start; with JavaScript off the panels stay visible as before.
- Fixed: filter controls in a Row shared the space evenly regardless of their labels, so "All venues" was truncated beside "All dates" and resized whenever a date range was chosen or cleared. Each control now keeps at least the width of its own label.

### 1.3.1
- Fixed: "Show past" listed events that had started but not finished — an exhibit still open for another month appeared under past events. Past now means the event has ended; ongoing events never appear there, and past listings default to most recent first.
- Fixed: a single-event block placed on a page, in a template part, or rendered outside a post (for example from WP-CLI) caused a fatal error. Those blocks now render nothing when there is no event to show.
- Fixed: rebuilding an event's index rows appended instead of replacing them, so a site indexed twice listed every event twice. Rebuilding is now safe to repeat.
- Fixed: after updating the plugin, event type and venue archive URLs could resolve to the wrong page until rewrite rules were flushed by hand. Rules are now flushed once on the first load after an update.
- Fixed: the "Events base slug" setting was saved but never applied. It now sets the URL base for single events, the events archive, and the type, venue and tag archives, and rewrite rules refresh automatically when it changes.
- The index is rebuilt and rewrite rules are flushed automatically on first load after upgrading.

### 1.3.0
- Added: an "Ongoing, no end date" toggle in the event editor for events with no announced end, such as long-running exhibits. Ongoing events stay in upcoming listings until you set an end date or unpublish them.
- Added: the Event Date & Time block shows only the start for ongoing events, with an optional "Ongoing label" attribute to print in place of the end date.
- Added: an "Exclude Event Types" filter on the Events Query block, so a listing can show everything except one type without naming every other type.
- Changed: the REST API returns `"ongoing": true` with null end fields for ongoing events; the Calendar View shows them on their start day; the iCal feed and per-event .ics downloads emit a VEVENT with no DTEND.
- Changed: the admin Events list shows "Ongoing" in the End Date column for these events.
- Changed: the countdown block treats a started ongoing event as in progress indefinitely.
- Database schema upgraded to version 3 (new `ongoing` column). The event index is rebuilt automatically on first load after upgrading.

### 1.2.0
- Added: a separate event template per layout — list and grid can each show their own blocks. Splitting is opt-in per block, so existing content is untouched.
- Added: a WordPress Playground demo blueprint, so the plugin can be tried in a browser.
- Changed: switching between list and grid no longer reloads the page.
- Changed: the view switcher's starting layout follows the Events Query block. The separate Default view control is gone — it was what let the two disagree.
- Changed: every Events Query starts with an Event Template container, matching the core Query Loop's Post Template.
- Changed: the view switcher draws real SVG icons, in the editor preview as well as the front end.
- Fixed: undoing a list/grid change in the editor did not revert it, and left the page permanently unsaved
- Fixed: splitting a template dropped the list template and rendered every layout through the grid one
- Fixed: splitting a template could fail with an error and corrupt the block tree
- Fixed: "No events found." appeared beneath every event in editor previews

### 1.1.0
- Added: Events View Switcher block — visitors can switch the event list between list and grid layouts. The choice travels in the URL, and the control works without JavaScript.
- Added: filter blocks for event type, venue and date range, each as a dropdown with its choices in a panel.
- Added: base styling for all filter controls, taking colour from the theme.
- Added: a translation template (`languages/blockendar.pot`).
- Changed: the Event Type and Venue filters now default to their dropdown style. Blocks where the style was never chosen explicitly will switch from a list to a dropdown on upgrade.
- Changed: the Date Range filter shows a calendar directly in its dropdown, and dates apply on Apply rather than as you click.
- Changed: the Event Type and Venue filters list only terms that have upcoming events.
- Changed: the Events Query block previews real events in the editor instead of grey placeholder bars.
- Fixed: the Event Type filter never actually filtered anything
- Fixed: filter blocks collapsed to zero width inside a Row block
- Fixed: the date picker opened far below its field, and closing it reloaded the page
- Fixed: a backwards date range showed "no events found" instead of the range you meant
- Fixed: the filter blocks' own stylesheets were never loaded on the front end
- Fixed: an invalid datetime attribute on single-day events with an end time
- Fixed: filter parameters given as arrays in a URL could select the wrong term
- Performance: calendar front-end script 263 KB → 5 KB, and event queries are now cached
- Tested up to WordPress 7.1

### 1.0.1
- Fixed: event titles containing `&` were displayed as `&amp;` in the Calendar View block

### 1.0.0
- Event Cost block: added Show Cost and Show Button toggles; removed unused min/max cost, currency, and capacity meta fields
- Event Venue block: support for multiple venue terms with horizontal rule dividers; removed redundant Show Map toggle (use the dedicated Event Map block instead)
- Event Map block: support for multiple venue pins with automatic bounds fitting
- Event Countdown block: added three-state display — counting down, started (event in progress), and passed (after end time)
- Venue admin: added "Look up coordinates" button using Nominatim (OpenStreetMap) geocoding — no API key required
- Removed Google Maps integration — all map and geocoding features now use OpenStreetMap/Nominatim exclusively
- Performance: replaced `wp_postmeta` subqueries for `featured` and `hide_from_listings` with denormalised columns in the event index table
- Performance: replaced `JSON_CONTAINS` type-term filtering with a dedicated junction table (`blockendar_event_type_terms`)
- DB schema upgraded to version 2; existing sites will rebuild the event index automatically on upgrade

### 0.10.0
- Calendar View block: automatically switches to list view on mobile (≤767px)
- Calendar View block: view switcher buttons hidden on mobile (≤767px)
- Calendar View block: toolbar buttons smaller below 425px
- Calendar View block: toolbar title right-aligned below 425px
- Calendar View block: list event time allowed to wrap below 425px

### 0.9.9
- Added Plugin Update Checker (v5.6) for automatic update notifications via GitHub releases

### 0.9.8
- Added `blockendar/events-query-no-results` block: a customisable empty-state block for the Events Query block, matching the pattern of `core/query-no-results`
- Events Query block: block spacing (gap) now respects the spacing preset selected in the editor, including "None" to remove all gap
- Events Query block: `No Results - Events Query` block is included in the default template

### 0.9.7
- Events Query block: added responsive column controls for grid layout — separate column counts for mobile (≤599px), tablet (600–781px), and desktop

### 0.9.6
- Event Date & Time block: editor preview now reads date/time format from Blockendar settings via the WordPress data layer (fixes preview showing WP core format instead of plugin setting)
- Event Date & Time block: added block-level date format, time format, date/time separator, and range separator overrides in a new Format inspector panel
- Event Date & Time block: fixed "All day" label displaying when Show start time is disabled
- Editor: replaced deprecated `__experimentalGetSettings` with stable `getSettings` from `@wordpress/date`
- Editor: added `__next40pxDefaultSize` to all `SelectControl` instances in the event editor sidebar panels

### 0.9.5
- REST API: enforce `rest_public` / `rest_feed_token` settings across all public GET endpoints (events, calendar, iCal)
- Fixed: PHP fatal "Cannot redeclare" when two event-cost blocks appear on the same page (e.g. inside a Query Loop)
- Fixed: calendar-view fetch now checks HTTP status before parsing JSON, passing errors to FullCalendar's failure handler
- Added PHPUnit + Jest test suites covering the recurrence engine and JS utilities
- Added WP-CLI `wp blockendar rebuild-index` command
- Added search keywords to 7 blocks for improved block inserter discoverability
- Lint: fixed all JS and PHP code style warnings

### 0.9.4
- Events Query block now supports a "Related events" mode (same type / same venue / both); replaces the standalone Related Events block
- Removed the Related Events block
- Event Countdown block reworked: fixed garbled segment display, full unit labels, format picker, pin-to-any-event selector, improved editor preview
- Added full color, typography, spacing, border, and dimensions supports to Event Countdown
- Fixed: plugin header version was mismatched with the `BLOCKENDAR_VERSION` constant
- Fixed: uninstalling the plugin now correctly clears the WP-Cron schedule even if the plugin was not deactivated first
- Fixed: venue name is now HTML-escaped before being passed to the Leaflet map popup, preventing potential XSS
- Fixed: event-countdown timer is stopped when its element is removed from the DOM, preventing detached-element leaks
- Fixed: all-day events now store an exclusive end boundary (`DATE+1 00:00:00`) in the index, matching RFC 5545 and improving range-query accuracy (requires an index rebuild after updating)
- Added inline warning in the Event Details panel when min cost exceeds max cost

### 0.9.3
- Reworked Date & Time editor panel — new field order (start date → start time → end time → end date), smart defaults, end date/time safeguards, and past-date prevention on the start date field
- Recurrence preset labels are context-aware and derived from the selected start date ("Weekly on Thursday", "Monthly on the third Thursday", "Annually on March 13")
- Fixed: recurring events auto-save their recurrence rule when the post is updated — no separate save step required
- Fixed: changing a recurrence preset now marks the post dirty, activating the Update button immediately
- Calendar View event pills use `contrast-color()` (CSS Color Level 6) for automatic black/white text contrast against the event type background colour
- Added `blockendar_recurrence_preset` post meta field (REST-exposed) as a dirty-state proxy for recurrence changes

### 0.9.2
- Occurrence-aware routing — calendar chip links include `?occurrence_date=YYYY-MM-DD`; single-event blocks (`event-datetime`, `event-countdown`, `add-to-calendar`) display the clicked occurrence rather than always defaulting to the next upcoming one
- Events Query block now shows each occurrence in the queried range as its own list item with correct dates and permalinks; removed post-ID deduplication
- Calendar View block editor placeholder replaced with a live settings summary
- Added recurrence save/delete REST endpoints (`POST`/`DELETE /blockendar/v1/events/{id}/recurrence`)
- Removed the event-list block (superseded by events-query)
- Removed Published Date column from the Events admin list table

### 0.9.1
- Recurrence fields merged into the Date & Time editor panel
- Removed custom Venue editor panel — uses native taxonomy panel; venue details managed via term editor
- Added ABSPATH guard to all PHP files
- Simplified plugin URL constants — replaced symlink-aware IIFE with direct `plugins_url()` calls
- Fixed stale `editorStyle` and `interactivity` flags in `calendar-view` and `event-list` block.json
- Plugin zip now correctly includes the `templates/` directory
- Added Git Updater headers for automatic update notifications via tagged GitHub releases
- Added `languages/` directory; bumped Tested up to: 6.9

### 0.9.0
- Initial public release candidate

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
