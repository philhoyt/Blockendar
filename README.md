# Blockendar

A block-native WordPress events plugin.

## Features

- **Block-based event editor** — Date & time, recurrence, venue, cost, registration, and status managed through dedicated block editor sidebar panels
- **Recurring events** — Full recurrence rule support (daily, weekly, monthly, yearly) with exceptions, custom additions, and a rolling horizon cron job
- **Calendar View block** — Interactive FullCalendar-powered calendar with day, week, and month views; exposes a valid iCal feed
- **Events Query block** — Flexible query block for custom event displays; shows individual occurrences of recurring events with correct dates and occurrence-aware links
- **7 single-event blocks** — Date/time, venue, cost, status, countdown, map, add-to-calendar
- **Custom database layer** — All date range queries run against a dedicated indexed table (`{prefix}blockendar_events`)
- **REST API** — Full read/write API under `blockendar/v1`, including iCal feed and index rebuild endpoints
- **Venues** — Taxonomy with rich meta (address, coordinates, capacity, website) and inline venue creation in the editor
- **Admin settings** — Date/time formats, timezone mode, calendar defaults, map provider, currency, recurrence horizon, and more
- **GitHub-based updates** — Automatic update notifications in wp-admin via tagged releases

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.8 |
| PHP | 8.1 |

## Installation

1. Go to the [Releases page](https://github.com/philhoyt/Blockendar/releases) and download the latest `blockendar.zip` asset.
2. In your WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the downloaded `.zip` file and click **Install Now**.
4. Click **Activate Plugin**.

## Development

```bash
npm run start        # Watch mode
npm run build        # Production build
npm run lint:js      # JS lint
npm run lint:css     # CSS lint
npm run lint:php     # PHP lint (WPCS)
npm run lint:php:fix # Auto-fix PHP lint issues
npm run plugin-zip   # Build distributable zip
```

## Architecture

- **Namespace:** `Blockendar\` → `includes/` (PSR-4)
- **REST namespace:** `blockendar/v1`
- **CPT:** `blockendar_event` (authoring only — never queried by date via `WP_Query`)
- **Taxonomies:** `event_type` (hierarchical), `event_tag` (flat), `event_venue` (hierarchical)
- **DB tables:** `{prefix}blockendar_events` (occurrence index), `{prefix}blockendar_recurrence` (RRULE storage)

## Changelog

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
