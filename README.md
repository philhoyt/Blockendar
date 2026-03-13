# Blockendar

A block-native WordPress events plugin.

## Features

- **Block-based event editor** — Date & time, recurrence, venue, cost, registration, and status managed through dedicated block editor sidebar panels
- **Recurring events** — Full recurrence rule support (daily, weekly, monthly, yearly) with exceptions, custom additions, and a rolling horizon cron job
- **Calendar View block** — Interactive FullCalendar-powered calendar with day, week, and month views; exposes a valid iCal feed
- **Events Query block** — Flexible query block for custom event displays; shows individual occurrences of recurring events with correct dates and occurrence-aware links
- **8 single-event blocks** — Date/time, venue, cost, status, countdown, map, related events, add-to-calendar
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

```bash
# Clone or download the repository
git clone https://github.com/philhoyt/Blockendar.git

# Install JS dependencies and build
npm install
npm run build
```

Then upload the plugin directory to `/wp-content/plugins/` and activate through the WordPress admin.

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
