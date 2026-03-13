=== Blockendar ===
Contributors: philhoyt
Tags: events, calendar, blocks, gutenberg, recurring events
Requires at least: 6.8
Tested up to: 6.9
Stable tag: 0.9.4
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

1. Upload the `blockendar` directory to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Events** in the admin menu to create your first event.
4. Add the **Calendar View** or **Events Query** block to any page or post.

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
