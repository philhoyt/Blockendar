# Blockendar — Manual QA Checklist

**Plugin version:** 0.1.0
**Last updated:** 2026-03-11
**Legend:** ✅ Pass · ❌ Fail · ⚠️ Partial · 🔲 Untested

---

## 1. Plugin Activation & Setup

| # | Test | Status | Notes |
|---|------|--------|-------|
| 1.1 | Plugin activates without errors or warnings | 🔲 | |
| 1.2 | Both custom DB tables created on activation (`blockendar_events`, `blockendar_recurrence`) | 🔲 | |
| 1.3 | Deactivate → reactivate does not duplicate tables or throw errors | 🔲 | |
| 1.4 | `blockendar_db_version` option is set after activation | 🔲 | |
| 1.5 | **Events** menu item appears in WP admin | 🔲 | |
| 1.6 | **Settings > Blockendar** page is accessible | 🔲 | |
| 1.7 | Block category **Blockendar** appears in the block inserter | 🔲 | |

---

## 2. Event Creation — Editor Panels

### 2.1 Date & Time Panel

| # | Test | Status | Notes |
|---|------|--------|-------|
| 2.1.1 | Date & Time panel appears in sidebar when editing a `blockendar_event` | ✅ | |
| 2.1.2 | Start date input accepts and saves a date | ✅ | |
| 2.1.3 | End date input accepts and saves a date | ✅ | |
| 2.1.4 | Start time select saves correctly (12-hour mode) | ✅ | |
| 2.1.5 | End time select saves correctly (12-hour mode) | ✅ | |
| 2.1.6 | AM/PM toggle switches time correctly | ✅ | |
| 2.1.7 | All-day toggle hides time selects and saves `all_day = true` | 🔲 | |
| 2.1.8 | Timezone dropdown defaults to site timezone (not Africa/Abidjan) | ✅ | |
| 2.1.9 | Timezone selection is saved and persists on page reload | 🔲 | |
| 2.1.10 | 24-hour mode works after changing setting in admin (see §7.1) | 🔲 | |
| 2.1.11 | Date & Time panel does not overflow the sidebar | ✅ | |

### 2.2 Event Details Panel

| # | Test | Status | Notes |
|---|------|--------|-------|
| 2.2.1 | Event Details panel appears in sidebar | 🔲 | |
| 2.2.2 | Status selector saves (scheduled / cancelled / postponed / sold out) | 🔲 | |
| 2.2.3 | Cost field saves and persists | 🔲 | |
| 2.2.4 | Currency selector saves | 🔲 | |
| 2.2.5 | Registration URL saves | 🔲 | |
| 2.2.6 | Capacity field saves | 🔲 | |
| 2.2.7 | Featured toggle saves | 🔲 | |
| 2.2.8 | "Hide from listings" toggle saves | 🔲 | |

### 2.3 Venue Panel

| # | Test | Status | Notes |
|---|------|--------|-------|
| 2.3.1 | Venue panel appears in sidebar | 🔲 | |
| 2.3.2 | Existing venue can be selected from the combobox | 🔲 | |
| 2.3.3 | New venue can be created inline (name + address) | 🔲 | |
| 2.3.4 | Selected venue is saved and persists on reload | 🔲 | |

### 2.4 Recurrence Panel

| # | Test | Status | Notes |
|---|------|--------|-------|
| 2.4.1 | Recurrence panel appears in sidebar | 🔲 | |
| 2.4.2 | "Does not repeat" is the default | 🔲 | |
| 2.4.3 | Daily recurrence saves and generates index rows | 🔲 | |
| 2.4.4 | Weekly recurrence with specific days saves correctly | 🔲 | |
| 2.4.5 | Monthly recurrence (by day of month) saves correctly | 🔲 | |
| 2.4.6 | Monthly recurrence (by Nth weekday) saves correctly | 🔲 | |
| 2.4.7 | Yearly recurrence saves correctly | 🔲 | |
| 2.4.8 | "Ends on date" condition saves and limits instance generation | 🔲 | |
| 2.4.9 | "Ends after N occurrences" condition saves correctly | 🔲 | |
| 2.4.10 | Removing recurrence deletes rule and regenerates index as single event | 🔲 | |

---

## 3. Event Index

| # | Test | Status | Notes |
|---|------|--------|-------|
| 3.1 | Publishing a new event creates a row in `blockendar_events` | ✅ | Fixed: `rest_after_insert` hook |
| 3.2 | Updating an event's date refreshes its index row | 🔲 | |
| 3.3 | Trashing an event removes its index rows | 🔲 | |
| 3.4 | Restoring from trash re-creates index rows | 🔲 | |
| 3.5 | Deleting an event permanently removes its index rows | 🔲 | |
| 3.6 | "Rebuild Index" button in Settings works and updates the row count | 🔲 | |
| 3.7 | Index row count stat is accurate in Settings > Performance | 🔲 | |
| 3.8 | Hidden events (`hide_from_listings = true`) are excluded from calendar queries | 🔲 | |

