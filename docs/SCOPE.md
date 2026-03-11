# Blockendar — Plugin Scope & Architecture

**Status:** Draft — In Review
**WordPress Target:** 6.8+
**PHP Minimum:** 8.1
**JS Stack:** `@wordpress/scripts`, `@wordpress/element` (React 18)
**License:** GPL-2.0-or-later
**Text Domain / Prefix:** `blockendar`
**Block Namespace:** `blockendar/`
**Version:** 0.2 Draft — March 2026

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Data Architecture](#2-data-architecture)
3. [Database Tables](#3-database-tables)
4. [Block Architecture](#4-block-architecture)
5. [Admin UI](#5-admin-ui)
6. [Recurring Events](#6-recurring-events)
7. [REST API](#7-rest-api)
8. [Frontend Display](#8-frontend-display)
9. [Plugin File Structure](#9-plugin-file-structure)
10. [Out of Scope (v1)](#10-out-of-scope-v1)
11. [Open Questions](#11-open-questions)

---

## 1. Project Overview

Blockendar is a block-native WordPress events plugin built for modern WordPress (6.8+). It is designed around a single core philosophy: **the block editor is the UI** — for authoring, for display, and for configuration. There are no shortcodes, no proprietary frontend themes, and no legacy widget areas.

### The Problem With Existing Plugins

Most popular events plugins have two fundamental problems:

1. **They predate the block editor.** The Events Calendar, EventOn, and similar tools were built in the shortcode/widget era. Block support was bolted on later, and it shows — proprietary template systems, non-standard query patterns, and frontend markup that fights theme styles.

2. **They rely on WordPress's query infrastructure for everything.** Storing event dates in `wp_postmeta` and querying them via `WP_Query` with `meta_query` date comparisons is genuinely slow at scale. It also makes recurring events nearly impossible to query correctly without either storing a post per instance (post table bloat) or doing expensive PHP-side filtering.

### This Plugin's Approach

- **Events are a CPT** — but the CPT is used for authoring, content storage, and REST access. It is _not_ used for date queries.
- **All date/time queries run against a dedicated custom table** (`wpe_events`) — a denormalised index of event occurrences optimised for date-range lookups. This table is the single source of truth for "what events happen between date A and date B."
- **Recurring events are stored as rules, not posts** — a single CPT post with a recurrence rule in `wpe_recurrence`. Individual occurrences are materialised into the `wpe_events` index. No post-per-instance pollution.
- **All frontend display is via custom blocks** — no Query Loop dependency, no shortcodes. The plugin ships its own complete set of display blocks that read from the REST API or are server-side rendered.
- **The block editor is the event editor** — all event-specific fields (dates, venue, recurrence, cost) live in `PluginDocumentSettingPanel` sidebar panels. No separate admin pages for editing events.

### Core Design Principles

- Block-editor first: all display and authoring is via blocks
- Custom tables own date queries — `WP_Query` / `wp_postmeta` is never used for event date filtering
- Recurring events = one CPT post + one recurrence rule + N index rows (not N posts)
- Blocks are the display layer — the plugin ships every block needed; no dependency on Query Loop or theme templates for basic display
- REST API is the data contract between the database and all block-rendered UIs
- GPL-2.0-or-later throughout — safe for WordPress.org distribution

---

## 2. Data Architecture

### 2.1 Custom Post Type — `blockendar_event`

The CPT stores the canonical event content. Post meta stores event-specific fields. The CPT is **not** queried by date — all date-based queries go through the `blockendar_events` custom table.

| Property | Value |
|---|---|
| Post Type Slug | `blockendar_event` |
| Public | Yes — has archive and single templates |
| Has Archive | Yes — `/events/` |
| Supports | `title`, `editor`, `excerpt`, `thumbnail`, `custom-fields`, `revisions` |
| Show in REST | Yes |
| Menu Icon | `dashicons-calendar-alt` |

### 2.2 Post Meta Fields

All fields are prefixed `blockendar_` and registered via `register_post_meta()` for REST API and block editor access. These fields are **authoritative** — the `blockendar_events` index table is derived from them and can be rebuilt at any time.

| Field | Type | Required | Notes |
|---|---|---|---|
| `blockendar_start_date` | DATE | Yes | Event start date `Y-m-d` |
| `blockendar_end_date` | DATE | Yes | Event end date — same as start for single-day |
| `blockendar_start_time` | TIME | No | Start time `H:i` — null if all-day |
| `blockendar_end_time` | TIME | No | End time `H:i` — null if all-day |
| `blockendar_all_day` | BOOLEAN | No | Overrides times; hides time UI in editor |
| `blockendar_timezone` | STRING | No | IANA timezone e.g. `America/Chicago` |
| `blockendar_status` | STRING | No | `scheduled` \| `cancelled` \| `postponed` \| `sold_out` |
| `blockendar_cost` | STRING | No | Free-text display string e.g. `$10–$25` or `Free` |
| `blockendar_cost_min` | FLOAT | No | Numeric min cost for filtering/sorting |
| `blockendar_cost_max` | FLOAT | No | Numeric max cost for filtering/sorting |
| `blockendar_currency` | STRING | No | ISO 4217 e.g. `USD` |
| `blockendar_registration_url` | URL | No | External ticket/RSVP link |
| `blockendar_capacity` | INT | No | Max attendees — `0` = unlimited |
| `blockendar_featured` | BOOLEAN | No | Flag for featured/promoted events |
| `blockendar_hide_from_listings` | BOOLEAN | No | Exclude from calendar/lists without trashing |

### 2.3 Taxonomies

#### Event Type

Hierarchical taxonomy for categorising events. Behaves like post categories — supports parent/child relationships (e.g. Music > Live Performance). Term colour (for calendar colour-coding) is stored as term meta.

| Property | Value |
|---|---|
| Taxonomy Slug | `event_type` |
| Hierarchical | Yes |
| Public | Yes |
| Show in REST | Yes |
| Rewrite Slug | `/events/type/` |

**Term meta:**
- `blockendar_type_color` — Hex colour for calendar display (e.g. `#3B82F6`)

#### Event Tag

Non-hierarchical flat taxonomy for ad-hoc tagging. Analogous to post tags.

| Property | Value |
|---|---|
| Taxonomy Slug | `event_tag` |
| Hierarchical | No |
| Public | Yes |
| Show in REST | Yes |
| Rewrite Slug | `/events/tag/` |

#### Venue

Venue is modelled as a **hierarchical taxonomy** rather than a CPT. This is a deliberate v1 trade-off: it gives free WordPress archive URLs, REST filtering, and native block taxonomy support without the overhead of a relationship CPT. Venue-specific data (address, coordinates) is stored as term meta.

> **Note:** If a future version needs richer venue management (per-venue user roles, seating maps, media galleries), Venue can be promoted to a CPT with a straightforward migration. The taxonomy approach is the correct starting point.

| Property | Value |
|---|---|
| Taxonomy Slug | `event_venue` |
| Hierarchical | Yes |
| Public | Yes |
| Show in REST | Yes |
| Rewrite Slug | `/events/venue/` |

**Venue term meta:**

| Field | Type | Notes |
|---|---|---|
| `blockendar_venue_address` | STRING | Street address line 1 |
| `blockendar_venue_address2` | STRING | Suite, floor, etc. |
| `blockendar_venue_city` | STRING | City |
| `blockendar_venue_state` | STRING | State / Province |
| `blockendar_venue_postcode` | STRING | Postal / ZIP code |
| `blockendar_venue_country` | STRING | ISO 3166-1 alpha-2 |
| `blockendar_venue_lat` | FLOAT | Latitude (decimal degrees) |
| `blockendar_venue_lng` | FLOAT | Longitude (decimal degrees) |
| `blockendar_venue_url` | URL | Venue website |
| `blockendar_venue_phone` | STRING | Contact phone |
| `blockendar_venue_capacity` | INT | Venue max capacity |
| `blockendar_venue_virtual` | BOOLEAN | Flag as online/virtual venue |
| `blockendar_venue_stream_url` | URL | Stream link for virtual events |

---

## 3. Database Tables

This is the performance core of the plugin. Two custom tables are created on activation. They are **query-optimised projections** of the canonical CPT/meta data — never edited directly, always rebuilt from post meta.

### 3.1 `{prefix}blockendar_events` — Event Instance Index

A denormalised index of all event occurrences, including each instance of a recurring event. **All calendar and list queries run against this table only.** No `wp_postmeta` joins, no `WP_Query` meta queries.

```sql
CREATE TABLE {prefix}blockendar_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id        BIGINT UNSIGNED NOT NULL,
  start_datetime DATETIME        NOT NULL,          -- UTC
  end_datetime   DATETIME        NOT NULL,          -- UTC
  start_date     DATE            NOT NULL,          -- local date for day-based queries
  end_date       DATE            NOT NULL,
  all_day        TINYINT(1)      NOT NULL DEFAULT 0,
  recurrence_id  BIGINT UNSIGNED          DEFAULT NULL,
  status         VARCHAR(20)              DEFAULT 'scheduled',
  venue_term_id  BIGINT UNSIGNED          DEFAULT NULL, -- denormalised for join-free venue filtering
  type_term_ids  JSON                     DEFAULT NULL, -- denormalised array of event_type term IDs
  PRIMARY KEY (id),
  KEY idx_start_datetime (start_datetime),
  KEY idx_end_datetime   (end_datetime),
  KEY idx_start_date     (start_date),
  KEY idx_post_id        (post_id),
  KEY idx_venue          (venue_term_id),
  KEY idx_status         (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Query pattern for a date-range calendar fetch:**
```sql
SELECT e.id, e.post_id, e.start_datetime, e.end_datetime, e.all_day,
       e.status, e.venue_term_id, e.type_term_ids,
       p.post_title
FROM   {prefix}blockendar_events e
JOIN   {prefix}posts p ON p.ID = e.post_id
WHERE  e.start_datetime < '2026-04-01 00:00:00'
  AND  e.end_datetime   > '2026-03-01 00:00:00'
  AND  p.post_status    = 'publish'
ORDER  BY e.start_datetime ASC;
```

No `wp_postmeta` join. No `meta_query`. Indexed date columns only.

### 3.2 `{prefix}blockendar_recurrence` — Recurrence Rules

Stores the recurrence rule for each repeating event. Based on RFC 5545 (iCalendar) RRULE semantics.

```sql
CREATE TABLE {prefix}blockendar_recurrence (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id      BIGINT UNSIGNED NOT NULL,
  frequency    VARCHAR(10)     NOT NULL,  -- daily | weekly | monthly | yearly
  interval_val SMALLINT        NOT NULL DEFAULT 1,
  byday        VARCHAR(50)              DEFAULT NULL,  -- RFC 5545 e.g. MO,WE,FR
  bymonthday   VARCHAR(100)             DEFAULT NULL,  -- day(s) of month
  bysetpos     VARCHAR(50)              DEFAULT NULL,  -- e.g. 1=first, -1=last
  until_date   DATE                     DEFAULT NULL,  -- exclusive with count
  count        SMALLINT                 DEFAULT NULL,
  exceptions   JSON                     DEFAULT NULL,  -- excluded dates [Y-m-d]
  additions    JSON                     DEFAULT NULL,  -- manually added extra dates
  PRIMARY KEY (id),
  UNIQUE KEY idx_post_id (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.3 Index Rebuild Strategy

The `blockendar_events` table is a derived projection. It must be kept in sync:

- **On event save/update** — delete all rows for `post_id`, regenerate from post meta + recurrence rule
- **On event trash/delete** — delete all rows for `post_id`
- **Daily WP-Cron job** — rolls the recurrence horizon forward, generating new instances as they enter the lookahead window
- **WP-CLI command** — `wp blockendar rebuild-index` for full table rebuild (useful after bulk imports or plugin upgrades)
- **Admin action** — "Rebuild Event Index" button in plugin settings

---

## 4. Block Architecture

All frontend display is delivered through Gutenberg blocks. No shortcodes. No widget areas. No theme template overrides required for basic functionality.

Blocks are categorised as either **dynamic (server-side rendered)** — where output depends on live data fetched at render time — or **static** — where the block saves its output to post content and enhances with JS where needed.

### 4.1 Display Blocks

| Block | Type | Description |
|---|---|---|
| `blockendar/calendar-view` | Dynamic | FullCalendar React component — month/week/day/list views, fetches from REST |
| `blockendar/event-list` | Dynamic | Configurable upcoming/past events list with inline filters |
| `blockendar/event-header` | Dynamic | Event title, date/time, status badge — for single event templates |
| `blockendar/event-datetime` | Dynamic | Formatted start/end date and time with timezone |
| `blockendar/event-venue` | Dynamic | Venue name, address, and optional map embed |
| `blockendar/event-cost` | Dynamic | Cost display with registration/ticket link button |
| `blockendar/event-description` | Dynamic | Event content/description |
| `blockendar/event-categories` | Dynamic | Linked event type taxonomy terms |
| `blockendar/event-tags` | Dynamic | Linked event tag taxonomy terms |
| `blockendar/event-status` | Dynamic | Styled status badge (cancelled, postponed, sold out) |
| `blockendar/event-countdown` | Static + JS | Countdown timer to event start |
| `blockendar/event-map` | Dynamic | Map embed from venue lat/lng (Google Maps or Leaflet) |
| `blockendar/related-events` | Dynamic | Events sharing type or venue with the current event |
| `blockendar/add-to-calendar` | Static | Download .ics / add to Google Calendar / Outlook links |
| `blockendar/venue-info` | Dynamic | Full venue detail block — address, phone, website, map |

### 4.2 Block Query Controls

The `blockendar/calendar-view` and `blockendar/event-list` blocks share a common set of inspector panel query controls:

- Filter by Event Type (taxonomy multi-select)
- Filter by Venue (taxonomy select)
- Date range: upcoming only / past only / custom range
- Featured events only toggle
- Maximum events to display
- Order by: start date (asc/desc), title, cost

### 4.3 Single Event Block Template

The plugin registers a block template (`block-templates/single-blockendar_event.html`) that composes the plugin's own blocks into a complete single event page layout. This works out of the box with any block theme and can be overridden in `{theme}/templates/single-blockendar_event.html`.

The default template layout:
1. `blockendar/event-header` — title + date + status
2. Featured image (core `core/post-featured-image`)
3. `blockendar/event-datetime` + `blockendar/event-venue` — in a two-column layout
4. `blockendar/event-cost` + `blockendar/add-to-calendar`
5. `blockendar/event-description`
6. `blockendar/event-map`
7. `blockendar/event-categories` + `blockendar/event-tags`
8. `blockendar/related-events`

### 4.4 Block Patterns

Patterns are registered in a dedicated `Blockendar` pattern category:

- **Upcoming Events — Card Grid** — 3-column card layout with featured image, title, date, venue
- **Upcoming Events — Compact List** — date | title | venue in a tight list
- **Calendar + Sidebar** — FullCalendar block left, event list block right
- **Single Event — Full Layout** — complete single event page composition
- **Featured Event — Hero** — large featured event with image background
- **Venue Directory** — grid of venue cards from the `event_venue` taxonomy

---

## 5. Admin UI

### 5.1 Plugin Settings Page

A React SPA registered under **Settings > Blockendar**, built with `@wordpress/components` for native WP admin feel.

**Sections:**
- **General** — date format, time format, timezone handling (event timezone vs site timezone)
- **Calendar Display** — default view (month/week/day/list), first day of week, time slot duration
- **Permalinks** — base slug (default `/events/`), single event slug structure
- **Map** — provider (Google Maps API key | OpenStreetMap/Leaflet), default zoom level
- **Currency** — default currency symbol and position
- **Recurring Events** — lookahead horizon (default 365 days), max instances, generation strategy (on save vs cron)
- **Performance** — index rebuild action, index row count stats, last rebuild timestamp
- **REST API** — toggle public REST endpoint visibility, optional feed token for calendar endpoint

### 5.2 Event Editor Sidebar Panels

All event-specific fields are surfaced in the block editor sidebar via `PluginDocumentSettingPanel`. No separate metabox UI — the block editor is the event editor.

**Panels:**
- **Date & Time** — start/end date pickers, start/end time pickers, all-day toggle, timezone selector
- **Recurrence** — frequency, interval, day selectors, end condition, exception date picker
- **Event Details** — status selector, cost fields, registration URL, capacity, featured toggle
- **Venue** — `event_venue` taxonomy selector with inline term creation (name + address fields)

### 5.3 Venue Manager

A dedicated submenu page (**Events > Venues**) provides a searchable, sortable list of all venue terms with their address and metadata visible inline. Uses `@wordpress/dataviews` for the table UI. Clicking a venue opens a side panel for editing term meta without leaving the page.

---

## 6. Recurring Events

### 6.1 Architecture

Recurring events are stored as **one CPT post + one recurrence rule**. Individual occurrences are materialised into the `wpe_events` index table — not as separate posts.

This is the key decision that separates this plugin architecturally from The Events Calendar (which creates a post per recurrence instance, leading to massive post table bloat on busy sites). The index table approach keeps `wp_posts` clean and queries fast.

### 6.2 Instance Generation

When a recurring event is saved, instances are materialised into `wpe_events` up to the configured horizon (default: 365 days ahead):

- Small recurrence sets (< 50 instances in window) — generated synchronously on save
- Large sets — dispatched to `WP_Background_Processing` queue
- Daily WP-Cron job rolls the horizon forward and generates new instances as they enter range
- All instances inherit their parent post's taxonomy terms and status at query time (denormalised into the index row on generation, refreshed on index rebuild)

**Instance override ("break off"):**
Individual instances can be detached from the recurrence rule to become standalone posts. This creates a new `wp_event` post pre-populated with the instance's date, with the original recurrence rule updated to exclude that date. The editor presents this as: _"Edit this event / Edit this and following / Edit all events."_

### 6.3 Recurrence UI

The Recurrence sidebar panel mirrors Google Calendar / Outlook conventions:

- **Frequency:** Does not repeat / Daily / Weekly / Monthly / Yearly
- **Interval:** Every N [days / weeks / months / years]
- **Weekly options:** Day-of-week checkboxes (Mon–Sun)
- **Monthly options:** On day X of the month _or_ on the Nth weekday (e.g. second Tuesday)
- **Ends:** Never / On [date] / After [N] occurrences
- **Exception dates:** Date picker to mark specific occurrences as cancelled or removed

---

## 7. REST API

The plugin exposes a custom REST namespace `blockendar/v1`. All calendar and list block queries go through these endpoints — not through the default `wp/v2/blockendar_event` route — because date-range queries on recurring instances require the custom table.

### Endpoints

| Endpoint | Auth | Description |
|---|---|---|
| `GET /blockendar/v1/events` | Public | Date-range query against `blockendar_events` index. Params: `start`, `end`, `venue`, `type`, `featured`, `status`, `per_page`, `page` |
| `GET /blockendar/v1/events/{id}` | Public | Single event — full post meta + recurrence rule + upcoming instances |
| `GET /blockendar/v1/events/{id}/instances` | Public | All materialised instances of a recurring event |
| `GET /blockendar/v1/calendar` | Public | FullCalendar-compatible feed: returns `{id, title, start, end, url, color, extendedProps}` |
| `GET /blockendar/v1/venues` | Public | All venues with full term meta |
| `GET /blockendar/v1/venues/{id}` | Public | Single venue + upcoming events at that venue |
| `POST /blockendar/v1/events/{id}/instances/{date}/cancel` | Auth (edit_posts) | Mark a single occurrence as cancelled |
| `POST /blockendar/v1/events/{id}/instances/{date}/exception` | Auth (edit_posts) | Add an exception date to the recurrence rule |
| `POST /blockendar/v1/index/rebuild` | Auth (manage_options) | Trigger a full index rebuild |

### Response Shape — Calendar Feed

```json
{
  "id": "blockendar_42_2026-04-15",
  "post_id": 42,
  "title": "Spring Concert",
  "start": "2026-04-15T19:00:00-05:00",
  "end": "2026-04-15T22:00:00-05:00",
  "allDay": false,
  "url": "https://example.com/events/spring-concert/",
  "color": "#3B82F6",
  "status": "scheduled",
  "extendedProps": {
    "venue": { "id": 7, "name": "The Venue", "city": "Chicago" },
    "types": [{ "id": 3, "name": "Music", "slug": "music" }],
    "cost": "$15–$25",
    "featured": false
  }
}
```

---

## 8. Frontend Display

### 8.1 Calendar View Block (`blockendar/calendar-view`)

Renders a FullCalendar (MIT) React component. Fetches events from `/blockendar/v1/calendar` with date params automatically set to the current view window. The block inspector controls available views and default view.

- Views: month, week, day, list — all enabled by default, individually toggleable
- Colour-coded by Event Type term colour
- Click opens the single event URL — no modal (SEO-friendly, no JS dependency for content)
- Responsive — collapses to list view below configurable breakpoint
- Filter dropdowns (Event Type, Venue) rendered above the calendar
- Loading state with skeleton UI while fetching

### 8.2 Event List Block (`blockendar/event-list`)

Server-side rendered on initial load (no JS required for basic display). A React filter bar enhances the block progressively on the frontend. Filter state is managed in URL params so filtered views are shareable and back-button-safe.

- Groupable by: date, month, event type
- Configurable card layout vs compact list layout
- Past/upcoming toggle
- Pagination or "load more" (configurable)

### 8.3 iCalendar Export

- Every single event page gets a canonical `.ics` endpoint: `/events/{slug}/ical/`
- The calendar feed endpoint also supports `.ics` output: `/blockendar/v1/calendar?format=ics`
- `blockendar/add-to-calendar` block generates add-to links client-side (no server round-trip): Google Calendar, Apple Calendar, Outlook, generic `.ics` download

---

## 9. Plugin File Structure

```
blockendar/
├── blockendar.php                  # Plugin bootstrap, constants, loader
├── SCOPE.md                        # This document
│
├── includes/
│   ├── CPT/
│   │   └── EventPostType.php       # CPT registration
│   ├── Taxonomy/
│   │   ├── EventType.php
│   │   ├── EventTag.php
│   │   └── Venue.php
│   ├── Meta/
│   │   ├── EventMeta.php           # register_post_meta(), sanitisation, REST schema
│   │   └── VenueMeta.php           # register_term_meta()
│   ├── DB/
│   │   ├── Schema.php              # Table creation, version upgrades
│   │   ├── EventIndex.php          # All queries against blockendar_events
│   │   └── IndexBuilder.php        # Rebuilds index from CPT data
│   ├── Recurrence/
│   │   ├── Rule.php                # RRULE parsing (RFC 5545)
│   │   ├── Generator.php           # Materialises instances into blockendar_events
│   │   └── Cron.php                # Daily horizon-rolling WP-Cron job
│   ├── REST/
│   │   ├── EventsController.php
│   │   ├── CalendarController.php  # FullCalendar feed + iCal output
│   │   └── VenuesController.php
│   ├── Blocks/
│   │   └── BlockRegistrar.php      # Registers all blocks + patterns
│   ├── ICS/
│   │   └── Exporter.php            # iCalendar file generation
│   ├── Admin/
│   │   └── SettingsPage.php        # Settings SPA registration
│   └── CLI/
│       └── RebuildCommand.php      # wp blockendar rebuild-index
│
├── src/                            # JS/JSX source (@wordpress/scripts)
│   ├── blocks/
│   │   ├── calendar-view/          # block.json, edit.jsx, view.jsx (FullCalendar)
│   │   ├── event-list/             # block.json, edit.jsx, render.php
│   │   ├── event-header/
│   │   ├── event-datetime/
│   │   ├── event-venue/
│   │   ├── event-cost/
│   │   ├── event-description/
│   │   ├── event-categories/
│   │   ├── event-tags/
│   │   ├── event-status/
│   │   ├── event-countdown/
│   │   ├── event-map/
│   │   ├── related-events/
│   │   ├── add-to-calendar/
│   │   └── venue-info/
│   ├── editor/                     # PluginDocumentSettingPanel sidebars
│   │   ├── DateTimePanel.jsx
│   │   ├── RecurrencePanel.jsx
│   │   ├── EventDetailsPanel.jsx
│   │   └── VenuePanel.jsx
│   └── admin/                      # Settings page SPA
│       └── SettingsApp.jsx
│
├── build/                          # @wordpress/scripts compiled output
├── block-templates/
│   └── single-blockendar_event.html  # Default single event block template
├── patterns/                       # .php pattern files
└── languages/                      # .pot / .po / .mo
```

---

## 10. Out of Scope (v1)

The following are explicitly deferred to keep v1 focused and shippable:

- Ticketing / payment processing — registration URL link only; no checkout
- Attendee management / RSVP lists
- Frontend event submission forms
- Multi-organiser / multi-site support
- Email notifications or reminders
- Waiting lists
- Seating charts or venue room layouts
- iCalendar feed import (export only in v1)
- WooCommerce integration for paid tickets
- Event series (distinct from recurrence — e.g. a named multi-week course)

---

## 11. Open Questions

| Question | Category | Status | Notes |
|---|---|---|---|
| Venue as CPT vs Taxonomy | Architecture | Open — v1 uses Taxonomy | Revisit if per-venue user roles or media galleries are needed |
| Recurrence horizon | Performance | Open | 365-day default. High-volume sites may need shorter window or lazy generation |
| Map provider | UX / Cost | Open | Google Maps requires API key (billed). OSM/Leaflet is free. Support both with provider setting |
| Event Type colour source | UX | Open | Per-type term colour vs per-event colour override vs both with fallback chain |
| iCal feed import | Scope | Deferred v2 | Parsing external iCal feeds is complex and error-prone — keep out of v1 |
| Filter state in URL | UX | Open | URL params enable shareable filtered views but add JS complexity to the list block |
| Calendar feed auth | Security | Open | Public feeds risk scraping. Optional per-feed secret token would allow private/authenticated feeds |
| Index rebuild on bulk import | Performance | Open | Need a hook or WP-CLI trigger when events are created via bulk import / WXR upload |
| `blockendar_events.type_term_ids` JSON vs junction table | DB Design | **Resolved — JSON** | Date range filter runs first on indexed datetime columns, reducing the result set before the JSON_CONTAINS check. Single-term type filtering on a pre-filtered set is fast enough on MySQL 8+. No junction table needed in v1. |
