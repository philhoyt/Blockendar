=== Blockendar ===
Contributors: philhoyt
Tags: events, calendar, blocks, gutenberg, recurring events
Requires at least: 6.8
Tested up to: 6.9
Stable tag: 0.10.0
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A block-native WordPress events plugin.

== Description ==

Blockendar is a fully block-native events plugin for WordPress. Every part of the event editing and display experience is built with the block editor — no shortcodes, no legacy widgets, no classic meta boxes.

**Key features:**

* **Block-based event editor** — Date & time, recurrence, venue, cost, registration, and status are all managed through dedicated block editor sidebar panels.
* **Recurring events** — Full recurrence rule support (daily, weekly, monthly, yearly) with exceptions, custom additions, and a rolling horizon cron job.
* **Calendar view block** — Interactive FullCalendar-powered calendar with day, week, and month views. Outputs valid iCal feeds.
* **Events query block** — Flexible query block for custom event displays; shows individual occurrences of recurring events with correct dates and occurrence-aware links.
* **7 single-event blocks** — Modular display blocks for event data: date/time, venue, cost, status, countdown, map, and add-to-calendar.
* **Custom database layer** — All date range queries run against a dedicated indexed table, keeping calendar queries fast regardless of post count.
* **REST API** — Full read/write REST API under the `blockendar/v1` namespace, including iCal feed and index rebuild endpoints.
* **Venues** — Venue taxonomy with rich meta (address, coordinates, capacity, website) and an inline venue creator in the editor.
* **Admin settings** — Configurable date/time formats, timezone mode, calendar defaults, map provider, currency, recurrence horizon, and more.
* **GitHub-based updates** — Automatic update notifications in wp-admin, powered by tagged GitHub releases.

== Installation ==

