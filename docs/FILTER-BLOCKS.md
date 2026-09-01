# Blockendar — Filter Blocks Feature Plan

**Status:** Shipped in 1.1.0
**Last updated:** 2026-09-01

---

## Overview

A set of filter blocks that let visitors interactively narrow the results of an `events-query` block by event type, venue, and date range.

This is the plan the blocks were built from, kept for the reasoning behind the
design. It is not API documentation — `block.json` is the authority on
attributes. Where the built result diverged from the plan, the sections below
have been corrected and the change noted.

---

## Block Structure

```
blockendar/query-filters  (wrapper — provides queryId context)
├── blockendar/filter-event-type
├── blockendar/filter-venue
├── blockendar/filter-date-range
└── blockendar/events-query  (existing block, nested inside)
```

The wrapper mirrors WordPress core's `core/query` pattern. Its `queryId` attribute flows to all children as block context, enabling multiple independent filter+query groups on the same page.

---

## Communication: URL Query Params

Filter blocks render standard HTML `<form method="get">` in PHP. Filters are applied via URL params — works with zero JavaScript. A small `view.js` per block adds progressive enhancement (auto-submit, validation, clear button).

| Param | Format | Notes |
|---|---|---|
| `blockendar_type` | `3,7` | Comma-separated term IDs — multi-select |
| `blockendar_venue` | `12` | Single term ID |
| `blockendar_date_start` | `2026-04-01` | Y-m-d |
| `blockendar_date_end` | `2026-06-30` | Y-m-d |
| `blockendar_page` | `2` | Existing pagination param — unchanged |

When `queryId` is non-empty, each param gets a suffix: `blockendar_type_sidebar`, `blockendar_venue_sidebar`, etc. Allows independent filter groups on the same page.

---

## New Blocks

### `blockendar/query-filters`

Wrapper block. Renders no HTML of its own beyond a wrapper `<div data-blockendar-query-id="...">` containing inner blocks.

**Attributes:**
- `queryId` (string, default `""`)

**Provides context:** `blockendar/queryId`

**edit.jsx:** `InspectorControls` with a `TextControl` for `queryId`. Canvas renders `<InnerBlocks />`. Uses `BlockContextProvider` to push context to children. Suggested inner block template: the three filter blocks followed by `events-query`.

---

### `blockendar/filter-event-type`

Renders event type terms as a filterable list or dropdown.

**Attributes:**
- `displayStyle` (enum: `"list"` | `"dropdown"`, default `"dropdown"`) — planned as `"list"`; the dropdown proved the better default and existing blocks were migrated to it in 1.1.0.
- `showCount` (boolean, default `false`) — shows occurrence count per term. Note: enables N+1 queries; only suitable for small term lists.
- `showEmptyTerms` (boolean, default `false`)
- `label` (string, default `""`) — optional visible label above the control.
- `triggerLabel` (string, default `"All types"`) — text on the closed dropdown.

**render.php:** Fetches `event_type` terms, renders checkboxes (multi-select) or `<select multiple>`. Marks active terms from `$_GET`. Preserves other active filter params as hidden inputs.

**view.js:** Intercepts checkbox/select change, strips `blockendar_page` param, updates URL via `location.assign()`.

---

### `blockendar/filter-venue`

Renders venue terms as a filterable list or dropdown. Single-select (one venue per occurrence row in the index).

**Attributes:**
- `displayStyle` (enum: `"list"` | `"dropdown"`, default `"dropdown"`) — see the note on the event type filter.
- `showEmpty` (boolean, default `false`)
- `showVirtual` (boolean, default `true`)
- `label` (string, default `""`) — optional visible label above the control.
- `triggerLabel` (string, default `"All venues"`) — text on the closed dropdown.

**render.php:** Fetches `event_venue` terms, optionally filters out virtual venues. Renders radio buttons or `<select>`. Includes an "All venues" option that clears the filter. Marks active venue.

**view.js:** Auto-submit on radio/select change.

---

### `blockendar/filter-date-range`

Airbnb-style date range picker backed by **Flatpickr** (MIT, ~16 KB gzipped, vanilla JS).

**Attributes:**
- `labelStart` (string, default `"From"`)
- `labelEnd` (string, default `"To"`)
- `minDate` (string, default `""`) — optional Y-m-d lower bound
- `maxDate` (string, default `""`) — optional Y-m-d upper bound
- `label` (string, default `""`) — optional visible label above the control.
- `triggerLabel` (string, default `"All dates"`) — text on the closed dropdown.

**render.php:** Renders two `<input type="date">` fields (functional without JS), an "Apply dates" submit button and a "Clear dates" link. Pre-populates from `$_GET`.

**view.js:** Lazy-imports Flatpickr and renders it inline in the dropdown panel, so the panel *is* the calendar rather than fields that open one. Falls back to the native date inputs if Flatpickr fails to load.

Two changes from the plan, both made in 1.1.0: the calendar is shown directly
in the panel instead of being attached to the inputs, and a range applies when
"Apply dates" is pressed rather than on selection. Applying as you click fired
a navigation mid-range, before the second date had been chosen.

**Dependency:** `flatpickr` (MIT) — added to `package.json`, bundled via webpack into the block's `viewScript`.

---

## PHP Helper: `FilterContext`

`includes/Blocks/FilterContext.php` — centralises all `$_GET` reading and param-name resolution. Both `events-query/render.php` and each filter block's `render.php` call into it.

