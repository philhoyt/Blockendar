=== Blockendar ===
Contributors: philhoyt
Tags: events, calendar, blocks, gutenberg, recurring events
Requires at least: 6.8
Tested up to: 6.8
Stable tag: 0.9.0
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
* **Event list block** — Grouped, paginated event listing with server-side rendering.
* **13 single-event blocks** — Modular display blocks for every piece of event data: header, date/time, venue, cost, description, categories, tags, status, countdown, map, related events, add-to-calendar, and venue info.
* **Custom database layer** — All date range queries run against a dedicated indexed table, keeping calendar queries fast regardless of post count.
* **REST API** — Full read/write REST API under the `blockendar/v1` namespace, including iCal feed and index rebuild endpoints.
* **Venues** — Venue taxonomy with rich meta (address, coordinates, capacity, website) and an inline venue creator in the editor.
* **Admin settings** — Configurable date/time formats, timezone mode, calendar defaults, map provider, currency, recurrence horizon, and more.
* **GitHub-based updates** — Automatic update notifications in wp-admin, powered by tagged GitHub releases.

== Installation ==

1. Upload the `blockendar` directory to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Events** in the admin menu to create your first event.
4. Add the **Calendar View** or **Event List** block to any page or post.

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

= 0.9.0 =
* Initial public release candidate.
* Block-native event editor with date/time, recurrence, venue, cost, and status panels.
* Calendar View block with FullCalendar integration and iCal feed.
* Event List block with grouped, paginated output.
* 13 single-event display blocks.
* Custom database layer with indexed occurrence table.
* Full recurrence engine with daily cron horizon rolling.
* REST API under `blockendar/v1`.
* Admin settings SPA with general, calendar, permalinks, map, currency, recurring, performance, and REST API sections.
* GitHub-based automatic update notifications.

== Upgrade Notice ==

= 0.9.0 =
Initial public release candidate. No upgrade steps required.