---

## 4. Calendar View Block

| # | Test | Status | Notes |
|---|------|--------|-------|
| 4.1 | Block can be inserted from the block inserter | ✅ | |
| 4.2 | Block shows a placeholder in the editor | 🔲 | |
| 4.3 | Block renders FullCalendar on the frontend | ✅ | |
| 4.4 | Month view displays events | ✅ | |
| 4.5 | Week view displays events | 🔲 | |
| 4.6 | Day view displays events | 🔲 | |
| 4.7 | List view (next 31 days) displays events | ✅ | |
| 4.8 | List view "today" button resets to current date | 🔲 | |
| 4.9 | Prev/next navigation loads correct events | 🔲 | |
| 4.10 | Clicking an event navigates to the event's single page | 🔲 | |
| 4.11 | All-day events display correctly (no time shown) | 🔲 | |
| 4.12 | Multi-day events span correctly across days | 🔲 | |
| 4.13 | Event type colour is applied to event chips | 🔲 | |
| 4.14 | Recurring event instances all appear individually | 🔲 | |
| 4.15 | Inspector: disabling a view removes its button from the toolbar | 🔲 | |
| 4.16 | Inspector: changing the default view is reflected on frontend | 🔲 | |
| 4.17 | Inspector: venue filter limits events shown | 🔲 | |
| 4.18 | Inspector: event type filter limits events shown | 🔲 | |
| 4.19 | Inspector: featured-only toggle limits events shown | 🔲 | |
| 4.20 | Cancelled events still appear (with status visible) | 🔲 | |
| 4.21 | Hidden events do not appear | 🔲 | |

---

## 5. Event List Block

| # | Test | Status | Notes |
|---|------|--------|-------|
| 5.1 | Block can be inserted from the block inserter | 🔲 | |
| 5.2 | Upcoming events render correctly | 🔲 | |
| 5.3 | Past events toggle works | 🔲 | |
| 5.4 | List layout renders | 🔲 | |
| 5.5 | Grid layout renders | 🔲 | |
| 5.6 | Group by date works | 🔲 | |
| 5.7 | Group by month works | 🔲 | |
| 5.8 | Group by event type works | 🔲 | |
| 5.9 | Pagination works (paged mode) | 🔲 | |
| 5.10 | "Load more" works (load_more mode) | 🔲 | |
| 5.11 | Venue filter limits results | 🔲 | |
| 5.12 | Event type filter limits results | 🔲 | |
| 5.13 | Featured-only filter limits results | 🔲 | |
| 5.14 | Hidden events do not appear | 🔲 | |
| 5.15 | ServerSideRender preview updates when inspector controls change | 🔲 | |

---

## 6. Single Event Blocks

| # | Block | Test | Status | Notes |
|---|-------|------|--------|-------|
| 6.1 | `event-header` | Displays title, date, and status badge | 🔲 | |
| 6.2 | `event-datetime` | Displays formatted start/end date and time | 🔲 | |
| 6.3 | `event-datetime` | All-day events show date only (no time) | 🔲 | |
| 6.4 | `event-venue` | Displays venue name and address | 🔲 | |
| 6.5 | `event-cost` | Displays cost and registration link | 🔲 | |
| 6.6 | `event-cost` | Free events display "Free" | 🔲 | |
| 6.7 | `event-description` | Displays event post content | 🔲 | |
| 6.8 | `event-categories` | Displays linked event type terms | 🔲 | |
| 6.9 | `event-tags` | Displays linked event tag terms | 🔲 | |
| 6.10 | `event-status` | Displays styled badge for cancelled / postponed / sold out | 🔲 | |
| 6.11 | `event-countdown` | Counts down to event start in real time | 🔲 | |
| 6.12 | `event-countdown` | Shows "Event has started" or similar when past | 🔲 | |
| 6.13 | `event-map` | Renders Leaflet map when venue has lat/lng | 🔲 | |
| 6.14 | `event-map` | No map rendered when venue has no coordinates | 🔲 | |
| 6.15 | `related-events` | Shows events sharing type or venue | 🔲 | |
| 6.16 | `add-to-calendar` | Google Calendar link opens correctly | 🔲 | |
| 6.17 | `add-to-calendar` | `.ics` download works | 🔲 | |
| 6.18 | `venue-info` | Displays full venue details | 🔲 | |

---

## 7. Admin Settings

### 7.1 General

| # | Test | Status | Notes |
|---|------|--------|-------|
| 7.1.1 | Settings page loads without errors | 🔲 | |
| 7.1.2 | Date format field saves | 🔲 | |
| 7.1.3 | 12-hour radio saves `g:i a` format | 🔲 | |
| 7.1.4 | 24-hour radio saves `H:i` format | 🔲 | |
| 7.1.5 | Time format change is reflected in the event editor on next load | 🔲 | |
| 7.1.6 | Timezone display mode saves (event / site) | 🔲 | |

### 7.2 Calendar Display