1. Go to the [Releases page](https://github.com/philhoyt/Blockendar/releases) and download the latest `blockendar.zip` asset.
2. In your WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the downloaded `.zip` file and click **Install Now**.
4. Click **Activate Plugin**.
5. Navigate to **Events** in the admin menu to create your first event.
6. Add the **Calendar View** or **Events Query** block to any page or post.

== Frequently Asked Questions ==

= Does this work with classic themes? =

Yes. The display blocks render standard HTML and can be used in any theme that supports the block editor. Single-event page templates are provided for block themes.

= How do recurring events work? =

Recurring events are authored as a single post with a recurrence rule (RRULE-style). Blockendar expands the rule into an occurrence index table for fast date queries. A daily WP-Cron job rolls the horizon forward automatically.

= Can I export events to a calendar app? =

Yes. The Calendar View block exposes an iCal feed URL, and individual events have an Add to Calendar block that generates `.ics` files compatible with Google Calendar, Apple Calendar, and Outlook.

= Where is event data stored? =

Event metadata is stored in standard WordPress post meta. All date/time data is additionally indexed in a custom table (`{prefix}blockendar_events`) for performant range queries.

= Does it work with WordPress Multisite? =

Each site in a multisite network gets its own database tables. The plugin has not been tested extensively in multisite environments.

== Screenshots ==

1. Event editor with Date & Time, Recurrence, Venue, and Event Details sidebar panels.
2. Calendar View block displaying a monthly calendar.
3. Event List block with grouped date headings.
4. Admin Settings page.

== Changelog ==

= 0.10.0 =
* Calendar View block: automatically switches to list view on mobile (≤767px).
* Calendar View block: view switcher buttons hidden on mobile (≤767px).
* Calendar View block: toolbar buttons smaller below 425px.
* Calendar View block: toolbar title right-aligned below 425px.
* Calendar View block: list event time allowed to wrap below 425px.

= 0.9.9 =
* Added Plugin Update Checker (v5.6) for automatic update notifications via GitHub releases.

= 0.9.8 =
* Added No Results - Events Query block: a customisable empty-state block for the Events Query block, matching the pattern of core/query-no-results.
* Events Query block: block spacing (gap) now respects the spacing preset selected in the editor, including "None" to remove all gap.
* Events Query block: No Results block is included in the default template.

= 0.9.7 =
* Events Query block: added responsive column controls for grid layout — separate column counts for mobile (≤599px), tablet (600–781px), and desktop.

= 0.9.6 =
* Event Date & Time block: editor preview now reads date/time format from Blockendar settings via the WordPress data layer (fixes preview showing WP core format instead of plugin setting).
* Event Date & Time block: added block-level date format, time format, date/time separator, and range separator overrides in a new Format inspector panel.
* Event Date & Time block: fixed "All day" label displaying when Show start time is disabled.
* Editor: replaced deprecated __experimentalGetSettings with stable getSettings from @wordpress/date.
* Editor: added __next40pxDefaultSize to all SelectControl instances in the event editor sidebar panels.

= 0.9.5 =
* REST API: enforce rest_public / rest_feed_token settings across all public GET endpoints (events, calendar, iCal).
* Fixed: PHP fatal "Cannot redeclare" when two event-cost blocks appear on the same page (e.g. inside a Query Loop).
* Fixed: calendar-view fetch now checks HTTP status before parsing JSON, passing errors to FullCalendar's failure handler.
* Added PHPUnit + Jest test suites covering the recurrence engine and JS utilities.
* Added WP-CLI wp blockendar rebuild-index command.
* Added search keywords to 7 blocks for improved block inserter discoverability.
* Lint: fixed all JS and PHP code style warnings.

= 0.9.4 =
* Events Query block now supports a "Related events" mode — set to same event type, same venue, or both to automatically show events related to the current page's event. Replaces the standalone Related Events block.
* Removed the Related Events block (superseded by Events Query's built-in related mode).
* Event Countdown block reworked: fixed garbled segment display, full unit labels (days, hours, minutes, seconds), format picker (days+hours+minutes+seconds / days+hours+minutes / days+hours / days only), pin-to-any-event selector, and improved editor preview.
* Added full color, typography, spacing, border, and dimensions supports to the Event Countdown block.
* Fixed: plugin header version was mismatched with the BLOCKENDAR_VERSION constant.
* Fixed: uninstalling the plugin now correctly clears the WP-Cron schedule even if the plugin was not deactivated first.
* Fixed: venue name is now HTML-escaped before being passed to the Leaflet map popup, preventing potential XSS.
* Fixed: event-countdown timer is stopped when its element is removed from the DOM, preventing detached-element leaks.
* Fixed: all-day events now store an exclusive end boundary (next-day midnight) in the index, matching RFC 5545 and improving range-query accuracy. Requires an index rebuild after updating.
* Added inline warning in the Event Details panel when min cost exceeds max cost.

= 0.9.3 =
* Reworked Date & Time editor panel — new field order (start date → start time → end time → end date), smart defaults, end date/time safeguards that prevent end from being set before start, and past-date prevention on the start date field.
* Recurrence preset labels are now context-aware and derived from the selected start date (e.g. "Weekly on Thursday", "Monthly on the third Thursday", "Annually on March 13").
* Fixed: recurring events now auto-save their recurrence rule whenever the post is updated — no separate save step required.
* Fixed: changing a recurrence preset now correctly marks the post as dirty, activating the Update button immediately.
* Calendar View event pills use `contrast-color()` (CSS Color Level 6) to automatically select black or white text based on the event type's background colour.
* Added `blockendar_recurrence_preset` post meta field (REST-exposed) as a dirty-state proxy for recurrence changes.

= 0.9.2 =
* Occurrence-aware routing — calendar chip links include `?occurrence_date=YYYY-MM-DD`; single-event blocks (`event-datetime`, `event-countdown`, `add-to-calendar`) display the clicked occurrence rather than always defaulting to the next upcoming one.
* Events Query block now shows each occurrence in the queried range as its own list item with correct dates and permalinks; removed post-ID deduplication.
* Calendar View block editor placeholder replaced with a live settings summary.
* Added recurrence save/delete REST endpoints (`POST`/`DELETE /blockendar/v1/events/{id}/recurrence`).
* Removed the event-list block (superseded by events-query).
* Removed Published Date column from the Events admin list table.

= 0.9.1 =
* Recurrence fields merged into the Date & Time editor panel.
* Removed custom Venue editor panel — venue selection uses the native taxonomy panel; venue details are managed via the term editor.
* Added ABSPATH guard to all PHP files.
* Simplified plugin URL constants — replaced symlink-aware IIFE with direct `plugins_url()` calls.
* Fixed stale `editorStyle` and `interactivity` flags in `calendar-view` and `event-list` block.json files.
* Plugin zip now correctly includes the `templates/` directory.
* Added Git Updater headers for automatic update notifications via tagged GitHub releases.
* Added `languages/` directory to satisfy `Domain Path` plugin header.
* Updated block count in readme to reflect current 11 blocks.
* Bumped Tested up to: 6.9.

= 0.9.0 =
* Initial public release candidate.
* Block-native event editor with date/time, recurrence, venue, cost, and status panels.
* Calendar View block with FullCalendar integration and iCal feed.
* Event List block with grouped, paginated output.
* 11 blocks: calendar view, event list, events query, and 8 single-event display blocks.
* Custom database layer with indexed occurrence table.
* Full recurrence engine with daily cron horizon rolling.
* REST API under `blockendar/v1`.
* Admin settings SPA with general, calendar, permalinks, map, currency, recurring, performance, and REST API sections.
* GitHub-based automatic update notifications.

== Upgrade Notice ==

= 0.10.0 =
No upgrade steps required.

= 0.9.9 =
No upgrade steps required.

= 0.9.8 =
No upgrade steps required.

= 0.9.7 =
No upgrade steps required.

= 0.9.6 =
No upgrade steps required.

= 0.9.5 =
No upgrade steps required.

= 0.9.4 =
The Related Events block has been removed. Replace any existing instances with the Events Query block and enable the "Related events" mode in its settings. After updating, run Rebuild Index from Settings > Blockendar > Performance to correct all-day event end boundaries.

= 0.9.3 =
No upgrade steps required.

= 0.9.2 =
No upgrade steps required. The event-list block is removed; replace any instances with the events-query block.

= 0.9.1 =
No upgrade steps required.

= 0.9.0 =
Initial public release candidate. No upgrade steps required.