```php
namespace Blockendar\Blocks;

class FilterContext {

    public static function param_name( string $key, string $query_id ): string {
        $base = 'blockendar_' . $key;
        return '' !== $query_id ? $base . '_' . sanitize_key( $query_id ) : $base;
    }

    public static function get_active_filters( string $query_id ): array {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $type_raw   = sanitize_text_field( wp_unslash( $_GET[ self::param_name( 'type', $query_id ) ] ?? '' ) );
        $venue_raw  = absint( $_GET[ self::param_name( 'venue', $query_id ) ] ?? 0 );
        $date_start = sanitize_text_field( wp_unslash( $_GET[ self::param_name( 'date_start', $query_id ) ] ?? '' ) );
        $date_end   = sanitize_text_field( wp_unslash( $_GET[ self::param_name( 'date_end', $query_id ) ] ?? '' ) );
        // phpcs:enable

        return [
            'type_ids'   => array_filter( array_map( 'absint', explode( ',', $type_raw ) ) ),
            'venue_id'   => $venue_raw ?: null,
            'date_start' => self::validate_date( $date_start ),
            'date_end'   => self::validate_date( $date_end ),
        ];
    }

    private static function validate_date( string $date ): ?string {
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : null;
    }
}
```

---

## Changes to Existing Files

### `src/blocks/events-query/block.json`

Add `"blockendar/queryId"` to the `usesContext` array.

### `src/blocks/events-query/render.php`

In the standard (non-inherit, non-relatedTo) branch:

1. Read `$query_id = $block->context['blockendar/queryId'] ?? '';`
2. Call `$active = FilterContext::get_active_filters( $query_id );`
3. Merge URL type IDs with the block's static `typeIds` using **intersection logic**: if the block has static `typeIds`, the URL filter cannot widen past what the editor configured. `$effective_type_ids = empty($static_type_ids) ? $url_type_ids : array_intersect($url_type_ids, $static_type_ids)`
4. Add `venue_term_id` to `$standard_filters` when a venue filter is active.
5. Override `$start` / `$end` when date range params are present. When `showPast = false`, apply intersection: `$start = max( $now, $filter_date_start . ' 00:00:00' )`.
6. Namespace the `blockendar_page` param via `FilterContext::param_name('page', $query_id)`.

The `inherit` and `relatedTo` branches are **not** modified — they derive filters from WP context and post relationships, not URL params.

---

## Edge Cases

| Scenario | Behaviour |
|---|---|
| Filter block outside a wrapper | `queryId` context is `""` — falls back to unprefixed params. Works for single-group pages. |
| Changing a filter | `view.js` strips `blockendar_page` before submitting, resetting to page 1. |
| `showPast = false` + past date range | Effective start = `max(now, filter_date_start)`. If date range is entirely in the past, returns empty. |
| Block has static `typeIds` + URL type filter | Intersection applied — URL cannot widen past the editor's curation. |
| Pretty vs plain permalinks | Form action uses `get_pagenum_link(1)` to handle both permalink modes. |
| Multiple query groups on one page | Each wrapper has a distinct `queryId` — params are namespaced, groups are independent. |

---

## New Files

```
includes/Blocks/FilterContext.php

src/blocks/query-filters/block.json
src/blocks/query-filters/edit.jsx
src/blocks/query-filters/index.js
src/blocks/query-filters/render.php

src/blocks/filter-event-type/block.json
src/blocks/filter-event-type/edit.jsx
src/blocks/filter-event-type/index.js
src/blocks/filter-event-type/render.php
src/blocks/filter-event-type/view.js
src/blocks/filter-event-type/style.css

src/blocks/filter-venue/block.json
src/blocks/filter-venue/edit.jsx
src/blocks/filter-venue/index.js
src/blocks/filter-venue/render.php
src/blocks/filter-venue/view.js
src/blocks/filter-venue/style.css

src/blocks/filter-date-range/block.json
src/blocks/filter-date-range/edit.jsx
src/blocks/filter-date-range/index.js
src/blocks/filter-date-range/render.php
src/blocks/filter-date-range/view.js
src/blocks/filter-date-range/style.css
```

---

## Build Order

1. `FilterContext.php` — no dependencies, can be tested in isolation
2. `events-query` modifications — verify existing pagination and modes are unaffected
3. `query-filters` wrapper — mostly context plumbing, trivial render
4. `filter-event-type` — most complex (multi-select, optional counts)
5. `filter-venue` — simpler (single-select, virtual venue toggle)
6. `filter-date-range` — Flatpickr integration, date validation in `view.js`

---

## Future Improvements (Post-v1)

- **AJAX re-render**: A `GET /blockendar/v1/render/events-query` endpoint that returns rendered HTML. Filter `view.js` calls it on change and swaps the DOM instead of doing a full page reload. Requires converting `events-query/render.php` to be callable outside the block render pipeline. Still open: every filter change is a full page load. Note that the view switcher now swaps layout in place, but it does so by rendering both layouts up front, which only works because there are two known outcomes — filters have arbitrarily many.
- **Term event counts** in the type/venue filters: currently a potential N+1 when `showCount` is enabled. A single `GROUP BY` query against the index table would be more efficient.
- **Denormalize `featured` and `hide_from_listings`** into the index table to eliminate the `wp_postmeta` subqueries (see `SCOPE.md` §3.3).
- ~~**Junction table for `type_term_ids`**~~ — delivered in 1.0.0 as `{prefix}blockendar_event_type_terms`, replacing the `JSON_CONTAINS` filtering entirely.