| # | Test | Status | Notes |
|---|------|--------|-------|
| 7.2.1 | Default view setting saves | 🔲 | |
| 7.2.2 | First day of week setting saves | 🔲 | |

### 7.3 Permalinks

| # | Test | Status | Notes |
|---|------|--------|-------|
| 7.3.1 | Events base slug saves | 🔲 | |
| 7.3.2 | Changing slug updates the event archive URL | 🔲 | |

### 7.4 Map

| # | Test | Status | Notes |
|---|------|--------|-------|
| 7.4.1 | Map provider setting saves | 🔲 | |
| 7.4.2 | Default zoom saves | 🔲 | |

### 7.5 Currency

| # | Test | Status | Notes |
|---|------|--------|-------|
| 7.5.1 | Default currency saves | 🔲 | |
| 7.5.2 | Currency position (before/after) saves | 🔲 | |

### 7.6 Recurring Events

| # | Test | Status | Notes |
|---|------|--------|-------|
| 7.6.1 | Horizon days setting saves | 🔲 | |
| 7.6.2 | Max instances setting saves | 🔲 | |

### 7.7 Performance

| # | Test | Status | Notes |
|---|------|--------|-------|
| 7.7.1 | Index row count is displayed | 🔲 | |
| 7.7.2 | Last rebuild timestamp is displayed | 🔲 | |
| 7.7.3 | Rebuild Index button triggers rebuild and updates stats | 🔲 | |

### 7.8 REST API

| # | Test | Status | Notes |
|---|------|--------|-------|
| 7.8.1 | Public REST toggle saves | 🔲 | |
| 7.8.2 | Feed token field saves | 🔲 | |

---

## 8. REST API Endpoints

| # | Endpoint | Test | Status | Notes |
|---|----------|------|--------|-------|
| 8.1 | `GET /blockendar/v1/calendar` | Returns events for a date range | ✅ | |
| 8.2 | `GET /blockendar/v1/calendar` | `?venue=` filter works | 🔲 | |
| 8.3 | `GET /blockendar/v1/calendar` | `?type=` filter works | 🔲 | |
| 8.4 | `GET /blockendar/v1/calendar` | `?featured=1` filter works | 🔲 | |
| 8.5 | `GET /blockendar/v1/calendar` | `?format=ics` returns valid iCal | 🔲 | |
| 8.6 | `GET /blockendar/v1/events` | Returns paginated event list | 🔲 | |
| 8.7 | `GET /blockendar/v1/events/{id}` | Returns single event detail | 🔲 | |
| 8.8 | `GET /blockendar/v1/events/{id}/instances` | Returns recurrence instances | 🔲 | |
| 8.9 | `GET /blockendar/v1/venues` | Returns venue list | 🔲 | |
| 8.10 | `GET /blockendar/v1/venues/{id}` | Returns single venue + upcoming events | 🔲 | |
| 8.11 | `POST /blockendar/v1/index/rebuild` | Requires authentication | 🔲 | |
| 8.12 | `POST /blockendar/v1/index/rebuild` | Rebuilds index and returns stats | 🔲 | |

---

## 9. iCalendar Export

| # | Test | Status | Notes |
|---|------|--------|-------|
| 9.1 | `/blockendar/v1/calendar?format=ics` returns valid `.ics` content-type | 🔲 | |
| 9.2 | Downloaded `.ics` imports correctly into Apple Calendar | 🔲 | |
| 9.3 | Downloaded `.ics` imports correctly into Google Calendar | 🔲 | |
| 9.4 | All-day events use date-only `DTSTART;VALUE=DATE` format | 🔲 | |
| 9.5 | Timed events use UTC `DTSTART` with Z suffix | 🔲 | |
| 9.6 | Event titles with special characters are correctly escaped | 🔲 | |

---

## 10. Uninstall

| # | Test | Status | Notes |
|---|------|--------|-------|
| 10.1 | Deleting the plugin via WP admin runs `uninstall.php` | 🔲 | |
| 10.2 | Both custom tables are dropped on uninstall | 🔲 | |
| 10.3 | `blockendar_settings` option is removed on uninstall | 🔲 | |

---

## Known Issues

| # | Description | Severity | Status |
|---|-------------|----------|--------|
| K1 | `save_post` fires before REST meta write — fixed with `rest_after_insert` hook | High | ✅ Fixed |
| K2 | Calendar view block missing `render.php` — fixed | High | ✅ Fixed |
| K3 | `listWeek` replaced with rolling 31-day `listNextMonth` custom view | Medium | ✅ Fixed |
| K4 | Date picker (DatePicker component) overflow in sidebar — replaced with `<input type="date">` | Medium | ✅ Fixed |
| K5 | Timezone dropdown defaulting to Africa/Abidjan on UTC-offset sites — fixed | Medium | ✅ Fixed |
| K6 | UTC normalisation bug in `parse_datetime_param` for ISO 8601 with offset — fixed | Medium | ✅ Fixed |
